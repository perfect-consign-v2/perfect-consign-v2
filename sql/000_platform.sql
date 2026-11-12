-- The platform schema. Apply once to an empty database.
-- An existing database is not upgraded.

SET check_function_bodies = false;

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
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'stall_worker') THEN
        CREATE ROLE stall_worker NOINHERIT NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'stall_vendor') THEN
        CREATE ROLE stall_vendor NOINHERIT NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
    END IF;
END $$;

DO $$
BEGIN
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO stall_backup', current_database());
END $$;

DO $$
BEGIN
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO stall_worker', current_database());
END $$;

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

CREATE FUNCTION platform.clear_sign_in_directory(p_kind text, p_account_id uuid) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    DELETE FROM platform.sign_in_directory
    WHERE account_kind = p_kind AND account_id = p_account_id;
END;
$$;

CREATE FUNCTION platform.create_poster(poster text) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $_$
BEGIN
    IF poster IS NULL OR poster !~ '^[a-z_][a-z0-9_]{0,62}$' THEN
        RAISE EXCEPTION 'a poster role name is required'
            USING ERRCODE = '23514';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = poster) THEN
        EXECUTE format(
            'CREATE ROLE %I NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE',
            poster
        );
    END IF;
END;
$_$;

CREATE FUNCTION platform.enqueue_vendor_letter(letter_date date, letter_id uuid) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $_$
DECLARE
    poster text;
    tenant_slug text;
BEGIN
    poster := nullif(current_setting('role', true), 'none');
    IF poster IS NULL OR poster = '' THEN
        RAISE EXCEPTION 'a poster role must enqueue the letter'
            USING ERRCODE = '42501';
    END IF;
    IF poster = 'stall_poster' THEN
        tenant_slug := 'mall';
    ELSIF poster ~ '^poster_[0-9a-f]{32}$' THEN
        SELECT slug INTO tenant_slug
        FROM platform.tenants
        WHERE schema_name = 'tenant_' || substr(poster, 8);
    ELSE
        RAISE EXCEPTION 'a poster role must enqueue the letter'
            USING ERRCODE = '42501';
    END IF;
    IF tenant_slug IS NULL THEN
        RAISE EXCEPTION 'the mall is not a mall'
            USING ERRCODE = '23514';
    END IF;
    INSERT INTO platform.jobs (tenant, kind, payload, run_at)
    VALUES (
        tenant_slug,
        'vendor-letter',
        jsonb_build_object('date', letter_date::text, 'letter', letter_id::text),
        clock_timestamp()
    )
    ON CONFLICT (tenant, kind, (payload ->> 'letter')) WHERE ((payload ->> 'letter') IS NOT NULL) DO NOTHING;
END;
$_$;

CREATE FUNCTION platform.grant_poster_jobs(poster text) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $_$
BEGIN
    IF poster IS NULL OR poster !~ '^[a-z_][a-z0-9_]{0,62}$' THEN
        RAISE EXCEPTION 'a poster role name is required'
            USING ERRCODE = '23514';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = poster) THEN
        RAISE EXCEPTION 'poster role % is missing', poster
            USING ERRCODE = '42704';
    END IF;
    IF to_regclass('platform.jobs') IS NULL THEN
        RAISE EXCEPTION 'platform.jobs is missing'
            USING ERRCODE = '42P01';
    END IF;
    EXECUTE format('REVOKE INSERT ON TABLE platform.jobs FROM %I', poster);
    EXECUTE format('GRANT SELECT ON TABLE platform.jobs TO %I', poster);
    EXECUTE format('GRANT EXECUTE ON FUNCTION platform.enqueue_vendor_letter(date, uuid) TO %I', poster);
END;
$_$;

CREATE FUNCTION platform.latest_owner_backup(tenant uuid) RETURNS timestamp with time zone
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'platform', 'pg_catalog'
    AS $$
    SELECT max(completed_at) FROM owner_backups WHERE tenant_id = tenant;
$$;

CREATE FUNCTION platform.owners_sign_in_directory() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        PERFORM platform.clear_sign_in_directory('owner', OLD.id);
        RETURN OLD;
    END IF;
    PERFORM platform.put_sign_in_directory(
        NEW.email,
        NEW.tenant_id,
        'owner',
        NEW.id,
        NEW.username
    );
    RETURN NEW;
END;
$$;

CREATE FUNCTION platform.put_sign_in_directory(p_email text, p_tenant_id uuid, p_kind text, p_account_id uuid, p_username text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $_$
DECLARE
    normalized text;
BEGIN
    IF p_email IS NULL OR btrim(p_email) = '' THEN
        DELETE FROM platform.sign_in_directory
        WHERE account_kind = p_kind AND account_id = p_account_id;
        RETURN;
    END IF;
    normalized := lower(btrim(p_email));
    IF char_length(normalized) > 200
        OR normalized !~ '^[^@\s]+@[^@\s]+\.[^@\s]+$' THEN
        RAISE EXCEPTION 'sign-in email is not usable'
            USING ERRCODE = 'check_violation';
    END IF;
    IF p_kind IS DISTINCT FROM 'owner' AND p_kind IS DISTINCT FROM 'staff' THEN
        RAISE EXCEPTION 'sign-in account kind is not usable'
            USING ERRCODE = 'check_violation';
    END IF;
    IF p_username IS NULL OR p_username !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RAISE EXCEPTION 'sign-in username is not usable'
            USING ERRCODE = 'check_violation';
    END IF;
    INSERT INTO platform.sign_in_directory (email, tenant_id, account_kind, account_id, username)
    VALUES (normalized, p_tenant_id, p_kind, p_account_id, p_username)
    ON CONFLICT (account_kind, account_id) DO UPDATE
        SET email = EXCLUDED.email,
            tenant_id = EXCLUDED.tenant_id,
            username = EXCLUDED.username;
END;
$_$;

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

CREATE FUNCTION platform.reject_admin_append_only_guard() RETURNS event_trigger
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
                AND (
                    right(dropped.object_identity, length(' on platform.admin_password_resets'))
                        = ' on platform.admin_password_resets'
                    OR right(dropped.object_identity, length(' on platform.admin_audit'))
                        = ' on platform.admin_audit'
                ) THEN
                RAISE EXCEPTION 'append-only triggers cannot be dropped'
                    USING ERRCODE = '25006';
            END IF;
            IF dropped.object_type = 'function'
                AND dropped.object_identity IN (
                    'platform.reject_admin_password_reset_mutation()',
                    'platform.reject_admin_password_reset_truncate()',
                    'platform.reject_admin_audit_mutation()',
                    'platform.reject_admin_audit_truncate()',
                    'platform.reject_admin_append_only_guard()'
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
            AND command.objid IN (
                to_regclass('platform.admin_password_resets'),
                to_regclass('platform.admin_audit')
            )
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
                'platform.reject_admin_password_reset_mutation()'::regprocedure,
                'platform.reject_admin_password_reset_truncate()'::regprocedure,
                'platform.reject_admin_audit_mutation()'::regprocedure,
                'platform.reject_admin_audit_truncate()'::regprocedure,
                'platform.reject_admin_append_only_guard()'::regprocedure
            ) THEN
            RAISE EXCEPTION 'a guard function cannot be replaced'
                USING ERRCODE = '25006';
        END IF;
    END LOOP;
END;
$_$;

CREATE FUNCTION platform.reject_admin_audit_mutation() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'an admin audit row cannot be updated or deleted'
        USING ERRCODE = '25006';
END;
$$;

CREATE FUNCTION platform.reject_admin_audit_truncate() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'admin audit cannot be truncated'
        USING ERRCODE = '25006';
END;
$$;

CREATE FUNCTION platform.reject_admin_password_reset_mutation() RETURNS trigger
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

CREATE FUNCTION platform.reject_admin_password_reset_truncate() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'password resets cannot be truncated'
        USING ERRCODE = '25006';
END;
$$;

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

CREATE FUNCTION platform.wipe_tenant(target_slug text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'platform', 'pg_catalog'
    AS $_$
DECLARE
    schema_name text;
    poster text;
    reset_present boolean;
    mall_id uuid;
BEGIN
    IF target_slug IS NULL OR target_slug !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RAISE EXCEPTION 'refusing to remove %', target_slug;
    END IF;
    SELECT tenants.id, tenants.schema_name INTO mall_id, schema_name
    FROM platform.tenants AS tenants
    WHERE tenants.slug = target_slug;
    IF schema_name IS NULL THEN
        RETURN;
    END IF;
    IF schema_name !~ '^tenant_[0-9a-f]{32}$' THEN
        RAISE EXCEPTION 'refusing to remove %', schema_name;
    END IF;
    SELECT EXISTS (
        SELECT 1
        FROM platform.password_resets AS resets
        WHERE resets.tenant_id = mall_id
        LIMIT 1
    ) INTO reset_present;
    IF reset_present THEN
        RAISE EXCEPTION 'a password reset still names this mall';
    END IF;
    poster := CASE
        WHEN target_slug = 'mall' THEN 'stall_poster'
        ELSE 'poster_' || substr(schema_name, length('tenant_') + 1)
    END;
    DELETE FROM platform.jobs WHERE tenant = target_slug;
    DELETE FROM platform.sign_in_directory WHERE tenant_id = mall_id;
    DELETE FROM platform.owner_sign_in_failures WHERE tenant_id = mall_id;
    DELETE FROM platform.password_reset_requests WHERE tenant_id = mall_id;
    DELETE FROM platform.owners WHERE tenant_id = mall_id;
    DELETE FROM platform.tenants WHERE slug = target_slug;
    EXECUTE format('DROP SCHEMA %I CASCADE', schema_name);
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = poster) THEN
        RETURN;
    END IF;
    IF EXISTS (
        SELECT 1
        FROM pg_auth_members AS membership
        JOIN pg_roles AS granted ON granted.oid = membership.roleid
        JOIN pg_roles AS login ON login.oid = membership.member
        WHERE granted.rolname = poster AND login.rolname = 'stall_app'
    ) THEN
        EXECUTE format('REVOKE %I FROM stall_app', poster);
    END IF;
    EXECUTE format('GRANT %I TO CURRENT_USER', poster);
    EXECUTE format('DROP OWNED BY %I', poster);
    EXECUTE format('DROP ROLE %I', poster);
END;
$_$;

CREATE TABLE platform.admin_audit (
    id uuid DEFAULT uuidv7() NOT NULL,
    admin_id uuid,
    admin_email text NOT NULL,
    action text NOT NULL,
    outcome text NOT NULL,
    tenant_id uuid,
    slug text,
    detail jsonb DEFAULT '{}'::jsonb NOT NULL,
    request_id text NOT NULL,
    client_address text DEFAULT ''::text NOT NULL,
    at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT admin_audit_action_chk CHECK ((action = ANY (ARRAY['admin.sign_in'::text, 'admin.sign_in_refused'::text, 'admin.password_change'::text, 'admin.password_reset_request'::text, 'admin.password_reset'::text, 'tenant.enter_as'::text, 'tenant.enter_as_refused'::text, 'tenant.delete'::text, 'tenant.delete_refused'::text, 'store.signup_toggle'::text, 'store.signup'::text]))),
    CONSTRAINT admin_audit_address_chk CHECK ((char_length(client_address) <= 64)),
    CONSTRAINT admin_audit_email_chk CHECK (((admin_email = btrim(admin_email)) AND (char_length(admin_email) <= 200) AND (admin_email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text))),
    CONSTRAINT admin_audit_ok_admin_chk CHECK (((outcome <> 'ok'::text) OR (admin_id IS NOT NULL) OR (action = 'store.signup'::text))),
    CONSTRAINT admin_audit_outcome_chk CHECK ((outcome = ANY (ARRAY['ok'::text, 'refused'::text, 'failed'::text]))),
    CONSTRAINT admin_audit_request_chk CHECK (((request_id = btrim(request_id)) AND (request_id <> ''::text))),
    CONSTRAINT admin_audit_slug_chk CHECK (((slug IS NULL) OR ((slug = btrim(slug)) AND (slug ~ '^[a-z][a-z0-9-]{0,62}$'::text))))
);

CREATE TABLE platform.admin_password_reset_requests (
    id uuid DEFAULT uuidv7() NOT NULL,
    email text NOT NULL,
    requested_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT admin_password_reset_requests_email_chk CHECK (((email = btrim(email)) AND (char_length(email) <= 200) AND (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text)))
);

CREATE TABLE platform.admin_password_resets (
    id uuid DEFAULT uuidv7() NOT NULL,
    account_id uuid NOT NULL,
    token_hash text NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    used_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT admin_password_resets_hash_chk CHECK ((token_hash ~ '^[0-9a-f]{64}$'::text))
);

CREATE TABLE platform.admin_sign_in_failures (
    id uuid DEFAULT uuidv7() NOT NULL,
    email text NOT NULL,
    failed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    client_address text DEFAULT ''::text NOT NULL,
    CONSTRAINT admin_sign_in_failures_address_chk CHECK ((char_length(client_address) <= 64)),
    CONSTRAINT admin_sign_in_failures_email_chk CHECK (((email = btrim(email)) AND (char_length(email) <= 200) AND (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text)))
);

CREATE TABLE platform.admins (
    id uuid DEFAULT uuidv7() NOT NULL,
    email text NOT NULL,
    password_hash text NOT NULL,
    credentials_version integer DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT admins_credentials_version_chk CHECK ((credentials_version >= 1)),
    CONSTRAINT admins_email_chk CHECK (((email = btrim(email)) AND (char_length(email) <= 200) AND (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text))),
    CONSTRAINT admins_hash_chk CHECK ((password_hash <> ''::text))
);

CREATE TABLE platform.email_sign_in_failures (
    id uuid DEFAULT uuidv7() NOT NULL,
    email text NOT NULL,
    failed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    client_address text DEFAULT ''::text NOT NULL,
    CONSTRAINT email_sign_in_failures_address_chk CHECK ((char_length(client_address) <= 64)),
    CONSTRAINT email_sign_in_failures_email_chk CHECK (((email = btrim(email)) AND (char_length(email) <= 200) AND (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text)))
);

CREATE TABLE platform.jobs (
    id uuid DEFAULT uuidv7() NOT NULL,
    tenant text NOT NULL,
    kind text NOT NULL,
    payload jsonb DEFAULT '{}'::jsonb NOT NULL,
    run_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    attempts integer DEFAULT 0 NOT NULL,
    locked_by text,
    locked_at timestamp with time zone,
    finished_at timestamp with time zone,
    error text,
    CONSTRAINT jobs_attempts_chk CHECK ((attempts >= 0)),
    CONSTRAINT jobs_kind_chk CHECK ((kind = ANY (ARRAY['assess-late-fees'::text, 'vendor-digest'::text, 'rent-reminder'::text, 'reconcile-reports'::text, 'sale-notice'::text, 'vendor-letter'::text, 'markdown-propose'::text]))),
    CONSTRAINT jobs_tenant_chk CHECK (((tenant = btrim(tenant)) AND (tenant ~ '^[a-z][a-z0-9-]{0,62}$'::text)))
);

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

CREATE TABLE platform.settings (
    key text NOT NULL,
    value jsonb NOT NULL,
    CONSTRAINT settings_key_chk CHECK (((key = btrim(key)) AND (key <> ''::text) AND (char_length(key) <= 100)))
);

CREATE TABLE platform.sign_in_directory (
    email text NOT NULL,
    tenant_id uuid NOT NULL,
    account_kind text NOT NULL,
    account_id uuid NOT NULL,
    username text NOT NULL,
    CONSTRAINT sign_in_directory_email_chk CHECK (((email = btrim(email)) AND (char_length(email) <= 200) AND (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text))),
    CONSTRAINT sign_in_directory_kind_chk CHECK ((account_kind = ANY (ARRAY['owner'::text, 'staff'::text]))),
    CONSTRAINT sign_in_directory_username_chk CHECK (((username = btrim(username)) AND (username ~ '^[a-z][a-z0-9-]{0,62}$'::text)))
);

CREATE TABLE platform.signup_attempts (
    id uuid DEFAULT uuidv7() NOT NULL,
    client_address text DEFAULT ''::text NOT NULL,
    attempted_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT signup_attempts_address_chk CHECK ((char_length(client_address) <= 64))
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

ALTER TABLE ONLY platform.admin_audit
    ADD CONSTRAINT admin_audit_pkey PRIMARY KEY (id);

ALTER TABLE ONLY platform.admin_password_reset_requests
    ADD CONSTRAINT admin_password_reset_requests_pkey PRIMARY KEY (id);

ALTER TABLE ONLY platform.admin_password_resets
    ADD CONSTRAINT admin_password_resets_hash_key UNIQUE (token_hash);

ALTER TABLE ONLY platform.admin_password_resets
    ADD CONSTRAINT admin_password_resets_pkey PRIMARY KEY (id);

ALTER TABLE ONLY platform.admin_sign_in_failures
    ADD CONSTRAINT admin_sign_in_failures_pkey PRIMARY KEY (id);

ALTER TABLE ONLY platform.admins
    ADD CONSTRAINT admins_email_key UNIQUE (email);

ALTER TABLE ONLY platform.admins
    ADD CONSTRAINT admins_pkey PRIMARY KEY (id);

ALTER TABLE ONLY platform.email_sign_in_failures
    ADD CONSTRAINT email_sign_in_failures_pkey PRIMARY KEY (id);

ALTER TABLE ONLY platform.jobs
    ADD CONSTRAINT jobs_pkey PRIMARY KEY (id);

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

ALTER TABLE ONLY platform.settings
    ADD CONSTRAINT settings_pkey PRIMARY KEY (key);

ALTER TABLE ONLY platform.sign_in_directory
    ADD CONSTRAINT sign_in_directory_pkey PRIMARY KEY (account_kind, account_id);

ALTER TABLE ONLY platform.signup_attempts
    ADD CONSTRAINT signup_attempts_pkey PRIMARY KEY (id);

ALTER TABLE ONLY platform.tenants
    ADD CONSTRAINT tenants_pkey PRIMARY KEY (id);

ALTER TABLE ONLY platform.tenants
    ADD CONSTRAINT tenants_schema_key UNIQUE (schema_name);

ALTER TABLE ONLY platform.tenants
    ADD CONSTRAINT tenants_slug_key UNIQUE (slug);

CREATE INDEX admin_audit_at ON platform.admin_audit USING btree (at DESC);

CREATE INDEX admin_password_reset_requests_window ON platform.admin_password_reset_requests USING btree (email, requested_at);

CREATE INDEX admin_sign_in_failures_window ON platform.admin_sign_in_failures USING btree (email, client_address, failed_at);

CREATE INDEX email_sign_in_failures_address ON platform.email_sign_in_failures USING btree (client_address, failed_at);

CREATE INDEX email_sign_in_failures_stale ON platform.email_sign_in_failures USING btree (failed_at);

CREATE INDEX email_sign_in_failures_window ON platform.email_sign_in_failures USING btree (email, client_address, failed_at);

CREATE UNIQUE INDEX jobs_tenant_kind_day_key ON platform.jobs USING btree (tenant, kind, ((payload ->> 'date'::text))) WHERE ((payload ->> 'letter'::text) IS NULL);

CREATE UNIQUE INDEX jobs_tenant_kind_letter_key ON platform.jobs USING btree (tenant, kind, ((payload ->> 'letter'::text))) WHERE ((payload ->> 'letter'::text) IS NOT NULL);

CREATE INDEX owner_sign_in_failures_window ON platform.owner_sign_in_failures USING btree (tenant_id, username, client_address, failed_at);

CREATE UNIQUE INDEX owners_email_key ON platform.owners USING btree (lower(email)) WHERE (email IS NOT NULL);

CREATE INDEX password_reset_requests_window ON platform.password_reset_requests USING btree (tenant_id, username, requested_at);

CREATE INDEX password_resets_account ON platform.password_resets USING btree (tenant_id, account_kind, account_id);

CREATE UNIQUE INDEX sign_in_directory_email_key ON platform.sign_in_directory USING btree (lower(email));

CREATE INDEX signup_attempts_window ON platform.signup_attempts USING btree (client_address, attempted_at);

CREATE TRIGGER admin_audit_append_only BEFORE DELETE OR UPDATE ON platform.admin_audit FOR EACH ROW EXECUTE FUNCTION platform.reject_admin_audit_mutation();

ALTER TABLE platform.admin_audit ENABLE ALWAYS TRIGGER admin_audit_append_only;

CREATE TRIGGER admin_audit_no_truncate BEFORE TRUNCATE ON platform.admin_audit FOR EACH STATEMENT EXECUTE FUNCTION platform.reject_admin_audit_truncate();

ALTER TABLE platform.admin_audit ENABLE ALWAYS TRIGGER admin_audit_no_truncate;

CREATE TRIGGER admin_password_resets_append_only BEFORE DELETE OR UPDATE ON platform.admin_password_resets FOR EACH ROW EXECUTE FUNCTION platform.reject_admin_password_reset_mutation();

ALTER TABLE platform.admin_password_resets ENABLE ALWAYS TRIGGER admin_password_resets_append_only;

CREATE TRIGGER admin_password_resets_no_truncate BEFORE TRUNCATE ON platform.admin_password_resets FOR EACH STATEMENT EXECUTE FUNCTION platform.reject_admin_password_reset_truncate();

ALTER TABLE platform.admin_password_resets ENABLE ALWAYS TRIGGER admin_password_resets_no_truncate;

CREATE TRIGGER owner_backups_append_only BEFORE DELETE OR UPDATE ON platform.owner_backups FOR EACH ROW EXECUTE FUNCTION platform.reject_owner_backup_mutation();

ALTER TABLE platform.owner_backups ENABLE ALWAYS TRIGGER owner_backups_append_only;

CREATE TRIGGER owner_backups_assign_time BEFORE INSERT ON platform.owner_backups FOR EACH ROW EXECUTE FUNCTION platform.assign_owner_backup_time();

CREATE TRIGGER owner_backups_no_truncate BEFORE TRUNCATE ON platform.owner_backups FOR EACH STATEMENT EXECUTE FUNCTION platform.reject_owner_backup_truncate();

ALTER TABLE platform.owner_backups ENABLE ALWAYS TRIGGER owner_backups_no_truncate;

CREATE TRIGGER owners_sign_in_directory AFTER INSERT OR UPDATE OF email, username, tenant_id ON platform.owners FOR EACH ROW EXECUTE FUNCTION platform.owners_sign_in_directory();

CREATE TRIGGER owners_sign_in_directory_delete AFTER DELETE ON platform.owners FOR EACH ROW EXECUTE FUNCTION platform.owners_sign_in_directory();

CREATE TRIGGER password_resets_append_only BEFORE DELETE OR UPDATE ON platform.password_resets FOR EACH ROW EXECUTE FUNCTION platform.reject_password_reset_mutation();

ALTER TABLE platform.password_resets ENABLE ALWAYS TRIGGER password_resets_append_only;

CREATE TRIGGER password_resets_no_truncate BEFORE TRUNCATE ON platform.password_resets FOR EACH STATEMENT EXECUTE FUNCTION platform.reject_password_reset_truncate();

ALTER TABLE platform.password_resets ENABLE ALWAYS TRIGGER password_resets_no_truncate;

ALTER TABLE ONLY platform.admin_audit
    ADD CONSTRAINT admin_audit_admin_id_fkey FOREIGN KEY (admin_id) REFERENCES platform.admins(id);

ALTER TABLE ONLY platform.admin_password_resets
    ADD CONSTRAINT admin_password_resets_account_id_fkey FOREIGN KEY (account_id) REFERENCES platform.admins(id);

ALTER TABLE ONLY platform.owners
    ADD CONSTRAINT owners_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES platform.tenants(id);

ALTER TABLE ONLY platform.password_reset_requests
    ADD CONSTRAINT password_reset_requests_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES platform.tenants(id);

ALTER TABLE ONLY platform.password_resets
    ADD CONSTRAINT password_resets_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES platform.tenants(id);

ALTER TABLE ONLY platform.sign_in_directory
    ADD CONSTRAINT sign_in_directory_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES platform.tenants(id);

SELECT platform.create_poster('stall_poster');

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

GRANT SELECT ON TABLE platform.jobs TO stall_app;

GRANT SELECT ON TABLE platform.jobs TO stall_poster;

GRANT INSERT ON TABLE platform.jobs TO stall_app;

GRANT INSERT ON TABLE platform.jobs TO stall_poster;

GRANT SELECT, INSERT, DELETE ON TABLE platform.owner_sign_in_failures TO stall_poster;

GRANT USAGE ON SCHEMA platform TO stall_worker;

GRANT SELECT, INSERT, UPDATE ON TABLE platform.jobs TO stall_worker;

GRANT SELECT ON TABLE platform.tenants TO stall_worker;

REVOKE SELECT, INSERT, DELETE ON TABLE platform.owner_sign_in_failures FROM stall_poster;

REVOKE ALL ON FUNCTION platform.enqueue_vendor_letter(date, uuid) FROM PUBLIC;

GRANT EXECUTE ON FUNCTION platform.enqueue_vendor_letter(date, uuid) TO stall_app;

GRANT EXECUTE ON FUNCTION platform.enqueue_vendor_letter(date, uuid) TO stall_poster;

REVOKE ALL ON FUNCTION platform.wipe_tenant(text) FROM PUBLIC;

GRANT EXECUTE ON FUNCTION platform.wipe_tenant(text) TO stall_app;

GRANT SELECT ON TABLE platform.admins TO stall_app;

GRANT UPDATE (password_hash) ON TABLE platform.admins TO stall_app;

GRANT UPDATE (credentials_version) ON TABLE platform.admins TO stall_app;

GRANT SELECT, INSERT, DELETE ON TABLE platform.admin_sign_in_failures TO stall_app;

GRANT SELECT, INSERT ON TABLE platform.admin_password_reset_requests TO stall_app;

GRANT SELECT, INSERT ON TABLE platform.admin_password_resets TO stall_app;

GRANT UPDATE (used_at) ON TABLE platform.admin_password_resets TO stall_app;

GRANT SELECT, INSERT ON TABLE platform.admin_audit TO stall_app;

REVOKE ALL ON FUNCTION platform.put_sign_in_directory(text, uuid, text, uuid, text) FROM PUBLIC;

REVOKE ALL ON FUNCTION platform.clear_sign_in_directory(text, uuid) FROM PUBLIC;

GRANT EXECUTE ON FUNCTION platform.put_sign_in_directory(text, uuid, text, uuid, text) TO stall_app;

GRANT EXECUTE ON FUNCTION platform.clear_sign_in_directory(text, uuid) TO stall_app;

GRANT SELECT ON TABLE platform.sign_in_directory TO stall_app;

GRANT SELECT, INSERT, DELETE ON TABLE platform.email_sign_in_failures TO stall_app;

CREATE EVENT TRIGGER platform_reject_admin_append_only_change ON ddl_command_end WHEN TAG IN ('ALTER FUNCTION', 'ALTER ROUTINE', 'ALTER TABLE', 'CREATE FUNCTION')
    EXECUTE FUNCTION platform.reject_admin_append_only_guard();

ALTER EVENT TRIGGER platform_reject_admin_append_only_change ENABLE ALWAYS;

CREATE EVENT TRIGGER platform_reject_admin_append_only_drop ON sql_drop WHEN TAG IN ('DROP FUNCTION', 'DROP TRIGGER')
    EXECUTE FUNCTION platform.reject_admin_append_only_guard();

ALTER EVENT TRIGGER platform_reject_admin_append_only_drop ENABLE ALWAYS;

CREATE EVENT TRIGGER platform_reject_append_only_trigger_change ON ddl_command_end WHEN TAG IN ('ALTER TABLE')
    EXECUTE FUNCTION platform.reject_append_only_trigger_change();

ALTER EVENT TRIGGER platform_reject_append_only_trigger_change ENABLE ALWAYS;

CREATE EVENT TRIGGER platform_reject_append_only_trigger_drop ON sql_drop WHEN TAG IN ('DROP TRIGGER')
    EXECUTE FUNCTION platform.reject_append_only_trigger_change();

ALTER EVENT TRIGGER platform_reject_append_only_trigger_drop ENABLE ALWAYS;

CREATE EVENT TRIGGER platform_reject_guard_function_again ON ddl_command_end WHEN TAG IN ('CREATE FUNCTION')
    EXECUTE FUNCTION platform.reject_guard_function_again();

ALTER EVENT TRIGGER platform_reject_guard_function_again ENABLE ALWAYS;

CREATE EVENT TRIGGER platform_reject_guard_function_replace ON ddl_command_end WHEN TAG IN ('CREATE FUNCTION')
    EXECUTE FUNCTION platform.reject_guard_function_replace();

ALTER EVENT TRIGGER platform_reject_guard_function_replace ENABLE ALWAYS;

CREATE EVENT TRIGGER platform_reject_password_reset_change ON ddl_command_end WHEN TAG IN ('ALTER FUNCTION', 'ALTER ROUTINE', 'ALTER TABLE', 'CREATE FUNCTION')
    EXECUTE FUNCTION platform.reject_password_reset_guard();

ALTER EVENT TRIGGER platform_reject_password_reset_change ENABLE ALWAYS;

CREATE EVENT TRIGGER platform_reject_password_reset_drop ON sql_drop WHEN TAG IN ('DROP FUNCTION', 'DROP TRIGGER')
    EXECUTE FUNCTION platform.reject_password_reset_guard();

ALTER EVENT TRIGGER platform_reject_password_reset_drop ENABLE ALWAYS;


-- >>> folded from 055_store_signup.sql
-- Public store signup settings, owner email verification, and rate-limit storage.

INSERT INTO platform.settings (key, value)
VALUES
    ('store_signup_enabled', 'false'::jsonb),
    ('store_signup_require_email_verification', 'true'::jsonb)
ON CONFLICT (key) DO NOTHING;

ALTER TABLE platform.owners
    ADD COLUMN IF NOT EXISTS email_verified_at timestamp with time zone;

UPDATE platform.owners
SET email_verified_at = created_at
WHERE email_verified_at IS NULL;

CREATE TABLE IF NOT EXISTS platform.owner_email_verifications (
    id uuid DEFAULT uuidv7() NOT NULL,
    owner_id uuid NOT NULL,
    token_hash text NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    used_at timestamp with time zone,
    CONSTRAINT owner_email_verifications_pkey PRIMARY KEY (id),
    CONSTRAINT owner_email_verifications_hash_key UNIQUE (token_hash),
    CONSTRAINT owner_email_verifications_hash_chk CHECK (token_hash ~ '^[0-9a-f]{64}$'::text),
    CONSTRAINT owner_email_verifications_owner_id_fkey
        FOREIGN KEY (owner_id) REFERENCES platform.owners (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS owner_email_verifications_owner
    ON platform.owner_email_verifications USING btree (owner_id);

CREATE OR REPLACE FUNCTION platform.reject_owner_email_verification_mutation() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'an email verification cannot be removed'
            USING ERRCODE = '25006';
    END IF;
    IF OLD.used_at IS NOT NULL THEN
        RAISE EXCEPTION 'an email verification was already used'
            USING ERRCODE = '25006';
    END IF;
    IF NEW.used_at IS NULL
        OR NEW.id IS DISTINCT FROM OLD.id
        OR NEW.owner_id IS DISTINCT FROM OLD.owner_id
        OR NEW.token_hash IS DISTINCT FROM OLD.token_hash
        OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'an email verification cannot be rewritten'
            USING ERRCODE = '25006';
    END IF;

    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION platform.reject_owner_email_verification_truncate() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'email verifications cannot be truncated'
        USING ERRCODE = '25006';
END;
$$;

CREATE OR REPLACE FUNCTION platform.reject_owner_email_verification_guard() RETURNS event_trigger
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
                AND right(dropped.object_identity, length(' on platform.owner_email_verifications'))
                    = ' on platform.owner_email_verifications' THEN
                RAISE EXCEPTION 'append-only triggers cannot be dropped'
                    USING ERRCODE = '25006';
            END IF;
            IF dropped.object_type = 'function'
                AND dropped.object_identity IN (
                    'platform.reject_owner_email_verification_mutation()',
                    'platform.reject_owner_email_verification_truncate()',
                    'platform.reject_owner_email_verification_guard()'
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
            AND command.objid IS NOT DISTINCT FROM to_regclass('platform.owner_email_verifications')
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
                'platform.reject_owner_email_verification_mutation()'::regprocedure,
                'platform.reject_owner_email_verification_truncate()'::regprocedure,
                'platform.reject_owner_email_verification_guard()'::regprocedure
            ) THEN
            RAISE EXCEPTION 'a guard function cannot be replaced'
                USING ERRCODE = '25006';
        END IF;
    END LOOP;
END;
$_$;

DROP TRIGGER IF EXISTS owner_email_verifications_append_only ON platform.owner_email_verifications;
CREATE TRIGGER owner_email_verifications_append_only
    BEFORE DELETE OR UPDATE ON platform.owner_email_verifications
    FOR EACH ROW EXECUTE FUNCTION platform.reject_owner_email_verification_mutation();

ALTER TABLE platform.owner_email_verifications ENABLE ALWAYS TRIGGER owner_email_verifications_append_only;

DROP TRIGGER IF EXISTS owner_email_verifications_no_truncate ON platform.owner_email_verifications;
CREATE TRIGGER owner_email_verifications_no_truncate
    BEFORE TRUNCATE ON platform.owner_email_verifications
    FOR EACH STATEMENT EXECUTE FUNCTION platform.reject_owner_email_verification_truncate();

ALTER TABLE platform.owner_email_verifications ENABLE ALWAYS TRIGGER owner_email_verifications_no_truncate;

DROP EVENT TRIGGER IF EXISTS platform_reject_owner_email_verification_change;
CREATE EVENT TRIGGER platform_reject_owner_email_verification_change
    ON ddl_command_end
    WHEN TAG IN ('ALTER FUNCTION', 'ALTER ROUTINE', 'ALTER TABLE', 'CREATE FUNCTION')
    EXECUTE FUNCTION platform.reject_owner_email_verification_guard();

ALTER EVENT TRIGGER platform_reject_owner_email_verification_change ENABLE ALWAYS;

DROP EVENT TRIGGER IF EXISTS platform_reject_owner_email_verification_drop;
CREATE EVENT TRIGGER platform_reject_owner_email_verification_drop
    ON sql_drop
    WHEN TAG IN ('DROP FUNCTION', 'DROP TRIGGER')
    EXECUTE FUNCTION platform.reject_owner_email_verification_guard();

ALTER EVENT TRIGGER platform_reject_owner_email_verification_drop ENABLE ALWAYS;

ALTER TABLE platform.admin_audit
    DROP CONSTRAINT IF EXISTS admin_audit_action_chk;

ALTER TABLE platform.admin_audit
    ADD CONSTRAINT admin_audit_action_chk CHECK (action = ANY (ARRAY[
        'admin.sign_in'::text,
        'admin.sign_in_refused'::text,
        'admin.password_change'::text,
        'admin.password_reset_request'::text,
        'admin.password_reset'::text,
        'tenant.enter_as'::text,
        'tenant.enter_as_refused'::text,
        'tenant.delete'::text,
        'tenant.delete_refused'::text,
        'store.signup_toggle'::text,
        'store.signup'::text,
        'store.admin_create'::text,
        'store.email_verified'::text
    ]));

ALTER TABLE platform.admin_audit
    DROP CONSTRAINT IF EXISTS admin_audit_ok_admin_chk;

ALTER TABLE platform.admin_audit
    ADD CONSTRAINT admin_audit_ok_admin_chk CHECK (
        (outcome <> 'ok'::text)
        OR (admin_id IS NOT NULL)
        OR (action = ANY (ARRAY['store.signup'::text, 'store.email_verified'::text]))
    );

REVOKE ALL ON TABLE platform.settings FROM PUBLIC;
REVOKE ALL ON TABLE platform.signup_attempts FROM PUBLIC;
REVOKE ALL ON TABLE platform.owner_email_verifications FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.reject_owner_email_verification_mutation() FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.reject_owner_email_verification_truncate() FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.reject_owner_email_verification_guard() FROM PUBLIC;

GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE platform.settings TO stall_app;
GRANT SELECT, INSERT, DELETE ON TABLE platform.signup_attempts TO stall_app;
GRANT SELECT, INSERT ON TABLE platform.owner_email_verifications TO stall_app;
GRANT UPDATE (used_at) ON TABLE platform.owner_email_verifications TO stall_app;
GRANT UPDATE (email_verified_at) ON TABLE platform.owners TO stall_app;
GRANT ALL ON FUNCTION platform.reject_owner_email_verification_mutation() TO stall_app;
GRANT ALL ON FUNCTION platform.reject_owner_email_verification_truncate() TO stall_app;


-- >>> folded from 056_wipe_email_verifications.sql
-- Allow platform.wipe_tenant to remove owner email verification rows for a mall.

CREATE OR REPLACE FUNCTION platform.wipe_tenant(target_slug text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'platform', 'pg_catalog'
    AS $_$
DECLARE
    schema_name text;
    poster text;
    reset_present boolean;
    mall_id uuid;
BEGIN
    IF target_slug IS NULL OR target_slug !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RAISE EXCEPTION 'refusing to remove %', target_slug;
    END IF;
    SELECT tenants.id, tenants.schema_name INTO mall_id, schema_name
    FROM platform.tenants AS tenants
    WHERE tenants.slug = target_slug;
    IF schema_name IS NULL THEN
        RETURN;
    END IF;
    IF schema_name !~ '^tenant_[0-9a-f]{32}$' THEN
        RAISE EXCEPTION 'refusing to remove %', schema_name;
    END IF;
    SELECT EXISTS (
        SELECT 1
        FROM platform.password_resets AS resets
        WHERE resets.tenant_id = mall_id
        LIMIT 1
    ) INTO reset_present;
    IF reset_present THEN
        RAISE EXCEPTION 'a password reset still names this mall';
    END IF;
    poster := CASE
        WHEN target_slug = 'mall' THEN 'stall_poster'
        ELSE 'poster_' || substr(schema_name, length('tenant_') + 1)
    END;
    DELETE FROM platform.jobs WHERE tenant = target_slug;
    DELETE FROM platform.sign_in_directory WHERE tenant_id = mall_id;
    DELETE FROM platform.owner_sign_in_failures WHERE tenant_id = mall_id;
    DELETE FROM platform.password_reset_requests WHERE tenant_id = mall_id;
    IF to_regclass('platform.owner_email_verifications') IS NOT NULL THEN
        PERFORM set_config('event_triggers', 'off', true);
        ALTER TABLE platform.owner_email_verifications DISABLE TRIGGER owner_email_verifications_append_only;
        ALTER TABLE platform.owner_email_verifications DISABLE TRIGGER owner_email_verifications_no_truncate;
        DELETE FROM platform.owner_email_verifications
        WHERE owner_id IN (SELECT id FROM platform.owners WHERE tenant_id = mall_id);
        ALTER TABLE platform.owner_email_verifications ENABLE ALWAYS TRIGGER owner_email_verifications_append_only;
        ALTER TABLE platform.owner_email_verifications ENABLE ALWAYS TRIGGER owner_email_verifications_no_truncate;
        PERFORM set_config('event_triggers', 'on', true);
    END IF;
    DELETE FROM platform.owners WHERE tenant_id = mall_id;
    DELETE FROM platform.tenants WHERE slug = target_slug;
    EXECUTE format('DROP SCHEMA %I CASCADE', schema_name);
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = poster) THEN
        RETURN;
    END IF;
    IF EXISTS (
        SELECT 1
        FROM pg_auth_members AS membership
        JOIN pg_roles AS granted ON granted.oid = membership.roleid
        JOIN pg_roles AS login ON login.oid = membership.member
        WHERE granted.rolname = poster AND login.rolname = 'stall_app'
    ) THEN
        EXECUTE format('REVOKE %I FROM stall_app', poster);
    END IF;
    EXECUTE format('GRANT %I TO CURRENT_USER', poster);
    EXECUTE format('DROP OWNED BY %I', poster);
    EXECUTE format('DROP ROLE %I', poster);
END;
$_$;

REVOKE ALL ON FUNCTION platform.wipe_tenant(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION platform.wipe_tenant(text) TO stall_app;


-- >>> folded from 057_mail_outbox.sql
-- One outbound mail door: file to platform.mail_outbox, deliver on the worker.

CREATE TABLE platform.mail_outbox (
    id uuid DEFAULT uuidv7() NOT NULL,
    tenant text NOT NULL,
    mailbox text NOT NULL,
    purpose text NOT NULL,
    address text NOT NULL,
    subject text NOT NULL,
    body text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    sent_at timestamp with time zone,
    CONSTRAINT mail_outbox_pkey PRIMARY KEY (id),
    CONSTRAINT mail_outbox_tenant_chk CHECK (((tenant = btrim(tenant)) AND (tenant ~ '^[a-z][a-z0-9-]{0,62}$'::text))),
    CONSTRAINT mail_outbox_mailbox_chk CHECK ((mailbox = ANY (ARRAY['mall'::text, 'platform'::text]))),
    CONSTRAINT mail_outbox_purpose_chk CHECK (((purpose = btrim(purpose)) AND (char_length(purpose) >= 1) AND (char_length(purpose) <= 64))),
    CONSTRAINT mail_outbox_address_chk CHECK (((address = btrim(address)) AND (char_length(address) <= 200) AND (address ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text))),
    CONSTRAINT mail_outbox_subject_chk CHECK (((char_length(subject) >= 1) AND (char_length(subject) <= 200) AND (subject !~ '[\r\n]'::text))),
    CONSTRAINT mail_outbox_body_chk CHECK (((char_length(body) >= 1) AND (char_length(body) <= 20000)))
);

CREATE INDEX mail_outbox_waiting
    ON platform.mail_outbox (created_at)
    WHERE (sent_at IS NULL);

ALTER TABLE platform.jobs DROP CONSTRAINT jobs_kind_chk;
ALTER TABLE platform.jobs ADD CONSTRAINT jobs_kind_chk CHECK (kind = ANY (ARRAY[
    'assess-late-fees'::text,
    'vendor-digest'::text,
    'rent-reminder'::text,
    'reconcile-reports'::text,
    'sale-notice'::text,
    'vendor-letter'::text,
    'markdown-propose'::text,
    'outbound-mail'::text
]));

DROP INDEX IF EXISTS platform.jobs_tenant_kind_day_key;
CREATE UNIQUE INDEX jobs_tenant_kind_day_key
    ON platform.jobs USING btree (tenant, kind, ((payload ->> 'date'::text)))
    WHERE (((payload ->> 'letter'::text) IS NULL) AND ((payload ->> 'mail'::text) IS NULL));

CREATE UNIQUE INDEX IF NOT EXISTS jobs_tenant_kind_mail_key
    ON platform.jobs USING btree (tenant, kind, ((payload ->> 'mail'::text)))
    WHERE ((payload ->> 'mail'::text) IS NOT NULL);

CREATE FUNCTION platform.file_outbound_mail(
    p_tenant text,
    p_mailbox text,
    p_purpose text,
    p_address text,
    p_subject text,
    p_body text
) RETURNS uuid
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $_$
DECLARE
    poster text;
    tenant_slug text;
    mail_id uuid;
    today text;
BEGIN
    IF p_mailbox IS NULL OR p_mailbox NOT IN ('mall', 'platform') THEN
        RAISE EXCEPTION 'the mailbox is not a mailbox'
            USING ERRCODE = '23514';
    END IF;
    IF p_tenant IS NULL OR p_tenant !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RAISE EXCEPTION 'the mall is not a mall'
            USING ERRCODE = '23514';
    END IF;
    poster := nullif(current_setting('role', true), 'none');
    IF p_mailbox = 'platform' THEN
        IF poster IS NOT NULL AND poster <> '' THEN
            RAISE EXCEPTION 'platform mail is not filed as a poster'
                USING ERRCODE = '42501';
        END IF;
        IF p_tenant <> 'platform' THEN
            RAISE EXCEPTION 'platform mail names platform'
                USING ERRCODE = '23514';
        END IF;
        tenant_slug := 'platform';
    ELSIF poster IS NULL OR poster = '' THEN
        tenant_slug := p_tenant;
    ELSIF poster = 'stall_poster' THEN
        tenant_slug := 'mall';
        IF p_tenant <> tenant_slug THEN
            RAISE EXCEPTION 'a poster may only file mail for its own mall'
                USING ERRCODE = '42501';
        END IF;
    ELSIF poster ~ '^poster_[0-9a-f]{32}$' THEN
        SELECT slug INTO tenant_slug
        FROM platform.tenants
        WHERE schema_name = 'tenant_' || substr(poster, 8);
        IF tenant_slug IS NULL OR tenant_slug <> p_tenant THEN
            RAISE EXCEPTION 'a poster may only file mail for its own mall'
                USING ERRCODE = '42501';
        END IF;
    ELSE
        RAISE EXCEPTION 'a poster role must file the mail'
            USING ERRCODE = '42501';
    END IF;
    INSERT INTO platform.mail_outbox (tenant, mailbox, purpose, address, subject, body)
    VALUES (tenant_slug, p_mailbox, p_purpose, lower(p_address), p_subject, p_body)
    RETURNING id INTO mail_id;
    today := to_char((CURRENT_TIMESTAMP AT TIME ZONE 'UTC')::date, 'YYYY-MM-DD');
    INSERT INTO platform.jobs (tenant, kind, payload, run_at)
    VALUES (
        tenant_slug,
        'outbound-mail',
        jsonb_build_object('date', today, 'mail', mail_id::text),
        clock_timestamp()
    )
    ON CONFLICT (tenant, kind, (payload ->> 'mail')) WHERE ((payload ->> 'mail') IS NOT NULL) DO NOTHING;
    RETURN mail_id;
END;
$_$;

REVOKE ALL ON FUNCTION platform.file_outbound_mail(text, text, text, text, text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION platform.file_outbound_mail(text, text, text, text, text, text) TO stall_app;
GRANT EXECUTE ON FUNCTION platform.file_outbound_mail(text, text, text, text, text, text) TO stall_poster;

GRANT SELECT, INSERT ON TABLE platform.mail_outbox TO stall_app;
GRANT SELECT, UPDATE ON TABLE platform.mail_outbox TO stall_worker;
GRANT SELECT, UPDATE ON TABLE platform.mail_outbox TO stall_poster;

CREATE OR REPLACE FUNCTION platform.grant_poster_jobs(poster text) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $_$
BEGIN
    IF poster IS NULL OR poster !~ '^[a-z_][a-z0-9_]{0,62}$' THEN
        RAISE EXCEPTION 'a poster role name is required'
            USING ERRCODE = '23514';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = poster) THEN
        RAISE EXCEPTION 'poster role % is missing', poster
            USING ERRCODE = '42704';
    END IF;
    IF to_regclass('platform.jobs') IS NULL THEN
        RAISE EXCEPTION 'platform.jobs is missing'
            USING ERRCODE = '42P01';
    END IF;
    EXECUTE format('REVOKE INSERT ON TABLE platform.jobs FROM %I', poster);
    EXECUTE format('GRANT SELECT ON TABLE platform.jobs TO %I', poster);
    EXECUTE format('GRANT EXECUTE ON FUNCTION platform.enqueue_vendor_letter(date, uuid) TO %I', poster);
    EXECUTE format('GRANT EXECUTE ON FUNCTION platform.file_outbound_mail(text, text, text, text, text, text) TO %I', poster);
    EXECUTE format('GRANT SELECT, UPDATE ON TABLE platform.mail_outbox TO %I', poster);
END;
$_$;

DO $$
DECLARE
    poster text;
BEGIN
    FOR poster IN
        SELECT rolname
        FROM pg_roles
        WHERE rolname = 'stall_poster' OR rolname ~ '^poster_[0-9a-f]{32}$'
    LOOP
        PERFORM platform.grant_poster_jobs(poster);
    END LOOP;
END;
$$;

CREATE OR REPLACE FUNCTION platform.wipe_tenant(target_slug text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'platform', 'pg_catalog'
    AS $_$
DECLARE
    schema_name text;
    poster text;
    reset_present boolean;
    mall_id uuid;
BEGIN
    IF target_slug IS NULL OR target_slug !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RAISE EXCEPTION 'refusing to remove %', target_slug;
    END IF;
    SELECT tenants.id, tenants.schema_name INTO mall_id, schema_name
    FROM platform.tenants AS tenants
    WHERE tenants.slug = target_slug;
    IF schema_name IS NULL THEN
        RETURN;
    END IF;
    IF schema_name !~ '^tenant_[0-9a-f]{32}$' THEN
        RAISE EXCEPTION 'refusing to remove %', schema_name;
    END IF;
    SELECT EXISTS (
        SELECT 1
        FROM platform.password_resets AS resets
        WHERE resets.tenant_id = mall_id
        LIMIT 1
    ) INTO reset_present;
    IF reset_present THEN
        RAISE EXCEPTION 'a password reset still names this mall';
    END IF;
    poster := CASE
        WHEN target_slug = 'mall' THEN 'stall_poster'
        ELSE 'poster_' || substr(schema_name, length('tenant_') + 1)
    END;
    DELETE FROM platform.jobs WHERE tenant = target_slug;
    DELETE FROM platform.mail_outbox WHERE tenant = target_slug;
    DELETE FROM platform.sign_in_directory WHERE tenant_id = mall_id;
    DELETE FROM platform.owner_sign_in_failures WHERE tenant_id = mall_id;
    DELETE FROM platform.password_reset_requests WHERE tenant_id = mall_id;
    IF to_regclass('platform.owner_email_verifications') IS NOT NULL THEN
        PERFORM set_config('event_triggers', 'off', true);
        ALTER TABLE platform.owner_email_verifications DISABLE TRIGGER owner_email_verifications_append_only;
        ALTER TABLE platform.owner_email_verifications DISABLE TRIGGER owner_email_verifications_no_truncate;
        DELETE FROM platform.owner_email_verifications
        WHERE owner_id IN (SELECT id FROM platform.owners WHERE tenant_id = mall_id);
        ALTER TABLE platform.owner_email_verifications ENABLE ALWAYS TRIGGER owner_email_verifications_append_only;
        ALTER TABLE platform.owner_email_verifications ENABLE ALWAYS TRIGGER owner_email_verifications_no_truncate;
        PERFORM set_config('event_triggers', 'on', true);
    END IF;
    DELETE FROM platform.owners WHERE tenant_id = mall_id;
    DELETE FROM platform.tenants WHERE slug = target_slug;
    EXECUTE format('DROP SCHEMA %I CASCADE', schema_name);
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = poster) THEN
        RETURN;
    END IF;
    IF EXISTS (
        SELECT 1
        FROM pg_auth_members AS membership
        JOIN pg_roles AS granted ON granted.oid = membership.roleid
        JOIN pg_roles AS login ON login.oid = membership.member
        WHERE granted.rolname = poster AND login.rolname = 'stall_app'
    ) THEN
        EXECUTE format('REVOKE %I FROM stall_app', poster);
    END IF;
    EXECUTE format('GRANT %I TO CURRENT_USER', poster);
    EXECUTE format('DROP OWNED BY %I', poster);
    EXECUTE format('DROP ROLE %I', poster);
END;
$_$;

REVOKE ALL ON FUNCTION platform.wipe_tenant(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION platform.wipe_tenant(text) TO stall_app;


-- >>> folded from 058_worker_runs.sql
-- Record each php bin/work.php pass so operators can see the worker is alive.

CREATE TABLE platform.worker_runs (
    id uuid DEFAULT uuidv7() NOT NULL,
    worker text NOT NULL,
    started_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    finished_at timestamp with time zone,
    jobs_finished integer DEFAULT 0 NOT NULL,
    jobs_failed integer DEFAULT 0 NOT NULL,
    CONSTRAINT worker_runs_pkey PRIMARY KEY (id),
    CONSTRAINT worker_runs_worker_chk CHECK (
        ((worker = btrim(worker)) AND (char_length(worker) >= 1) AND (char_length(worker) <= 64))
    ),
    CONSTRAINT worker_runs_jobs_finished_chk CHECK ((jobs_finished >= 0)),
    CONSTRAINT worker_runs_jobs_failed_chk CHECK ((jobs_failed >= 0))
);

CREATE INDEX worker_runs_started_at_key
    ON platform.worker_runs (started_at DESC);

GRANT SELECT, DELETE ON TABLE platform.worker_runs TO stall_app;
GRANT SELECT, INSERT, UPDATE ON TABLE platform.worker_runs TO stall_worker;


-- >>> folded from 059_worker_heartbeat.sql
-- One heartbeat row per worker name so idle passes do not flood worker_runs.

CREATE TABLE platform.worker_heartbeat (
    worker text NOT NULL,
    seen_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    last_jobs_finished integer DEFAULT 0 NOT NULL,
    last_jobs_failed integer DEFAULT 0 NOT NULL,
    CONSTRAINT worker_heartbeat_pkey PRIMARY KEY (worker),
    CONSTRAINT worker_heartbeat_worker_chk CHECK (
        ((worker = btrim(worker)) AND (char_length(worker) >= 1) AND (char_length(worker) <= 64))
    ),
    CONSTRAINT worker_heartbeat_jobs_finished_chk CHECK ((last_jobs_finished >= 0)),
    CONSTRAINT worker_heartbeat_jobs_failed_chk CHECK ((last_jobs_failed >= 0))
);

GRANT SELECT ON TABLE platform.worker_heartbeat TO stall_app;
GRANT SELECT, INSERT, UPDATE ON TABLE platform.worker_heartbeat TO stall_worker;


-- >>> folded from 060_worker_runs_delete.sql
-- stall_worker auto-prunes platform.worker_runs on each php bin/work.php pass.

GRANT DELETE ON TABLE platform.worker_runs TO stall_worker;


-- >>> folded from 061_admin_app_log_audit.sql
-- Allow Admin → App log clear to be recorded on platform.admin_audit.

ALTER TABLE platform.admin_audit
    DROP CONSTRAINT IF EXISTS admin_audit_action_chk;

ALTER TABLE platform.admin_audit
    ADD CONSTRAINT admin_audit_action_chk CHECK (action = ANY (ARRAY[
        'admin.sign_in'::text,
        'admin.sign_in_refused'::text,
        'admin.password_change'::text,
        'admin.password_reset_request'::text,
        'admin.password_reset'::text,
        'tenant.enter_as'::text,
        'tenant.enter_as_refused'::text,
        'tenant.delete'::text,
        'tenant.delete_refused'::text,
        'store.signup_toggle'::text,
        'store.signup'::text,
        'store.admin_create'::text,
        'store.email_verified'::text,
        'app.log_clear'::text
    ]));


-- >>> folded from 062_wipe_password_resets.sql
-- Allow platform.wipe_tenant to remove password reset rows that name the mall.

CREATE OR REPLACE FUNCTION platform.wipe_tenant(target_slug text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'platform', 'pg_catalog'
    AS $_$
DECLARE
    schema_name text;
    poster text;
    mall_id uuid;
BEGIN
    IF target_slug IS NULL OR target_slug !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RAISE EXCEPTION 'refusing to remove %', target_slug;
    END IF;
    SELECT tenants.id, tenants.schema_name INTO mall_id, schema_name
    FROM platform.tenants AS tenants
    WHERE tenants.slug = target_slug;
    IF schema_name IS NULL THEN
        RETURN;
    END IF;
    IF schema_name !~ '^tenant_[0-9a-f]{32}$' THEN
        RAISE EXCEPTION 'refusing to remove %', schema_name;
    END IF;
    poster := CASE
        WHEN target_slug = 'mall' THEN 'stall_poster'
        ELSE 'poster_' || substr(schema_name, length('tenant_') + 1)
    END;
    DELETE FROM platform.jobs WHERE tenant = target_slug;
    DELETE FROM platform.mail_outbox WHERE tenant = target_slug;
    DELETE FROM platform.sign_in_directory WHERE tenant_id = mall_id;
    DELETE FROM platform.owner_sign_in_failures WHERE tenant_id = mall_id;
    DELETE FROM platform.password_reset_requests WHERE tenant_id = mall_id;
    PERFORM set_config('event_triggers', 'off', true);
    ALTER TABLE platform.password_resets DISABLE TRIGGER password_resets_append_only;
    ALTER TABLE platform.password_resets DISABLE TRIGGER password_resets_no_truncate;
    DELETE FROM platform.password_resets WHERE tenant_id = mall_id;
    ALTER TABLE platform.password_resets ENABLE ALWAYS TRIGGER password_resets_append_only;
    ALTER TABLE platform.password_resets ENABLE ALWAYS TRIGGER password_resets_no_truncate;
    IF to_regclass('platform.owner_email_verifications') IS NOT NULL THEN
        ALTER TABLE platform.owner_email_verifications DISABLE TRIGGER owner_email_verifications_append_only;
        ALTER TABLE platform.owner_email_verifications DISABLE TRIGGER owner_email_verifications_no_truncate;
        DELETE FROM platform.owner_email_verifications
        WHERE owner_id IN (SELECT id FROM platform.owners WHERE tenant_id = mall_id);
        ALTER TABLE platform.owner_email_verifications ENABLE ALWAYS TRIGGER owner_email_verifications_append_only;
        ALTER TABLE platform.owner_email_verifications ENABLE ALWAYS TRIGGER owner_email_verifications_no_truncate;
    END IF;
    PERFORM set_config('event_triggers', 'on', true);
    DELETE FROM platform.owners WHERE tenant_id = mall_id;
    DELETE FROM platform.tenants WHERE slug = target_slug;
    EXECUTE format('DROP SCHEMA %I CASCADE', schema_name);
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = poster) THEN
        RETURN;
    END IF;
    IF EXISTS (
        SELECT 1
        FROM pg_auth_members AS membership
        JOIN pg_roles AS granted ON granted.oid = membership.roleid
        JOIN pg_roles AS login ON login.oid = membership.member
        WHERE granted.rolname = poster AND login.rolname = 'stall_app'
    ) THEN
        EXECUTE format('REVOKE %I FROM stall_app', poster);
    END IF;
    EXECUTE format('GRANT %I TO CURRENT_USER', poster);
    EXECUTE format('DROP OWNED BY %I', poster);
    EXECUTE format('DROP ROLE %I', poster);
END;
$_$;

REVOKE ALL ON FUNCTION platform.wipe_tenant(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION platform.wipe_tenant(text) TO stall_app;


-- >>> folded from 063_sign_in_failure_stale.sql
-- Stale indexes for owner/admin sign-in failure prune (15-minute lock window).

CREATE INDEX IF NOT EXISTS owner_sign_in_failures_stale
    ON platform.owner_sign_in_failures USING btree (failed_at);

CREATE INDEX IF NOT EXISTS admin_sign_in_failures_stale
    ON platform.admin_sign_in_failures USING btree (failed_at);


-- >>> folded from 065_stall_app_vendor_grant.sql
-- Forward the stall_vendor membership that was briefly edited into 000_platform
-- (a725d5d). Existing demos stamped the older 000 checksum; keep 000 frozen.

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'stall_app') THEN
        GRANT stall_vendor TO stall_app;
    END IF;
    EXECUTE format('GRANT stall_vendor TO %I', current_user);
END $$;


-- >>> folded from 066_co_owners.sql
-- Co-owners: many owners per mall. Username unique per mall.
-- stall_app adds/removes only through SECURITY DEFINER helpers.

ALTER TABLE platform.owners
    DROP CONSTRAINT IF EXISTS owners_tenant_id_key;

ALTER TABLE platform.owners
    DROP CONSTRAINT IF EXISTS owners_tenant_username_key;

ALTER TABLE platform.owners
    ADD CONSTRAINT owners_tenant_username_key UNIQUE (tenant_id, username);

CREATE OR REPLACE FUNCTION platform.add_mall_owner(
    mall_id uuid,
    owner_username text,
    owner_password_hash text,
    owner_email text
) RETURNS uuid
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $_$
DECLARE
    new_id uuid;
    cleaned_username text;
    cleaned_email text;
BEGIN
    IF mall_id IS NULL THEN
        RAISE EXCEPTION 'a mall is required'
            USING ERRCODE = '23514';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM platform.tenants WHERE id = mall_id) THEN
        RAISE EXCEPTION 'that mall was not found'
            USING ERRCODE = 'P0002';
    END IF;
    cleaned_username := lower(btrim(owner_username));
    IF cleaned_username IS NULL OR cleaned_username !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RAISE EXCEPTION 'Use a sign-in name of letters, numbers, and hyphens.'
            USING ERRCODE = '23514';
    END IF;
    IF owner_password_hash IS NULL OR owner_password_hash = '' THEN
        RAISE EXCEPTION 'a password is required'
            USING ERRCODE = '23514';
    END IF;
    cleaned_email := lower(btrim(owner_email));
    IF cleaned_email IS NULL
        OR char_length(cleaned_email) > 200
        OR cleaned_email !~ '^[^@\s]+@[^@\s]+\.[^@\s]+$' THEN
        RAISE EXCEPTION 'Enter a usable email.'
            USING ERRCODE = '23514';
    END IF;
    INSERT INTO platform.owners (id, tenant_id, username, password_hash, email, email_verified_at)
    VALUES (uuidv7(), mall_id, cleaned_username, owner_password_hash, cleaned_email, clock_timestamp())
    RETURNING id INTO new_id;
    RETURN new_id;
END;
$_$;

CREATE OR REPLACE FUNCTION platform.remove_mall_owner(
    mall_id uuid,
    owner_id uuid
) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $_$
DECLARE
    owner_count integer;
BEGIN
    IF mall_id IS NULL OR owner_id IS NULL THEN
        RAISE EXCEPTION 'Choose an owner.'
            USING ERRCODE = '23514';
    END IF;
    SELECT count(*)::integer INTO owner_count
    FROM platform.owners
    WHERE tenant_id = mall_id;
    IF owner_count < 2 THEN
        RAISE EXCEPTION 'Keep at least one owner on the mall.'
            USING ERRCODE = 'P0001';
    END IF;
    DELETE FROM platform.owners
    WHERE id = owner_id AND tenant_id = mall_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Choose an owner.'
            USING ERRCODE = 'P0002';
    END IF;
END;
$_$;

REVOKE ALL ON FUNCTION platform.add_mall_owner(uuid, text, text, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.remove_mall_owner(uuid, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION platform.add_mall_owner(uuid, text, text, text) TO stall_app;
GRANT EXECUTE ON FUNCTION platform.remove_mall_owner(uuid, uuid) TO stall_app;


-- >>> folded from 068_owner_can_sign_in.sql
-- People lifecycle: suspend owners without deleting the row.

ALTER TABLE platform.owners
    ADD COLUMN IF NOT EXISTS can_sign_in boolean NOT NULL DEFAULT true;

GRANT UPDATE (can_sign_in) ON TABLE platform.owners TO stall_app;


-- >>> folded from 070_wipe_tenant_no_superuser.sql
-- wipe_tenant no longer requires set_config('event_triggers') when the mall
-- has no append-only password-reset / email-verification rows. Non-superuser
-- STALL_DB_USER (demo host) can seed-mall in that common case.

CREATE OR REPLACE FUNCTION platform.wipe_tenant(target_slug text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'platform', 'pg_catalog'
    AS $_$
DECLARE
    schema_name text;
    poster text;
    mall_id uuid;
    reset_count bigint;
    verify_count bigint;
BEGIN
    IF target_slug IS NULL OR target_slug !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RAISE EXCEPTION 'refusing to remove %', target_slug;
    END IF;
    SELECT tenants.id, tenants.schema_name INTO mall_id, schema_name
    FROM platform.tenants AS tenants
    WHERE tenants.slug = target_slug;
    IF schema_name IS NULL THEN
        RETURN;
    END IF;
    IF schema_name !~ '^tenant_[0-9a-f]{32}$' THEN
        RAISE EXCEPTION 'refusing to remove %', schema_name;
    END IF;
    poster := CASE
        WHEN target_slug = 'mall' THEN 'stall_poster'
        ELSE 'poster_' || substr(schema_name, length('tenant_') + 1)
    END;
    DELETE FROM platform.jobs WHERE tenant = target_slug;
    DELETE FROM platform.mail_outbox WHERE tenant = target_slug;
    DELETE FROM platform.sign_in_directory WHERE tenant_id = mall_id;
    DELETE FROM platform.owner_sign_in_failures WHERE tenant_id = mall_id;
    DELETE FROM platform.password_reset_requests WHERE tenant_id = mall_id;

    SELECT count(*) INTO reset_count
    FROM platform.password_resets
    WHERE tenant_id = mall_id;
    verify_count := 0;
    IF to_regclass('platform.owner_email_verifications') IS NOT NULL THEN
        SELECT count(*) INTO verify_count
        FROM platform.owner_email_verifications
        WHERE owner_id IN (SELECT id FROM platform.owners WHERE tenant_id = mall_id);
    END IF;

    IF reset_count > 0 OR verify_count > 0 THEN
        BEGIN
            PERFORM set_config('event_triggers', 'off', true);
        EXCEPTION
            WHEN insufficient_privilege THEN
                RAISE EXCEPTION
                    'wipe_tenant needs a superuser when password resets or email verifications still name this mall'
                    USING ERRCODE = '42501';
        END;
        IF reset_count > 0 THEN
            ALTER TABLE platform.password_resets DISABLE TRIGGER password_resets_append_only;
            ALTER TABLE platform.password_resets DISABLE TRIGGER password_resets_no_truncate;
            DELETE FROM platform.password_resets WHERE tenant_id = mall_id;
            ALTER TABLE platform.password_resets ENABLE ALWAYS TRIGGER password_resets_append_only;
            ALTER TABLE platform.password_resets ENABLE ALWAYS TRIGGER password_resets_no_truncate;
        END IF;
        IF verify_count > 0 THEN
            ALTER TABLE platform.owner_email_verifications DISABLE TRIGGER owner_email_verifications_append_only;
            ALTER TABLE platform.owner_email_verifications DISABLE TRIGGER owner_email_verifications_no_truncate;
            DELETE FROM platform.owner_email_verifications
            WHERE owner_id IN (SELECT id FROM platform.owners WHERE tenant_id = mall_id);
            ALTER TABLE platform.owner_email_verifications ENABLE ALWAYS TRIGGER owner_email_verifications_append_only;
            ALTER TABLE platform.owner_email_verifications ENABLE ALWAYS TRIGGER owner_email_verifications_no_truncate;
        END IF;
        PERFORM set_config('event_triggers', 'on', true);
    END IF;

    DELETE FROM platform.owners WHERE tenant_id = mall_id;
    DELETE FROM platform.tenants WHERE slug = target_slug;
    EXECUTE format('DROP SCHEMA %I CASCADE', schema_name);
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = poster) THEN
        RETURN;
    END IF;
    IF EXISTS (
        SELECT 1
        FROM pg_auth_members AS membership
        JOIN pg_roles AS granted ON granted.oid = membership.roleid
        JOIN pg_roles AS login ON login.oid = membership.member
        WHERE granted.rolname = poster AND login.rolname = 'stall_app'
    ) THEN
        EXECUTE format('REVOKE %I FROM stall_app', poster);
    END IF;
    EXECUTE format('GRANT %I TO CURRENT_USER', poster);
    EXECUTE format('DROP OWNED BY %I', poster);
    EXECUTE format('DROP ROLE %I', poster);
END;
$_$;

REVOKE ALL ON FUNCTION platform.wipe_tenant(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION platform.wipe_tenant(text) TO stall_app;


-- >>> folded from 071_admin_sign_in_lockout.sql
-- Admin sign-in spray and email-wide lockout indexes (mirror email_sign_in_failures).

CREATE INDEX IF NOT EXISTS admin_sign_in_failures_address
    ON platform.admin_sign_in_failures USING btree (client_address, failed_at);

CREATE INDEX IF NOT EXISTS admin_sign_in_failures_email
    ON platform.admin_sign_in_failures USING btree (email, failed_at);


-- >>> folded from 073_vendor_entry_role.sql
-- Shared role for consignor magic-link entry (not the mall poster).

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'stall_vendor_entry') THEN
        CREATE ROLE stall_vendor_entry NOLOGIN;
    END IF;
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'stall_app') THEN
        GRANT stall_vendor_entry TO stall_app;
    END IF;
    EXECUTE format('GRANT stall_vendor_entry TO %I', current_user);
END $$;


-- >>> folded from 075_password_reset_client.sql
-- Forgot-password spray: client address + prune indexes.

ALTER TABLE platform.password_reset_requests
    ADD COLUMN IF NOT EXISTS client_address text DEFAULT ''::text NOT NULL;

ALTER TABLE platform.password_reset_requests
    DROP CONSTRAINT IF EXISTS password_reset_requests_address_chk;

ALTER TABLE platform.password_reset_requests
    ADD CONSTRAINT password_reset_requests_address_chk CHECK ((char_length(client_address) <= 64));

ALTER TABLE platform.admin_password_reset_requests
    ADD COLUMN IF NOT EXISTS client_address text DEFAULT ''::text NOT NULL;

ALTER TABLE platform.admin_password_reset_requests
    DROP CONSTRAINT IF EXISTS admin_password_reset_requests_address_chk;

ALTER TABLE platform.admin_password_reset_requests
    ADD CONSTRAINT admin_password_reset_requests_address_chk CHECK ((char_length(client_address) <= 64));

CREATE INDEX IF NOT EXISTS password_reset_requests_stale
    ON platform.password_reset_requests USING btree (requested_at);

CREATE INDEX IF NOT EXISTS password_reset_requests_address
    ON platform.password_reset_requests USING btree (client_address, requested_at);

CREATE INDEX IF NOT EXISTS admin_password_reset_requests_stale
    ON platform.admin_password_reset_requests USING btree (requested_at);

CREATE INDEX IF NOT EXISTS admin_password_reset_requests_address
    ON platform.admin_password_reset_requests USING btree (client_address, requested_at);


-- >>> folded from 076_password_reset_delete.sql
-- Prune stale forgot-password rows as stall_app (pair with 075 client_address).

GRANT SELECT, INSERT, DELETE ON TABLE platform.password_reset_requests TO stall_app;
GRANT SELECT, INSERT, DELETE ON TABLE platform.admin_password_reset_requests TO stall_app;


-- >>> folded from 086_owner_duplicate_username.sql
-- Duplicate co-owner username / sign-in email: plain English (ERRCODE 23505).
CREATE OR REPLACE FUNCTION platform.put_sign_in_directory(
    p_email text,
    p_tenant_id uuid,
    p_kind text,
    p_account_id uuid,
    p_username text
) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $_$
DECLARE
    normalized text;
BEGIN
    IF p_email IS NULL OR btrim(p_email) = '' THEN
        DELETE FROM platform.sign_in_directory
        WHERE account_kind = p_kind AND account_id = p_account_id;
        RETURN;
    END IF;
    normalized := lower(btrim(p_email));
    IF char_length(normalized) > 200
        OR normalized !~ '^[^@\s]+@[^@\s]+\.[^@\s]+$' THEN
        RAISE EXCEPTION 'sign-in email is not usable'
            USING ERRCODE = 'check_violation';
    END IF;
    IF p_kind IS DISTINCT FROM 'owner' AND p_kind IS DISTINCT FROM 'staff' THEN
        RAISE EXCEPTION 'sign-in account kind is not usable'
            USING ERRCODE = 'check_violation';
    END IF;
    IF p_username IS NULL OR p_username !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RAISE EXCEPTION 'sign-in username is not usable'
            USING ERRCODE = 'check_violation';
    END IF;
    IF EXISTS (
        SELECT 1 FROM platform.sign_in_directory AS other
        WHERE lower(other.email) = normalized
          AND (other.account_kind, other.account_id) IS DISTINCT FROM (p_kind, p_account_id)
    ) THEN
        RAISE EXCEPTION 'That email is already used on another sign-in.'
            USING ERRCODE = '23505';
    END IF;
    INSERT INTO platform.sign_in_directory (email, tenant_id, account_kind, account_id, username)
    VALUES (normalized, p_tenant_id, p_kind, p_account_id, p_username)
    ON CONFLICT (account_kind, account_id) DO UPDATE
        SET email = EXCLUDED.email,
            tenant_id = EXCLUDED.tenant_id,
            username = EXCLUDED.username;
END;
$_$;

CREATE OR REPLACE FUNCTION platform.add_mall_owner(
    mall_id uuid,
    owner_username text,
    owner_password_hash text,
    owner_email text
) RETURNS uuid
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $_$
DECLARE
    new_id uuid;
    cleaned_username text;
    cleaned_email text;
BEGIN
    IF mall_id IS NULL THEN
        RAISE EXCEPTION 'a mall is required'
            USING ERRCODE = '23514';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM platform.tenants WHERE id = mall_id) THEN
        RAISE EXCEPTION 'that mall was not found'
            USING ERRCODE = 'P0002';
    END IF;
    cleaned_username := lower(btrim(owner_username));
    IF cleaned_username IS NULL OR cleaned_username !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RAISE EXCEPTION 'Use a sign-in name of letters, numbers, and hyphens.'
            USING ERRCODE = '23514';
    END IF;
    IF owner_password_hash IS NULL OR owner_password_hash = '' THEN
        RAISE EXCEPTION 'a password is required'
            USING ERRCODE = '23514';
    END IF;
    cleaned_email := lower(btrim(owner_email));
    IF cleaned_email IS NULL
        OR char_length(cleaned_email) > 200
        OR cleaned_email !~ '^[^@\s]+@[^@\s]+\.[^@\s]+$' THEN
        RAISE EXCEPTION 'Enter a usable email.'
            USING ERRCODE = '23514';
    END IF;
    IF EXISTS (
        SELECT 1 FROM platform.owners
        WHERE tenant_id = mall_id AND username = cleaned_username
    ) THEN
        RAISE EXCEPTION 'That sign-in name is already used.'
            USING ERRCODE = '23505';
    END IF;
    INSERT INTO platform.owners (id, tenant_id, username, password_hash, email, email_verified_at)
    VALUES (uuidv7(), mall_id, cleaned_username, owner_password_hash, cleaned_email, clock_timestamp())
    RETURNING id INTO new_id;
    RETURN new_id;
END;
$_$;
