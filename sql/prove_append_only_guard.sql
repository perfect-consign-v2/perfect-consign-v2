-- The append-only guard refuses disabling, replica-only, and dropping row triggers.
-- Postgres does not deliver ALTER EVENT TRIGGER or DROP EVENT TRIGGER to this guard.
-- session_replication_role=replica still skips origin row triggers. Only a superuser can set it.
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
          AND tgenabled = 'O'
          AND NOT tgisinternal
    ) OR NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'journals'::regclass
          AND tgname = 'journals_no_truncate'
          AND tgenabled = 'O'
          AND NOT tgisinternal
    ) OR NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'brands'::regclass
          AND tgname = 'brands_no_truncate'
          AND tgenabled = 'O'
          AND NOT tgisinternal
    ) OR NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'platform.owner_backups'::regclass
          AND tgname = 'owner_backups_append_only'
          AND tgenabled = 'O'
          AND NOT tgisinternal
    ) THEN
        RAISE EXCEPTION 'an append-only trigger was left disabled or missing';
    END IF;
END $$;

DO $$
BEGIN
    TRUNCATE brands;
    RAISE EXCEPTION 'brand truncate was accepted after the guard held';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        NULL;
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
