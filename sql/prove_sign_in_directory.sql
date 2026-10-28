-- Sign-in emails are unique across the platform. Staff and owner rows sync the directory.
-- Empty email does not claim a row. Wipe clears the directory. The script rolls back.

BEGIN;

DO $$
DECLARE
    mall_id uuid;
    owner_id uuid := '01900000-0000-7000-8000-00000000d001';
    staff_id uuid := '01900000-0000-7000-8000-00000000d010';
BEGIN
    SELECT id INTO mall_id FROM platform.tenants WHERE schema_name = current_schema();
    IF mall_id IS NULL THEN
        RAISE EXCEPTION 'the tenant for this schema is missing';
    END IF;

    DELETE FROM platform.sign_in_directory WHERE tenant_id = mall_id;
    DELETE FROM platform.owners WHERE tenant_id = mall_id;

    INSERT INTO platform.owners (id, tenant_id, username, password_hash, email)
    VALUES (owner_id, mall_id, 'dirowner', 'hash', 'DirOwner@Example.com');

    IF NOT EXISTS (
        SELECT 1
        FROM platform.sign_in_directory
        WHERE account_kind = 'owner'
          AND account_id = owner_id
          AND email = 'dirowner@example.com'
          AND username = 'dirowner'
    ) THEN
        RAISE EXCEPTION 'owner email did not enter the directory';
    END IF;

    BEGIN
        UPDATE platform.owners SET email = 'DirOwner@Example.com' WHERE id = owner_id;
        -- same address, different case — still one row
        NULL;
    END;

    INSERT INTO staff (id, username, display_name, password_hash, role, email)
    VALUES (
        staff_id,
        'dircashier',
        'Directory Cashier',
        repeat('a', 20),
        'cashier',
        'cashier-dir@example.com'
    );

    IF NOT EXISTS (
        SELECT 1
        FROM platform.sign_in_directory
        WHERE account_kind = 'staff'
          AND account_id = staff_id
          AND email = 'cashier-dir@example.com'
    ) THEN
        RAISE EXCEPTION 'staff email did not enter the directory';
    END IF;

    BEGIN
        INSERT INTO staff (username, display_name, password_hash, role, email)
        VALUES (
            'dircollide',
            'Collide',
            repeat('a', 20),
            'cashier',
            'DirOwner@Example.com'
        );
        RAISE EXCEPTION 'a staff email that collides with an owner was accepted';
    EXCEPTION
        WHEN unique_violation THEN
            NULL;
    END;

    BEGIN
        INSERT INTO staff (username, display_name, password_hash, role, email)
        VALUES (
            'dircollide2',
            'Collide Two',
            repeat('a', 20),
            'books',
            'cashier-dir@example.com'
        );
        RAISE EXCEPTION 'a duplicate staff email was accepted';
    EXCEPTION
        WHEN unique_violation THEN
            NULL;
    END;

    UPDATE staff SET email = NULL WHERE id = staff_id;
    IF EXISTS (
        SELECT 1 FROM platform.sign_in_directory WHERE account_kind = 'staff' AND account_id = staff_id
    ) THEN
        RAISE EXCEPTION 'clearing staff email left a directory row';
    END IF;

    UPDATE staff SET email = 'cashier-dir@example.com' WHERE id = staff_id;
    DELETE FROM staff WHERE id = staff_id;
    IF EXISTS (
        SELECT 1 FROM platform.sign_in_directory WHERE account_kind = 'staff' AND account_id = staff_id
    ) THEN
        RAISE EXCEPTION 'deleting staff left a directory row';
    END IF;

    UPDATE platform.owners SET email = NULL WHERE id = owner_id;
    IF EXISTS (
        SELECT 1 FROM platform.sign_in_directory WHERE account_kind = 'owner' AND account_id = owner_id
    ) THEN
        RAISE EXCEPTION 'clearing owner email left a directory row';
    END IF;

    UPDATE platform.owners SET email = 'dirowner@example.com' WHERE id = owner_id;

    INSERT INTO platform.email_sign_in_failures (email, client_address)
    VALUES ('dirowner@example.com', '127.0.0.1');
    INSERT INTO platform.email_sign_in_failures (email, client_address)
    VALUES ('dirowner@example.com', '10.0.0.2');
    IF (
        SELECT count(*)
        FROM platform.email_sign_in_failures
        WHERE email = 'dirowner@example.com'
          AND failed_at > clock_timestamp() - interval '15 minutes'
    ) < 2 THEN
        RAISE EXCEPTION 'email sign-in failures were not stored across addresses';
    END IF;

    INSERT INTO platform.email_sign_in_failures (email, client_address, failed_at)
    VALUES (
        'stale-fail@example.com',
        '127.0.0.1',
        clock_timestamp() - interval '20 minutes'
    );
    DELETE FROM platform.email_sign_in_failures
    WHERE failed_at <= clock_timestamp() - interval '15 minutes';
    IF EXISTS (
        SELECT 1 FROM platform.email_sign_in_failures WHERE email = 'stale-fail@example.com'
    ) THEN
        RAISE EXCEPTION 'stale email sign-in failures were not pruned';
    END IF;
    IF (
        SELECT count(*)
        FROM platform.email_sign_in_failures
        WHERE email = 'dirowner@example.com'
          AND failed_at > clock_timestamp() - interval '15 minutes'
    ) < 2 THEN
        RAISE EXCEPTION 'retention prune removed fresh email sign-in failures';
    END IF;
    IF NOT EXISTS (
        SELECT 1
        FROM pg_indexes
        WHERE schemaname = 'platform'
          AND indexname = 'email_sign_in_failures_stale'
    ) THEN
        RAISE EXCEPTION 'email_sign_in_failures_stale index is missing';
    END IF;
    IF NOT EXISTS (
        SELECT 1
        FROM pg_indexes
        WHERE schemaname = 'platform'
          AND indexname = 'email_sign_in_failures_address'
    ) THEN
        RAISE EXCEPTION 'email_sign_in_failures_address index is missing';
    END IF;

    SET LOCAL ROLE stall_app;
    IF (
        SELECT email FROM platform.sign_in_directory WHERE account_id = owner_id AND account_kind = 'owner'
    ) IS DISTINCT FROM 'dirowner@example.com' THEN
        RAISE EXCEPTION 'stall_app could not read the directory';
    END IF;
    BEGIN
        INSERT INTO platform.sign_in_directory (email, tenant_id, account_kind, account_id, username)
        VALUES (
            'app-insert@example.com',
            mall_id,
            'staff',
            '01900000-0000-7000-8000-00000000d099',
            'appinsert'
        );
        RAISE EXCEPTION 'stall_app inserted a directory row directly';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    INSERT INTO platform.email_sign_in_failures (email, client_address)
    VALUES ('locked@example.com', '10.0.0.1');
    DELETE FROM platform.email_sign_in_failures WHERE email = 'locked@example.com';
    RESET ROLE;
END;
$$;

ROLLBACK;
