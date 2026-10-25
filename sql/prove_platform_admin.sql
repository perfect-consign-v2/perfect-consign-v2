-- Platform admin password resets and admin audit are append-only.
-- The script rolls back.

BEGIN;

DO $$
DECLARE
    admin_id uuid := '01900000-0000-7000-8000-00000000a001';
    token_id uuid := '01900000-0000-7000-8000-00000000a010';
    audit_id uuid := '01900000-0000-7000-8000-00000000a020';
    kept text;
BEGIN
    DELETE FROM platform.admin_sign_in_failures WHERE email = 'prove-admin@example.com';
    DELETE FROM platform.admin_password_reset_requests WHERE email = 'prove-admin@example.com';
    IF EXISTS (SELECT 1 FROM platform.admins WHERE email = 'prove-admin@example.com') THEN
        SELECT id INTO admin_id FROM platform.admins WHERE email = 'prove-admin@example.com';
    ELSE
        INSERT INTO platform.admins (id, email, password_hash)
        VALUES (admin_id, 'prove-admin@example.com', 'hash');
    END IF;

    BEGIN
        INSERT INTO platform.admins (id, email, password_hash)
        VALUES ('01900000-0000-7000-8000-00000000a002', 'not-an-email', 'hash');
        RAISE EXCEPTION 'a bad admin email was accepted';
    EXCEPTION
        WHEN check_violation THEN
            NULL;
    END;

    INSERT INTO platform.admin_password_resets (
        id, account_id, token_hash, expires_at
    ) VALUES (
        token_id,
        admin_id,
        repeat('ab', 32),
        clock_timestamp() + interval '30 minutes'
    );

    BEGIN
        UPDATE platform.admin_password_resets SET token_hash = repeat('cd', 32) WHERE id = token_id;
        RAISE EXCEPTION 'an admin password reset body was rewritten';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%a password reset cannot be rewritten%' THEN
                RAISE;
            END IF;
    END;

    UPDATE platform.admin_password_resets SET used_at = clock_timestamp() WHERE id = token_id;
    IF (SELECT used_at FROM platform.admin_password_resets WHERE id = token_id) IS NULL THEN
        RAISE EXCEPTION 'an admin password reset was not marked used';
    END IF;

    BEGIN
        UPDATE platform.admin_password_resets SET used_at = clock_timestamp() WHERE id = token_id;
        RAISE EXCEPTION 'a used admin password reset was reused';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%a password reset was already used%' THEN
                RAISE;
            END IF;
    END;

    BEGIN
        DELETE FROM platform.admin_password_resets WHERE id = token_id;
        RAISE EXCEPTION 'an admin password reset was deleted';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%a password reset cannot be removed%' THEN
                RAISE;
            END IF;
    END;

    BEGIN
        TRUNCATE platform.admin_password_resets;
        RAISE EXCEPTION 'admin password resets were truncated';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%password resets cannot be truncated%' THEN
                RAISE;
            END IF;
    END;

    INSERT INTO platform.admin_audit (
        id, admin_id, admin_email, action, outcome, request_id, client_address
    ) VALUES (
        audit_id,
        admin_id,
        'prove-admin@example.com',
        'admin.sign_in',
        'ok',
        'prove-request',
        '127.0.0.1'
    );

    BEGIN
        UPDATE platform.admin_audit SET action = 'admin.password_change' WHERE id = audit_id;
        RAISE EXCEPTION 'an admin audit row was rewritten';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%an admin audit row cannot be updated or deleted%' THEN
                RAISE;
            END IF;
    END;

    BEGIN
        DELETE FROM platform.admin_audit WHERE id = audit_id;
        RAISE EXCEPTION 'an admin audit row was deleted';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%an admin audit row cannot be updated or deleted%' THEN
                RAISE;
            END IF;
    END;

    BEGIN
        TRUNCATE platform.admin_audit;
        RAISE EXCEPTION 'admin audit was truncated';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%admin audit cannot be truncated%' THEN
                RAISE;
            END IF;
    END;
END $$;

DO $$
BEGIN
    ALTER TABLE platform.admin_password_resets DISABLE TRIGGER admin_password_resets_append_only;
    RAISE EXCEPTION 'disabling the admin reset trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE platform.admin_audit DISABLE TRIGGER admin_audit_append_only;
    RAISE EXCEPTION 'disabling the admin audit trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER admin_password_resets_append_only ON platform.admin_password_resets;
    RAISE EXCEPTION 'dropping the admin reset trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER admin_audit_append_only ON platform.admin_audit;
    RAISE EXCEPTION 'dropping the admin audit trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION platform.reject_admin_password_reset_mutation()
    RETURNS trigger
    LANGUAGE plpgsql
    AS $body$ BEGIN RETURN NEW; END; $body$;
    RAISE EXCEPTION 'replacing the admin reset guard was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION platform.reject_admin_audit_mutation()
    RETURNS trigger
    LANGUAGE plpgsql
    AS $body$ BEGIN RETURN NEW; END; $body$;
    RAISE EXCEPTION 'replacing the admin audit guard was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
            RAISE;
        END IF;
END $$;

DO $$
DECLARE
    before_hash text;
    admin_id uuid;
BEGIN
    SELECT id INTO admin_id FROM platform.admins WHERE email = 'prove-admin@example.com';
    INSERT INTO platform.admin_password_resets (
        id, account_id, token_hash, expires_at
    ) VALUES (
        '01900000-0000-7000-8000-00000000a011',
        admin_id,
        repeat('99', 32),
        clock_timestamp() + interval '30 minutes'
    );
    before_hash := repeat('99', 32);
    SET LOCAL session_replication_role = replica;
    BEGIN
        UPDATE platform.admin_password_resets
        SET token_hash = repeat('88', 32)
        WHERE id = '01900000-0000-7000-8000-00000000a011';
        RAISE EXCEPTION 'replica mode rewrote an admin password reset';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            NULL;
    END;
    BEGIN
        ALTER TABLE platform.admin_password_resets DISABLE TRIGGER admin_password_resets_append_only;
        RAISE EXCEPTION 'replica mode disabled the admin reset trigger';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            NULL;
    END;
    SET LOCAL session_replication_role = origin;
    IF (SELECT token_hash FROM platform.admin_password_resets WHERE id = '01900000-0000-7000-8000-00000000a011')
        IS DISTINCT FROM before_hash THEN
        RAISE EXCEPTION 'a replica session changed an admin password reset';
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

ROLLBACK;
