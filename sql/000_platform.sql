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

CREATE OR REPLACE FUNCTION platform.reject_append_only_trigger_change()
RETURNS event_trigger
LANGUAGE plpgsql
AS $$
DECLARE
    command record;
BEGIN
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
              AND tgenabled = 'D'
        ) THEN
            RAISE EXCEPTION 'append-only triggers cannot be disabled'
                USING ERRCODE = '25006';
        END IF;
    END LOOP;
END;
$$;

DROP EVENT TRIGGER IF EXISTS platform_reject_append_only_trigger_change;

CREATE EVENT TRIGGER platform_reject_append_only_trigger_change
    ON ddl_command_end
    WHEN TAG IN ('ALTER TABLE')
    EXECUTE FUNCTION platform.reject_append_only_trigger_change();
