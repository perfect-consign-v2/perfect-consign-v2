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
                    OR right(dropped.object_identity, length(' on platform.admin_mfa_recovery_codes'))
                        = ' on platform.admin_mfa_recovery_codes'
                    OR dropped.object_identity = 'admin_mfa_transition on platform.admins'
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
                    'platform.reject_admin_append_only_guard()',
                    'platform.reject_admin_mfa_transition()',
                    'platform.reject_admin_mfa_recovery_mutation()',
                    'platform.reject_admin_mfa_recovery_truncate()'
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
                to_regclass('platform.admin_audit'),
                to_regclass('platform.admin_mfa_recovery_codes'),
                to_regclass('platform.admins')
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
                          AND tgname ~ '(_append_only|_no_truncate|_no_delete|_transition)$'
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
                'platform.reject_admin_append_only_guard()'::regprocedure,
                'platform.reject_admin_mfa_transition()'::regprocedure,
                'platform.reject_admin_mfa_recovery_mutation()'::regprocedure,
                'platform.reject_admin_mfa_recovery_truncate()'::regprocedure
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

-- >>> folded from 107_admin_mfa.sql (authenticator tables, before the admin guard triggers)
-- Platform admin authenticator (TOTP) and one-time recovery codes.

ALTER TABLE platform.admins
    ADD COLUMN mfa_secret text,
    ADD COLUMN mfa_confirmed_at timestamp with time zone,
    ADD COLUMN mfa_last_used_step bigint;

ALTER TABLE platform.admins
    ADD CONSTRAINT admins_mfa_chk CHECK (
        (
            mfa_secret IS NULL
            AND mfa_confirmed_at IS NULL
            AND mfa_last_used_step IS NULL
        )
        OR (
            mfa_secret ~ '^v1:[A-Za-z0-9+/]+=*$'
            AND char_length(mfa_secret) BETWEEN 8 AND 200
            AND mfa_confirmed_at IS NOT NULL
            AND mfa_last_used_step IS NOT NULL
            AND mfa_last_used_step >= 0
        )
    );

CREATE TABLE platform.admin_mfa_recovery_codes (
    id uuid DEFAULT uuidv7() NOT NULL,
    admin_id uuid NOT NULL,
    code_hash character(64) NOT NULL,
    batch_issued_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    used_at timestamp with time zone,
    CONSTRAINT admin_mfa_recovery_codes_hash_chk CHECK ((code_hash ~ '^[0-9a-f]{64}$'::text))
);

CREATE TABLE platform.admin_mfa_failures (
    id uuid DEFAULT uuidv7() NOT NULL,
    email text NOT NULL,
    failed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    client_address text DEFAULT ''::text NOT NULL,
    CONSTRAINT admin_mfa_failures_address_chk CHECK ((char_length(client_address) <= 64)),
    CONSTRAINT admin_mfa_failures_email_chk CHECK (((email = btrim(email)) AND (char_length(email) <= 200) AND (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text)))
);

ALTER TABLE ONLY platform.admin_mfa_recovery_codes
    ADD CONSTRAINT admin_mfa_recovery_codes_pkey PRIMARY KEY (id);

ALTER TABLE ONLY platform.admin_mfa_failures
    ADD CONSTRAINT admin_mfa_failures_pkey PRIMARY KEY (id);

CREATE UNIQUE INDEX admin_mfa_recovery_codes_hash
    ON platform.admin_mfa_recovery_codes USING btree (admin_id, code_hash);

CREATE INDEX admin_mfa_recovery_codes_admin
    ON platform.admin_mfa_recovery_codes USING btree (admin_id);

CREATE INDEX admin_mfa_failures_window
    ON platform.admin_mfa_failures USING btree (email, client_address, failed_at);

CREATE INDEX admin_mfa_failures_address
    ON platform.admin_mfa_failures USING btree (client_address, failed_at);

CREATE INDEX admin_mfa_failures_email
    ON platform.admin_mfa_failures USING btree (email, failed_at);

CREATE INDEX admin_mfa_failures_stale
    ON platform.admin_mfa_failures USING btree (failed_at);

ALTER TABLE ONLY platform.admin_mfa_recovery_codes
    ADD CONSTRAINT admin_mfa_recovery_codes_admin_id_fkey
    FOREIGN KEY (admin_id) REFERENCES platform.admins (id);

CREATE FUNCTION platform.reject_admin_mfa_transition() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    owner name;
BEGIN
    IF NEW.mfa_secret IS NOT DISTINCT FROM OLD.mfa_secret
        AND NEW.mfa_confirmed_at IS NOT DISTINCT FROM OLD.mfa_confirmed_at
        AND NEW.mfa_last_used_step IS NOT DISTINCT FROM OLD.mfa_last_used_step THEN
        RETURN NEW;
    END IF;

    SELECT tableowner INTO owner
    FROM pg_tables
    WHERE schemaname = 'platform'
      AND tablename = 'admins';

    IF current_user = owner
        AND NEW.mfa_secret IS NULL
        AND NEW.mfa_confirmed_at IS NULL
        AND NEW.mfa_last_used_step IS NULL THEN
        RETURN NEW;
    END IF;

    IF OLD.mfa_secret IS NULL
        AND OLD.mfa_confirmed_at IS NULL
        AND OLD.mfa_last_used_step IS NULL
        AND NEW.mfa_secret IS NOT NULL
        AND NEW.mfa_confirmed_at IS NOT NULL
        AND NEW.mfa_last_used_step IS NOT NULL
        AND NEW.mfa_last_used_step >= 0 THEN
        RETURN NEW;
    END IF;

    IF NEW.mfa_secret IS NOT DISTINCT FROM OLD.mfa_secret
        AND NEW.mfa_confirmed_at IS NOT DISTINCT FROM OLD.mfa_confirmed_at
        AND OLD.mfa_last_used_step IS NOT NULL
        AND NEW.mfa_last_used_step IS NOT NULL
        AND NEW.mfa_last_used_step > OLD.mfa_last_used_step THEN
        RETURN NEW;
    END IF;

    RAISE EXCEPTION 'an admin authenticator cannot be rewritten'
        USING ERRCODE = '25006';
END;
$$;

CREATE FUNCTION platform.reject_admin_mfa_recovery_mutation() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'a recovery code cannot be removed'
            USING ERRCODE = '25006';
    END IF;
    IF OLD.used_at IS NOT NULL THEN
        RAISE EXCEPTION 'a recovery code was already used'
            USING ERRCODE = '25006';
    END IF;
    IF NEW.used_at IS NULL
        OR NEW.id IS DISTINCT FROM OLD.id
        OR NEW.admin_id IS DISTINCT FROM OLD.admin_id
        OR NEW.code_hash IS DISTINCT FROM OLD.code_hash
        OR NEW.batch_issued_at IS DISTINCT FROM OLD.batch_issued_at THEN
        RAISE EXCEPTION 'a recovery code cannot be rewritten'
            USING ERRCODE = '25006';
    END IF;

    RETURN NEW;
END;
$$;

CREATE FUNCTION platform.reject_admin_mfa_recovery_truncate() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'recovery codes cannot be truncated'
        USING ERRCODE = '25006';
END;
$$;

CREATE TRIGGER admin_mfa_transition
    BEFORE UPDATE ON platform.admins
    FOR EACH ROW
    EXECUTE FUNCTION platform.reject_admin_mfa_transition();

ALTER TABLE platform.admins ENABLE ALWAYS TRIGGER admin_mfa_transition;

CREATE TRIGGER admin_mfa_recovery_append_only
    BEFORE DELETE OR UPDATE ON platform.admin_mfa_recovery_codes
    FOR EACH ROW
    EXECUTE FUNCTION platform.reject_admin_mfa_recovery_mutation();

ALTER TABLE platform.admin_mfa_recovery_codes ENABLE ALWAYS TRIGGER admin_mfa_recovery_append_only;

CREATE TRIGGER admin_mfa_recovery_no_truncate
    BEFORE TRUNCATE ON platform.admin_mfa_recovery_codes
    FOR EACH STATEMENT
    EXECUTE FUNCTION platform.reject_admin_mfa_recovery_truncate();

ALTER TABLE platform.admin_mfa_recovery_codes ENABLE ALWAYS TRIGGER admin_mfa_recovery_no_truncate;

GRANT UPDATE (mfa_secret, mfa_confirmed_at, mfa_last_used_step) ON TABLE platform.admins TO stall_app;

GRANT SELECT, INSERT ON TABLE platform.admin_mfa_recovery_codes TO stall_app;

GRANT UPDATE (used_at) ON TABLE platform.admin_mfa_recovery_codes TO stall_app;

GRANT SELECT, INSERT, DELETE ON TABLE platform.admin_mfa_failures TO stall_app;

REVOKE ALL ON FUNCTION platform.reject_admin_mfa_transition() FROM PUBLIC;

GRANT EXECUTE ON FUNCTION platform.reject_admin_mfa_transition() TO stall_app;

REVOKE ALL ON FUNCTION platform.reject_admin_mfa_recovery_mutation() FROM PUBLIC;

GRANT EXECUTE ON FUNCTION platform.reject_admin_mfa_recovery_mutation() TO stall_app;

REVOKE ALL ON FUNCTION platform.reject_admin_mfa_recovery_truncate() FROM PUBLIC;

GRANT EXECUTE ON FUNCTION platform.reject_admin_mfa_recovery_truncate() TO stall_app;

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
    ('store_signup_enabled', 'false'::jsonb)
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
        OR (action = ANY (ARRAY['store.signup'::text, 'store.email_verified'::text, 'store.ready'::text]))
    );

REVOKE ALL ON TABLE platform.settings FROM PUBLIC;
REVOKE ALL ON TABLE platform.signup_attempts FROM PUBLIC;
REVOKE ALL ON TABLE platform.owner_email_verifications FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.reject_owner_email_verification_mutation() FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.reject_owner_email_verification_truncate() FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.reject_owner_email_verification_guard() FROM PUBLIC;

GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE platform.settings TO stall_app;
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


-- >>> folded from 094_wipe_keeps_reset_history.sql
-- Password-reset and email-verification rows stay when a mall is deleted.
-- The append-only guards cannot be replaced, and stall_app cannot turn
-- event triggers off, so wipe leaves those rows in place.

ALTER TABLE platform.password_resets
    DROP CONSTRAINT IF EXISTS password_resets_tenant_id_fkey;

ALTER TABLE platform.owner_email_verifications
    DROP CONSTRAINT IF EXISTS owner_email_verifications_owner_id_fkey;

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

CREATE TABLE platform.admin_wipe_failures (
    id uuid DEFAULT uuidv7() NOT NULL,
    email text NOT NULL,
    failed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    client_address text DEFAULT ''::text NOT NULL,
    CONSTRAINT admin_wipe_failures_pkey PRIMARY KEY (id),
    CONSTRAINT admin_wipe_failures_address_chk CHECK ((char_length(client_address) <= 64)),
    CONSTRAINT admin_wipe_failures_email_chk CHECK (((email = btrim(email)) AND (char_length(email) <= 200) AND (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text)))
);

CREATE INDEX admin_wipe_failures_pair
    ON platform.admin_wipe_failures USING btree (email, client_address, failed_at);

CREATE INDEX admin_wipe_failures_address
    ON platform.admin_wipe_failures USING btree (client_address, failed_at);

CREATE INDEX admin_wipe_failures_stale
    ON platform.admin_wipe_failures USING btree (failed_at);

REVOKE ALL ON TABLE platform.admin_wipe_failures FROM PUBLIC;

GRANT SELECT, INSERT, DELETE ON TABLE platform.admin_wipe_failures TO stall_app;


-- >>> folded from 102_job_attention.sql
-- Mail worker queue for platform admin, and one-time notice stamps.
-- stall_app can read platform.jobs but not a store's letters or mail setting.

ALTER TABLE platform.jobs
    ADD COLUMN IF NOT EXISTS overdue_noticed_at timestamp with time zone,
    ADD COLUMN IF NOT EXISTS abandoned_noticed_at timestamp with time zone;

CREATE OR REPLACE FUNCTION platform.job_attention(p_bucket text)
RETURNS TABLE (
    id text,
    tenant text,
    kind text,
    run_at text,
    finished_at text,
    attempts integer,
    error text,
    subject text,
    recipients text,
    mail_via text,
    mailbox text,
    payload_date text,
    due boolean
)
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path TO pg_catalog, platform
AS $_$
DECLARE
    job record;
    list_sql text;
    schema_name text;
    letter_id uuid;
    found_subject text;
    found_recipients text;
    found_via text;
    found_mailbox text;
    found_date text;
BEGIN
    IF p_bucket IS NULL OR p_bucket NOT IN ('open', 'overdue', 'given_up', 'abandoned') THEN
        RAISE EXCEPTION 'the job list is not a list'
            USING ERRCODE = '22023';
    END IF;
    IF p_bucket = 'open' THEN
        list_sql := $open$
            SELECT j.id, j.tenant, j.kind, j.payload, j.run_at, j.finished_at, j.attempts, j.error
            FROM platform.jobs AS j
            WHERE j.finished_at IS NULL
              AND j.locked_at IS NULL
              AND NOT (
                  j.kind = 'outbound-mail'
                  AND EXISTS (
                      SELECT 1
                      FROM platform.mail_outbox AS mail
                      WHERE mail.id::text = j.payload ->> 'mail'
                        AND mail.sent_at IS NOT NULL
                  )
              )
            ORDER BY j.run_at, j.id
        $open$;
    ELSIF p_bucket = 'overdue' THEN
        list_sql := $overdue$
            SELECT j.id, j.tenant, j.kind, j.payload, j.run_at, j.finished_at, j.attempts, j.error
            FROM platform.jobs AS j
            WHERE j.finished_at IS NULL
              AND j.locked_at IS NULL
              AND j.overdue_noticed_at IS NULL
              AND j.run_at < clock_timestamp() - interval '15 minutes'
              AND NOT (
                  j.kind = 'outbound-mail'
                  AND EXISTS (
                      SELECT 1
                      FROM platform.mail_outbox AS mail
                      WHERE mail.id::text = j.payload ->> 'mail'
                        AND mail.sent_at IS NOT NULL
                  )
              )
            ORDER BY j.run_at, j.id
        $overdue$;
    ELSIF p_bucket = 'given_up' THEN
        list_sql := $given$
            SELECT j.id, j.tenant, j.kind, j.payload, j.run_at, j.finished_at, j.attempts, j.error
            FROM platform.jobs AS j
            WHERE j.finished_at IS NOT NULL
              AND j.error IS NOT NULL
              AND btrim(j.error) <> ''
              AND j.attempts >= 5
            ORDER BY j.finished_at DESC, j.id
            LIMIT 50
        $given$;
    ELSE
        list_sql := $abandoned$
            SELECT j.id, j.tenant, j.kind, j.payload, j.run_at, j.finished_at, j.attempts, j.error
            FROM platform.jobs AS j
            WHERE j.finished_at IS NOT NULL
              AND j.error IS NOT NULL
              AND btrim(j.error) <> ''
              AND j.attempts >= 5
              AND j.abandoned_noticed_at IS NULL
            ORDER BY j.finished_at, j.id
        $abandoned$;
    END IF;

    FOR job IN EXECUTE list_sql
    LOOP
        found_subject := '';
        found_recipients := '';
        found_via := '';
        found_mailbox := '';
        found_date := COALESCE(job.payload ->> 'date', '');
        schema_name := NULL;
        IF job.tenant ~ '^[a-z][a-z0-9-]{0,62}$' THEN
            SELECT tenants.schema_name INTO schema_name
            FROM platform.tenants AS tenants
            WHERE tenants.slug = job.tenant;
        END IF;
        IF schema_name IS NOT NULL AND schema_name !~ '^tenant_[0-9a-f]{32}$' THEN
            schema_name := NULL;
        END IF;
        IF job.kind = 'vendor-letter'
           AND COALESCE(job.payload ->> 'letter', '') ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
           AND schema_name IS NOT NULL
        THEN
            letter_id := (job.payload ->> 'letter')::uuid;
            EXECUTE format('SELECT subject FROM %I.vendor_letters WHERE id = $1', schema_name)
                INTO found_subject USING letter_id;
            EXECUTE format(
                'SELECT string_agg(copy.email, '', '' ORDER BY copy.email) FROM %I.vendor_letter_copies AS copy WHERE copy.letter_id = $1',
                schema_name
            ) INTO found_recipients USING letter_id;
            EXECUTE format('SELECT receipt_mail_via FROM %I.mall LIMIT 1', schema_name)
                INTO found_via;
        ELSIF job.kind = 'outbound-mail'
              AND COALESCE(job.payload ->> 'mail', '') ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
        THEN
            SELECT mail.subject, mail.address, mail.mailbox
            INTO found_subject, found_recipients, found_mailbox
            FROM platform.mail_outbox AS mail
            WHERE mail.id = (job.payload ->> 'mail')::uuid;
            IF found_mailbox = 'mall' AND schema_name IS NOT NULL THEN
                EXECUTE format('SELECT receipt_mail_via FROM %I.mall LIMIT 1', schema_name)
                    INTO found_via;
            END IF;
        END IF;
        id := job.id::text;
        tenant := job.tenant;
        kind := job.kind;
        run_at := job.run_at::text;
        finished_at := COALESCE(job.finished_at::text, '');
        attempts := job.attempts;
        error := COALESCE(job.error, '');
        subject := COALESCE(found_subject, '');
        recipients := COALESCE(found_recipients, '');
        mail_via := COALESCE(found_via, '');
        mailbox := COALESCE(found_mailbox, '');
        payload_date := found_date;
        due := job.run_at <= clock_timestamp();
        RETURN NEXT;
    END LOOP;
END;
$_$;

REVOKE ALL ON FUNCTION platform.job_attention(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION platform.job_attention(text) TO stall_app;
GRANT EXECUTE ON FUNCTION platform.job_attention(text) TO stall_worker;


-- >>> folded from 104_store_load_audit.sql
-- A PC1 mirror load is an operator action, not a mall user.

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
        'app.log_clear'::text,
        'store.load'::text
    ]));


-- >>> folded from 105_probe_ban.sql
-- Permanent bans for scanner clients, and the short streak that leads there.
-- stall_app checks and writes these rows before a session starts.

CREATE TABLE platform.probe_bans (
    client_address text NOT NULL,
    banned_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    reason text NOT NULL,
    user_agent text DEFAULT ''::text NOT NULL,
    path text DEFAULT ''::text NOT NULL,
    CONSTRAINT probe_bans_address_chk CHECK (((char_length(client_address) >= 1) AND (char_length(client_address) <= 64))),
    CONSTRAINT probe_bans_agent_chk CHECK ((char_length(user_agent) <= 200)),
    CONSTRAINT probe_bans_path_chk CHECK ((char_length(path) <= 200)),
    CONSTRAINT probe_bans_reason_chk CHECK ((reason = ANY (ARRAY['scanner'::text, 'streak'::text])))
);

CREATE TABLE platform.probe_streaks (
    client_address text NOT NULL,
    streak integer NOT NULL,
    updated_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT probe_streaks_address_chk CHECK (((char_length(client_address) >= 1) AND (char_length(client_address) <= 64))),
    CONSTRAINT probe_streaks_streak_chk CHECK ((streak >= 1))
);

ALTER TABLE ONLY platform.probe_bans
    ADD CONSTRAINT probe_bans_pkey PRIMARY KEY (client_address);

ALTER TABLE ONLY platform.probe_streaks
    ADD CONSTRAINT probe_streaks_pkey PRIMARY KEY (client_address);

GRANT SELECT, INSERT, DELETE ON TABLE platform.probe_bans TO stall_app;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE platform.probe_streaks TO stall_app;


-- >>> folded from 106_probe_ban_audit.sql
-- Lifting a probe ban is an operator action.

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
        'app.log_clear'::text,
        'store.load'::text,
        'probe_ban_lift'::text
    ]));


-- >>> folded from 107_admin_mfa.sql
ALTER TABLE platform.admin_audit
    DROP CONSTRAINT IF EXISTS admin_audit_action_chk;

ALTER TABLE platform.admin_audit
    ADD CONSTRAINT admin_audit_action_chk CHECK (action = ANY (ARRAY[
        'admin.sign_in'::text,
        'admin.sign_in_refused'::text,
        'admin.mfa_enrolled'::text,
        'admin.mfa_refused'::text,
        'admin.mfa_cleared'::text,
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
        'app.log_clear'::text,
        'store.load'::text,
        'probe_ban_lift'::text
    ]));


-- >>> folded from 108_admin_mfa_switch.sql
-- A signed-in admin can turn the authenticator requirement on or off.
-- The phone secret stays. Clearing it is still owner-only.

ALTER TABLE platform.admin_audit
    DROP CONSTRAINT IF EXISTS admin_audit_action_chk;

ALTER TABLE platform.admin_audit
    ADD CONSTRAINT admin_audit_action_chk CHECK (action = ANY (ARRAY[
        'admin.sign_in'::text,
        'admin.sign_in_refused'::text,
        'admin.mfa_enrolled'::text,
        'admin.mfa_refused'::text,
        'admin.mfa_cleared'::text,
        'admin.mfa_enabled'::text,
        'admin.mfa_disabled'::text,
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
        'app.log_clear'::text,
        'store.load'::text,
        'probe_ban_lift'::text,
        'store.ready'::text,
        'store.signup_resend'::text,
        'store.signup_discard'::text
    ]));


-- >>> folded from 110_probe_ban_rules.sql
-- Probe bans follow rules kept in the database, and every ban ends.
--
-- One row of platform.probe_rules says how many probe hits from one address,
-- inside how many minutes, start a ban, and how long each ban in a row lasts.
-- A ban that starts again within strike_reset_days of the last one moves one
-- step up the ladder. The last step repeats.
--
-- stall_app no longer writes probe_bans or probe_streaks directly. It calls
-- platform.probe_hit and platform.probe_ban, which apply these rules. It can
-- still read the bans for the admin list and delete one to lift it.
--
-- A ban already in place when this file runs ends fifteen minutes later.

CREATE TABLE platform.probe_rules (
    id boolean DEFAULT true NOT NULL,
    hit_limit integer NOT NULL,
    window_minutes integer NOT NULL,
    ban_minutes integer[] NOT NULL,
    strike_reset_days integer NOT NULL,
    CONSTRAINT probe_rules_pkey PRIMARY KEY (id),
    CONSTRAINT probe_rules_one_row_chk CHECK (id),
    CONSTRAINT probe_rules_hit_limit_chk CHECK (((hit_limit >= 2) AND (hit_limit <= 1000))),
    CONSTRAINT probe_rules_window_chk CHECK (((window_minutes >= 1) AND (window_minutes <= 1440))),
    CONSTRAINT probe_rules_ban_minutes_chk CHECK (((cardinality(ban_minutes) >= 1)
        AND (cardinality(ban_minutes) <= 10)
        AND (array_position(ban_minutes, NULL) IS NULL)
        AND (1 <= ALL (ban_minutes))
        AND (525600 >= ALL (ban_minutes)))),
    CONSTRAINT probe_rules_strike_reset_chk CHECK (((strike_reset_days >= 1) AND (strike_reset_days <= 365)))
);

INSERT INTO platform.probe_rules (id, hit_limit, window_minutes, ban_minutes, strike_reset_days)
VALUES (true, 20, 10, ARRAY[15, 60, 1440], 7);

ALTER TABLE platform.probe_bans
    ADD COLUMN strikes integer DEFAULT 1 NOT NULL,
    ADD COLUMN banned_until timestamp with time zone;

UPDATE platform.probe_bans
SET banned_until = clock_timestamp() + interval '15 minutes';

ALTER TABLE platform.probe_bans
    ALTER COLUMN banned_until SET NOT NULL,
    ADD CONSTRAINT probe_bans_strikes_chk CHECK ((strikes >= 1)),
    ADD CONSTRAINT probe_bans_until_chk CHECK ((banned_until > banned_at));

ALTER TABLE platform.probe_streaks
    ADD COLUMN window_started_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL;

CREATE FUNCTION platform.probe_ban(p_address text, p_reason text, p_agent text, p_path text) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $$
DECLARE
    rules platform.probe_rules%ROWTYPE;
    prior platform.probe_bans%ROWTYPE;
    now_at timestamp with time zone := clock_timestamp();
    next_strike integer;
    minutes integer;
BEGIN
    IF p_address IS NULL OR p_address !~ '^[A-Za-z0-9:.]{1,64}$' THEN
        RAISE EXCEPTION 'a probe ban needs a client address'
            USING ERRCODE = '23514';
    END IF;
    IF p_reason IS NULL OR p_reason NOT IN ('scanner', 'streak') THEN
        RAISE EXCEPTION 'a probe ban needs a known reason'
            USING ERRCODE = '23514';
    END IF;
    PERFORM pg_advisory_xact_lock(87301, hashtext(p_address));
    SELECT * INTO rules FROM platform.probe_rules WHERE id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'the probe rules are missing'
            USING ERRCODE = '23514';
    END IF;
    SELECT * INTO prior FROM platform.probe_bans WHERE client_address = p_address;
    IF FOUND AND prior.banned_until > now_at THEN
        RETURN false;
    END IF;
    IF FOUND AND prior.banned_until > now_at - make_interval(days => rules.strike_reset_days) THEN
        next_strike := prior.strikes + 1;
    ELSE
        next_strike := 1;
    END IF;
    minutes := rules.ban_minutes[least(next_strike, cardinality(rules.ban_minutes))];
    INSERT INTO platform.probe_bans (client_address, banned_at, reason, user_agent, path, strikes, banned_until)
    VALUES (
        p_address,
        now_at,
        p_reason,
        left(COALESCE(p_agent, ''), 200),
        left(COALESCE(p_path, ''), 200),
        next_strike,
        now_at + make_interval(mins => minutes)
    )
    ON CONFLICT (client_address) DO UPDATE
    SET banned_at = EXCLUDED.banned_at,
        reason = EXCLUDED.reason,
        user_agent = EXCLUDED.user_agent,
        path = EXCLUDED.path,
        strikes = EXCLUDED.strikes,
        banned_until = EXCLUDED.banned_until;
    DELETE FROM platform.probe_streaks WHERE client_address = p_address;
    RETURN true;
END;
$$;

CREATE FUNCTION platform.probe_hit(p_address text, p_agent text, p_path text) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $$
DECLARE
    rules platform.probe_rules%ROWTYPE;
    now_at timestamp with time zone := clock_timestamp();
    window_start timestamp with time zone;
    hits integer;
BEGIN
    IF p_address IS NULL OR p_address !~ '^[A-Za-z0-9:.]{1,64}$' THEN
        RAISE EXCEPTION 'a probe hit needs a client address'
            USING ERRCODE = '23514';
    END IF;
    PERFORM pg_advisory_xact_lock(87301, hashtext(p_address));
    SELECT * INTO rules FROM platform.probe_rules WHERE id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'the probe rules are missing'
            USING ERRCODE = '23514';
    END IF;
    window_start := now_at - make_interval(mins => rules.window_minutes);
    DELETE FROM platform.probe_streaks WHERE window_started_at <= window_start;
    INSERT INTO platform.probe_streaks (client_address, streak, window_started_at, updated_at)
    VALUES (p_address, 1, now_at, now_at)
    ON CONFLICT (client_address) DO UPDATE
    SET streak = platform.probe_streaks.streak + 1,
        updated_at = now_at
    RETURNING streak INTO hits;
    IF hits < rules.hit_limit THEN
        RETURN false;
    END IF;

    RETURN platform.probe_ban(p_address, 'streak', p_agent, p_path);
END;
$$;

REVOKE ALL ON TABLE platform.probe_rules FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.probe_ban(text, text, text, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.probe_hit(text, text, text) FROM PUBLIC;

GRANT SELECT ON TABLE platform.probe_rules TO stall_app;
GRANT EXECUTE ON FUNCTION platform.probe_ban(text, text, text, text) TO stall_app;
GRANT EXECUTE ON FUNCTION platform.probe_hit(text, text, text) TO stall_app;

REVOKE INSERT ON TABLE platform.probe_bans FROM stall_app;
REVOKE INSERT, UPDATE ON TABLE platform.probe_streaks FROM stall_app;


-- >>> folded from 111_probe_ban_summary.sql
-- Probe-shaped bans stay short, scanner bans climb, and the platform admin
-- gets one summary of new bans a day.
--
-- A streak ban (twenty probe hits from one address) always lasts
-- streak_ban_minutes. A hostile web page can make a store's browser send those
-- hits, so a streak ban must never grow into a day-long block of that store.
-- A scanner ban (a known scanning tool's user agent) still climbs
-- scanner_ban_minutes for repeats within strike_reset_days. Strikes count
-- every ban, so a scanner that comes back after streak bans starts higher.
--
-- The worker files one probe-ban-digest job a day once the local hour reaches
-- summary_hour in summary_time_zone, while summary_enabled is on. The job lists
-- the bans that started in the day before that hour and sends nothing when
-- there were none.

ALTER TABLE platform.probe_rules RENAME COLUMN ban_minutes TO scanner_ban_minutes;
ALTER TABLE platform.probe_rules RENAME CONSTRAINT probe_rules_ban_minutes_chk TO probe_rules_scanner_ban_chk;

ALTER TABLE platform.probe_rules
    ADD COLUMN streak_ban_minutes integer DEFAULT 15 NOT NULL,
    ADD COLUMN summary_enabled boolean DEFAULT true NOT NULL,
    ADD COLUMN summary_hour integer DEFAULT 7 NOT NULL,
    ADD COLUMN summary_time_zone text DEFAULT 'America/Los_Angeles' NOT NULL,
    ADD CONSTRAINT probe_rules_streak_ban_chk CHECK (((streak_ban_minutes >= 1) AND (streak_ban_minutes <= 1440))),
    ADD CONSTRAINT probe_rules_summary_hour_chk CHECK (((summary_hour >= 0) AND (summary_hour <= 23))),
    ADD CONSTRAINT probe_rules_summary_zone_chk CHECK (((summary_time_zone = btrim(summary_time_zone)) AND (summary_time_zone <> ''::text)));

ALTER TABLE platform.probe_rules
    ALTER COLUMN streak_ban_minutes DROP DEFAULT,
    ALTER COLUMN summary_enabled DROP DEFAULT,
    ALTER COLUMN summary_hour DROP DEFAULT,
    ALTER COLUMN summary_time_zone DROP DEFAULT;

CREATE FUNCTION platform.probe_rules_reject_zone() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    BEGIN
        PERFORM (CURRENT_TIMESTAMP AT TIME ZONE NEW.summary_time_zone);
    EXCEPTION
        WHEN invalid_parameter_value THEN
            RAISE EXCEPTION 'the ban summary time zone is not an IANA name'
                USING ERRCODE = '23514';
    END;
    RETURN NEW;
END;
$$;

CREATE TRIGGER probe_rules_zone
    BEFORE INSERT OR UPDATE OF summary_time_zone ON platform.probe_rules
    FOR EACH ROW EXECUTE FUNCTION platform.probe_rules_reject_zone();

REVOKE ALL ON FUNCTION platform.probe_rules_reject_zone() FROM PUBLIC;

CREATE OR REPLACE FUNCTION platform.probe_ban(p_address text, p_reason text, p_agent text, p_path text) RETURNS boolean
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $$
DECLARE
    rules platform.probe_rules%ROWTYPE;
    prior platform.probe_bans%ROWTYPE;
    now_at timestamp with time zone := clock_timestamp();
    next_strike integer;
    minutes integer;
BEGIN
    IF p_address IS NULL OR p_address !~ '^[A-Za-z0-9:.]{1,64}$' THEN
        RAISE EXCEPTION 'a probe ban needs a client address'
            USING ERRCODE = '23514';
    END IF;
    IF p_reason IS NULL OR p_reason NOT IN ('scanner', 'streak') THEN
        RAISE EXCEPTION 'a probe ban needs a known reason'
            USING ERRCODE = '23514';
    END IF;
    PERFORM pg_advisory_xact_lock(87301, hashtext(p_address));
    SELECT * INTO rules FROM platform.probe_rules WHERE id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'the probe rules are missing'
            USING ERRCODE = '23514';
    END IF;
    SELECT * INTO prior FROM platform.probe_bans WHERE client_address = p_address;
    IF FOUND AND prior.banned_until > now_at THEN
        RETURN false;
    END IF;
    IF FOUND AND prior.banned_until > now_at - make_interval(days => rules.strike_reset_days) THEN
        next_strike := prior.strikes + 1;
    ELSE
        next_strike := 1;
    END IF;
    IF p_reason = 'streak' THEN
        minutes := rules.streak_ban_minutes;
    ELSE
        minutes := rules.scanner_ban_minutes[least(next_strike, cardinality(rules.scanner_ban_minutes))];
    END IF;
    INSERT INTO platform.probe_bans (client_address, banned_at, reason, user_agent, path, strikes, banned_until)
    VALUES (
        p_address,
        now_at,
        p_reason,
        left(COALESCE(p_agent, ''), 200),
        left(COALESCE(p_path, ''), 200),
        next_strike,
        now_at + make_interval(mins => minutes)
    )
    ON CONFLICT (client_address) DO UPDATE
    SET banned_at = EXCLUDED.banned_at,
        reason = EXCLUDED.reason,
        user_agent = EXCLUDED.user_agent,
        path = EXCLUDED.path,
        strikes = EXCLUDED.strikes,
        banned_until = EXCLUDED.banned_until;
    DELETE FROM platform.probe_streaks WHERE client_address = p_address;
    RETURN true;
END;
$$;

-- A streak ban already running past the streak length ends at the streak length.
UPDATE platform.probe_bans AS ban
SET banned_until = ban.banned_at + make_interval(mins => rules.streak_ban_minutes)
FROM platform.probe_rules AS rules
WHERE rules.id
  AND ban.reason = 'streak'
  AND ban.banned_until > ban.banned_at + make_interval(mins => rules.streak_ban_minutes);

-- The local day whose summary is due now, or NULL while the summary is off or
-- the summary hour has not come yet today.
CREATE FUNCTION platform.probe_ban_summary_day() RETURNS date
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $$
DECLARE
    rules platform.probe_rules%ROWTYPE;
    local_now timestamp without time zone;
BEGIN
    SELECT * INTO rules FROM platform.probe_rules WHERE id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'the probe rules are missing'
            USING ERRCODE = '23514';
    END IF;
    IF NOT rules.summary_enabled THEN
        RETURN NULL;
    END IF;
    local_now := clock_timestamp() AT TIME ZONE rules.summary_time_zone;
    IF extract(hour FROM local_now) < rules.summary_hour THEN
        RETURN NULL;
    END IF;

    RETURN local_now::date;
END;
$$;

-- The bans that started in the day before the summary hour on p_day, in the
-- summary time zone. A lifted ban is gone and is not listed.
CREATE FUNCTION platform.probe_ban_summary(p_day date)
RETURNS TABLE (
    address text,
    reason text,
    strikes integer,
    path text,
    banned_at text,
    banned_until text,
    time_zone text
)
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $$
DECLARE
    rules platform.probe_rules%ROWTYPE;
    period_end timestamp with time zone;
BEGIN
    IF p_day IS NULL THEN
        RAISE EXCEPTION 'the ban summary needs a day'
            USING ERRCODE = '22023';
    END IF;
    SELECT * INTO rules FROM platform.probe_rules WHERE id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'the probe rules are missing'
            USING ERRCODE = '23514';
    END IF;
    period_end := (p_day + make_interval(hours => rules.summary_hour)) AT TIME ZONE rules.summary_time_zone;

    RETURN QUERY
    SELECT ban.client_address,
           ban.reason,
           ban.strikes,
           ban.path,
           to_char(ban.banned_at AT TIME ZONE rules.summary_time_zone, 'YYYY-MM-DD HH24:MI'),
           to_char(ban.banned_until AT TIME ZONE rules.summary_time_zone, 'YYYY-MM-DD HH24:MI'),
           rules.summary_time_zone
    FROM platform.probe_bans AS ban
    WHERE ban.banned_at >= period_end - interval '1 day'
      AND ban.banned_at < period_end
    ORDER BY ban.banned_at, ban.client_address;
END;
$$;

REVOKE ALL ON FUNCTION platform.probe_ban_summary_day() FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.probe_ban_summary(date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION platform.probe_ban_summary_day() TO stall_worker;
GRANT EXECUTE ON FUNCTION platform.probe_ban_summary(date) TO stall_worker;

-- The digest files its mail through the platform mailbox like every other
-- platform letter, so a failed send retries as an outbound-mail job.
GRANT EXECUTE ON FUNCTION platform.file_outbound_mail(text, text, text, text, text, text) TO stall_worker;

ALTER TABLE platform.jobs DROP CONSTRAINT jobs_kind_chk;
ALTER TABLE platform.jobs ADD CONSTRAINT jobs_kind_chk CHECK (kind = ANY (ARRAY[
    'assess-late-fees'::text,
    'vendor-digest'::text,
    'rent-reminder'::text,
    'reconcile-reports'::text,
    'sale-notice'::text,
    'vendor-letter'::text,
    'markdown-propose'::text,
    'outbound-mail'::text,
    'probe-ban-digest'::text,
    'store-provision'::text,
    'signup-reap'::text
]));


-- Store signups
--
-- A public signup writes one pending row and nothing else: no store, no owner,
-- no sign-in directory entry, no reserved address. The store is built only
-- after the owner opens the emailed link, and it is built by the worker as
-- stall_provisioner, which is not a superuser. The web request never holds
-- the superuser password.
--
-- platform.signup_rules holds the limits. A one-time proof-of-work challenge
-- makes each request cost the sender about a second of work.

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'stall_provisioner') THEN
        CREATE ROLE stall_provisioner NOINHERIT NOLOGIN NOSUPERUSER NOCREATEDB CREATEROLE;
    END IF;
    EXECUTE format('GRANT CREATE ON DATABASE %I TO stall_provisioner', current_database());
END;
$$;

-- The worker builds a store under SET ROLE stall_provisioner. It does not
-- inherit the role's rights for anything else it runs.
GRANT stall_provisioner TO stall_worker WITH INHERIT FALSE, SET TRUE;

CREATE TABLE platform.signup_rules (
    id boolean DEFAULT true NOT NULL,
    pending_hours integer NOT NULL,
    per_address_per_hour integer NOT NULL,
    per_email_per_hour integer NOT NULL,
    pending_cap_per_hour integer NOT NULL,
    proof_bits integer NOT NULL,
    proof_minutes integer NOT NULL,
    kept_days integer NOT NULL,
    CONSTRAINT signup_rules_pkey PRIMARY KEY (id),
    CONSTRAINT signup_rules_one_row_chk CHECK (id),
    CONSTRAINT signup_rules_pending_chk CHECK (pending_hours BETWEEN 1 AND 168),
    CONSTRAINT signup_rules_address_chk CHECK (per_address_per_hour BETWEEN 1 AND 1000),
    CONSTRAINT signup_rules_email_chk CHECK (per_email_per_hour BETWEEN 1 AND 100),
    CONSTRAINT signup_rules_cap_chk CHECK (pending_cap_per_hour BETWEEN 1 AND 100000),
    CONSTRAINT signup_rules_bits_chk CHECK (proof_bits BETWEEN 8 AND 28),
    CONSTRAINT signup_rules_proof_minutes_chk CHECK (proof_minutes BETWEEN 1 AND 120),
    CONSTRAINT signup_rules_kept_chk CHECK (kept_days BETWEEN 1 AND 365)
);

INSERT INTO platform.signup_rules (
    pending_hours, per_address_per_hour, per_email_per_hour, pending_cap_per_hour,
    proof_bits, proof_minutes, kept_days
) VALUES (24, 5, 3, 200, 18, 30, 30);

CREATE TABLE platform.store_signups (
    id uuid DEFAULT uuidv7() NOT NULL,
    email text NOT NULL,
    store_name text NOT NULL,
    password_hash text,
    token_hash text NOT NULL,
    client_address text,
    admin_id uuid,
    status text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    verified_at timestamp with time zone,
    provisioned_at timestamp with time zone,
    tenant_id uuid,
    slug text,
    last_error text,
    ready_mail_id uuid,
    CONSTRAINT store_signups_pkey PRIMARY KEY (id),
    CONSTRAINT store_signups_token_key UNIQUE (token_hash),
    CONSTRAINT store_signups_token_chk CHECK (token_hash ~ '^[0-9a-f]{64}$'),
    CONSTRAINT store_signups_email_chk CHECK (
        email = lower(btrim(email))
        AND char_length(email) <= 200
        AND email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'
    ),
    CONSTRAINT store_signups_name_chk CHECK (
        store_name = btrim(store_name) AND store_name <> '' AND char_length(store_name) <= 120
    ),
    CONSTRAINT store_signups_hash_chk CHECK (password_hash IS NULL OR password_hash <> ''),
    CONSTRAINT store_signups_who_chk CHECK (
        (admin_id IS NULL AND client_address IS NOT NULL AND char_length(client_address) <= 64)
        OR (admin_id IS NOT NULL AND client_address IS NULL)
    ),
    CONSTRAINT store_signups_error_chk CHECK (last_error IS NULL OR char_length(last_error) <= 500),
    CONSTRAINT store_signups_status_chk CHECK (status = ANY (ARRAY[
        'pending', 'verified', 'provisioning', 'ready', 'failed', 'expired', 'discarded'
    ])),
    -- A password hash lives only while the store is still to be built.
    CONSTRAINT store_signups_shape_chk CHECK (
        (status = 'pending'
            AND verified_at IS NULL AND password_hash IS NOT NULL
            AND tenant_id IS NULL AND slug IS NULL AND provisioned_at IS NULL)
        OR (status = ANY (ARRAY['verified', 'provisioning', 'failed'])
            AND verified_at IS NOT NULL AND password_hash IS NOT NULL
            AND tenant_id IS NULL AND slug IS NULL AND provisioned_at IS NULL)
        OR (status = 'ready'
            AND verified_at IS NOT NULL AND password_hash IS NULL
            AND tenant_id IS NOT NULL AND slug IS NOT NULL AND provisioned_at IS NOT NULL)
        OR (status = ANY (ARRAY['expired', 'discarded'])
            AND password_hash IS NULL
            AND tenant_id IS NULL AND slug IS NULL AND provisioned_at IS NULL)
    )
);

-- One open signup per email at a time.
CREATE UNIQUE INDEX store_signups_open_email
    ON platform.store_signups USING btree (email)
    WHERE status = ANY (ARRAY['pending', 'verified', 'provisioning', 'failed']);

CREATE INDEX store_signups_created ON platform.store_signups USING btree (created_at);

CREATE FUNCTION platform.store_signups_guard() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF NEW.id IS DISTINCT FROM OLD.id
        OR NEW.email IS DISTINCT FROM OLD.email
        OR NEW.store_name IS DISTINCT FROM OLD.store_name
        OR NEW.client_address IS DISTINCT FROM OLD.client_address
        OR NEW.admin_id IS DISTINCT FROM OLD.admin_id
        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'a signup keeps who asked and for what'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.status = OLD.status THEN
        -- A resent link changes only the token and the deadline.
        IF NEW.status = 'pending'
            AND NEW.password_hash IS NOT DISTINCT FROM OLD.password_hash THEN
            RETURN NEW;
        END IF;
        -- The ready letter is filed once.
        IF NEW.status = 'ready'
            AND OLD.ready_mail_id IS NULL
            AND NEW.ready_mail_id IS NOT NULL
            AND NEW.token_hash = OLD.token_hash
            AND NEW.tenant_id = OLD.tenant_id
            AND NEW.slug = OLD.slug THEN
            RETURN NEW;
        END IF;
        RAISE EXCEPTION 'a % signup does not change in place', OLD.status
            USING ERRCODE = '23514';
    END IF;
    IF NEW.token_hash IS DISTINCT FROM OLD.token_hash THEN
        RAISE EXCEPTION 'a signup keeps its link'
            USING ERRCODE = '23514';
    END IF;
    IF NOT (
        (OLD.status = 'pending' AND NEW.status = ANY (ARRAY['verified', 'expired', 'discarded']))
        OR (OLD.status = 'verified' AND NEW.status = ANY (ARRAY['provisioning', 'discarded']))
        OR (OLD.status = 'provisioning' AND NEW.status = ANY (ARRAY['ready', 'failed']))
        OR (OLD.status = 'failed' AND NEW.status = ANY (ARRAY['provisioning', 'discarded']))
    ) THEN
        RAISE EXCEPTION 'a signup cannot go from % to %', OLD.status, NEW.status
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER store_signups_guard
    BEFORE UPDATE ON platform.store_signups
    FOR EACH ROW EXECUTE FUNCTION platform.store_signups_guard();

ALTER TABLE platform.store_signups ENABLE ALWAYS TRIGGER store_signups_guard;

-- How many unconfirmed signups the reaper removed each day.
CREATE TABLE platform.signup_reaped (
    day date NOT NULL,
    expired integer NOT NULL,
    CONSTRAINT signup_reaped_pkey PRIMARY KEY (day),
    CONSTRAINT signup_reaped_count_chk CHECK (expired >= 0)
);

CREATE TABLE platform.signup_challenges (
    seed text NOT NULL,
    bits integer NOT NULL,
    client_address text NOT NULL,
    issued_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    spent_at timestamp with time zone,
    CONSTRAINT signup_challenges_pkey PRIMARY KEY (seed),
    CONSTRAINT signup_challenges_seed_chk CHECK (seed ~ '^[0-9a-f]{32}$'),
    CONSTRAINT signup_challenges_bits_chk CHECK (bits BETWEEN 8 AND 28),
    CONSTRAINT signup_challenges_address_chk CHECK (char_length(client_address) <= 64)
);

CREATE INDEX signup_challenges_open
    ON platform.signup_challenges USING btree (client_address, issued_at)
    WHERE spent_at IS NULL;

ALTER TABLE platform.signup_attempts ADD COLUMN email text;
ALTER TABLE platform.signup_attempts
    ADD CONSTRAINT signup_attempts_email_chk CHECK (email IS NULL OR char_length(email) <= 200);
CREATE INDEX signup_attempts_email_window
    ON platform.signup_attempts USING btree (email, attempted_at);

-- True when sha256(seed ':' nonce) starts with the asked-for number of zero bits.
CREATE FUNCTION platform.signup_proof_ok(p_seed text, p_nonce text, p_bits integer) RETURNS boolean
    LANGUAGE sql IMMUTABLE
    SET search_path TO 'pg_catalog'
    AS $$
    SELECT p_seed ~ '^[0-9a-f]{32}$'
        AND p_nonce ~ '^[0-9]{1,20}$'
        AND p_bits BETWEEN 8 AND 28
        AND (('x' || lpad(encode(substr(sha256(convert_to(p_seed || ':' || p_nonce, 'UTF8')), 1, 4), 'hex'), 16, '0'))::bit(64)::bigint
            >> (32 - p_bits)) = 0;
$$;

-- A challenge for the signup form. An address with as many open challenges as
-- it may send signups in an hour gets its newest one again instead of a new row.
CREATE FUNCTION platform.signup_challenge(p_address text) RETURNS TABLE(seed text, bits integer)
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $$
DECLARE
    rules platform.signup_rules%ROWTYPE;
    open_count integer;
BEGIN
    IF p_address IS NULL OR p_address = '' OR char_length(p_address) > 64 THEN
        RAISE EXCEPTION 'a signup challenge needs the client address'
            USING ERRCODE = '22023';
    END IF;
    SELECT * INTO STRICT rules FROM platform.signup_rules;
    PERFORM pg_advisory_xact_lock(87232, hashtext(p_address));
    SELECT count(*)::integer INTO open_count
    FROM platform.signup_challenges AS challenge
    WHERE challenge.client_address = p_address
      AND challenge.spent_at IS NULL
      AND challenge.expires_at > clock_timestamp();
    IF open_count >= rules.per_address_per_hour THEN
        RETURN QUERY
            SELECT challenge.seed, challenge.bits
            FROM platform.signup_challenges AS challenge
            WHERE challenge.client_address = p_address
              AND challenge.spent_at IS NULL
              AND challenge.expires_at > clock_timestamp()
            ORDER BY challenge.issued_at DESC
            LIMIT 1;
        RETURN;
    END IF;
    RETURN QUERY
        INSERT INTO platform.signup_challenges (seed, bits, client_address, expires_at)
        VALUES (
            replace(gen_random_uuid()::text, '-', ''),
            rules.proof_bits,
            p_address,
            clock_timestamp() + make_interval(mins => rules.proof_minutes)
        )
        RETURNING signup_challenges.seed, signup_challenges.bits;
END;
$$;

-- A public signup request. The answer tells the web page what to send:
--   closed    signup is off
--   challenge the proof-of-work is missing, wrong, spent, or expired
--   limited   too many requests from this address this hour
--   busy      too many new signups across the platform this hour
--   known     the email already signs in somewhere: send "you already have a store"
--   quiet     send nothing (too many for this email, or its store is being built)
--   pending   a pending row was written: send the confirmation link
-- known, quiet, and pending look the same to the visitor.
CREATE FUNCTION platform.signup_request(
    p_email text,
    p_store_name text,
    p_password_hash text,
    p_token_hash text,
    p_address text,
    p_seed text,
    p_nonce text
) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $$
DECLARE
    rules platform.signup_rules%ROWTYPE;
    v_email text := lower(btrim(coalesce(p_email, '')));
    attempts integer;
BEGIN
    IF p_address IS NULL OR p_address = '' OR char_length(p_address) > 64 THEN
        RAISE EXCEPTION 'a signup needs the client address'
            USING ERRCODE = '22023';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM platform.settings
        WHERE key = 'store_signup_enabled' AND value = 'true'::jsonb
    ) THEN
        RETURN 'closed';
    END IF;
    SELECT * INTO STRICT rules FROM platform.signup_rules;
    PERFORM pg_advisory_xact_lock(87231, hashtext(p_address));

    UPDATE platform.signup_challenges AS challenge
    SET spent_at = clock_timestamp()
    WHERE challenge.seed = p_seed
      AND challenge.spent_at IS NULL
      AND challenge.expires_at > clock_timestamp()
      AND platform.signup_proof_ok(challenge.seed, p_nonce, challenge.bits);
    IF NOT FOUND THEN
        RETURN 'challenge';
    END IF;

    SELECT count(*)::integer INTO attempts
    FROM platform.signup_attempts AS attempt
    WHERE attempt.client_address = p_address
      AND attempt.attempted_at > clock_timestamp() - interval '1 hour';
    IF attempts >= rules.per_address_per_hour THEN
        RETURN 'limited';
    END IF;
    INSERT INTO platform.signup_attempts (client_address, email)
    VALUES (p_address, left(v_email, 200));

    PERFORM pg_advisory_xact_lock(87233, hashtext(v_email));
    SELECT count(*)::integer INTO attempts
    FROM platform.signup_attempts AS attempt
    WHERE attempt.email = left(v_email, 200)
      AND attempt.attempted_at > clock_timestamp() - interval '1 hour';
    IF attempts > rules.per_email_per_hour THEN
        RETURN 'quiet';
    END IF;

    IF EXISTS (SELECT 1 FROM platform.sign_in_directory WHERE email = v_email) THEN
        RETURN 'known';
    END IF;
    IF EXISTS (
        SELECT 1 FROM platform.store_signups
        WHERE email = v_email
          AND status = ANY (ARRAY['verified', 'provisioning', 'failed'])
    ) THEN
        RETURN 'quiet';
    END IF;

    PERFORM pg_advisory_xact_lock(87234, 0);
    IF (
        SELECT count(*) FROM platform.store_signups
        WHERE created_at > clock_timestamp() - interval '1 hour'
    ) >= rules.pending_cap_per_hour THEN
        RETURN 'busy';
    END IF;

    -- A second request replaces the first: only the newest link works.
    UPDATE platform.store_signups
    SET status = 'expired', password_hash = NULL
    WHERE email = v_email AND status = 'pending';

    INSERT INTO platform.store_signups (
        email, store_name, password_hash, token_hash, client_address, status, expires_at
    ) VALUES (
        v_email,
        btrim(p_store_name),
        p_password_hash,
        p_token_hash,
        p_address,
        'pending',
        clock_timestamp() + make_interval(hours => rules.pending_hours)
    );
    RETURN 'pending';
END;
$$;

-- An operator creates a store through the same queue. The email counts as
-- confirmed, so the row starts verified and the build job is filed now.
CREATE FUNCTION platform.signup_admin_create(
    p_email text,
    p_store_name text,
    p_password_hash text,
    p_token_hash text,
    p_admin_id uuid
) RETURNS uuid
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $$
DECLARE
    rules platform.signup_rules%ROWTYPE;
    v_email text := lower(btrim(coalesce(p_email, '')));
    signup_id uuid;
BEGIN
    IF p_admin_id IS NULL OR NOT EXISTS (SELECT 1 FROM platform.admins WHERE id = p_admin_id) THEN
        RAISE EXCEPTION 'only an operator creates a store here'
            USING ERRCODE = '42501';
    END IF;
    SELECT * INTO STRICT rules FROM platform.signup_rules;
    PERFORM pg_advisory_xact_lock(87233, hashtext(v_email));
    IF EXISTS (SELECT 1 FROM platform.sign_in_directory WHERE email = v_email) THEN
        RAISE EXCEPTION 'That email already signs in to a store.'
            USING ERRCODE = '23505';
    END IF;
    IF EXISTS (
        SELECT 1 FROM platform.store_signups
        WHERE email = v_email
          AND status = ANY (ARRAY['verified', 'provisioning', 'failed'])
    ) THEN
        RAISE EXCEPTION 'A store for that email is already being set up.'
            USING ERRCODE = '23505';
    END IF;
    UPDATE platform.store_signups
    SET status = 'discarded', password_hash = NULL
    WHERE email = v_email AND status = 'pending';
    INSERT INTO platform.store_signups (
        email, store_name, password_hash, token_hash, admin_id, status, expires_at, verified_at
    ) VALUES (
        v_email,
        btrim(p_store_name),
        p_password_hash,
        p_token_hash,
        p_admin_id,
        'verified',
        clock_timestamp() + make_interval(hours => rules.pending_hours),
        clock_timestamp()
    )
    RETURNING id INTO signup_id;
    INSERT INTO platform.jobs (tenant, kind, payload, run_at)
    VALUES ('platform', 'store-provision', jsonb_build_object('signup', signup_id::text), clock_timestamp());
    RETURN signup_id;
END;
$$;

-- Where a signup link stands. A pending link past its deadline reads as expired.
CREATE FUNCTION platform.signup_state(p_token_hash text)
    RETURNS TABLE(status text, email text, slug text)
    LANGUAGE sql STABLE SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $$
    SELECT CASE
               WHEN signup.status = 'pending' AND signup.expires_at <= clock_timestamp() THEN 'expired'
               ELSE signup.status
           END,
           signup.email,
           signup.slug
    FROM platform.store_signups AS signup
    WHERE signup.token_hash = p_token_hash;
$$;

-- The owner opened the link and pressed the button. The build job is filed in
-- the same transaction. Returns the signup's status afterwards, or NULL when
-- the link is unknown.
CREATE FUNCTION platform.signup_verify(p_token_hash text) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $$
DECLARE
    signup platform.store_signups%ROWTYPE;
BEGIN
    SELECT * INTO signup
    FROM platform.store_signups
    WHERE token_hash = p_token_hash
    FOR UPDATE;
    IF NOT FOUND THEN
        RETURN NULL;
    END IF;
    IF signup.status <> 'pending' THEN
        RETURN signup.status;
    END IF;
    IF signup.expires_at <= clock_timestamp() THEN
        UPDATE platform.store_signups
        SET status = 'expired', password_hash = NULL
        WHERE id = signup.id;
        RETURN 'expired';
    END IF;
    UPDATE platform.store_signups
    SET status = 'verified', verified_at = clock_timestamp()
    WHERE id = signup.id;
    INSERT INTO platform.jobs (tenant, kind, payload, run_at)
    VALUES ('platform', 'store-provision', jsonb_build_object('signup', signup.id::text), clock_timestamp());
    RETURN 'verified';
END;
$$;

-- Admin: send a pending signup a fresh link. Returns the email to send it to.
CREATE FUNCTION platform.signup_resend(p_id uuid, p_token_hash text) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $$
DECLARE
    rules platform.signup_rules%ROWTYPE;
    v_email text;
BEGIN
    SELECT * INTO STRICT rules FROM platform.signup_rules;
    UPDATE platform.store_signups
    SET token_hash = p_token_hash,
        expires_at = clock_timestamp() + make_interval(hours => rules.pending_hours)
    WHERE id = p_id AND status = 'pending'
    RETURNING email INTO v_email;
    IF v_email IS NULL THEN
        RAISE EXCEPTION 'Only a signup waiting for its link can get a new one.'
            USING ERRCODE = '23514';
    END IF;
    RETURN v_email;
END;
$$;

-- Admin: drop a signup that is waiting or failed. Its password hash goes too.
CREATE FUNCTION platform.signup_discard(p_id uuid) RETURNS text
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $$
DECLARE
    v_email text;
BEGIN
    UPDATE platform.store_signups
    SET status = 'discarded', password_hash = NULL
    WHERE id = p_id AND status = ANY (ARRAY['pending', 'verified', 'failed'])
    RETURNING email INTO v_email;
    IF v_email IS NULL THEN
        RAISE EXCEPTION 'Only a signup that is waiting or failed can be discarded.'
            USING ERRCODE = '23514';
    END IF;
    RETURN v_email;
END;
$$;

-- Daily clean-up. Unconfirmed signups past their deadline are deleted and only
-- counted. Finished and discarded rows go after kept_days. Failed rows stay
-- until an operator discards them. Returns how many unconfirmed rows went.
CREATE FUNCTION platform.signup_reap() RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $$
DECLARE
    rules platform.signup_rules%ROWTYPE;
    gone integer;
BEGIN
    SELECT * INTO STRICT rules FROM platform.signup_rules;
    DELETE FROM platform.store_signups
    WHERE (status = 'pending' AND expires_at <= clock_timestamp())
       OR status = 'expired';
    GET DIAGNOSTICS gone = ROW_COUNT;
    IF gone > 0 THEN
        INSERT INTO platform.signup_reaped (day, expired)
        VALUES ((clock_timestamp() AT TIME ZONE 'UTC')::date, gone)
        ON CONFLICT (day) DO UPDATE SET expired = signup_reaped.expired + EXCLUDED.expired;
    END IF;
    DELETE FROM platform.store_signups
    WHERE status = ANY (ARRAY['ready', 'discarded'])
      AND created_at <= clock_timestamp() - make_interval(days => rules.kept_days);
    DELETE FROM platform.signup_challenges
    WHERE expires_at <= clock_timestamp() - interval '1 day';
    DELETE FROM platform.signup_attempts
    WHERE attempted_at <= clock_timestamp() - interval '1 day';
    DELETE FROM platform.signup_reaped
    WHERE day <= (clock_timestamp() AT TIME ZONE 'UTC')::date - rules.kept_days;
    RETURN gone;
END;
$$;

-- Gives a store's poster role its platform rights. It runs as the installer,
-- which owns the platform objects, so the owner is always the grantor and a
-- store wipe's DROP OWNED takes every one of these back. The builder calls
-- it instead of holding grant options of its own.
CREATE FUNCTION platform.grant_poster_platform(poster text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $_$
BEGIN
    IF poster IS NULL OR poster !~ '^(stall_poster|poster_[0-9a-f]{32})$' THEN
        RAISE EXCEPTION 'a poster role name is required'
            USING ERRCODE = '23514';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_roles
        WHERE rolname = poster AND NOT rolsuper AND NOT rolcanlogin AND NOT rolcreaterole AND NOT rolcreatedb
    ) THEN
        RAISE EXCEPTION 'poster role % is missing', poster
            USING ERRCODE = '42704';
    END IF;
    EXECUTE format('GRANT USAGE ON SCHEMA platform TO %I', poster);
    EXECUTE format('REVOKE INSERT ON TABLE platform.jobs FROM %I', poster);
    EXECUTE format('GRANT SELECT ON TABLE platform.jobs TO %I', poster);
    EXECUTE format('GRANT SELECT, UPDATE ON TABLE platform.mail_outbox TO %I', poster);
    EXECUTE format(
        'GRANT EXECUTE ON FUNCTION platform.enqueue_vendor_letter(date, uuid), '
        'platform.file_outbound_mail(text, text, text, text, text, text), '
        'platform.latest_owner_backup(uuid), '
        'platform.record_owner_backup(uuid, uuid, bigint, text), '
        'platform.put_sign_in_directory(text, uuid, text, uuid, text), '
        'platform.clear_sign_in_directory(text, uuid) TO %I',
        poster
    );
END;
$_$;

REVOKE ALL ON FUNCTION platform.grant_poster_platform(text) FROM PUBLIC;

-- Every platform right a store's poster role must hold. The builder checks it
-- after the store is built, because a GRANT that cannot be made only warns.
CREATE FUNCTION platform.assert_poster_grants(poster text) RETURNS void
    LANGUAGE plpgsql STABLE
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    missing text[] := ARRAY[]::text[];
BEGIN
    IF poster IS NULL OR NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = poster) THEN
        RAISE EXCEPTION 'poster role % is missing', poster
            USING ERRCODE = '42704';
    END IF;
    IF NOT has_schema_privilege(poster, 'platform', 'USAGE') THEN
        missing := array_append(missing, 'USAGE ON SCHEMA platform'::text);
    END IF;
    IF NOT has_table_privilege(poster, 'platform.jobs', 'SELECT') THEN
        missing := array_append(missing, 'SELECT ON platform.jobs'::text);
    END IF;
    IF NOT has_table_privilege(poster, 'platform.mail_outbox', 'SELECT') THEN
        missing := array_append(missing, 'SELECT ON platform.mail_outbox'::text);
    END IF;
    IF NOT has_table_privilege(poster, 'platform.mail_outbox', 'UPDATE') THEN
        missing := array_append(missing, 'UPDATE ON platform.mail_outbox'::text);
    END IF;
    IF NOT has_function_privilege(poster, 'platform.enqueue_vendor_letter(date, uuid)', 'EXECUTE') THEN
        missing := array_append(missing, 'EXECUTE ON platform.enqueue_vendor_letter'::text);
    END IF;
    IF NOT has_function_privilege(poster, 'platform.file_outbound_mail(text, text, text, text, text, text)', 'EXECUTE') THEN
        missing := array_append(missing, 'EXECUTE ON platform.file_outbound_mail'::text);
    END IF;
    IF NOT has_function_privilege(poster, 'platform.latest_owner_backup(uuid)', 'EXECUTE') THEN
        missing := array_append(missing, 'EXECUTE ON platform.latest_owner_backup'::text);
    END IF;
    IF NOT has_function_privilege(poster, 'platform.record_owner_backup(uuid, uuid, bigint, text)', 'EXECUTE') THEN
        missing := array_append(missing, 'EXECUTE ON platform.record_owner_backup'::text);
    END IF;
    IF NOT has_function_privilege(poster, 'platform.put_sign_in_directory(text, uuid, text, uuid, text)', 'EXECUTE') THEN
        missing := array_append(missing, 'EXECUTE ON platform.put_sign_in_directory'::text);
    END IF;
    IF NOT has_function_privilege(poster, 'platform.clear_sign_in_directory(text, uuid)', 'EXECUTE') THEN
        missing := array_append(missing, 'EXECUTE ON platform.clear_sign_in_directory'::text);
    END IF;
    IF cardinality(missing) > 0 THEN
        RAISE EXCEPTION 'poster role % is missing %', poster, array_to_string(missing, ', ')
            USING ERRCODE = '42501';
    END IF;
END;
$$;

REVOKE ALL ON TABLE platform.signup_rules FROM PUBLIC;
REVOKE ALL ON TABLE platform.store_signups FROM PUBLIC;
REVOKE ALL ON TABLE platform.signup_reaped FROM PUBLIC;
REVOKE ALL ON TABLE platform.signup_challenges FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.signup_challenge(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.signup_request(text, text, text, text, text, text, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.signup_admin_create(text, text, text, text, uuid) FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.signup_state(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.signup_verify(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.signup_resend(uuid, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.signup_discard(uuid) FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.signup_reap() FROM PUBLIC;

-- The web role asks through the functions and reads the admin list. It never
-- writes a signup row and never sees a password hash or a link hash.
GRANT SELECT ON TABLE platform.signup_rules TO stall_app;
GRANT SELECT ON TABLE platform.signup_reaped TO stall_app;
GRANT SELECT (id, email, store_name, client_address, admin_id, status, created_at, expires_at,
              verified_at, provisioned_at, tenant_id, slug, last_error)
    ON TABLE platform.store_signups TO stall_app;
GRANT EXECUTE ON FUNCTION platform.signup_challenge(text) TO stall_app;
GRANT EXECUTE ON FUNCTION platform.signup_request(text, text, text, text, text, text, text) TO stall_app;
GRANT EXECUTE ON FUNCTION platform.signup_admin_create(text, text, text, text, uuid) TO stall_app;
GRANT EXECUTE ON FUNCTION platform.signup_state(text) TO stall_app;
GRANT EXECUTE ON FUNCTION platform.signup_verify(text) TO stall_app;
GRANT EXECUTE ON FUNCTION platform.signup_resend(uuid, text) TO stall_app;
GRANT EXECUTE ON FUNCTION platform.signup_discard(uuid) TO stall_app;

-- The worker moves a signup into and out of building, files the ready letter,
-- and runs the daily clean-up.
GRANT SELECT (id, email, store_name, admin_id, status, verified_at, tenant_id, slug, last_error, ready_mail_id)
    ON TABLE platform.store_signups TO stall_worker;
GRANT UPDATE (status, last_error, ready_mail_id) ON TABLE platform.store_signups TO stall_worker;
GRANT EXECUTE ON FUNCTION platform.signup_reap() TO stall_worker;

-- stall_provisioner builds the store: the tenant row, the schema, the poster
-- role and its grants, and the owner, then marks the signup ready. It holds no
-- grant options; grant_poster_platform gives a poster its platform rights.
GRANT USAGE ON SCHEMA platform TO stall_provisioner;
GRANT SELECT, INSERT ON TABLE platform.tenants TO stall_provisioner;
GRANT SELECT, INSERT ON TABLE platform.owners TO stall_provisioner;
GRANT INSERT ON TABLE platform.admin_audit TO stall_provisioner;
GRANT SELECT ON TABLE platform.store_signups TO stall_provisioner;
GRANT UPDATE (status, tenant_id, slug, provisioned_at, password_hash)
    ON TABLE platform.store_signups TO stall_provisioner;
GRANT EXECUTE ON FUNCTION platform.create_poster(text), platform.grant_poster_platform(text)
    TO stall_provisioner;
