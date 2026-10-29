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
