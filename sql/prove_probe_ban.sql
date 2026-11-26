-- Probe bans follow platform.probe_rules, and every ban ends. A streak ban
-- always lasts streak_ban_minutes; only a scanner ban climbs the ladder.
-- The daily summary reads the same rules. The script rolls back.

BEGIN;

DELETE FROM platform.probe_bans WHERE client_address LIKE '198.51.100.%';
DELETE FROM platform.probe_streaks WHERE client_address LIKE '198.51.100.%';

UPDATE platform.probe_rules
SET hit_limit = 20,
    window_minutes = 10,
    scanner_ban_minutes = ARRAY[15, 60, 1440],
    streak_ban_minutes = 15,
    strike_reset_days = 7,
    summary_enabled = true,
    summary_hour = 7,
    summary_time_zone = 'UTC';

-- Nineteen hits inside the window do not ban. The twentieth does, for fifteen minutes.
DO $$
DECLARE
    hit integer;
    started boolean;
    ban platform.probe_bans%ROWTYPE;
BEGIN
    FOR hit IN 1..19 LOOP
        IF platform.probe_hit('198.51.100.1', 'browser', '/x.php') THEN
            RAISE EXCEPTION 'hit % started a ban before the limit', hit;
        END IF;
    END LOOP;
    IF EXISTS (SELECT 1 FROM platform.probe_bans WHERE client_address = '198.51.100.1') THEN
        RAISE EXCEPTION 'a ban started before the limit';
    END IF;
    started := platform.probe_hit('198.51.100.1', 'browser', '/x.php');
    IF NOT started THEN
        RAISE EXCEPTION 'the twentieth hit did not start a ban';
    END IF;
    SELECT * INTO ban FROM platform.probe_bans WHERE client_address = '198.51.100.1';
    IF ban.strikes <> 1 OR ban.reason <> 'streak' OR ban.path <> '/x.php' THEN
        RAISE EXCEPTION 'the first ban was stored wrong';
    END IF;
    IF ban.banned_until - ban.banned_at <> interval '15 minutes' THEN
        RAISE EXCEPTION 'the first ban does not last fifteen minutes';
    END IF;
    IF EXISTS (SELECT 1 FROM platform.probe_streaks WHERE client_address = '198.51.100.1') THEN
        RAISE EXCEPTION 'the hit count survived the ban';
    END IF;
    IF platform.probe_ban('198.51.100.1', 'scanner', 'browser', '/') THEN
        RAISE EXCEPTION 'a running ban started again';
    END IF;
END $$;

-- A streak ban that comes back within the reset days counts a strike and still lasts fifteen minutes.
DO $$
DECLARE
    step integer;
    ban platform.probe_bans%ROWTYPE;
BEGIN
    FOR step IN 1..3 LOOP
        UPDATE platform.probe_bans
        SET banned_at = clock_timestamp() - interval '2 days',
            banned_until = clock_timestamp() - interval '1 day'
        WHERE client_address = '198.51.100.1';
        IF NOT platform.probe_ban('198.51.100.1', 'streak', 'browser', '/x.php') THEN
            RAISE EXCEPTION 'an ended streak ban did not start again';
        END IF;
        SELECT * INTO ban FROM platform.probe_bans WHERE client_address = '198.51.100.1';
        IF ban.strikes <> step + 1 THEN
            RAISE EXCEPTION 'streak ban % has strikes %', step + 1, ban.strikes;
        END IF;
        IF ban.banned_until - ban.banned_at <> interval '15 minutes' THEN
            RAISE EXCEPTION 'streak ban % does not last fifteen minutes', step + 1;
        END IF;
    END LOOP;
    UPDATE platform.probe_bans
    SET strikes = 1,
        banned_at = clock_timestamp() - interval '2 days',
        banned_until = clock_timestamp() - interval '1 day'
    WHERE client_address = '198.51.100.1';
END $$;

-- The streak length is a rule.
DO $$
DECLARE
    ban platform.probe_bans%ROWTYPE;
BEGIN
    UPDATE platform.probe_rules SET streak_ban_minutes = 5;
    IF NOT platform.probe_ban('198.51.100.7', 'streak', 'browser', '/x.php') THEN
        RAISE EXCEPTION 'a streak ban did not start';
    END IF;
    SELECT * INTO ban FROM platform.probe_bans WHERE client_address = '198.51.100.7';
    IF ban.banned_until - ban.banned_at <> interval '5 minutes' THEN
        RAISE EXCEPTION 'the streak length rule was not used';
    END IF;
    UPDATE platform.probe_rules SET streak_ban_minutes = 15;
END $$;

-- A scanner ban that comes back within the reset days climbs the ladder. The last step repeats.
DO $$
DECLARE
    expected integer[] := ARRAY[60, 1440, 1440];
    step integer;
    ban platform.probe_bans%ROWTYPE;
BEGIN
    FOR step IN 1..3 LOOP
        UPDATE platform.probe_bans
        SET banned_at = clock_timestamp() - interval '2 days',
            banned_until = clock_timestamp() - interval '1 day'
        WHERE client_address = '198.51.100.1';
        IF NOT platform.probe_ban('198.51.100.1', 'scanner', 'browser', '/') THEN
            RAISE EXCEPTION 'an ended ban did not start again';
        END IF;
        SELECT * INTO ban FROM platform.probe_bans WHERE client_address = '198.51.100.1';
        IF ban.strikes <> step + 1 THEN
            RAISE EXCEPTION 'ban % has strikes %', step + 1, ban.strikes;
        END IF;
        IF ban.banned_until - ban.banned_at <> make_interval(mins => expected[step]) THEN
            RAISE EXCEPTION 'ban % does not last % minutes', step + 1, expected[step];
        END IF;
    END LOOP;
END $$;

-- A ban that ended more than the reset days ago starts over at the first step.
DO $$
DECLARE
    ban platform.probe_bans%ROWTYPE;
BEGIN
    UPDATE platform.probe_bans
    SET banned_at = clock_timestamp() - interval '9 days',
        banned_until = clock_timestamp() - interval '8 days'
    WHERE client_address = '198.51.100.1';
    IF EXISTS (
        SELECT 1 FROM platform.probe_bans
        WHERE client_address = '198.51.100.1' AND banned_until > clock_timestamp()
    ) THEN
        RAISE EXCEPTION 'an ended ban still reads as running';
    END IF;
    PERFORM platform.probe_ban('198.51.100.1', 'scanner', 'browser', '/');
    SELECT * INTO ban FROM platform.probe_bans WHERE client_address = '198.51.100.1';
    IF ban.strikes <> 1 OR ban.banned_until - ban.banned_at <> interval '15 minutes' THEN
        RAISE EXCEPTION 'an old ban did not start over';
    END IF;
END $$;

-- Hits outside the window are forgotten.
DO $$
BEGIN
    INSERT INTO platform.probe_streaks (client_address, streak, window_started_at, updated_at)
    VALUES ('198.51.100.2', 19, clock_timestamp() - interval '11 minutes', clock_timestamp() - interval '1 minute');
    IF platform.probe_hit('198.51.100.2', 'browser', '/x.php') THEN
        RAISE EXCEPTION 'a hit after the window started a ban';
    END IF;
    IF (SELECT streak FROM platform.probe_streaks WHERE client_address = '198.51.100.2') <> 1 THEN
        RAISE EXCEPTION 'a hit after the window did not start a new count';
    END IF;
END $$;

-- The rules decide. A lower limit bans sooner.
DO $$
BEGIN
    UPDATE platform.probe_rules SET hit_limit = 2;
    IF platform.probe_hit('198.51.100.3', 'browser', '/x.php') THEN
        RAISE EXCEPTION 'the first of two hits started a ban';
    END IF;
    IF NOT platform.probe_hit('198.51.100.3', 'browser', '/x.php') THEN
        RAISE EXCEPTION 'the rule limit was not used';
    END IF;
    UPDATE platform.probe_rules SET hit_limit = 20;
END $$;

-- Bad input and bad rules are refused.
DO $$
BEGIN
    PERFORM platform.probe_hit('not an address', 'browser', '/');
    RAISE EXCEPTION 'a bad address was counted';
EXCEPTION
    WHEN check_violation THEN
        IF SQLERRM NOT LIKE '%client address%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    PERFORM platform.probe_ban('198.51.100.4', 'because', 'browser', '/');
    RAISE EXCEPTION 'an unknown reason was stored';
EXCEPTION
    WHEN check_violation THEN
        IF SQLERRM NOT LIKE '%known reason%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    UPDATE platform.probe_rules SET hit_limit = 1;
    RAISE EXCEPTION 'a limit of one was accepted';
EXCEPTION
    WHEN check_violation THEN
        IF SQLERRM NOT LIKE '%probe_rules_hit_limit_chk%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    UPDATE platform.probe_rules SET scanner_ban_minutes = ARRAY[15, 0];
    RAISE EXCEPTION 'a zero-minute ban was accepted';
EXCEPTION
    WHEN check_violation THEN
        IF SQLERRM NOT LIKE '%probe_rules_scanner_ban_chk%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    UPDATE platform.probe_rules SET streak_ban_minutes = 0;
    RAISE EXCEPTION 'a zero-minute streak ban was accepted';
EXCEPTION
    WHEN check_violation THEN
        IF SQLERRM NOT LIKE '%probe_rules_streak_ban_chk%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    UPDATE platform.probe_rules SET streak_ban_minutes = 1441;
    RAISE EXCEPTION 'a streak ban longer than a day was accepted';
EXCEPTION
    WHEN check_violation THEN
        IF SQLERRM NOT LIKE '%probe_rules_streak_ban_chk%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    UPDATE platform.probe_rules SET summary_hour = 24;
    RAISE EXCEPTION 'a summary hour of 24 was accepted';
EXCEPTION
    WHEN check_violation THEN
        IF SQLERRM NOT LIKE '%probe_rules_summary_hour_chk%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    UPDATE platform.probe_rules SET summary_time_zone = 'Mars/Olympus';
    RAISE EXCEPTION 'a made-up time zone was accepted';
EXCEPTION
    WHEN check_violation THEN
        IF SQLERRM NOT LIKE '%IANA%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO platform.probe_rules (
        id, hit_limit, window_minutes, scanner_ban_minutes, strike_reset_days,
        streak_ban_minutes, summary_enabled, summary_hour, summary_time_zone
    )
    VALUES (false, 20, 10, ARRAY[15], 7, 15, true, 7, 'UTC');
    RAISE EXCEPTION 'a second rules row was accepted';
EXCEPTION
    WHEN check_violation THEN
        IF SQLERRM NOT LIKE '%probe_rules_one_row_chk%' THEN
            RAISE;
        END IF;
END $$;

-- The summary is due once its hour has come, and never while it is off.
DO $$
DECLARE
    local_hour integer := extract(hour FROM clock_timestamp() AT TIME ZONE 'UTC');
BEGIN
    UPDATE platform.probe_rules SET summary_hour = 0, summary_time_zone = 'UTC';
    IF platform.probe_ban_summary_day() IS DISTINCT FROM (clock_timestamp() AT TIME ZONE 'UTC')::date THEN
        RAISE EXCEPTION 'the summary at hour zero is not due today';
    END IF;
    IF local_hour < 23 THEN
        UPDATE platform.probe_rules SET summary_hour = 23;
        IF platform.probe_ban_summary_day() IS NOT NULL THEN
            RAISE EXCEPTION 'the summary was due before its hour';
        END IF;
    END IF;
    UPDATE platform.probe_rules SET summary_hour = 0, summary_enabled = false;
    IF platform.probe_ban_summary_day() IS NOT NULL THEN
        RAISE EXCEPTION 'the summary was due while it was off';
    END IF;
    UPDATE platform.probe_rules SET summary_hour = 7, summary_enabled = true;
END $$;

-- The summary lists the bans that started in the day before the summary hour, in the summary time zone.
DO $$
DECLARE
    listed text[];
    found_row record;
BEGIN
    UPDATE platform.probe_rules SET summary_hour = 7, summary_time_zone = 'UTC';
    INSERT INTO platform.probe_bans (client_address, banned_at, reason, user_agent, path, strikes, banned_until)
    VALUES
        ('198.51.100.20', '2099-01-01 06:59:59+00', 'streak', 'browser', '/early.php', 1, '2099-01-01 07:14:59+00'),
        ('198.51.100.21', '2099-01-01 07:00:00+00', 'streak', 'browser', '/first.php', 1, '2099-01-01 07:15:00+00'),
        ('198.51.100.22', '2099-01-02 06:59:59+00', 'scanner', 'sqlmap', '/', 3, '2099-01-03 06:59:59+00'),
        ('198.51.100.23', '2099-01-02 07:00:00+00', 'streak', 'browser', '/late.php', 1, '2099-01-02 07:15:00+00');
    SELECT array_agg(summary.address ORDER BY summary.banned_at) INTO listed
    FROM platform.probe_ban_summary('2099-01-02') AS summary
    WHERE summary.address LIKE '198.51.100.%';
    IF listed IS DISTINCT FROM ARRAY['198.51.100.21', '198.51.100.22'] THEN
        RAISE EXCEPTION 'the summary listed %', listed;
    END IF;
    SELECT * INTO found_row
    FROM platform.probe_ban_summary('2099-01-02') AS summary
    WHERE summary.address = '198.51.100.22';
    IF found_row.reason <> 'scanner' OR found_row.strikes <> 3 OR found_row.path <> '/'
        OR found_row.banned_at <> '2099-01-02 06:59' OR found_row.banned_until <> '2099-01-03 06:59'
        OR found_row.time_zone <> 'UTC' THEN
        RAISE EXCEPTION 'the summary row was read wrong';
    END IF;
    UPDATE platform.probe_rules SET summary_time_zone = 'America/Los_Angeles';
    SELECT array_agg(summary.address ORDER BY summary.banned_at) INTO listed
    FROM platform.probe_ban_summary('2099-01-02') AS summary
    WHERE summary.address LIKE '198.51.100.%';
    IF listed IS DISTINCT FROM ARRAY['198.51.100.22', '198.51.100.23'] THEN
        RAISE EXCEPTION 'the summary ignored the time zone: %', listed;
    END IF;
    UPDATE platform.probe_rules SET summary_time_zone = 'UTC';
END $$;

DO $$
BEGIN
    PERFORM platform.probe_ban_summary(NULL);
    RAISE EXCEPTION 'a summary with no day was read';
EXCEPTION
    WHEN invalid_parameter_value THEN
        IF SQLERRM NOT LIKE '%needs a day%' THEN
            RAISE;
        END IF;
END $$;

-- The worker reads the summary and files its letter. It cannot change the rules or a ban.
-- It signs in as stall_worker; platform mail refuses a session that only SET ROLE to it.
SET LOCAL SESSION AUTHORIZATION stall_worker;

DO $$
DECLARE
    mail_id uuid;
BEGIN
    PERFORM platform.probe_ban_summary_day();
    IF NOT EXISTS (SELECT 1 FROM platform.probe_ban_summary('2099-01-02')) THEN
        RAISE EXCEPTION 'the worker could not read the summary';
    END IF;
    mail_id := platform.file_outbound_mail(
        'platform', 'platform', 'probe-ban-digest', 'admin@example.test',
        'Perfect Consign: new probe bans for 2099-01-02', 'Body'
    );
    IF mail_id IS NULL THEN
        RAISE EXCEPTION 'the worker could not file the summary letter';
    END IF;
    INSERT INTO platform.jobs (tenant, kind, payload)
    VALUES ('platform', 'probe-ban-digest', jsonb_build_object('date', '2099-01-02'));
END $$;

DO $$
BEGIN
    UPDATE platform.probe_rules SET summary_enabled = false;
    RAISE EXCEPTION 'the worker changed the rules';
EXCEPTION
    WHEN insufficient_privilege THEN
        NULL;
END $$;

DO $$
BEGIN
    DELETE FROM platform.probe_bans WHERE client_address = '198.51.100.22';
    RAISE EXCEPTION 'the worker lifted a ban';
EXCEPTION
    WHEN insufficient_privilege THEN
        NULL;
END $$;

RESET SESSION AUTHORIZATION;

-- The web role bans only through the rules. It can still read and lift.
SET LOCAL ROLE stall_app;

DO $$
BEGIN
    INSERT INTO platform.probe_bans (client_address, reason, banned_until)
    VALUES ('198.51.100.5', 'streak', clock_timestamp() + interval '1 year');
    RAISE EXCEPTION 'the web role wrote a ban directly';
EXCEPTION
    WHEN insufficient_privilege THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE platform.probe_streaks SET streak = 1000 WHERE client_address = '198.51.100.2';
    RAISE EXCEPTION 'the web role changed a hit count directly';
EXCEPTION
    WHEN insufficient_privilege THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE platform.probe_rules SET hit_limit = 2;
    RAISE EXCEPTION 'the web role changed the rules';
EXCEPTION
    WHEN insufficient_privilege THEN
        NULL;
END $$;

DO $$
BEGIN
    IF NOT platform.probe_ban('198.51.100.6', 'scanner', 'browser', '/') THEN
        RAISE EXCEPTION 'the web role could not ban through the rules';
    END IF;
    DELETE FROM platform.probe_bans WHERE client_address = '198.51.100.6';
    IF EXISTS (SELECT 1 FROM platform.probe_bans WHERE client_address = '198.51.100.6') THEN
        RAISE EXCEPTION 'the web role could not lift a ban';
    END IF;
END $$;

DO $$
BEGIN
    PERFORM platform.probe_ban_summary('2099-01-02');
    RAISE EXCEPTION 'the web role read the ban summary';
EXCEPTION
    WHEN insufficient_privilege THEN
        NULL;
END $$;

RESET ROLE;

ROLLBACK;
