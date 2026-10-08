-- Owner backup log. stall_app records through the function. The time is not chosen.
-- The script rolls back.

BEGIN;

DO $$
DECLARE
    mall_id uuid;
    first_id uuid := '01900000-0000-7000-8000-00000000d001';
    second_id uuid := '01900000-0000-7000-8000-00000000d002';
    recorded timestamptz;
BEGIN
    SELECT id INTO mall_id FROM platform.tenants WHERE schema_name = current_schema();
    IF mall_id IS NULL THEN
        RAISE EXCEPTION 'the tenant for this schema is missing';
    END IF;

    SET LOCAL ROLE stall_app;

    BEGIN
        INSERT INTO platform.owner_backups (id, tenant_id, byte_size, sha256)
        VALUES (first_id, mall_id, 1, repeat('a', 64));
        RAISE EXCEPTION 'stall_app inserted a backup';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;

    BEGIN
        PERFORM platform.record_owner_backup(mall_id, first_id, -1, repeat('a', 64));
        RAISE EXCEPTION 'a negative backup size was accepted';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            NULL;
    END;

    PERFORM platform.record_owner_backup(mall_id, first_id, 12, repeat('ab', 32));
    PERFORM platform.record_owner_backup(mall_id, second_id, 13, repeat('cd', 32));
    recorded := platform.latest_owner_backup(mall_id);
    IF recorded IS NULL THEN
        RAISE EXCEPTION 'the backup function did not record a row';
    END IF;
    IF recorded < clock_timestamp() - interval '1 minute'
        OR recorded > clock_timestamp() + interval '1 minute' THEN
        RAISE EXCEPTION 'the backup time was not assigned by the database';
    END IF;

    RESET ROLE;

    IF (SELECT count(*) FROM platform.owner_backups WHERE tenant_id = mall_id) <> 2 THEN
        RAISE EXCEPTION 'a second backup was not kept';
    END IF;

    BEGIN
        INSERT INTO platform.owner_backups (id, tenant_id, completed_at, byte_size, sha256)
        VALUES (
            '01900000-0000-7000-8000-00000000d003',
            mall_id,
            '2020-01-01 00:00:00+00',
            1,
            repeat('e', 64)
        );
        RAISE EXCEPTION 'a chosen backup time was accepted';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM NOT LIKE '%a backup time is assigned by the database%' THEN
                RAISE;
            END IF;
    END;

    BEGIN
        UPDATE platform.owner_backups SET byte_size = 0 WHERE id = first_id;
        RAISE EXCEPTION 'a backup row was updated';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            NULL;
    END;

    BEGIN
        DELETE FROM platform.owner_backups WHERE id = first_id;
        RAISE EXCEPTION 'a backup row was deleted';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            NULL;
    END;

    BEGIN
        TRUNCATE platform.owner_backups;
        RAISE EXCEPTION 'truncate was accepted';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            NULL;
    END;
END $$;

DO $$
BEGIN
    ALTER TABLE platform.owner_backups DISABLE TRIGGER owner_backups_append_only;
    RAISE EXCEPTION 'disabling the backup trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

ROLLBACK;
