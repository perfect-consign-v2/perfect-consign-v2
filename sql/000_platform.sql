-- The platform schema. Apply once to an empty database.
-- An existing database is not upgraded.

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'stall_app') THEN
        CREATE ROLE stall_app NOINHERIT NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
    END IF;
END $$;

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

SET check_function_bodies = false;
CREATE SCHEMA extensions;

CREATE SCHEMA platform;

CREATE EXTENSION IF NOT EXISTS btree_gist WITH SCHEMA extensions;

CREATE FUNCTION platform.assign_owner_backup_time() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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

CREATE FUNCTION platform.latest_owner_backup(tenant uuid) RETURNS timestamp with time zone
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'platform', 'pg_catalog'
    AS $$
    SELECT max(completed_at) FROM owner_backups WHERE tenant_id = tenant;
$$;

CREATE FUNCTION platform.record_owner_backup(tenant uuid, backup uuid, bytes bigint, digest text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'platform', 'pg_catalog'
    AS $_$
BEGIN
    IF tenant IS NULL OR backup IS NULL OR bytes IS NULL OR bytes < 0
        OR digest IS NULL OR digest !~ '^[0-9a-f]{64}$' THEN
        RAISE EXCEPTION 'a backup record is incomplete'
            USING ERRCODE = '23514';
    END IF;
    INSERT INTO owner_backups (id, tenant_id, byte_size, sha256)
    VALUES (backup, tenant, bytes, digest);
END;
$_$;

CREATE FUNCTION platform.reject_append_only_trigger_change() RETURNS event_trigger
    LANGUAGE plpgsql
    AS $_$
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
$_$;

CREATE FUNCTION platform.reject_guard_function_again() RETURNS event_trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $_$
DECLARE
    command record;
BEGIN
    FOR command IN
        SELECT schema_name, objid, command_tag
        FROM pg_event_trigger_ddl_commands()
    LOOP
        IF command.command_tag <> 'CREATE FUNCTION' OR command.objid IS NULL THEN
            CONTINUE;
        END IF;
        IF command.schema_name = 'platform'
            AND command.objid IN (
                'platform.reject_guard_function_replace()'::regprocedure,
                'platform.reject_guard_function_again()'::regprocedure,
                'platform.reject_append_only_trigger_change()'::regprocedure
            ) THEN
            RAISE EXCEPTION 'a guard function cannot be replaced'
                USING ERRCODE = '25006';
        END IF;
        IF command.schema_name IS NULL OR command.schema_name !~ '^tenant_[0-9a-f]{32}$' THEN
            CONTINUE;
        END IF;
        IF EXISTS (
            SELECT 1
            FROM pg_trigger
            WHERE tgfoid = command.objid
              AND NOT tgisinternal
              AND tgname ~ '(_append_only|_no_truncate|_no_delete)$'
        ) THEN
            RAISE EXCEPTION 'a guard function cannot be replaced'
                USING ERRCODE = '25006';
        END IF;
    END LOOP;
END;
$_$;

CREATE FUNCTION platform.reject_guard_function_replace() RETURNS event_trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $_$
DECLARE
    command record;
BEGIN
    FOR command IN
        SELECT schema_name, objid, command_tag
        FROM pg_event_trigger_ddl_commands()
    LOOP
        IF command.command_tag <> 'CREATE FUNCTION' OR command.objid IS NULL THEN
            CONTINUE;
        END IF;
        IF command.schema_name = 'platform'
            AND command.objid IN (
                'platform.reject_guard_function_replace()'::regprocedure,
                'platform.reject_guard_function_again()'::regprocedure,
                'platform.reject_append_only_trigger_change()'::regprocedure
            ) THEN
            RAISE EXCEPTION 'a guard function cannot be replaced'
                USING ERRCODE = '25006';
        END IF;
        IF command.schema_name IS NULL OR command.schema_name !~ '^tenant_[0-9a-f]{32}$' THEN
            CONTINUE;
        END IF;
        IF EXISTS (
            SELECT 1
            FROM pg_trigger
            WHERE tgfoid = command.objid
              AND NOT tgisinternal
              AND tgname ~ '(_append_only|_no_truncate|_no_delete)$'
        ) THEN
            RAISE EXCEPTION 'a guard function cannot be replaced'
                USING ERRCODE = '25006';
        END IF;
    END LOOP;
END;
$_$;

CREATE FUNCTION platform.reject_owner_backup_mutation() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'a backup record cannot be updated or deleted'
        USING ERRCODE = '25006';
END;
$$;

CREATE FUNCTION platform.reject_owner_backup_truncate() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'table % is append-only and cannot be truncated', TG_TABLE_NAME
        USING ERRCODE = '25006';
END;
$$;

CREATE FUNCTION platform.reject_password_reset_guard() RETURNS event_trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $_$
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
    $_$;

CREATE FUNCTION platform.reject_password_reset_mutation() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
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
    $$;

CREATE FUNCTION platform.reject_password_reset_truncate() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
    BEGIN
        RAISE EXCEPTION 'password resets cannot be truncated'
            USING ERRCODE = '25006';
    END;
    $$;

CREATE TABLE platform.owner_backups (
    id uuid NOT NULL,
    tenant_id uuid NOT NULL,
    completed_at timestamp with time zone NOT NULL,
    byte_size bigint NOT NULL,
    sha256 text NOT NULL,
    CONSTRAINT owner_backups_sha_chk CHECK ((sha256 ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT owner_backups_size_chk CHECK ((byte_size >= 0))
);

CREATE TABLE platform.owner_sign_in_failures (
    id uuid DEFAULT uuidv7() NOT NULL,
    tenant_id uuid NOT NULL,
    username text NOT NULL,
    failed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    client_address text DEFAULT ''::text NOT NULL,
    CONSTRAINT owner_sign_in_failures_address_chk CHECK ((char_length(client_address) <= 64)),
    CONSTRAINT owner_sign_in_failures_username_chk CHECK (((username = btrim(username)) AND (username ~ '^[a-z][a-z0-9-]{0,62}$'::text)))
);

CREATE TABLE platform.owners (
    id uuid NOT NULL,
    tenant_id uuid NOT NULL,
    username text NOT NULL,
    password_hash text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    email text,
    credentials_version integer DEFAULT 1 NOT NULL,
    CONSTRAINT owners_credentials_version_chk CHECK ((credentials_version >= 1)),
    CONSTRAINT owners_email_chk CHECK (((email IS NULL) OR ((email = btrim(email)) AND (char_length(email) <= 200) AND (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text)))),
    CONSTRAINT owners_hash_chk CHECK ((password_hash <> ''::text)),
    CONSTRAINT owners_username_chk CHECK (((username = btrim(username)) AND (username ~ '^[a-z][a-z0-9-]{0,62}$'::text)))
);

CREATE TABLE platform.password_reset_requests (
    id uuid DEFAULT uuidv7() NOT NULL,
    tenant_id uuid NOT NULL,
    username text NOT NULL,
    requested_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT password_reset_requests_username_chk CHECK (((username = btrim(username)) AND (username ~ '^[a-z][a-z0-9-]{0,62}$'::text)))
);

CREATE TABLE platform.password_resets (
    id uuid DEFAULT uuidv7() NOT NULL,
    tenant_id uuid NOT NULL,
    account_kind text NOT NULL,
    account_id uuid NOT NULL,
    token_hash text NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    used_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT password_resets_hash_chk CHECK ((token_hash ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT password_resets_kind_chk CHECK ((account_kind = ANY (ARRAY['owner'::text, 'staff'::text])))
);

CREATE TABLE platform.schema_migrations (
    filename text NOT NULL,
    checksum text NOT NULL,
    applied_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT schema_migrations_checksum_chk CHECK ((checksum ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT schema_migrations_filename_chk CHECK ((filename ~ '^[0-9]{3}_[a-z0-9_]+\.sql$'::text))
);

CREATE TABLE platform.tenants (
    id uuid NOT NULL,
    slug text NOT NULL,
    schema_name text NOT NULL,
    display_name text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT tenants_name_chk CHECK (((display_name = btrim(display_name)) AND (display_name <> ''::text))),
    CONSTRAINT tenants_name_length_chk CHECK ((char_length(display_name) <= 120)),
    CONSTRAINT tenants_schema_chk CHECK ((schema_name ~ '^tenant_[0-9a-f]{32}$'::text)),
    CONSTRAINT tenants_slug_chk CHECK (((slug = btrim(slug)) AND (slug ~ '^[a-z][a-z0-9-]{0,62}$'::text)))
);

ALTER TABLE ONLY platform.owner_backups
    ADD CONSTRAINT owner_backups_pkey PRIMARY KEY (id);

ALTER TABLE ONLY platform.owner_sign_in_failures
    ADD CONSTRAINT owner_sign_in_failures_pkey PRIMARY KEY (id);

ALTER TABLE ONLY platform.owners
    ADD CONSTRAINT owners_pkey PRIMARY KEY (id);

ALTER TABLE ONLY platform.owners
    ADD CONSTRAINT owners_tenant_id_key UNIQUE (tenant_id);

ALTER TABLE ONLY platform.password_reset_requests
    ADD CONSTRAINT password_reset_requests_pkey PRIMARY KEY (id);

ALTER TABLE ONLY platform.password_resets
    ADD CONSTRAINT password_resets_hash_key UNIQUE (token_hash);

ALTER TABLE ONLY platform.password_resets
    ADD CONSTRAINT password_resets_pkey PRIMARY KEY (id);

ALTER TABLE ONLY platform.schema_migrations
    ADD CONSTRAINT schema_migrations_pkey PRIMARY KEY (filename);

ALTER TABLE ONLY platform.tenants
    ADD CONSTRAINT tenants_pkey PRIMARY KEY (id);

ALTER TABLE ONLY platform.tenants
    ADD CONSTRAINT tenants_schema_key UNIQUE (schema_name);

ALTER TABLE ONLY platform.tenants
    ADD CONSTRAINT tenants_slug_key UNIQUE (slug);

CREATE INDEX owner_sign_in_failures_window ON platform.owner_sign_in_failures USING btree (tenant_id, username, client_address, failed_at);

CREATE INDEX password_reset_requests_window ON platform.password_reset_requests USING btree (tenant_id, username, requested_at);

CREATE INDEX password_resets_account ON platform.password_resets USING btree (tenant_id, account_kind, account_id);

CREATE TRIGGER owner_backups_append_only BEFORE DELETE OR UPDATE ON platform.owner_backups FOR EACH ROW EXECUTE FUNCTION platform.reject_owner_backup_mutation();

ALTER TABLE platform.owner_backups ENABLE ALWAYS TRIGGER owner_backups_append_only;

CREATE TRIGGER owner_backups_assign_time BEFORE INSERT ON platform.owner_backups FOR EACH ROW EXECUTE FUNCTION platform.assign_owner_backup_time();

CREATE TRIGGER owner_backups_no_truncate BEFORE TRUNCATE ON platform.owner_backups FOR EACH STATEMENT EXECUTE FUNCTION platform.reject_owner_backup_truncate();

ALTER TABLE platform.owner_backups ENABLE ALWAYS TRIGGER owner_backups_no_truncate;

CREATE TRIGGER password_resets_append_only BEFORE DELETE OR UPDATE ON platform.password_resets FOR EACH ROW EXECUTE FUNCTION platform.reject_password_reset_mutation();

ALTER TABLE platform.password_resets ENABLE ALWAYS TRIGGER password_resets_append_only;

CREATE TRIGGER password_resets_no_truncate BEFORE TRUNCATE ON platform.password_resets FOR EACH STATEMENT EXECUTE FUNCTION platform.reject_password_reset_truncate();

ALTER TABLE platform.password_resets ENABLE ALWAYS TRIGGER password_resets_no_truncate;

ALTER TABLE ONLY platform.owners
    ADD CONSTRAINT owners_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES platform.tenants(id);

ALTER TABLE ONLY platform.password_reset_requests
    ADD CONSTRAINT password_reset_requests_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES platform.tenants(id);

ALTER TABLE ONLY platform.password_resets
    ADD CONSTRAINT password_resets_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES platform.tenants(id);

GRANT USAGE ON SCHEMA extensions TO PUBLIC;

GRANT USAGE ON SCHEMA platform TO stall_app;

GRANT USAGE ON SCHEMA platform TO stall_backup;

REVOKE ALL ON FUNCTION platform.latest_owner_backup(tenant uuid) FROM PUBLIC;

GRANT ALL ON FUNCTION platform.latest_owner_backup(tenant uuid) TO stall_app;

REVOKE ALL ON FUNCTION platform.record_owner_backup(tenant uuid, backup uuid, bytes bigint, digest text) FROM PUBLIC;

GRANT ALL ON FUNCTION platform.record_owner_backup(tenant uuid, backup uuid, bytes bigint, digest text) TO stall_app;

REVOKE ALL ON FUNCTION platform.reject_password_reset_mutation() FROM PUBLIC;

GRANT ALL ON FUNCTION platform.reject_password_reset_mutation() TO stall_app;

REVOKE ALL ON FUNCTION platform.reject_password_reset_truncate() FROM PUBLIC;

GRANT ALL ON FUNCTION platform.reject_password_reset_truncate() TO stall_app;

GRANT SELECT,INSERT,DELETE ON TABLE platform.owner_sign_in_failures TO stall_app;

GRANT SELECT ON TABLE platform.owners TO stall_app;

GRANT SELECT ON TABLE platform.owners TO stall_backup;

GRANT UPDATE(password_hash) ON TABLE platform.owners TO stall_app;

GRANT UPDATE(email) ON TABLE platform.owners TO stall_app;

GRANT UPDATE(credentials_version) ON TABLE platform.owners TO stall_app;

GRANT SELECT,INSERT ON TABLE platform.password_reset_requests TO stall_app;

GRANT SELECT,INSERT ON TABLE platform.password_resets TO stall_app;

GRANT UPDATE(used_at) ON TABLE platform.password_resets TO stall_app;

GRANT SELECT ON TABLE platform.tenants TO stall_app;

GRANT SELECT ON TABLE platform.tenants TO stall_backup;

GRANT UPDATE(display_name) ON TABLE platform.tenants TO stall_app;

CREATE EVENT TRIGGER platform_reject_append_only_trigger_change ON ddl_command_end
         WHEN TAG IN ('ALTER TABLE')
   EXECUTE FUNCTION platform.reject_append_only_trigger_change();

ALTER EVENT TRIGGER platform_reject_append_only_trigger_change ENABLE ALWAYS;

CREATE EVENT TRIGGER platform_reject_append_only_trigger_drop ON sql_drop
         WHEN TAG IN ('DROP TRIGGER')
   EXECUTE FUNCTION platform.reject_append_only_trigger_change();

ALTER EVENT TRIGGER platform_reject_append_only_trigger_drop ENABLE ALWAYS;

CREATE EVENT TRIGGER platform_reject_guard_function_again ON ddl_command_end
         WHEN TAG IN ('CREATE FUNCTION')
   EXECUTE FUNCTION platform.reject_guard_function_again();

ALTER EVENT TRIGGER platform_reject_guard_function_again ENABLE ALWAYS;

CREATE EVENT TRIGGER platform_reject_guard_function_replace ON ddl_command_end
         WHEN TAG IN ('CREATE FUNCTION')
   EXECUTE FUNCTION platform.reject_guard_function_replace();

ALTER EVENT TRIGGER platform_reject_guard_function_replace ENABLE ALWAYS;

CREATE EVENT TRIGGER platform_reject_password_reset_change ON ddl_command_end
         WHEN TAG IN ('ALTER TABLE', 'CREATE FUNCTION', 'ALTER FUNCTION', 'ALTER ROUTINE')
   EXECUTE FUNCTION platform.reject_password_reset_guard();

ALTER EVENT TRIGGER platform_reject_password_reset_change ENABLE ALWAYS;

CREATE EVENT TRIGGER platform_reject_password_reset_drop ON sql_drop
         WHEN TAG IN ('DROP TRIGGER', 'DROP FUNCTION')
   EXECUTE FUNCTION platform.reject_password_reset_guard();

ALTER EVENT TRIGGER platform_reject_password_reset_drop ENABLE ALWAYS;
