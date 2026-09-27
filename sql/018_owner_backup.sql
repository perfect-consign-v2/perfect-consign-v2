-- Owner download log. The time is assigned here. The bytes are not stored.
-- Apply once to database consign, after sql/016_owners.sql.

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'stall_backup') THEN
        CREATE ROLE stall_backup NOINHERIT LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
    END IF;
END $$;

DO $$
BEGIN
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO stall_backup', current_database());
END $$;

CREATE TABLE IF NOT EXISTS platform.owner_backups (
    id uuid PRIMARY KEY,
    tenant_id uuid NOT NULL REFERENCES platform.tenants (id),
    completed_at timestamptz NOT NULL,
    byte_size bigint NOT NULL,
    sha256 text NOT NULL,
    CONSTRAINT owner_backups_size_chk CHECK (byte_size >= 0),
    CONSTRAINT owner_backups_sha_chk CHECK (sha256 ~ '^[0-9a-f]{64}$')
);

REVOKE ALL ON TABLE platform.owner_backups FROM PUBLIC;

CREATE OR REPLACE FUNCTION platform.assign_owner_backup_time()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF NEW.completed_at IS NOT NULL THEN
        RAISE EXCEPTION 'a backup time is assigned by the database'
            USING ERRCODE = '23514';
    END IF;
    NEW.completed_at := clock_timestamp();
    RETURN NEW;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'platform.owner_backups'::regclass
          AND tgname = 'owner_backups_assign_time'
          AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER owner_backups_assign_time
            BEFORE INSERT ON platform.owner_backups
            FOR EACH ROW
            EXECUTE FUNCTION platform.assign_owner_backup_time();
    END IF;
END $$;

CREATE OR REPLACE FUNCTION platform.reject_owner_backup_mutation()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    RAISE EXCEPTION 'a backup record cannot be updated or deleted'
        USING ERRCODE = '25006';
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'platform.owner_backups'::regclass
          AND tgname = 'owner_backups_append_only'
          AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER owner_backups_append_only
            BEFORE UPDATE OR DELETE ON platform.owner_backups
            FOR EACH ROW
            EXECUTE FUNCTION platform.reject_owner_backup_mutation();
    END IF;
END $$;

CREATE OR REPLACE FUNCTION platform.reject_owner_backup_truncate()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    RAISE EXCEPTION 'table % is append-only and cannot be truncated', TG_TABLE_NAME
        USING ERRCODE = '25006';
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'platform.owner_backups'::regclass
          AND tgname = 'owner_backups_no_truncate'
          AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER owner_backups_no_truncate
            BEFORE TRUNCATE ON platform.owner_backups
            FOR EACH STATEMENT
            EXECUTE FUNCTION platform.reject_owner_backup_truncate();
    END IF;
END $$;

CREATE OR REPLACE FUNCTION platform.record_owner_backup(
    tenant uuid,
    backup uuid,
    bytes bigint,
    digest text
) RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = platform, pg_catalog
AS $$
BEGIN
    IF tenant IS NULL OR backup IS NULL OR bytes IS NULL OR bytes < 0
        OR digest IS NULL OR digest !~ '^[0-9a-f]{64}$' THEN
        RAISE EXCEPTION 'a backup record is incomplete'
            USING ERRCODE = '23514';
    END IF;
    INSERT INTO owner_backups (id, tenant_id, byte_size, sha256)
    VALUES (backup, tenant, bytes, digest);
END;
$$;

CREATE OR REPLACE FUNCTION platform.latest_owner_backup(tenant uuid)
RETURNS timestamptz
LANGUAGE sql
STABLE
SECURITY DEFINER
SET search_path = platform, pg_catalog
AS $$
    SELECT max(completed_at) FROM owner_backups WHERE tenant_id = tenant;
$$;

REVOKE ALL ON FUNCTION platform.record_owner_backup(uuid, uuid, bigint, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.latest_owner_backup(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION platform.record_owner_backup(uuid, uuid, bigint, text) TO stall_app;
GRANT EXECUTE ON FUNCTION platform.latest_owner_backup(uuid) TO stall_app;

GRANT USAGE ON SCHEMA platform TO stall_backup;
GRANT SELECT ON TABLE platform.tenants, platform.owners TO stall_backup;
REVOKE INSERT, UPDATE, DELETE, TRUNCATE, TRIGGER ON TABLE platform.tenants, platform.owners FROM stall_backup;

-- Same guard as sql/000_platform.sql. Kept here so applying this file still installs it.
CREATE OR REPLACE FUNCTION platform.reject_append_only_trigger_change()
RETURNS event_trigger
LANGUAGE plpgsql
AS $$
DECLARE
    command record;
    dropped record;
BEGIN
    IF TG_EVENT = 'sql_drop' THEN
        FOR dropped IN
            SELECT schema_name, object_identity
            FROM pg_event_trigger_dropped_objects()
            WHERE object_type = 'trigger'
        LOOP
            IF dropped.schema_name ~ '^tenant_[0-9a-f]{32}$'
                OR (
                    dropped.schema_name = 'platform'
                    AND right(dropped.object_identity, length(' on platform.owner_backups'))
                        = ' on platform.owner_backups'
                )
            THEN
                RAISE EXCEPTION 'append-only triggers cannot be dropped'
                    USING ERRCODE = '25006';
            END IF;
        END LOOP;
        RETURN;
    END IF;

    FOR command IN
        SELECT schema_name, command_tag, objid
        FROM pg_event_trigger_ddl_commands()
    LOOP
        IF command.command_tag <> 'ALTER TABLE' THEN
            CONTINUE;
        END IF;
        IF command.schema_name ~ '^tenant_[0-9a-f]{32}$' THEN
            NULL;
        ELSIF command.schema_name = 'platform'
            AND command.objid IS NOT DISTINCT FROM to_regclass('platform.owner_backups') THEN
            NULL;
        ELSE
            CONTINUE;
        END IF;
        IF EXISTS (
            SELECT 1
            FROM pg_trigger
            WHERE tgrelid = command.objid
              AND NOT tgisinternal
              AND (
                  tgenabled NOT IN ('O', 'A')
                  OR (
                      tgenabled <> 'A'
                      AND tgname ~ '(_append_only|_no_truncate|_no_delete)$'
                  )
              )
        ) THEN
            RAISE EXCEPTION 'append-only triggers cannot be disabled'
                USING ERRCODE = '25006';
        END IF;
    END LOOP;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_event_trigger
        WHERE evtname = 'platform_reject_append_only_trigger_change'
    ) THEN
        CREATE EVENT TRIGGER platform_reject_append_only_trigger_change
            ON ddl_command_end
            WHEN TAG IN ('ALTER TABLE')
            EXECUTE FUNCTION platform.reject_append_only_trigger_change();
    END IF;
    IF NOT EXISTS (
        SELECT 1
        FROM pg_event_trigger
        WHERE evtname = 'platform_reject_append_only_trigger_drop'
    ) THEN
        CREATE EVENT TRIGGER platform_reject_append_only_trigger_drop
            ON sql_drop
            WHEN TAG IN ('DROP TRIGGER')
            EXECUTE FUNCTION platform.reject_append_only_trigger_change();
    END IF;
END $$;

ALTER EVENT TRIGGER platform_reject_append_only_trigger_change ENABLE ALWAYS;
ALTER EVENT TRIGGER platform_reject_append_only_trigger_drop ENABLE ALWAYS;

ALTER TABLE platform.owner_backups
    ENABLE ALWAYS TRIGGER owner_backups_append_only,
    ENABLE ALWAYS TRIGGER owner_backups_no_truncate;

DO $$
DECLARE
    tenant record;
    poster_name text;
BEGIN
    FOR tenant IN
        SELECT slug, schema_name FROM platform.tenants
    LOOP
        poster_name := CASE
            WHEN tenant.slug = 'mall' THEN 'stall_poster'
            ELSE 'poster_' || substr(tenant.schema_name, 8)
        END;
        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = poster_name) THEN
            EXECUTE format('GRANT USAGE ON SCHEMA platform TO %I', poster_name);
            EXECUTE format(
                'GRANT EXECUTE ON FUNCTION platform.latest_owner_backup(uuid) TO %I',
                poster_name
            );
            EXECUTE format(
                'GRANT EXECUTE ON FUNCTION platform.record_owner_backup(uuid, uuid, bigint, text) TO %I',
                poster_name
            );
        END IF;
        EXECUTE format('GRANT USAGE ON SCHEMA %I TO stall_backup', tenant.schema_name);
        EXECUTE format('GRANT SELECT ON ALL TABLES IN SCHEMA %I TO stall_backup', tenant.schema_name);
        EXECUTE format('GRANT SELECT ON ALL SEQUENCES IN SCHEMA %I TO stall_backup', tenant.schema_name);
    END LOOP;
END $$;
