-- Owners per mall. Username unique per mall. Email unique globally.
-- stall_app reads and updates password/email/credentials_version by id.
-- It cannot INSERT/DELETE directly; add/remove go through SECURITY DEFINER.
-- The script rolls back.

BEGIN;

DO $$
DECLARE
    mall_id uuid;
    first_id uuid := '01900000-0000-7000-8000-00000000c001';
    other_id uuid := '01900000-0000-7000-8000-00000000c002';
    partner_id uuid;
    remaining_id uuid;
BEGIN
    SELECT id INTO mall_id FROM platform.tenants WHERE schema_name = current_schema();
    IF mall_id IS NULL THEN
        RAISE EXCEPTION 'the tenant for this schema is missing';
    END IF;

    DELETE FROM platform.owners WHERE tenant_id = mall_id;

    INSERT INTO platform.owners (id, tenant_id, username, password_hash)
    VALUES (first_id, mall_id, 'owner', 'hash');

    INSERT INTO platform.owners (id, tenant_id, username, password_hash)
    VALUES (other_id, mall_id, 'other', 'hash');

    BEGIN
        INSERT INTO platform.owners (id, tenant_id, username, password_hash)
        VALUES ('01900000-0000-7000-8000-00000000c003', mall_id, 'owner', 'hash');
        RAISE EXCEPTION 'a duplicate mall username was accepted';
    EXCEPTION
        WHEN unique_violation THEN
            NULL;
    END;

    BEGIN
        INSERT INTO platform.owners (id, tenant_id, username, password_hash)
        VALUES ('01900000-0000-7000-8000-00000000c004', mall_id, ' ', 'hash');
        RAISE EXCEPTION 'a blank owner username was accepted';
    EXCEPTION
        WHEN check_violation THEN
            NULL;
    END;

    SET LOCAL ROLE stall_app;
    IF (SELECT count(*) FROM platform.owners WHERE tenant_id = mall_id) <> 2 THEN
        RAISE EXCEPTION 'stall_app could not read the owners';
    END IF;
    BEGIN
        INSERT INTO platform.owners (id, tenant_id, username, password_hash)
        VALUES ('01900000-0000-7000-8000-00000000c005', mall_id, 'app', 'hash');
        RAISE EXCEPTION 'stall_app inserted an owner';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        DELETE FROM platform.owners WHERE id = first_id;
        RAISE EXCEPTION 'stall_app deleted an owner';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        UPDATE platform.owners SET username = 'changed' WHERE id = first_id;
        RAISE EXCEPTION 'stall_app updated an owner username';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        UPDATE platform.owners SET id = '01900000-0000-7000-8000-00000000c006' WHERE id = first_id;
        RAISE EXCEPTION 'stall_app updated an owner id';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        UPDATE platform.owners SET tenant_id = '01900000-0000-7000-8000-00000000c007' WHERE id = first_id;
        RAISE EXCEPTION 'stall_app updated an owner tenant';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        UPDATE platform.owners SET email = 'not-an-email' WHERE id = first_id;
        RAISE EXCEPTION 'a bad owner email was accepted';
    EXCEPTION
        WHEN check_violation THEN
            NULL;
    END;
    BEGIN
        UPDATE platform.owners SET credentials_version = 0 WHERE id = first_id;
        RAISE EXCEPTION 'a zero session generation was accepted';
    EXCEPTION
        WHEN check_violation THEN
            NULL;
    END;
    UPDATE platform.owners
    SET password_hash = 'hash-next', email = 'owner@example.com', credentials_version = 2
    WHERE id = first_id;
    IF (SELECT password_hash FROM platform.owners WHERE id = first_id) IS DISTINCT FROM 'hash-next'
        OR (SELECT email FROM platform.owners WHERE id = first_id) IS DISTINCT FROM 'owner@example.com'
        OR (SELECT credentials_version FROM platform.owners WHERE id = first_id) IS DISTINCT FROM 2 THEN
        RAISE EXCEPTION 'stall_app could not update the owner password';
    END IF;
    BEGIN
        UPDATE platform.owners
        SET username = 'changed', password_hash = 'nope'
        WHERE id = first_id;
        RAISE EXCEPTION 'stall_app changed the owner username';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    IF (SELECT password_hash FROM platform.owners WHERE id = first_id) IS DISTINCT FROM 'hash-next' THEN
        RAISE EXCEPTION 'a refused owner update changed the password';
    END IF;

    UPDATE platform.owners
    SET can_sign_in = false, credentials_version = credentials_version + 1
    WHERE id = first_id;
    IF (SELECT can_sign_in FROM platform.owners WHERE id = first_id) IS DISTINCT FROM false THEN
        RAISE EXCEPTION 'stall_app could not suspend the owner';
    END IF;
    UPDATE platform.owners SET can_sign_in = true WHERE id = first_id;
    IF (SELECT can_sign_in FROM platform.owners WHERE id = first_id) IS DISTINCT FROM true THEN
        RAISE EXCEPTION 'stall_app could not restore the owner';
    END IF;

    SELECT platform.add_mall_owner(mall_id, 'partner', 'hash-partner', 'partner@example.com')
    INTO partner_id;
    IF partner_id IS NULL THEN
        RAISE EXCEPTION 'add_mall_owner did not return an id';
    END IF;
    IF (SELECT count(*) FROM platform.owners WHERE tenant_id = mall_id) <> 3 THEN
        RAISE EXCEPTION 'add_mall_owner did not plant a co-owner';
    END IF;
    IF (SELECT email_verified_at FROM platform.owners WHERE id = partner_id) IS NULL THEN
        RAISE EXCEPTION 'add_mall_owner left email unverified';
    END IF;

    BEGIN
        PERFORM platform.add_mall_owner(mall_id, 'partner', 'hash-dup', 'other@example.com');
        RAISE EXCEPTION 'add_mall_owner accepted a duplicate username';
    EXCEPTION
        WHEN unique_violation THEN
            NULL;
    END;

    PERFORM platform.remove_mall_owner(mall_id, first_id);
    PERFORM platform.remove_mall_owner(mall_id, other_id);
    IF (SELECT count(*) FROM platform.owners WHERE tenant_id = mall_id) <> 1 THEN
        RAISE EXCEPTION 'remove left the wrong owner count';
    END IF;

    SELECT id INTO remaining_id FROM platform.owners WHERE tenant_id = mall_id;
    BEGIN
        PERFORM platform.remove_mall_owner(mall_id, remaining_id);
        RAISE EXCEPTION 'remove_mall_owner removed the sole owner';
    EXCEPTION
        WHEN raise_exception THEN
            IF SQLERRM IS DISTINCT FROM 'Keep at least one owner on the mall.' THEN
                RAISE;
            END IF;
    END;
END $$;

ROLLBACK;
