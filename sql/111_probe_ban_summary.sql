-- Probe-shaped bans stay short, scanner bans climb, and the platform admin
-- gets one summary of new bans a day.
--
-- A streak ban (twenty probe hits from one address) always lasts
-- streak_ban_minutes. A hostile web page can make a store's browser send those
-- hits, so a streak ban must never grow into a day-long block of that store.
-- A scanner ban (a known scanning tool's user agent) still climbs
-- scanner_ban_minutes for repeats within strike_reset_days. Strikes count
-- every ban, so a scanner that comes back after streak bans starts higher.
--
-- The worker files one probe-ban-digest job a day once the local hour reaches
-- summary_hour in summary_time_zone, while summary_enabled is on. The job lists
-- the bans that started in the day before that hour and sends nothing when
-- there were none.

ALTER TABLE platform.probe_rules RENAME COLUMN ban_minutes TO scanner_ban_minutes;
ALTER TABLE platform.probe_rules RENAME CONSTRAINT probe_rules_ban_minutes_chk TO probe_rules_scanner_ban_chk;

ALTER TABLE platform.probe_rules
    ADD COLUMN streak_ban_minutes integer DEFAULT 15 NOT NULL,
    ADD COLUMN summary_enabled boolean DEFAULT true NOT NULL,
    ADD COLUMN summary_hour integer DEFAULT 7 NOT NULL,
    ADD COLUMN summary_time_zone text DEFAULT 'America/Los_Angeles' NOT NULL,
    ADD CONSTRAINT probe_rules_streak_ban_chk CHECK (((streak_ban_minutes >= 1) AND (streak_ban_minutes <= 1440))),
    ADD CONSTRAINT probe_rules_summary_hour_chk CHECK (((summary_hour >= 0) AND (summary_hour <= 23))),
    ADD CONSTRAINT probe_rules_summary_zone_chk CHECK (((summary_time_zone = btrim(summary_time_zone)) AND (summary_time_zone <> ''::text)));

ALTER TABLE platform.probe_rules
    ALTER COLUMN streak_ban_minutes DROP DEFAULT,
    ALTER COLUMN summary_enabled DROP DEFAULT,
    ALTER COLUMN summary_hour DROP DEFAULT,
    ALTER COLUMN summary_time_zone DROP DEFAULT;

CREATE FUNCTION platform.probe_rules_reject_zone() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    BEGIN
        PERFORM (CURRENT_TIMESTAMP AT TIME ZONE NEW.summary_time_zone);
    EXCEPTION
        WHEN invalid_parameter_value THEN
            RAISE EXCEPTION 'the ban summary time zone is not an IANA name'
                USING ERRCODE = '23514';
    END;
    RETURN NEW;
END;
$$;

CREATE TRIGGER probe_rules_zone
    BEFORE INSERT OR UPDATE OF summary_time_zone ON platform.probe_rules
    FOR EACH ROW EXECUTE FUNCTION platform.probe_rules_reject_zone();

REVOKE ALL ON FUNCTION platform.probe_rules_reject_zone() FROM PUBLIC;

CREATE OR REPLACE FUNCTION platform.probe_ban(p_address text, p_reason text, p_agent text, p_path text) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $$
DECLARE
    rules platform.probe_rules%ROWTYPE;
    prior platform.probe_bans%ROWTYPE;
    now_at timestamp with time zone := clock_timestamp();
    next_strike integer;
    minutes integer;
BEGIN
    IF p_address IS NULL OR p_address !~ '^[A-Za-z0-9:.]{1,64}$' THEN
        RAISE EXCEPTION 'a probe ban needs a client address'
            USING ERRCODE = '23514';
    END IF;
    IF p_reason IS NULL OR p_reason NOT IN ('scanner', 'streak') THEN
        RAISE EXCEPTION 'a probe ban needs a known reason'
            USING ERRCODE = '23514';
    END IF;
    PERFORM pg_advisory_xact_lock(87301, hashtext(p_address));
    SELECT * INTO rules FROM platform.probe_rules WHERE id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'the probe rules are missing'
            USING ERRCODE = '23514';
    END IF;
    SELECT * INTO prior FROM platform.probe_bans WHERE client_address = p_address;
    IF FOUND AND prior.banned_until > now_at THEN
        RETURN false;
    END IF;
    IF FOUND AND prior.banned_until > now_at - make_interval(days => rules.strike_reset_days) THEN
        next_strike := prior.strikes + 1;
    ELSE
        next_strike := 1;
    END IF;
    IF p_reason = 'streak' THEN
        minutes := rules.streak_ban_minutes;
    ELSE
        minutes := rules.scanner_ban_minutes[least(next_strike, cardinality(rules.scanner_ban_minutes))];
    END IF;
    INSERT INTO platform.probe_bans (client_address, banned_at, reason, user_agent, path, strikes, banned_until)
    VALUES (
        p_address,
        now_at,
        p_reason,
        left(COALESCE(p_agent, ''), 200),
        left(COALESCE(p_path, ''), 200),
        next_strike,
        now_at + make_interval(mins => minutes)
    )
    ON CONFLICT (client_address) DO UPDATE
    SET banned_at = EXCLUDED.banned_at,
        reason = EXCLUDED.reason,
        user_agent = EXCLUDED.user_agent,
        path = EXCLUDED.path,
        strikes = EXCLUDED.strikes,
        banned_until = EXCLUDED.banned_until;
    DELETE FROM platform.probe_streaks WHERE client_address = p_address;
    RETURN true;
END;
$$;

-- A streak ban already running past the streak length ends at the streak length.
UPDATE platform.probe_bans AS ban
SET banned_until = ban.banned_at + make_interval(mins => rules.streak_ban_minutes)
FROM platform.probe_rules AS rules
WHERE rules.id
  AND ban.reason = 'streak'
  AND ban.banned_until > ban.banned_at + make_interval(mins => rules.streak_ban_minutes);

-- The local day whose summary is due now, or NULL while the summary is off or
-- the summary hour has not come yet today.
CREATE FUNCTION platform.probe_ban_summary_day() RETURNS date
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $$
DECLARE
    rules platform.probe_rules%ROWTYPE;
    local_now timestamp without time zone;
BEGIN
    SELECT * INTO rules FROM platform.probe_rules WHERE id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'the probe rules are missing'
            USING ERRCODE = '23514';
    END IF;
    IF NOT rules.summary_enabled THEN
        RETURN NULL;
    END IF;
    local_now := clock_timestamp() AT TIME ZONE rules.summary_time_zone;
    IF extract(hour FROM local_now) < rules.summary_hour THEN
        RETURN NULL;
    END IF;

    RETURN local_now::date;
END;
$$;

-- The bans that started in the day before the summary hour on p_day, in the
-- summary time zone. A lifted ban is gone and is not listed.
CREATE FUNCTION platform.probe_ban_summary(p_day date)
RETURNS TABLE (
    address text,
    reason text,
    strikes integer,
    path text,
    banned_at text,
    banned_until text,
    time_zone text
)
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $$
DECLARE
    rules platform.probe_rules%ROWTYPE;
    period_end timestamp with time zone;
BEGIN
    IF p_day IS NULL THEN
        RAISE EXCEPTION 'the ban summary needs a day'
            USING ERRCODE = '22023';
    END IF;
    SELECT * INTO rules FROM platform.probe_rules WHERE id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'the probe rules are missing'
            USING ERRCODE = '23514';
    END IF;
    period_end := (p_day + make_interval(hours => rules.summary_hour)) AT TIME ZONE rules.summary_time_zone;

    RETURN QUERY
    SELECT ban.client_address,
           ban.reason,
           ban.strikes,
           ban.path,
           to_char(ban.banned_at AT TIME ZONE rules.summary_time_zone, 'YYYY-MM-DD HH24:MI'),
           to_char(ban.banned_until AT TIME ZONE rules.summary_time_zone, 'YYYY-MM-DD HH24:MI'),
           rules.summary_time_zone
    FROM platform.probe_bans AS ban
    WHERE ban.banned_at >= period_end - interval '1 day'
      AND ban.banned_at < period_end
    ORDER BY ban.banned_at, ban.client_address;
END;
$$;

REVOKE ALL ON FUNCTION platform.probe_ban_summary_day() FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.probe_ban_summary(date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION platform.probe_ban_summary_day() TO stall_worker;
GRANT EXECUTE ON FUNCTION platform.probe_ban_summary(date) TO stall_worker;

-- The digest files its mail through the platform mailbox like every other
-- platform letter, so a failed send retries as an outbound-mail job.
GRANT EXECUTE ON FUNCTION platform.file_outbound_mail(text, text, text, text, text, text) TO stall_worker;

ALTER TABLE platform.jobs DROP CONSTRAINT jobs_kind_chk;
ALTER TABLE platform.jobs ADD CONSTRAINT jobs_kind_chk CHECK (kind = ANY (ARRAY[
    'assess-late-fees'::text,
    'vendor-digest'::text,
    'rent-reminder'::text,
    'reconcile-reports'::text,
    'sale-notice'::text,
    'vendor-letter'::text,
    'markdown-propose'::text,
    'outbound-mail'::text,
    'probe-ban-digest'::text
]));
