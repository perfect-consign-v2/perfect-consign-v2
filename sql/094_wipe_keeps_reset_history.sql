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
