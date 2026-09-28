-- The append-only guard refuses disabling, replica-only, origin, and dropping those triggers.
-- Postgres does not deliver ALTER EVENT TRIGGER or DROP EVENT TRIGGER to this guard.
-- Those triggers are ENABLE ALWAYS, so replica mode still refuses update, delete, and truncate.
-- The script rolls back.

BEGIN;

DO $$
DECLARE
    guard_count integer;
BEGIN
    SELECT count(*) INTO guard_count
    FROM pg_event_trigger
    WHERE evtname IN (
        'platform_reject_append_only_trigger_change',
        'platform_reject_append_only_trigger_drop'
    )
      AND evtenabled = 'A';
    IF guard_count <> 2 THEN
        RAISE EXCEPTION 'the append-only guard is not always enabled';
    END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE brands ENABLE REPLICA TRIGGER brands_no_truncate;
    RAISE EXCEPTION 'replica-only brand trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE items ENABLE REPLICA TRIGGER items_no_truncate;
    RAISE EXCEPTION 'replica-only item trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE wait_list ENABLE REPLICA TRIGGER wait_list_no_truncate;
    RAISE EXCEPTION 'replica-only wait list trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE journals ENABLE REPLICA TRIGGER journals_append_only;
    RAISE EXCEPTION 'replica-only journal trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE platform.owner_backups DISABLE TRIGGER USER;
    RAISE EXCEPTION 'disabling backup triggers was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER brands_no_truncate ON brands;
    RAISE EXCEPTION 'dropping the brand trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER items_no_truncate ON items;
    RAISE EXCEPTION 'dropping the item trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER wait_list_no_truncate ON wait_list;
    RAISE EXCEPTION 'dropping the wait list trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER journals_append_only ON journals;
    RAISE EXCEPTION 'dropping a journal trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER journals_no_truncate ON journals;
    RAISE EXCEPTION 'dropping the journal truncate trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER owner_backups_append_only ON platform.owner_backups;
    RAISE EXCEPTION 'dropping the backup trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    SET LOCAL session_replication_role = replica;
    BEGIN
        ALTER TABLE journals DISABLE TRIGGER journals_append_only;
        RAISE EXCEPTION 'replica disable was accepted';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
                RAISE;
            END IF;
    END;
    BEGIN
        DROP TRIGGER journals_no_truncate ON journals;
        RAISE EXCEPTION 'replica drop was accepted';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
                RAISE;
            END IF;
    END;
    SET LOCAL session_replication_role = origin;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'journals'::regclass
          AND tgname = 'journals_append_only'
          AND tgenabled = 'A'
          AND NOT tgisinternal
    ) OR NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'journals'::regclass
          AND tgname = 'journals_no_truncate'
          AND tgenabled = 'A'
          AND NOT tgisinternal
    ) OR NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'brands'::regclass
          AND tgname = 'brands_no_truncate'
          AND tgenabled = 'A'
          AND NOT tgisinternal
    ) OR NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'items'::regclass
          AND tgname = 'items_no_truncate'
          AND tgenabled = 'A'
          AND NOT tgisinternal
    ) OR NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'wait_list'::regclass
          AND tgname = 'wait_list_no_truncate'
          AND tgenabled = 'A'
          AND NOT tgisinternal
    ) OR NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'platform.owner_backups'::regclass
          AND tgname = 'owner_backups_append_only'
          AND tgenabled = 'A'
          AND NOT tgisinternal
    ) OR NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'platform.owner_backups'::regclass
          AND tgname = 'owner_backups_no_truncate'
          AND tgenabled = 'A'
          AND NOT tgisinternal
    ) THEN
        RAISE EXCEPTION 'an append-only trigger was left disabled or missing';
    END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE journals ENABLE TRIGGER journals_append_only;
    RAISE EXCEPTION 'putting the journal trigger back to origin was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000a021',
    '01900000-0000-7000-8000-000000000001',
    '2026-09-01',
    '2026-09-30',
    'open'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000a031',
    '01900000-0000-7000-8000-000000000001',
    'proof-always-guard',
    '2026-09-15',
    '2026-09-15 12:00:00+00',
    '01900000-0000-7000-8000-00000000a021',
    'USD',
    'adjustment',
    'always-guard',
    'Replica attack journal',
    1000,
    1000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-00000000a031', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 1000, 0),
    ('01900000-0000-7000-8000-00000000a031', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 1000);

DO $$
BEGIN
    SET LOCAL session_replication_role = replica;
    UPDATE journals
    SET description = 'wiped'
    WHERE id = '01900000-0000-7000-8000-00000000a031';
    RAISE EXCEPTION 'replica update was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%cannot be updated or deleted%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    SET LOCAL session_replication_role = replica;
    DELETE FROM journal_lines
    WHERE journal_id = '01900000-0000-7000-8000-00000000a031';
    RAISE EXCEPTION 'replica line delete was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%cannot be updated or deleted%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE items DROP CONSTRAINT items_brand_id_fkey;
    SET LOCAL session_replication_role = replica;
    TRUNCATE brands;
    RAISE EXCEPTION 'replica brand truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%table brands is append-only and cannot be truncated%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    SET LOCAL session_replication_role = replica;
    TRUNCATE items;
    RAISE EXCEPTION 'replica item truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only and cannot be truncated%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    SET LOCAL session_replication_role = replica;
    TRUNCATE wait_list;
    RAISE EXCEPTION 'replica wait list truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%table wait_list is append-only and cannot be truncated%' THEN
            RAISE;
        END IF;
END $$;

DO $$
DECLARE
    mall_id uuid;
    backup_id uuid := '01900000-0000-7000-8000-00000000d0aa';
BEGIN
    SELECT id INTO mall_id FROM platform.tenants WHERE slug = 'mall';
    IF mall_id IS NULL THEN
        RAISE EXCEPTION 'mall tenant is missing';
    END IF;
    INSERT INTO platform.owner_backups (id, tenant_id, completed_at, byte_size, sha256)
    VALUES (backup_id, mall_id, NULL, 1, repeat('a', 64));
    BEGIN
        SET LOCAL session_replication_role = replica;
        DELETE FROM platform.owner_backups WHERE id = backup_id;
        RAISE EXCEPTION 'replica backup delete was accepted';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%cannot be updated or deleted%' THEN
                RAISE;
            END IF;
    END;
END $$;

DO $$
BEGIN
    ALTER TABLE items DROP CONSTRAINT items_brand_id_fkey;
    TRUNCATE brands;
    RAISE EXCEPTION 'brand truncate was accepted after the guard held';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%table brands is append-only and cannot be truncated%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    TRUNCATE items;
    RAISE EXCEPTION 'item truncate was accepted after the guard held';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        NULL;
END $$;

DO $$
BEGIN
    TRUNCATE wait_list;
    RAISE EXCEPTION 'wait list truncate was accepted after the guard held';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%table wait_list is append-only and cannot be truncated%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    TRUNCATE platform.owner_backups;
    RAISE EXCEPTION 'backup truncate was accepted after the guard held';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        NULL;
END $$;

CREATE SCHEMA proof_guard;

CREATE TABLE proof_guard.scratch (
    id integer
);

CREATE FUNCTION proof_guard.scratch_noop()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RETURN NULL;
END;
$$;

CREATE TRIGGER scratch_noop
    BEFORE DELETE ON proof_guard.scratch
    FOR EACH ROW
    EXECUTE FUNCTION proof_guard.scratch_noop();

DROP TRIGGER scratch_noop ON proof_guard.scratch;

DROP SCHEMA proof_guard CASCADE;

CREATE SCHEMA tenant_00000000000000000000000000000000;

CREATE TABLE tenant_00000000000000000000000000000000.marker (
    id integer
);

CREATE FUNCTION tenant_00000000000000000000000000000000.marker_noop()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RETURN NULL;
END;
$$;

CREATE TRIGGER marker_noop
    BEFORE DELETE ON tenant_00000000000000000000000000000000.marker
    FOR EACH ROW
    EXECUTE FUNCTION tenant_00000000000000000000000000000000.marker_noop();

DROP SCHEMA tenant_00000000000000000000000000000000 CASCADE;

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    BEGIN
        SET LOCAL session_replication_role = replica;
        RAISE EXCEPTION 'poster replica mode was accepted';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        SET LOCAL event_triggers = false;
        RAISE EXCEPTION 'poster turned event triggers off';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    RESET ROLE;
END $$;

ROLLBACK;
