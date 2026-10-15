-- One owner per mall. stall_app can read the row.
-- It can change the password, recovery email, and session generation.
-- It cannot change the username, tenant, or id.
-- The script rolls back.

BEGIN;

DO $$
DECLARE
    mall_id uuid;
BEGIN
    SELECT id INTO mall_id FROM platform.tenants WHERE schema_name = current_schema();
    IF mall_id IS NULL THEN
        RAISE EXCEPTION 'the tenant for this schema is missing';
    END IF;

    DELETE FROM platform.owners WHERE tenant_id = mall_id;

    INSERT INTO platform.owners (id, tenant_id, username, password_hash)
    VALUES ('01900000-0000-7000-8000-00000000c001', mall_id, 'owner', 'hash');

    BEGIN
        INSERT INTO platform.owners (id, tenant_id, username, password_hash)
        VALUES ('01900000-0000-7000-8000-00000000c002', mall_id, 'other', 'hash');
        RAISE EXCEPTION 'a second owner was accepted';
    EXCEPTION
        WHEN unique_violation THEN
            NULL;
    END;

    BEGIN
        INSERT INTO platform.owners (id, tenant_id, username, password_hash)
        VALUES ('01900000-0000-7000-8000-00000000c003', mall_id, ' ', 'hash');
        RAISE EXCEPTION 'a blank owner username was accepted';
    EXCEPTION
        WHEN check_violation THEN
            NULL;
    END;

    SET LOCAL ROLE stall_app;
    IF (SELECT count(*) FROM platform.owners WHERE tenant_id = mall_id) <> 1 THEN
        RAISE EXCEPTION 'stall_app could not read the owner';
    END IF;
    BEGIN
        INSERT INTO platform.owners (id, tenant_id, username, password_hash)
        VALUES ('01900000-0000-7000-8000-00000000c004', mall_id, 'app', 'hash');
        RAISE EXCEPTION 'stall_app inserted an owner';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        UPDATE platform.owners SET username = 'changed' WHERE tenant_id = mall_id;
        RAISE EXCEPTION 'stall_app updated an owner username';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        UPDATE platform.owners SET id = '01900000-0000-7000-8000-00000000c005' WHERE tenant_id = mall_id;
        RAISE EXCEPTION 'stall_app updated an owner id';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        UPDATE platform.owners SET tenant_id = '01900000-0000-7000-8000-00000000c006' WHERE tenant_id = mall_id;
        RAISE EXCEPTION 'stall_app updated an owner tenant';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        UPDATE platform.owners SET email = 'not-an-email' WHERE tenant_id = mall_id;
        RAISE EXCEPTION 'a bad owner email was accepted';
    EXCEPTION
        WHEN check_violation THEN
            NULL;
    END;
    BEGIN
        UPDATE platform.owners SET credentials_version = 0 WHERE tenant_id = mall_id;
        RAISE EXCEPTION 'a zero session generation was accepted';
    EXCEPTION
        WHEN check_violation THEN
            NULL;
    END;
    UPDATE platform.owners
    SET password_hash = 'hash-next', email = 'owner@example.com', credentials_version = 2
    WHERE tenant_id = mall_id;
    IF (SELECT password_hash FROM platform.owners WHERE tenant_id = mall_id) IS DISTINCT FROM 'hash-next'
        OR (SELECT email FROM platform.owners WHERE tenant_id = mall_id) IS DISTINCT FROM 'owner@example.com'
        OR (SELECT credentials_version FROM platform.owners WHERE tenant_id = mall_id) IS DISTINCT FROM 2 THEN
        RAISE EXCEPTION 'stall_app could not update the owner password';
    END IF;
    BEGIN
        UPDATE platform.owners
        SET username = 'changed', password_hash = 'nope'
        WHERE tenant_id = mall_id;
        RAISE EXCEPTION 'stall_app changed the owner username';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    IF (SELECT password_hash FROM platform.owners WHERE tenant_id = mall_id) IS DISTINCT FROM 'hash-next' THEN
        RAISE EXCEPTION 'a refused owner update changed the password';
    END IF;
END $$;

ROLLBACK;
