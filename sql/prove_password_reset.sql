-- Password reset tokens. used_at can be set once. The body cannot change.
-- Postgres does not deliver ALTER EVENT TRIGGER or DROP EVENT TRIGGER to this guard.
-- Those triggers are ENABLE ALWAYS, so replica mode still refuses a rewrite.
-- The script rolls back.

BEGIN;

DO $$
DECLARE
    mall_id uuid;
    token_id uuid := '01900000-0000-7000-8000-00000000e001';
    second_id uuid := '01900000-0000-7000-8000-00000000e002';
    kept text;
BEGIN
    SELECT id INTO mall_id FROM platform.tenants WHERE schema_name = current_schema();
    IF mall_id IS NULL THEN
        RAISE EXCEPTION 'the tenant for this schema is missing';
    END IF;

    INSERT INTO platform.owners (id, tenant_id, username, password_hash)
    VALUES ('01900000-0000-7000-8000-00000000e020', mall_id, 'resetowner', 'hash')
    ON CONFLICT (tenant_id) DO NOTHING;

    BEGIN
        UPDATE platform.owners SET email = 'not-an-email' WHERE tenant_id = mall_id;
        RAISE EXCEPTION 'a bad owner email was accepted';
    EXCEPTION
        WHEN check_violation THEN
            NULL;
    END;

    BEGIN
        INSERT INTO staff (username, display_name, password_hash, role, email)
        VALUES ('resetcashier', 'Reset Cashier', repeat('a', 20), 'cashier', 'not-an-email');
        RAISE EXCEPTION 'a bad staff email was accepted';
    EXCEPTION
        WHEN check_violation THEN
            NULL;
    END;

    INSERT INTO staff (username, display_name, password_hash, role, email)
    VALUES ('resetcashier', 'Reset Cashier', repeat('a', 20), 'cashier', 'cashier@example.com');

    BEGIN
        UPDATE staff SET email = 'still-bad' WHERE username = 'resetcashier';
        RAISE EXCEPTION 'a bad staff email was accepted';
    EXCEPTION
        WHEN check_violation THEN
            NULL;
    END;

    BEGIN
        UPDATE staff SET credentials_version = 0 WHERE username = 'resetcashier';
        RAISE EXCEPTION 'a zero staff session generation was accepted';
    EXCEPTION
        WHEN check_violation THEN
            NULL;
    END;

    INSERT INTO platform.password_resets (
        id, tenant_id, account_kind, account_id, token_hash, expires_at
    ) VALUES (
        token_id,
        mall_id,
        'staff',
        '01900000-0000-7000-8000-00000000e010',
        repeat('ab', 32),
        clock_timestamp() + interval '30 minutes'
    );

    BEGIN
        UPDATE platform.password_resets SET token_hash = repeat('cd', 32) WHERE id = token_id;
        RAISE EXCEPTION 'a password reset body was rewritten';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%a password reset cannot be rewritten%' THEN
                RAISE;
            END IF;
    END;

    UPDATE platform.password_resets SET used_at = clock_timestamp() WHERE id = token_id;
    IF (SELECT used_at FROM platform.password_resets WHERE id = token_id) IS NULL THEN
        RAISE EXCEPTION 'a password reset was not marked used';
    END IF;

    BEGIN
        UPDATE platform.password_resets SET used_at = clock_timestamp() WHERE id = token_id;
        RAISE EXCEPTION 'a used password reset was reused';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%a password reset was already used%' THEN
                RAISE;
            END IF;
    END;

    BEGIN
        DELETE FROM platform.password_resets WHERE id = token_id;
        RAISE EXCEPTION 'a password reset was deleted';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%a password reset cannot be removed%' THEN
                RAISE;
            END IF;
    END;

    BEGIN
        TRUNCATE platform.password_resets;
        RAISE EXCEPTION 'password resets were truncated';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%password resets cannot be truncated%' THEN
                RAISE;
            END IF;
    END;

    INSERT INTO platform.password_resets (
        id, tenant_id, account_kind, account_id, token_hash, expires_at
    ) VALUES (
        second_id,
        mall_id,
        'owner',
        '01900000-0000-7000-8000-00000000e011',
        repeat('ef', 32),
        clock_timestamp() + interval '30 minutes'
    );
    kept := (SELECT token_hash FROM platform.password_resets WHERE id = second_id);

    SET LOCAL ROLE stall_app;
    BEGIN
        UPDATE platform.password_resets SET token_hash = repeat('11', 32) WHERE id = second_id;
        RAISE EXCEPTION 'stall_app rewrote a password reset';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    UPDATE platform.password_resets SET used_at = clock_timestamp() WHERE id = second_id;
    BEGIN
        UPDATE platform.password_resets SET used_at = clock_timestamp() WHERE id = second_id;
        RAISE EXCEPTION 'stall_app reused a password reset';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%a password reset was already used%' THEN
                RAISE;
            END IF;
    END;
    RESET ROLE;

    IF (SELECT token_hash FROM platform.password_resets WHERE id = second_id) IS DISTINCT FROM kept THEN
        RAISE EXCEPTION 'a refused reset update changed the token';
    END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE platform.password_resets DISABLE TRIGGER password_resets_append_only;
    RAISE EXCEPTION 'disabling the reset trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE platform.password_resets ENABLE REPLICA TRIGGER password_resets_append_only;
    RAISE EXCEPTION 'replica-only reset trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE platform.password_resets ENABLE TRIGGER password_resets_append_only;
    RAISE EXCEPTION 'origin-only reset trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER password_resets_append_only ON platform.password_resets;
    RAISE EXCEPTION 'dropping the reset trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION platform.reject_password_reset_mutation()
    RETURNS trigger
    LANGUAGE plpgsql
    AS $body$ BEGIN RETURN NEW; END; $body$;
    RAISE EXCEPTION 'replacing the reset guard was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
            RAISE;
        END IF;
END $$;

DO $$
DECLARE
    mall_id uuid;
    before_hash text;
BEGIN
    SELECT id INTO mall_id FROM platform.tenants WHERE schema_name = current_schema();
    INSERT INTO platform.password_resets (
        id, tenant_id, account_kind, account_id, token_hash, expires_at
    ) VALUES (
        '01900000-0000-7000-8000-00000000e003',
        mall_id,
        'staff',
        '01900000-0000-7000-8000-00000000e012',
        repeat('99', 32),
        clock_timestamp() + interval '30 minutes'
    );
    before_hash := repeat('99', 32);
    SET LOCAL session_replication_role = replica;
    BEGIN
        UPDATE platform.password_resets
        SET token_hash = repeat('88', 32)
        WHERE id = '01900000-0000-7000-8000-00000000e003';
        RAISE EXCEPTION 'replica mode rewrote a password reset';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            NULL;
    END;
    BEGIN
        ALTER TABLE platform.password_resets DISABLE TRIGGER password_resets_append_only;
        RAISE EXCEPTION 'replica mode disabled the reset trigger';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            NULL;
    END;
    SET LOCAL session_replication_role = origin;
    IF (SELECT token_hash FROM platform.password_resets WHERE id = '01900000-0000-7000-8000-00000000e003')
        IS DISTINCT FROM before_hash THEN
        RAISE EXCEPTION 'a replica session changed a password reset';
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
