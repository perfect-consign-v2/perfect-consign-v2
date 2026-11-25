-- Probe bans follow rules kept in the database, and every ban ends.
--
-- One row of platform.probe_rules says how many probe hits from one address,
-- inside how many minutes, start a ban, and how long each ban in a row lasts.
-- A ban that starts again within strike_reset_days of the last one moves one
-- step up the ladder. The last step repeats.
--
-- stall_app no longer writes probe_bans or probe_streaks directly. It calls
-- platform.probe_hit and platform.probe_ban, which apply these rules. It can
-- still read the bans for the admin list and delete one to lift it.
--
-- A ban already in place when this file runs ends fifteen minutes later.

CREATE TABLE platform.probe_rules (
    id boolean DEFAULT true NOT NULL,
    hit_limit integer NOT NULL,
    window_minutes integer NOT NULL,
    ban_minutes integer[] NOT NULL,
    strike_reset_days integer NOT NULL,
    CONSTRAINT probe_rules_pkey PRIMARY KEY (id),
    CONSTRAINT probe_rules_one_row_chk CHECK (id),
    CONSTRAINT probe_rules_hit_limit_chk CHECK (((hit_limit >= 2) AND (hit_limit <= 1000))),
    CONSTRAINT probe_rules_window_chk CHECK (((window_minutes >= 1) AND (window_minutes <= 1440))),
    CONSTRAINT probe_rules_ban_minutes_chk CHECK (((cardinality(ban_minutes) >= 1)
        AND (cardinality(ban_minutes) <= 10)
        AND (array_position(ban_minutes, NULL) IS NULL)
        AND (1 <= ALL (ban_minutes))
        AND (525600 >= ALL (ban_minutes)))),
    CONSTRAINT probe_rules_strike_reset_chk CHECK (((strike_reset_days >= 1) AND (strike_reset_days <= 365)))
);

INSERT INTO platform.probe_rules (id, hit_limit, window_minutes, ban_minutes, strike_reset_days)
VALUES (true, 20, 10, ARRAY[15, 60, 1440], 7);

ALTER TABLE platform.probe_bans
    ADD COLUMN strikes integer DEFAULT 1 NOT NULL,
    ADD COLUMN banned_until timestamp with time zone;

UPDATE platform.probe_bans
SET banned_until = clock_timestamp() + interval '15 minutes';

ALTER TABLE platform.probe_bans
    ALTER COLUMN banned_until SET NOT NULL,
    ADD CONSTRAINT probe_bans_strikes_chk CHECK ((strikes >= 1)),
    ADD CONSTRAINT probe_bans_until_chk CHECK ((banned_until > banned_at));

ALTER TABLE platform.probe_streaks
    ADD COLUMN window_started_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL;

CREATE FUNCTION platform.probe_ban(p_address text, p_reason text, p_agent text, p_path text) RETURNS boolean
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
    minutes := rules.ban_minutes[least(next_strike, cardinality(rules.ban_minutes))];
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

CREATE FUNCTION platform.probe_hit(p_address text, p_agent text, p_path text) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $$
DECLARE
    rules platform.probe_rules%ROWTYPE;
    now_at timestamp with time zone := clock_timestamp();
    window_start timestamp with time zone;
    hits integer;
BEGIN
    IF p_address IS NULL OR p_address !~ '^[A-Za-z0-9:.]{1,64}$' THEN
        RAISE EXCEPTION 'a probe hit needs a client address'
            USING ERRCODE = '23514';
    END IF;
    PERFORM pg_advisory_xact_lock(87301, hashtext(p_address));
    SELECT * INTO rules FROM platform.probe_rules WHERE id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'the probe rules are missing'
            USING ERRCODE = '23514';
    END IF;
    window_start := now_at - make_interval(mins => rules.window_minutes);
    DELETE FROM platform.probe_streaks WHERE window_started_at <= window_start;
    INSERT INTO platform.probe_streaks (client_address, streak, window_started_at, updated_at)
    VALUES (p_address, 1, now_at, now_at)
    ON CONFLICT (client_address) DO UPDATE
    SET streak = platform.probe_streaks.streak + 1,
        updated_at = now_at
    RETURNING streak INTO hits;
    IF hits < rules.hit_limit THEN
        RETURN false;
    END IF;

    RETURN platform.probe_ban(p_address, 'streak', p_agent, p_path);
END;
$$;

REVOKE ALL ON TABLE platform.probe_rules FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.probe_ban(text, text, text, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.probe_hit(text, text, text) FROM PUBLIC;

GRANT SELECT ON TABLE platform.probe_rules TO stall_app;
GRANT EXECUTE ON FUNCTION platform.probe_ban(text, text, text, text) TO stall_app;
GRANT EXECUTE ON FUNCTION platform.probe_hit(text, text, text) TO stall_app;

REVOKE INSERT ON TABLE platform.probe_bans FROM stall_app;
REVOKE INSERT, UPDATE ON TABLE platform.probe_streaks FROM stall_app;
