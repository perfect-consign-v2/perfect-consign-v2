-- One owner per mall. stall_app can read the row and cannot write it.
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
        RAISE EXCEPTION 'stall_app updated an owner';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
END $$;

ROLLBACK;
