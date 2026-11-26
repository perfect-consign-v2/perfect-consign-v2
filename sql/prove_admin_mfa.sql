-- Platform admin authenticator guards. The script rolls back.
--
-- DROP EVENT TRIGGER is not in platform.reject_admin_append_only_guard's tag
-- list (DROP FUNCTION, DROP TRIGGER only). Postgres does not deliver that
-- command to the guard. sql/000_platform.sql creates the authenticator tables
-- and the guard before it creates the event triggers. This proof leaves the
-- event triggers installed and attacks the authenticator triggers underneath
-- them.

BEGIN;

INSERT INTO platform.admins (id, email, password_hash)
VALUES (
    '01900000-0000-7000-8000-00000000b001',
    'prove-mfa@example.com',
    'hash'
);

DO $$
BEGIN
    UPDATE platform.admins
    SET mfa_secret = 'plain-secret',
        mfa_confirmed_at = clock_timestamp(),
        mfa_last_used_step = 1
    WHERE id = '01900000-0000-7000-8000-00000000b001';
    RAISE EXCEPTION 'an unsealed authenticator secret was accepted';
EXCEPTION
    WHEN check_violation THEN
        IF SQLERRM NOT LIKE '%admins_mfa_chk%' THEN
            RAISE;
        END IF;
END $$;

UPDATE platform.admins
SET mfa_secret = 'v1:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
    mfa_confirmed_at = clock_timestamp(),
    mfa_last_used_step = 10
WHERE id = '01900000-0000-7000-8000-00000000b001';

DO $$
BEGIN
    SET LOCAL ROLE stall_app;
    BEGIN
        UPDATE platform.admins
        SET mfa_secret = NULL,
            mfa_confirmed_at = NULL,
            mfa_last_used_step = NULL
        WHERE id = '01900000-0000-7000-8000-00000000b001';
        RAISE EXCEPTION 'stall_app cleared the authenticator';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%an admin authenticator cannot be rewritten%' THEN
                RAISE;
            END IF;
    END;
    RESET ROLE;
END $$;

DO $$
BEGIN
    UPDATE platform.admins
    SET mfa_last_used_step = 9
    WHERE id = '01900000-0000-7000-8000-00000000b001';
    RAISE EXCEPTION 'an authenticator step was rewound';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%an admin authenticator cannot be rewritten%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    UPDATE platform.admins
    SET mfa_secret = 'v1:BBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBBB='
    WHERE id = '01900000-0000-7000-8000-00000000b001';
    RAISE EXCEPTION 'an authenticator secret was replaced';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%an admin authenticator cannot be rewritten%' THEN
            RAISE;
        END IF;
END $$;

UPDATE platform.admins
SET mfa_last_used_step = 11
WHERE id = '01900000-0000-7000-8000-00000000b001';

INSERT INTO platform.admin_mfa_recovery_codes (id, admin_id, code_hash)
VALUES (
    '01900000-0000-7000-8000-00000000b010',
    '01900000-0000-7000-8000-00000000b001',
    repeat('ab', 32)
);

DO $$
BEGIN
    UPDATE platform.admin_mfa_recovery_codes
    SET code_hash = repeat('cd', 32)
    WHERE id = '01900000-0000-7000-8000-00000000b010';
    RAISE EXCEPTION 'a recovery code hash was rewritten';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a recovery code cannot be rewritten%' THEN
            RAISE;
        END IF;
END $$;

UPDATE platform.admin_mfa_recovery_codes
SET used_at = clock_timestamp()
WHERE id = '01900000-0000-7000-8000-00000000b010';

DO $$
BEGIN
    UPDATE platform.admin_mfa_recovery_codes
    SET used_at = clock_timestamp()
    WHERE id = '01900000-0000-7000-8000-00000000b010';
    RAISE EXCEPTION 'a used recovery code was rewritten';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a recovery code was already used%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DELETE FROM platform.admin_mfa_recovery_codes
    WHERE id = '01900000-0000-7000-8000-00000000b010';
    RAISE EXCEPTION 'a recovery code was deleted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a recovery code cannot be removed%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    TRUNCATE platform.admin_mfa_recovery_codes;
    RAISE EXCEPTION 'recovery codes were truncated';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%recovery codes cannot be truncated%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE platform.admin_mfa_recovery_codes DISABLE TRIGGER admin_mfa_recovery_append_only;
    RAISE EXCEPTION 'disabling the recovery code trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE platform.admins DISABLE TRIGGER admin_mfa_transition;
    RAISE EXCEPTION 'disabling the authenticator trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE platform.admins ENABLE REPLICA TRIGGER admin_mfa_transition;
    RAISE EXCEPTION 'a replica-only authenticator trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER admin_mfa_recovery_append_only ON platform.admin_mfa_recovery_codes;
    RAISE EXCEPTION 'dropping the recovery code trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER admin_mfa_transition ON platform.admins;
    RAISE EXCEPTION 'dropping the authenticator trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION platform.reject_admin_mfa_transition()
    RETURNS trigger
    LANGUAGE plpgsql
    AS $body$ BEGIN RETURN NEW; END; $body$;
    RAISE EXCEPTION 'replacing the authenticator guard was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION platform.reject_admin_mfa_recovery_mutation()
    RETURNS trigger
    LANGUAGE plpgsql
    AS $body$ BEGIN RETURN NEW; END; $body$;
    RAISE EXCEPTION 'replacing the recovery code guard was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
            RAISE;
        END IF;
END $$;

DO $$
DECLARE
    before_hash character(64);
BEGIN
    before_hash := repeat('ab', 32);
    SET LOCAL session_replication_role = replica;
    BEGIN
        UPDATE platform.admin_mfa_recovery_codes
        SET code_hash = repeat('cd', 32)
        WHERE id = '01900000-0000-7000-8000-00000000b010';
        RAISE EXCEPTION 'replica mode rewrote a recovery code';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            NULL;
    END;
    BEGIN
        UPDATE platform.admins
        SET mfa_last_used_step = 1
        WHERE id = '01900000-0000-7000-8000-00000000b001';
        RAISE EXCEPTION 'replica mode rewound an authenticator step';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            NULL;
    END;
    BEGIN
        ALTER TABLE platform.admins DISABLE TRIGGER admin_mfa_transition;
        RAISE EXCEPTION 'replica mode disabled the authenticator trigger';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            NULL;
    END;
    SET LOCAL session_replication_role = origin;
    IF (SELECT code_hash FROM platform.admin_mfa_recovery_codes WHERE id = '01900000-0000-7000-8000-00000000b010')
        IS DISTINCT FROM before_hash THEN
        RAISE EXCEPTION 'a replica session changed a recovery code';
    END IF;
    IF (SELECT mfa_last_used_step FROM platform.admins WHERE id = '01900000-0000-7000-8000-00000000b001')
        IS DISTINCT FROM 11 THEN
        RAISE EXCEPTION 'a replica session changed an authenticator step';
    END IF;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE stall_app;
    BEGIN
        SET LOCAL session_replication_role = replica;
        RAISE EXCEPTION 'stall_app replica mode was accepted';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        SET LOCAL event_triggers = false;
        RAISE EXCEPTION 'stall_app turned event triggers off';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    RESET ROLE;
END $$;

UPDATE platform.admins
SET mfa_secret = NULL,
    mfa_confirmed_at = NULL,
    mfa_last_used_step = NULL
WHERE id = '01900000-0000-7000-8000-00000000b001';

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM platform.admins
        WHERE id = '01900000-0000-7000-8000-00000000b001'
          AND (
              mfa_secret IS NOT NULL
              OR mfa_confirmed_at IS NOT NULL
              OR mfa_last_used_step IS NOT NULL
          )
    ) THEN
        RAISE EXCEPTION 'the table owner could not clear the authenticator';
    END IF;
END $$;

ROLLBACK;
