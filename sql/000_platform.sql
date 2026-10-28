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
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'stall_app') THEN
        GRANT stall_vendor TO stall_app;
    END IF;
    EXECUTE format('GRANT stall_vendor TO %I', current_user);
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
