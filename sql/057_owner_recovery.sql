-- Recovery email, session generation, and one-time password reset tokens.
-- stall_app may change the owner password, recovery email, and session generation.
-- It may not change the username, tenant, or id.
-- Apply once to database consign, after sql/047_platform_schema_migrations.sql.
-- Re-running this file is safe: a platform stamp rebuild executes it again.

DO $owners$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'platform' AND table_name = 'owners' AND column_name = 'email'
    ) THEN
        ALTER TABLE platform.owners ADD COLUMN email text;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'platform' AND table_name = 'owners' AND column_name = 'credentials_version'
    ) THEN
        ALTER TABLE platform.owners ADD COLUMN credentials_version integer NOT NULL DEFAULT 1;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'owners_email_chk') THEN
        ALTER TABLE platform.owners ADD CONSTRAINT owners_email_chk CHECK (
            email IS NULL
            OR (
                email = btrim(email)
                AND char_length(email) <= 200
                AND email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'
            )
        );
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'owners_credentials_version_chk') THEN
        ALTER TABLE platform.owners ADD CONSTRAINT owners_credentials_version_chk CHECK (credentials_version >= 1);
    END IF;
END
$owners$;

GRANT UPDATE (password_hash, email, credentials_version) ON TABLE platform.owners TO stall_app;

CREATE TABLE IF NOT EXISTS platform.password_resets (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id uuid NOT NULL REFERENCES platform.tenants (id),
    account_kind text NOT NULL,
    account_id uuid NOT NULL,
    token_hash text NOT NULL,
    expires_at timestamptz NOT NULL,
    used_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT password_resets_kind_chk CHECK (account_kind IN ('owner', 'staff')),
    CONSTRAINT password_resets_hash_chk CHECK (token_hash ~ '^[0-9a-f]{64}$'),
    CONSTRAINT password_resets_hash_key UNIQUE (token_hash)
);

CREATE INDEX IF NOT EXISTS password_resets_account
    ON platform.password_resets (tenant_id, account_kind, account_id);

REVOKE ALL ON TABLE platform.password_resets FROM PUBLIC;
GRANT SELECT, INSERT ON TABLE platform.password_resets TO stall_app;
GRANT UPDATE (used_at) ON TABLE platform.password_resets TO stall_app;
REVOKE DELETE, TRUNCATE, TRIGGER ON TABLE platform.password_resets FROM stall_app;

CREATE TABLE IF NOT EXISTS platform.password_reset_requests (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id uuid NOT NULL REFERENCES platform.tenants (id),
    username text NOT NULL,
    requested_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT password_reset_requests_username_chk CHECK (
        username = btrim(username) AND username ~ '^[a-z][a-z0-9-]{0,62}$'
    )
);

CREATE INDEX IF NOT EXISTS password_reset_requests_window
    ON platform.password_reset_requests (tenant_id, username, requested_at);

REVOKE ALL ON TABLE platform.password_reset_requests FROM PUBLIC;
GRANT SELECT, INSERT ON TABLE platform.password_reset_requests TO stall_app;
REVOKE UPDATE, DELETE, TRUNCATE, TRIGGER ON TABLE platform.password_reset_requests FROM stall_app;

DO $reset_fn$
BEGIN
    IF to_regprocedure('platform.reject_password_reset_mutation()') IS NOT NULL THEN
        RETURN;
    END IF;
    CREATE FUNCTION platform.reject_password_reset_mutation()
    RETURNS trigger
    LANGUAGE plpgsql
    SET search_path = pg_catalog
    AS $body$
    BEGIN
        IF TG_OP = 'DELETE' THEN
            RAISE EXCEPTION 'a password reset cannot be removed'
                USING ERRCODE = '25006';
        END IF;
        IF OLD.used_at IS NOT NULL THEN
            RAISE EXCEPTION 'a password reset was already used'
                USING ERRCODE = '25006';
        END IF;
        IF NEW.used_at IS NULL
            OR NEW.id IS DISTINCT FROM OLD.id
            OR NEW.tenant_id IS DISTINCT FROM OLD.tenant_id
            OR NEW.account_kind IS DISTINCT FROM OLD.account_kind
            OR NEW.account_id IS DISTINCT FROM OLD.account_id
            OR NEW.token_hash IS DISTINCT FROM OLD.token_hash
            OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
            OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
            RAISE EXCEPTION 'a password reset cannot be rewritten'
                USING ERRCODE = '25006';
        END IF;

        RETURN NEW;
    END;
    $body$;

    CREATE FUNCTION platform.reject_password_reset_truncate()
    RETURNS trigger
    LANGUAGE plpgsql
    SET search_path = pg_catalog
    AS $body$
    BEGIN
        RAISE EXCEPTION 'password resets cannot be truncated'
            USING ERRCODE = '25006';
    END;
    $body$;

    CREATE TRIGGER password_resets_append_only
        BEFORE UPDATE OR DELETE ON platform.password_resets
        FOR EACH ROW
        EXECUTE FUNCTION platform.reject_password_reset_mutation();

    CREATE TRIGGER password_resets_no_truncate
        BEFORE TRUNCATE ON platform.password_resets
        FOR EACH STATEMENT
        EXECUTE FUNCTION platform.reject_password_reset_truncate();
END
$reset_fn$;

ALTER TABLE platform.password_resets
    ENABLE ALWAYS TRIGGER password_resets_append_only,
    ENABLE ALWAYS TRIGGER password_resets_no_truncate;

REVOKE ALL ON FUNCTION platform.reject_password_reset_mutation() FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.reject_password_reset_truncate() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION platform.reject_password_reset_mutation() TO stall_app;
GRANT EXECUTE ON FUNCTION platform.reject_password_reset_truncate() TO stall_app;

-- Postgres does not deliver ALTER EVENT TRIGGER or DROP EVENT TRIGGER to this guard.
-- A superuser can still set event_triggers=false. stall_app cannot.
DO $guard_fn$
BEGIN
    IF to_regprocedure('platform.reject_password_reset_guard()') IS NOT NULL THEN
        RETURN;
    END IF;
    CREATE FUNCTION platform.reject_password_reset_guard()
    RETURNS event_trigger
    LANGUAGE plpgsql
    SET search_path = pg_catalog
    AS $body$
    DECLARE
        command record;
        dropped record;
    BEGIN
        IF TG_EVENT = 'sql_drop' THEN
            FOR dropped IN
                SELECT object_type, object_identity
                FROM pg_event_trigger_dropped_objects()
            LOOP
                IF dropped.object_type = 'trigger'
                    AND right(dropped.object_identity, length(' on platform.password_resets'))
                        = ' on platform.password_resets' THEN
                    RAISE EXCEPTION 'append-only triggers cannot be dropped'
                        USING ERRCODE = '25006';
                END IF;
                IF dropped.object_type = 'function'
                    AND dropped.object_identity IN (
                        'platform.reject_password_reset_mutation()',
                        'platform.reject_password_reset_truncate()',
                        'platform.reject_password_reset_guard()'
                    ) THEN
                    RAISE EXCEPTION 'a guard function cannot be replaced'
                        USING ERRCODE = '25006';
                END IF;
            END LOOP;
            RETURN;
        END IF;

        FOR command IN
            SELECT command_tag, objid
            FROM pg_event_trigger_ddl_commands()
        LOOP
            IF command.command_tag = 'ALTER TABLE'
                AND command.objid IS NOT DISTINCT FROM to_regclass('platform.password_resets')
                AND EXISTS (
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
            IF command.command_tag IN ('CREATE FUNCTION', 'ALTER FUNCTION', 'ALTER ROUTINE')
                AND command.objid IN (
                    'platform.reject_password_reset_mutation()'::regprocedure,
                    'platform.reject_password_reset_truncate()'::regprocedure,
                    'platform.reject_password_reset_guard()'::regprocedure
                ) THEN
                RAISE EXCEPTION 'a guard function cannot be replaced'
                    USING ERRCODE = '25006';
            END IF;
        END LOOP;
    END;
    $body$;
END
$guard_fn$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_event_trigger WHERE evtname = 'platform_reject_password_reset_change'
    ) THEN
        CREATE EVENT TRIGGER platform_reject_password_reset_change
            ON ddl_command_end
            WHEN TAG IN ('ALTER TABLE', 'CREATE FUNCTION', 'ALTER FUNCTION', 'ALTER ROUTINE')
            EXECUTE FUNCTION platform.reject_password_reset_guard();
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_event_trigger WHERE evtname = 'platform_reject_password_reset_drop'
    ) THEN
        CREATE EVENT TRIGGER platform_reject_password_reset_drop
            ON sql_drop
            WHEN TAG IN ('DROP TRIGGER', 'DROP FUNCTION')
            EXECUTE FUNCTION platform.reject_password_reset_guard();
    END IF;
END $$;

ALTER EVENT TRIGGER platform_reject_password_reset_change ENABLE ALWAYS;
ALTER EVENT TRIGGER platform_reject_password_reset_drop ENABLE ALWAYS;
