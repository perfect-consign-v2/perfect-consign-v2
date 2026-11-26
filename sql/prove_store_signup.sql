-- Public store signup: the rules, the browser check, each answer a request can
-- get, the signup state machine, who may touch what, and the daily clean-up.
-- Also the older owner email verification table, which stays append-only.
-- One transaction, then ROLLBACK.

BEGIN;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM platform.settings WHERE key = 'store_signup_enabled') THEN
        RAISE EXCEPTION 'store_signup_enabled setting is missing';
    END IF;
    IF EXISTS (SELECT 1 FROM platform.settings WHERE key = 'store_signup_require_email_verification') THEN
        RAISE EXCEPTION 'email confirmation can still be switched off';
    END IF;
    IF (SELECT count(*) FROM platform.signup_rules) <> 1 THEN
        RAISE EXCEPTION 'signup rules are not one row';
    END IF;
    IF (SELECT row(pending_hours, per_address_per_hour, per_email_per_hour, pending_cap_per_hour, proof_bits, proof_minutes, kept_days)::text
        FROM platform.signup_rules) <> '(24,5,3,200,18,30,30)' THEN
        RAISE EXCEPTION 'signup rules do not start at the agreed values';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO platform.signup_rules (id, pending_hours, per_address_per_hour, per_email_per_hour,
        pending_cap_per_hour, proof_bits, proof_minutes, kept_days)
    VALUES (false, 24, 5, 3, 200, 18, 30, 30);
    RAISE EXCEPTION 'a second rules row was accepted';
EXCEPTION
    WHEN check_violation THEN
        NULL;
END $$;

DO $$
DECLARE
    bad text;
BEGIN
    FOREACH bad IN ARRAY ARRAY[
        'pending_hours = 0', 'pending_hours = 169', 'per_address_per_hour = 0',
        'per_email_per_hour = 101', 'pending_cap_per_hour = 0', 'proof_bits = 7', 'proof_bits = 29',
        'proof_minutes = 0', 'kept_days = 366'
    ] LOOP
        BEGIN
            EXECUTE 'UPDATE platform.signup_rules SET ' || bad;
            RAISE EXCEPTION 'signup rule % was accepted', bad;
        EXCEPTION
            WHEN check_violation THEN
                NULL;
        END;
    END LOOP;
END $$;

-- The browser check: sha256(seed ':' nonce) must start with the asked-for zero bits.
DO $$
BEGIN
    IF NOT platform.signup_proof_ok('0123456789abcdef0123456789abcdef', '261544', 18) THEN
        RAISE EXCEPTION 'a right answer was refused';
    END IF;
    IF platform.signup_proof_ok('0123456789abcdef0123456789abcdef', '261543', 18) THEN
        RAISE EXCEPTION 'a wrong answer was accepted';
    END IF;
    IF platform.signup_proof_ok('0123456789abcdef0123456789abcdef', '-1', 8)
        OR platform.signup_proof_ok('0123456789abcdef0123456789abcdef', '', 8)
        OR platform.signup_proof_ok('0123456789abcdef0123456789abcdef', '1e3', 8)
        OR platform.signup_proof_ok('not-a-seed', '1', 8)
        OR platform.signup_proof_ok('0123456789abcdef0123456789abcdef', '261544', 29) THEN
        RAISE EXCEPTION 'a malformed answer was accepted';
    END IF;
END $$;

-- Make the check cheap for this proof and turn signup on.
UPDATE platform.signup_rules SET proof_bits = 8;
UPDATE platform.settings SET value = 'true'::jsonb WHERE key = 'store_signup_enabled';

-- Ask for a store the way the form does: fetch a challenge, solve it, send.
CREATE FUNCTION pg_temp.ask(p_email text, p_name text, p_address text, p_token text) RETURNS text
    LANGUAGE plpgsql AS $fn$
DECLARE
    challenge record;
    nonce integer := 0;
BEGIN
    SELECT * INTO challenge FROM platform.signup_challenge(p_address);
    WHILE NOT platform.signup_proof_ok(challenge.seed, nonce::text, challenge.bits) LOOP
        nonce := nonce + 1;
    END LOOP;
    RETURN platform.signup_request(p_email, p_name, 'proof-hash', p_token, p_address, challenge.seed, nonce::text);
END;
$fn$;

DO $$
DECLARE
    challenge record;
    outcome text;
    tenants_before bigint := (SELECT count(*) FROM platform.tenants);
    owners_before bigint := (SELECT count(*) FROM platform.owners);
    directory_before bigint := (SELECT count(*) FROM platform.sign_in_directory);
BEGIN
    outcome := pg_temp.ask(' New.Owner@Example.TEST ', '  Proof Store  ', '198.51.100.10', repeat('1', 64));
    IF outcome <> 'pending' THEN
        RAISE EXCEPTION 'a new signup answered %', outcome;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM platform.store_signups
        WHERE email = 'new.owner@example.test' AND store_name = 'Proof Store'
          AND status = 'pending' AND expires_at > clock_timestamp() + interval '23 hours'
    ) THEN
        RAISE EXCEPTION 'the pending row was not written as asked';
    END IF;
    IF (SELECT count(*) FROM platform.tenants) <> tenants_before
        OR (SELECT count(*) FROM platform.owners) <> owners_before
        OR (SELECT count(*) FROM platform.sign_in_directory) <> directory_before THEN
        RAISE EXCEPTION 'a signup built something before its link was opened';
    END IF;

    -- A challenge works once.
    SELECT * INTO challenge FROM platform.signup_challenge('198.51.100.11');
    UPDATE platform.signup_challenges SET spent_at = clock_timestamp() WHERE seed = challenge.seed;
    IF platform.signup_request('spent@example.test', 'Spent', 'h', repeat('2', 64), '198.51.100.11', challenge.seed, '0') <> 'challenge' THEN
        RAISE EXCEPTION 'a spent challenge was accepted';
    END IF;
    IF platform.signup_request('spent@example.test', 'Spent', 'h', repeat('2', 64), '198.51.100.11', repeat('f', 32), '0') <> 'challenge' THEN
        RAISE EXCEPTION 'an unknown challenge was accepted';
    END IF;

    -- A second request for the same email replaces the first link.
    outcome := pg_temp.ask('new.owner@example.test', 'Proof Store', '198.51.100.12', repeat('3', 64));
    IF outcome <> 'pending' THEN
        RAISE EXCEPTION 'a second request answered %', outcome;
    END IF;
    IF (SELECT status FROM platform.store_signups WHERE token_hash = repeat('1', 64)) <> 'expired'
        OR (SELECT password_hash FROM platform.store_signups WHERE token_hash = repeat('1', 64)) IS NOT NULL THEN
        RAISE EXCEPTION 'the first link still works after a second request';
    END IF;

    -- An email that already signs in gets the "you already have a store" letter.
    INSERT INTO platform.sign_in_directory (email, tenant_id, account_kind, account_id, username)
    SELECT 'taken@example.test', id, 'owner', '01900000-0000-7000-8000-00000000f0a1', 'taken'
    FROM platform.tenants WHERE schema_name = current_schema();
    IF pg_temp.ask('Taken@example.test', 'Squat', '198.51.100.13', repeat('4', 64)) <> 'known' THEN
        RAISE EXCEPTION 'a known email was not answered known';
    END IF;
    IF EXISTS (SELECT 1 FROM platform.store_signups WHERE email = 'taken@example.test') THEN
        RAISE EXCEPTION 'a known email got a pending row';
    END IF;
END $$;

-- Per-address, per-email, and platform-wide limits.
DO $$
DECLARE
    i integer;
    outcome text;
BEGIN
    FOR i IN 1..5 LOOP
        PERFORM pg_temp.ask('busy' || i || '@example.test', 'Busy', '198.51.100.20', encode(sha256(convert_to('busy' || i, 'UTF8')), 'hex'));
    END LOOP;
    outcome := pg_temp.ask('busy6@example.test', 'Busy', '198.51.100.20', repeat('a', 64));
    IF outcome <> 'limited' THEN
        RAISE EXCEPTION 'a sixth request from one address answered %', outcome;
    END IF;

    FOR i IN 1..3 LOOP
        outcome := pg_temp.ask('often@example.test', 'Often', '198.51.100.3' || i, encode(sha256(convert_to('often' || i, 'UTF8')), 'hex'));
    END LOOP;
    outcome := pg_temp.ask('often@example.test', 'Often', '198.51.100.39', repeat('e', 64));
    IF outcome <> 'quiet' THEN
        RAISE EXCEPTION 'a fourth request for one email answered %', outcome;
    END IF;

    UPDATE platform.signup_rules SET pending_cap_per_hour = 1;
    outcome := pg_temp.ask('capped@example.test', 'Capped', '198.51.100.40', repeat('9', 64));
    IF outcome <> 'busy' THEN
        RAISE EXCEPTION 'a request past the platform cap answered %', outcome;
    END IF;
    UPDATE platform.signup_rules SET pending_cap_per_hour = 200;

    UPDATE platform.settings SET value = 'false'::jsonb WHERE key = 'store_signup_enabled';
    IF platform.signup_request('off@example.test', 'Off', 'h', repeat('8', 64), '198.51.100.41', repeat('0', 32), '0') <> 'closed' THEN
        RAISE EXCEPTION 'a request while signup is off was not refused';
    END IF;
    UPDATE platform.settings SET value = 'true'::jsonb WHERE key = 'store_signup_enabled';
END $$;

-- An address with as many open challenges as it may send gets its newest again.
DO $$
DECLARE
    first_seed text;
    again text;
BEGIN
    FOR i IN 1..5 LOOP
        SELECT seed INTO first_seed FROM platform.signup_challenge('198.51.100.50');
    END LOOP;
    SELECT seed INTO again FROM platform.signup_challenge('198.51.100.50');
    IF again <> first_seed THEN
        RAISE EXCEPTION 'a sixth challenge was issued to one address';
    END IF;
    IF (SELECT count(*) FROM platform.signup_challenges WHERE client_address = '198.51.100.50') <> 5 THEN
        RAISE EXCEPTION 'open challenges per address are not capped';
    END IF;
END $$;

-- The link: pressing the button confirms and files the build job; an old link is refused.
DO $$
DECLARE
    signup_id uuid := (SELECT id FROM platform.store_signups WHERE token_hash = repeat('3', 64));
BEGIN
    IF (SELECT status FROM platform.signup_state(repeat('3', 64))) <> 'pending' THEN
        RAISE EXCEPTION 'a fresh link does not read pending';
    END IF;
    IF platform.signup_verify(repeat('3', 64)) <> 'verified' THEN
        RAISE EXCEPTION 'a fresh link was not confirmed';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM platform.jobs
        WHERE tenant = 'platform' AND kind = 'store-provision' AND payload ->> 'signup' = signup_id::text
    ) THEN
        RAISE EXCEPTION 'confirming did not file the build job';
    END IF;
    IF platform.signup_verify(repeat('3', 64)) <> 'verified' THEN
        RAISE EXCEPTION 'a second press changed the signup';
    END IF;
    IF (SELECT count(*) FROM platform.jobs WHERE payload ->> 'signup' = signup_id::text) <> 1 THEN
        RAISE EXCEPTION 'a second press filed a second job';
    END IF;
    IF platform.signup_verify(repeat('0', 64)) IS NOT NULL THEN
        RAISE EXCEPTION 'an unknown link was answered';
    END IF;
    IF (SELECT status FROM platform.signup_state(repeat('1', 64))) <> 'expired' THEN
        RAISE EXCEPTION 'a replaced link does not read expired';
    END IF;

    -- A request while that store is being built sends nothing new.
    IF pg_temp.ask('new.owner@example.test', 'Again', '198.51.100.60', repeat('5', 64)) <> 'quiet' THEN
        RAISE EXCEPTION 'a request during the build was not answered quiet';
    END IF;
END $$;

DO $$
DECLARE
    late_id uuid;
BEGIN
    PERFORM pg_temp.ask('late@example.test', 'Late', '198.51.100.61', repeat('6', 64));
    SELECT id INTO late_id FROM platform.store_signups WHERE token_hash = repeat('6', 64);
    ALTER TABLE platform.store_signups DISABLE TRIGGER store_signups_guard;
    UPDATE platform.store_signups SET expires_at = clock_timestamp() - interval '1 minute' WHERE id = late_id;
    ALTER TABLE platform.store_signups ENABLE ALWAYS TRIGGER store_signups_guard;
    IF (SELECT status FROM platform.signup_state(repeat('6', 64))) <> 'expired' THEN
        RAISE EXCEPTION 'a link past its deadline does not read expired';
    END IF;
    IF platform.signup_verify(repeat('6', 64)) <> 'expired' THEN
        RAISE EXCEPTION 'a link past its deadline was confirmed';
    END IF;
    IF (SELECT password_hash FROM platform.store_signups WHERE id = late_id) IS NOT NULL THEN
        RAISE EXCEPTION 'an expired signup kept its password hash';
    END IF;
END $$;

-- The state machine and the row's shape.
DO $$
DECLARE
    signup_id uuid := (SELECT id FROM platform.store_signups WHERE token_hash = repeat('3', 64));
    pending_id uuid := (SELECT id FROM platform.store_signups WHERE email = 'busy1@example.test');
BEGIN
    BEGIN
        UPDATE platform.store_signups SET email = 'other@example.test' WHERE id = pending_id;
        RAISE EXCEPTION 'a signup changed its email';
    EXCEPTION
        WHEN check_violation THEN
            IF SQLERRM NOT LIKE '%keeps who asked%' THEN
                RAISE;
            END IF;
    END;
    BEGIN
        UPDATE platform.store_signups
        SET status = 'ready', password_hash = NULL, tenant_id = gen_random_uuid(), slug = 'x', provisioned_at = clock_timestamp()
        WHERE id = pending_id;
        RAISE EXCEPTION 'a pending signup jumped to ready';
    EXCEPTION
        WHEN check_violation THEN
            IF SQLERRM NOT LIKE '%cannot go from pending to ready%' THEN
                RAISE;
            END IF;
    END;
    BEGIN
        UPDATE platform.store_signups SET token_hash = repeat('7', 64), status = 'provisioning' WHERE id = signup_id;
        RAISE EXCEPTION 'a signup changed its link while moving on';
    EXCEPTION
        WHEN check_violation THEN
            IF SQLERRM NOT LIKE '%keeps its link%' THEN
                RAISE;
            END IF;
    END;
    UPDATE platform.store_signups SET status = 'provisioning' WHERE id = signup_id;
    BEGIN
        UPDATE platform.store_signups
        SET status = 'ready', tenant_id = gen_random_uuid(), slug = 'kept-hash', provisioned_at = clock_timestamp()
        WHERE id = signup_id;
        RAISE EXCEPTION 'a ready signup kept its password hash';
    EXCEPTION
        WHEN check_violation THEN
            IF SQLERRM NOT LIKE '%store_signups_shape_chk%' THEN
                RAISE;
            END IF;
    END;
    UPDATE platform.store_signups SET status = 'failed', last_error = 'proof' WHERE id = signup_id;
    UPDATE platform.store_signups SET status = 'provisioning', last_error = NULL WHERE id = signup_id;
    UPDATE platform.store_signups
    SET status = 'ready', password_hash = NULL, tenant_id = gen_random_uuid(), slug = 'proof-store',
        provisioned_at = clock_timestamp()
    WHERE id = signup_id;
    UPDATE platform.store_signups SET ready_mail_id = gen_random_uuid() WHERE id = signup_id;
    BEGIN
        UPDATE platform.store_signups SET ready_mail_id = gen_random_uuid() WHERE id = signup_id;
        RAISE EXCEPTION 'the ready letter was filed twice';
    EXCEPTION
        WHEN check_violation THEN
            IF SQLERRM NOT LIKE '%does not change in place%' THEN
                RAISE;
            END IF;
    END;
    BEGIN
        UPDATE platform.store_signups SET status = 'discarded' WHERE id = signup_id;
        RAISE EXCEPTION 'a ready signup was discarded';
    EXCEPTION
        WHEN check_violation THEN
            NULL;
    END;
    BEGIN
        INSERT INTO platform.store_signups (email, store_name, password_hash, token_hash, client_address, status, expires_at)
        VALUES ('busy2@example.test', 'Twice', 'h', repeat('c', 64), '198.51.100.70', 'pending', clock_timestamp() + interval '1 hour');
        RAISE EXCEPTION 'a second open signup for one email was accepted';
    EXCEPTION
        WHEN unique_violation THEN
            NULL;
    END;
    BEGIN
        INSERT INTO platform.store_signups (email, store_name, password_hash, token_hash, client_address, status, expires_at)
        VALUES ('Upper@Example.test', 'Upper', 'h', repeat('d', 64), '198.51.100.70', 'pending', clock_timestamp() + interval '1 hour');
        RAISE EXCEPTION 'an email that is not lowercase was stored';
    EXCEPTION
        WHEN check_violation THEN
            NULL;
    END;
END $$;

-- An operator: create through the queue, resend, and discard.
DO $$
DECLARE
    operator uuid;
    created uuid;
    resent text;
    waiting uuid := (SELECT id FROM platform.store_signups WHERE email = 'busy3@example.test');
BEGIN
    INSERT INTO platform.admins (id, email, password_hash)
    VALUES ('01900000-0000-7000-8000-00000000f0b1', 'proof-signup-operator@example.test', 'hash')
    RETURNING id INTO operator;
    created := platform.signup_admin_create(' Made@Example.test ', 'Made Store', 'hash', repeat('b', 64), operator);
    IF (SELECT status FROM platform.store_signups WHERE id = created) <> 'verified'
        OR (SELECT admin_id FROM platform.store_signups WHERE id = created) <> operator
        OR (SELECT client_address FROM platform.store_signups WHERE id = created) IS NOT NULL THEN
        RAISE EXCEPTION 'an operator store did not start confirmed';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM platform.jobs WHERE payload ->> 'signup' = created::text) THEN
        RAISE EXCEPTION 'an operator store did not file its build job';
    END IF;
    BEGIN
        PERFORM platform.signup_admin_create('taken@example.test', 'Squat', 'hash', repeat('f', 63) || '0', operator);
        RAISE EXCEPTION 'an operator store was made for a known email';
    EXCEPTION
        WHEN unique_violation THEN
            IF SQLERRM NOT LIKE 'That email already signs in to a store.' THEN
                RAISE;
            END IF;
    END;
    BEGIN
        PERFORM platform.signup_admin_create('made@example.test', 'Twice', 'hash', repeat('f', 62) || '00', operator);
        RAISE EXCEPTION 'a second operator store was made for one email';
    EXCEPTION
        WHEN unique_violation THEN
            IF SQLERRM NOT LIKE 'A store for that email is already being set up.' THEN
                RAISE;
            END IF;
    END;
    BEGIN
        PERFORM platform.signup_admin_create('nobody@example.test', 'Nobody', 'hash', repeat('f', 61) || '000', gen_random_uuid());
        RAISE EXCEPTION 'a store was made without an operator';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;

    resent := platform.signup_resend(waiting, repeat('ab', 32));
    IF resent <> 'busy3@example.test' OR (SELECT token_hash FROM platform.store_signups WHERE id = waiting) <> repeat('ab', 32) THEN
        RAISE EXCEPTION 'a waiting signup did not get a new link';
    END IF;
    BEGIN
        PERFORM platform.signup_resend(created, repeat('ac', 32));
        RAISE EXCEPTION 'a confirmed signup got a new link';
    EXCEPTION
        WHEN check_violation THEN
            NULL;
    END;
    resent := platform.signup_discard(waiting);
    IF resent <> 'busy3@example.test'
        OR (SELECT password_hash FROM platform.store_signups WHERE id = waiting) IS NOT NULL THEN
        RAISE EXCEPTION 'a discarded signup kept its password hash';
    END IF;
    BEGIN
        PERFORM platform.signup_discard(waiting);
        RAISE EXCEPTION 'a signup was discarded twice';
    EXCEPTION
        WHEN check_violation THEN
            NULL;
    END;
END $$;

-- Who may touch what.
SET LOCAL ROLE stall_app;

DO $$
DECLARE
    probe text;
BEGIN
    PERFORM email, status FROM platform.store_signups LIMIT 1;
    PERFORM count(*) FROM platform.signup_rules;
    PERFORM platform.signup_state(repeat('3', 64));
    FOREACH probe IN ARRAY ARRAY[
        'SELECT password_hash FROM platform.store_signups',
        'SELECT token_hash FROM platform.store_signups',
        'SELECT seed FROM platform.signup_challenges',
        'SELECT 1 FROM platform.signup_attempts',
        $q$INSERT INTO platform.store_signups (email, store_name, password_hash, token_hash, client_address, status, expires_at)
           VALUES ('web@example.test', 'Web', 'h', repeat('9', 64), '198.51.100.80', 'pending', clock_timestamp())$q$,
        $q$UPDATE platform.store_signups SET status = 'verified'$q$,
        'DELETE FROM platform.store_signups',
        'UPDATE platform.signup_rules SET proof_bits = 8',
        'SELECT platform.signup_reap()'
    ] LOOP
        BEGIN
            EXECUTE probe;
            RAISE EXCEPTION 'the web role ran: %', probe;
        EXCEPTION
            WHEN insufficient_privilege THEN
                NULL;
        END;
    END LOOP;
END $$;

RESET ROLE;
SET LOCAL ROLE stall_worker;

DO $$
DECLARE
    probe text;
BEGIN
    PERFORM id, status, ready_mail_id FROM platform.store_signups LIMIT 1;
    FOREACH probe IN ARRAY ARRAY[
        'SELECT password_hash FROM platform.store_signups',
        $q$UPDATE platform.store_signups SET slug = 'worker'$q$,
        'DELETE FROM platform.store_signups',
        'CREATE SCHEMA worker_made'
    ] LOOP
        BEGIN
            EXECUTE probe;
            RAISE EXCEPTION 'the worker ran: %', probe;
        EXCEPTION
            WHEN insufficient_privilege THEN
                NULL;
        END;
    END LOOP;
END $$;

-- The worker builds stores only after it becomes stall_provisioner.
SET LOCAL ROLE stall_provisioner;

DO $$
BEGIN
    IF NOT has_database_privilege(current_database(), 'CREATE') THEN
        RAISE EXCEPTION 'the builder cannot create a store schema';
    END IF;
    PERFORM password_hash FROM platform.store_signups LIMIT 1;
    BEGIN
        DELETE FROM platform.store_signups;
        RAISE EXCEPTION 'the builder deleted a signup';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        PERFORM platform.signup_reap();
        RAISE EXCEPTION 'the builder ran the clean-up';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
END $$;

RESET ROLE;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_roles
        WHERE rolname = 'stall_provisioner'
          AND rolcreaterole AND NOT rolsuper AND NOT rolcanlogin AND NOT rolcreatedb AND NOT rolinherit
    ) THEN
        RAISE EXCEPTION 'stall_provisioner is not a create-role-only role that cannot sign in';
    END IF;
    IF NOT pg_has_role('stall_worker', 'stall_provisioner', 'SET')
        OR pg_has_role('stall_worker', 'stall_provisioner', 'USAGE') THEN
        RAISE EXCEPTION 'the worker must switch to the builder, not inherit it';
    END IF;
    IF pg_has_role('stall_app', 'stall_provisioner', 'SET') THEN
        RAISE EXCEPTION 'the web role can become the builder';
    END IF;
END $$;

-- A store's poster role must hold every platform grant.
DO $$
BEGIN
    CREATE ROLE proof_bare_poster NOLOGIN;
    BEGIN
        PERFORM platform.assert_poster_grants('proof_bare_poster');
        RAISE EXCEPTION 'a poster without grants passed';
    EXCEPTION
        WHEN insufficient_privilege THEN
            IF SQLERRM NOT LIKE '%is missing USAGE ON SCHEMA platform%' THEN
                RAISE;
            END IF;
    END;
    BEGIN
        PERFORM platform.assert_poster_grants('proof_no_such_role');
        RAISE EXCEPTION 'a missing poster passed';
    EXCEPTION
        WHEN undefined_object THEN
            NULL;
    END;
    PERFORM platform.assert_poster_grants('poster_' || substr(current_schema(), 8));
END $$;

-- The daily clean-up deletes unconfirmed signups past their deadline and counts them.
DO $$
DECLARE
    gone integer;
    before_count integer := coalesce((SELECT expired FROM platform.signup_reaped
        WHERE day = (clock_timestamp() AT TIME ZONE 'UTC')::date), 0);
BEGIN
    gone := platform.signup_reap();
    IF gone < 2 THEN
        RAISE EXCEPTION 'the clean-up removed % unconfirmed signups', gone;
    END IF;
    IF EXISTS (SELECT 1 FROM platform.store_signups WHERE status = 'expired') THEN
        RAISE EXCEPTION 'an expired signup was kept';
    END IF;
    IF (SELECT expired FROM platform.signup_reaped WHERE day = (clock_timestamp() AT TIME ZONE 'UTC')::date) <> before_count + gone THEN
        RAISE EXCEPTION 'the clean-up count is wrong';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM platform.store_signups WHERE status = 'ready') THEN
        RAISE EXCEPTION 'a ready signup was removed before its kept days';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM platform.store_signups WHERE status = 'pending') THEN
        RAISE EXCEPTION 'a pending signup inside its deadline was removed';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO platform.settings (key, value) VALUES ('', 'true'::jsonb);
    RAISE EXCEPTION 'empty settings key was accepted';
EXCEPTION
    WHEN check_violation THEN
        NULL;
END $$;

INSERT INTO platform.admin_audit (
    admin_id, admin_email, action, outcome, detail, request_id, client_address
) VALUES
    (NULL, 'owner@example.test', 'store.signup', 'ok', '{"display_name":"Proof Mall"}'::jsonb, 'proof-signup', ''),
    (NULL, 'owner@example.test', 'store.email_verified', 'ok', '{}'::jsonb, 'proof-signup-verify', ''),
    (NULL, 'owner@example.test', 'store.ready', 'ok', '{}'::jsonb, 'worker', '');

DO $$
BEGIN
    INSERT INTO platform.admin_audit (admin_id, admin_email, action, outcome, detail, request_id, client_address)
    VALUES (NULL, 'owner@example.test', 'store.signup_discard', 'ok', '{}'::jsonb, 'proof-discard', '');
    RAISE EXCEPTION 'a discard was recorded without an operator';
EXCEPTION
    WHEN check_violation THEN
        NULL;
END $$;

DO $$
DECLARE
    admin_row record;
    mall_id uuid;
    owner_id uuid;
    token_id uuid := '01900000-0000-7000-8000-00000000f001';
BEGIN
    SELECT id, email INTO admin_row FROM platform.admins ORDER BY created_at LIMIT 1;
    IF admin_row.id IS NOT NULL THEN
        INSERT INTO platform.admin_audit (
            admin_id, admin_email, action, outcome, detail, request_id, client_address
        ) VALUES (
            admin_row.id, admin_row.email, 'store.signup_toggle', 'ok',
            '{"enabled":true}'::jsonb,
            'proof-signup-toggle', ''
        );
        INSERT INTO platform.admin_audit (
            admin_id, admin_email, action, outcome, detail, request_id, client_address
        ) VALUES (
            admin_row.id, admin_row.email, 'store.admin_create', 'ok',
            '{"display_name":"Admin Mall"}'::jsonb,
            'proof-admin-create', ''
        );
    END IF;

    SELECT id INTO mall_id FROM platform.tenants WHERE schema_name = current_schema();
    IF mall_id IS NULL THEN
        RAISE EXCEPTION 'the tenant for this schema is missing';
    END IF;

    INSERT INTO platform.owners (id, tenant_id, username, password_hash, email, email_verified_at)
    VALUES (
        '01900000-0000-7000-8000-00000000f020',
        mall_id,
        'signupowner',
        'hash',
        'signup-owner@example.test',
        NULL
    )
    ON CONFLICT (tenant_id, username) DO UPDATE
        SET email = EXCLUDED.email,
            email_verified_at = NULL
    RETURNING id INTO owner_id;

    IF owner_id IS NULL THEN
        SELECT id INTO owner_id
        FROM platform.owners
        WHERE tenant_id = mall_id AND username = 'signupowner';
    END IF;

    IF (SELECT email_verified_at FROM platform.owners WHERE id = owner_id) IS NOT NULL THEN
        RAISE EXCEPTION 'unverified owner was marked verified';
    END IF;

    UPDATE platform.owners
    SET email_verified_at = clock_timestamp()
    WHERE id = owner_id;

    IF (SELECT email_verified_at FROM platform.owners WHERE id = owner_id) IS NULL THEN
        RAISE EXCEPTION 'owner email_verified_at was not set';
    END IF;

    INSERT INTO platform.owner_email_verifications (
        id, owner_id, token_hash, expires_at
    ) VALUES (
        token_id,
        owner_id,
        repeat('ab', 32),
        clock_timestamp() + interval '30 minutes'
    );

    BEGIN
        UPDATE platform.owner_email_verifications SET token_hash = repeat('cd', 32) WHERE id = token_id;
        RAISE EXCEPTION 'an email verification body was rewritten';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%an email verification cannot be rewritten%' THEN
                RAISE;
            END IF;
    END;

    UPDATE platform.owner_email_verifications SET used_at = clock_timestamp() WHERE id = token_id;
    IF (SELECT used_at FROM platform.owner_email_verifications WHERE id = token_id) IS NULL THEN
        RAISE EXCEPTION 'an email verification was not marked used';
    END IF;

    BEGIN
        UPDATE platform.owner_email_verifications SET used_at = clock_timestamp() WHERE id = token_id;
        RAISE EXCEPTION 'a used email verification was reused';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%an email verification was already used%' THEN
                RAISE;
            END IF;
    END;

    BEGIN
        DELETE FROM platform.owner_email_verifications WHERE id = token_id;
        RAISE EXCEPTION 'an email verification was deleted';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%an email verification cannot be removed%' THEN
                RAISE;
            END IF;
    END;

    BEGIN
        TRUNCATE platform.owner_email_verifications;
        RAISE EXCEPTION 'email verifications were truncated';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%email verifications cannot be truncated%' THEN
                RAISE;
            END IF;
    END;

    BEGIN
        ALTER TABLE platform.owner_email_verifications DISABLE TRIGGER owner_email_verifications_append_only;
        RAISE EXCEPTION 'email verification append-only trigger was disabled';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
                RAISE;
            END IF;
    END;

    BEGIN
        DROP TRIGGER owner_email_verifications_append_only ON platform.owner_email_verifications;
        RAISE EXCEPTION 'email verification append-only trigger was dropped';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
                RAISE;
            END IF;
    END;

    BEGIN
        CREATE OR REPLACE FUNCTION platform.reject_owner_email_verification_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $fn$ BEGIN RETURN NEW; END; $fn$;
        RAISE EXCEPTION 'email verification guard function was replaced';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
                RAISE;
            END IF;
    END;

    BEGIN
        PERFORM set_config('session_replication_role', 'replica', true);
        UPDATE platform.owner_email_verifications
        SET token_hash = repeat('ef', 32)
        WHERE id = token_id;
        PERFORM set_config('session_replication_role', 'origin', true);
        RAISE EXCEPTION 'replica session rewrote an email verification';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            PERFORM set_config('session_replication_role', 'origin', true);
            IF SQLERRM NOT LIKE '%an email verification%' AND SQLERRM NOT LIKE '%already used%' THEN
                RAISE;
            END IF;
        WHEN insufficient_privilege THEN
            PERFORM set_config('session_replication_role', 'origin', true);
    END;
END $$;

ROLLBACK;
