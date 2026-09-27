-- Platform registry. One row per mall. No ledger tables.
-- Apply once to database consign, before any tenant schema.
-- btree_gist lives here, not in a tenant schema. Dropping a mall must not drop it.

CREATE SCHEMA IF NOT EXISTS extensions;

REVOKE ALL ON SCHEMA extensions FROM PUBLIC;

GRANT USAGE ON SCHEMA extensions TO PUBLIC;

CREATE EXTENSION IF NOT EXISTS btree_gist WITH SCHEMA extensions;

CREATE SCHEMA IF NOT EXISTS platform;

REVOKE ALL ON SCHEMA platform FROM PUBLIC;

CREATE TABLE IF NOT EXISTS platform.tenants (
    id uuid PRIMARY KEY,
    slug text NOT NULL,
    schema_name text NOT NULL,
    display_name text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT tenants_slug_chk CHECK (slug = btrim(slug) AND slug ~ '^[a-z][a-z0-9-]{0,62}$'),
    CONSTRAINT tenants_schema_chk CHECK (schema_name ~ '^tenant_[0-9a-f]{32}$'),
    CONSTRAINT tenants_name_chk CHECK (display_name = btrim(display_name) AND display_name <> ''),
    CONSTRAINT tenants_slug_key UNIQUE (slug),
    CONSTRAINT tenants_schema_key UNIQUE (schema_name)
);

REVOKE ALL ON TABLE platform.tenants FROM PUBLIC;

-- Postgres never fires an event trigger for ALTER EVENT TRIGGER or DROP EVENT TRIGGER.
-- A superuser can disable this guard with those commands, or with event_triggers=false.
-- Append-only triggers are ENABLE ALWAYS, so session_replication_role=replica still
-- refuses update, delete, and truncate. Only a superuser can set replica mode.
-- stall_poster and stall_app cannot. The event triggers below are ENABLE ALWAYS, so
-- that session still cannot disable or drop the row triggers.
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

DO $$
DECLARE
    target record;
    actions text;
BEGIN
    FOR target IN
        SELECT n.nspname AS schema_name, c.relname AS table_name
        FROM pg_trigger AS t
        JOIN pg_class AS c ON c.oid = t.tgrelid
        JOIN pg_namespace AS n ON n.oid = c.relnamespace
        WHERE NOT t.tgisinternal
          AND t.tgenabled IS DISTINCT FROM 'A'
          AND t.tgname ~ '(_append_only|_no_truncate|_no_delete)$'
          AND (
              n.nspname ~ '^tenant_[0-9a-f]{32}$'
              OR (n.nspname = 'platform' AND c.relname = 'owner_backups')
          )
        GROUP BY n.nspname, c.relname
    LOOP
        SELECT string_agg(format('ENABLE ALWAYS TRIGGER %I', t.tgname), ', ' ORDER BY t.tgname)
        INTO actions
        FROM pg_trigger AS t
        JOIN pg_class AS c ON c.oid = t.tgrelid
        JOIN pg_namespace AS n ON n.oid = c.relnamespace
        WHERE n.nspname = target.schema_name
          AND c.relname = target.table_name
          AND NOT t.tgisinternal
          AND t.tgenabled IS DISTINCT FROM 'A'
          AND t.tgname ~ '(_append_only|_no_truncate|_no_delete)$';
        EXECUTE format('ALTER TABLE %I.%I %s', target.schema_name, target.table_name, actions);
    END LOOP;
END $$;
