-- Platform operator accounts, password resets, append-only admin audit, and controlled wipe.

CREATE TABLE platform.admins (
    id uuid DEFAULT uuidv7() NOT NULL,
    email text NOT NULL,
    password_hash text NOT NULL,
    credentials_version integer DEFAULT 1 NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT admins_pkey PRIMARY KEY (id),
    CONSTRAINT admins_email_key UNIQUE (email),
    CONSTRAINT admins_credentials_version_chk CHECK (credentials_version >= 1),
    CONSTRAINT admins_email_chk CHECK (
        (email = btrim(email))
        AND (char_length(email) <= 200)
        AND (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text)
    ),
    CONSTRAINT admins_hash_chk CHECK (password_hash <> ''::text)
);

CREATE TABLE platform.admin_sign_in_failures (
    id uuid DEFAULT uuidv7() NOT NULL,
    email text NOT NULL,
    failed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    client_address text DEFAULT ''::text NOT NULL,
    CONSTRAINT admin_sign_in_failures_pkey PRIMARY KEY (id),
    CONSTRAINT admin_sign_in_failures_address_chk CHECK (char_length(client_address) <= 64),
    CONSTRAINT admin_sign_in_failures_email_chk CHECK (
        (email = btrim(email))
        AND (char_length(email) <= 200)
        AND (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text)
    )
);

CREATE INDEX admin_sign_in_failures_window
    ON platform.admin_sign_in_failures USING btree (email, client_address, failed_at);

CREATE TABLE platform.admin_password_reset_requests (
    id uuid DEFAULT uuidv7() NOT NULL,
    email text NOT NULL,
    requested_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT admin_password_reset_requests_pkey PRIMARY KEY (id),
    CONSTRAINT admin_password_reset_requests_email_chk CHECK (
        (email = btrim(email))
        AND (char_length(email) <= 200)
        AND (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text)
    )
);

CREATE INDEX admin_password_reset_requests_window
    ON platform.admin_password_reset_requests USING btree (email, requested_at);

CREATE TABLE platform.admin_password_resets (
    id uuid DEFAULT uuidv7() NOT NULL,
    account_id uuid NOT NULL,
    token_hash text NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    used_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT admin_password_resets_pkey PRIMARY KEY (id),
    CONSTRAINT admin_password_resets_hash_key UNIQUE (token_hash),
    CONSTRAINT admin_password_resets_hash_chk CHECK (token_hash ~ '^[0-9a-f]{64}$'::text),
    CONSTRAINT admin_password_resets_account_id_fkey FOREIGN KEY (account_id) REFERENCES platform.admins (id)
);

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
    CONSTRAINT admin_audit_pkey PRIMARY KEY (id),
    CONSTRAINT admin_audit_action_chk CHECK (action = ANY (ARRAY[
        'admin.sign_in'::text,
        'admin.sign_in_refused'::text,
        'admin.password_change'::text,
        'admin.password_reset_request'::text,
        'admin.password_reset'::text,
        'tenant.enter_as'::text,
        'tenant.enter_as_refused'::text,
        'tenant.delete'::text,
        'tenant.delete_refused'::text
    ])),
    CONSTRAINT admin_audit_outcome_chk CHECK (outcome = ANY (ARRAY['ok'::text, 'refused'::text, 'failed'::text])),
    CONSTRAINT admin_audit_ok_admin_chk CHECK ((outcome <> 'ok'::text) OR (admin_id IS NOT NULL)),
    CONSTRAINT admin_audit_email_chk CHECK (
        (admin_email = btrim(admin_email))
        AND (char_length(admin_email) <= 200)
        AND (admin_email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text)
    ),
    CONSTRAINT admin_audit_request_chk CHECK ((request_id = btrim(request_id)) AND (request_id <> ''::text)),
    CONSTRAINT admin_audit_address_chk CHECK (char_length(client_address) <= 64),
    CONSTRAINT admin_audit_slug_chk CHECK (
        (slug IS NULL)
        OR ((slug = btrim(slug)) AND (slug ~ '^[a-z][a-z0-9-]{0,62}$'::text))
    ),
    CONSTRAINT admin_audit_admin_id_fkey FOREIGN KEY (admin_id) REFERENCES platform.admins (id)
);

CREATE INDEX admin_audit_at ON platform.admin_audit USING btree (at DESC);

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

CREATE TRIGGER admin_password_resets_append_only
    BEFORE DELETE OR UPDATE ON platform.admin_password_resets
    FOR EACH ROW EXECUTE FUNCTION platform.reject_admin_password_reset_mutation();

ALTER TABLE platform.admin_password_resets ENABLE ALWAYS TRIGGER admin_password_resets_append_only;

CREATE TRIGGER admin_password_resets_no_truncate
    BEFORE TRUNCATE ON platform.admin_password_resets
    FOR EACH STATEMENT EXECUTE FUNCTION platform.reject_admin_password_reset_truncate();

ALTER TABLE platform.admin_password_resets ENABLE ALWAYS TRIGGER admin_password_resets_no_truncate;

CREATE TRIGGER admin_audit_append_only
    BEFORE DELETE OR UPDATE ON platform.admin_audit
    FOR EACH ROW EXECUTE FUNCTION platform.reject_admin_audit_mutation();

ALTER TABLE platform.admin_audit ENABLE ALWAYS TRIGGER admin_audit_append_only;

CREATE TRIGGER admin_audit_no_truncate
    BEFORE TRUNCATE ON platform.admin_audit
    FOR EACH STATEMENT EXECUTE FUNCTION platform.reject_admin_audit_truncate();

ALTER TABLE platform.admin_audit ENABLE ALWAYS TRIGGER admin_audit_no_truncate;

CREATE EVENT TRIGGER platform_reject_admin_append_only_change ON ddl_command_end
    WHEN TAG IN ('ALTER TABLE', 'CREATE FUNCTION', 'ALTER FUNCTION', 'ALTER ROUTINE')
    EXECUTE FUNCTION platform.reject_admin_append_only_guard();

ALTER EVENT TRIGGER platform_reject_admin_append_only_change ENABLE ALWAYS;

CREATE EVENT TRIGGER platform_reject_admin_append_only_drop ON sql_drop
    WHEN TAG IN ('DROP TRIGGER', 'DROP FUNCTION')
    EXECUTE FUNCTION platform.reject_admin_append_only_guard();

ALTER EVENT TRIGGER platform_reject_admin_append_only_drop ENABLE ALWAYS;

CREATE FUNCTION platform.wipe_tenant(target_slug text) RETURNS void
    LANGUAGE plpgsql
    SECURITY DEFINER
    SET search_path TO 'platform', 'pg_catalog'
    AS $$
DECLARE
    schema_name text;
    poster text;
    reset_present boolean;
BEGIN
    IF target_slug IS NULL OR target_slug !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RAISE EXCEPTION 'refusing to remove %', target_slug;
    END IF;
    SELECT tenants.schema_name INTO schema_name
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
        WHERE resets.tenant_id = (SELECT id FROM platform.tenants WHERE slug = target_slug)
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
    DELETE FROM platform.owner_sign_in_failures
    WHERE tenant_id = (SELECT id FROM platform.tenants WHERE slug = target_slug);
    DELETE FROM platform.password_reset_requests
    WHERE tenant_id = (SELECT id FROM platform.tenants WHERE slug = target_slug);
    DELETE FROM platform.owners
    WHERE tenant_id = (SELECT id FROM platform.tenants WHERE slug = target_slug);
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
$$;

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
