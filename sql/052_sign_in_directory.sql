-- Platform-unique sign-in emails. Global login resolves mall from this directory.

CREATE TABLE platform.sign_in_directory (
    email text NOT NULL,
    tenant_id uuid NOT NULL,
    account_kind text NOT NULL,
    account_id uuid NOT NULL,
    username text NOT NULL,
    CONSTRAINT sign_in_directory_pkey PRIMARY KEY (account_kind, account_id),
    CONSTRAINT sign_in_directory_email_chk CHECK (
        (email = btrim(email))
        AND (char_length(email) <= 200)
        AND (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text)
    ),
    CONSTRAINT sign_in_directory_kind_chk CHECK (account_kind = ANY (ARRAY['owner'::text, 'staff'::text])),
    CONSTRAINT sign_in_directory_username_chk CHECK (
        (username = btrim(username))
        AND (username ~ '^[a-z][a-z0-9-]{0,62}$'::text)
    ),
    CONSTRAINT sign_in_directory_tenant_id_fkey FOREIGN KEY (tenant_id) REFERENCES platform.tenants (id)
);

CREATE UNIQUE INDEX sign_in_directory_email_key
    ON platform.sign_in_directory USING btree (lower(email));

CREATE TABLE platform.email_sign_in_failures (
    id uuid DEFAULT uuidv7() NOT NULL,
    email text NOT NULL,
    failed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    client_address text DEFAULT ''::text NOT NULL,
    CONSTRAINT email_sign_in_failures_pkey PRIMARY KEY (id),
    CONSTRAINT email_sign_in_failures_address_chk CHECK (char_length(client_address) <= 64),
    CONSTRAINT email_sign_in_failures_email_chk CHECK (
        (email = btrim(email))
        AND (char_length(email) <= 200)
        AND (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text)
    )
);

CREATE INDEX email_sign_in_failures_window
    ON platform.email_sign_in_failures USING btree (email, client_address, failed_at);

CREATE UNIQUE INDEX owners_email_key
    ON platform.owners USING btree (lower(email))
    WHERE (email IS NOT NULL);

CREATE FUNCTION platform.put_sign_in_directory(
    p_email text,
    p_tenant_id uuid,
    p_kind text,
    p_account_id uuid,
    p_username text
) RETURNS void
    LANGUAGE plpgsql
    SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $$
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
$$;

CREATE FUNCTION platform.clear_sign_in_directory(p_kind text, p_account_id uuid) RETURNS void
    LANGUAGE plpgsql
    SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    DELETE FROM platform.sign_in_directory
    WHERE account_kind = p_kind AND account_id = p_account_id;
END;
$$;

CREATE FUNCTION platform.owners_sign_in_directory() RETURNS trigger
    LANGUAGE plpgsql
    SECURITY DEFINER
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

CREATE TRIGGER owners_sign_in_directory
    AFTER INSERT OR UPDATE OF email, username, tenant_id ON platform.owners
    FOR EACH ROW
    EXECUTE FUNCTION platform.owners_sign_in_directory();

CREATE TRIGGER owners_sign_in_directory_delete
    AFTER DELETE ON platform.owners
    FOR EACH ROW
    EXECUTE FUNCTION platform.owners_sign_in_directory();

DO $$
DECLARE
    tenant record;
    clash text;
BEGIN
    CREATE TEMP TABLE sign_in_email_scan (
        email text NOT NULL,
        tenant_id uuid NOT NULL,
        account_kind text NOT NULL,
        account_id uuid NOT NULL,
        username text NOT NULL
    ) ON COMMIT DROP;

    INSERT INTO sign_in_email_scan (email, tenant_id, account_kind, account_id, username)
    SELECT lower(btrim(owners.email)), owners.tenant_id, 'owner', owners.id, owners.username
    FROM platform.owners AS owners
    WHERE owners.email IS NOT NULL;

    FOR tenant IN
        SELECT id, schema_name
        FROM platform.tenants
        WHERE schema_name ~ '^tenant_[0-9a-f]{32}$'
    LOOP
        EXECUTE format(
            'INSERT INTO sign_in_email_scan (email, tenant_id, account_kind, account_id, username)
             SELECT lower(btrim(staff.email)), $1, ''staff'', staff.id, lower(staff.username)
             FROM %I.staff AS staff
             WHERE staff.email IS NOT NULL',
            tenant.schema_name
        ) USING tenant.id;
    END LOOP;

    SELECT scan.email INTO clash
    FROM sign_in_email_scan AS scan
    GROUP BY scan.email
    HAVING count(*) > 1
    LIMIT 1;
    IF clash IS NOT NULL THEN
        RAISE EXCEPTION 'sign-in email % is used more than once; fix duplicates before applying 052_sign_in_directory', clash;
    END IF;

    INSERT INTO platform.sign_in_directory (email, tenant_id, account_kind, account_id, username)
    SELECT scan.email, scan.tenant_id, scan.account_kind, scan.account_id, scan.username
    FROM sign_in_email_scan AS scan;
END;
$$;

CREATE OR REPLACE FUNCTION platform.wipe_tenant(target_slug text) RETURNS void
    LANGUAGE plpgsql
    SECURITY DEFINER
    SET search_path TO 'platform', 'pg_catalog'
    AS $$
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
$$;

REVOKE ALL ON FUNCTION platform.wipe_tenant(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION platform.wipe_tenant(text) TO stall_app;

REVOKE ALL ON FUNCTION platform.put_sign_in_directory(text, uuid, text, uuid, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.clear_sign_in_directory(text, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION platform.put_sign_in_directory(text, uuid, text, uuid, text) TO stall_app;
GRANT EXECUTE ON FUNCTION platform.clear_sign_in_directory(text, uuid) TO stall_app;

GRANT SELECT ON TABLE platform.sign_in_directory TO stall_app;
GRANT SELECT, INSERT, DELETE ON TABLE platform.email_sign_in_failures TO stall_app;
