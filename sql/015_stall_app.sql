-- Application login. Not a superuser. It does not inherit poster rights.
-- Apply once to database consign, after sql/000_platform.sql.
-- Login and the password are set by bin/enable-stall-app.php.

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'stall_app') THEN
        CREATE ROLE stall_app NOINHERIT NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
    END IF;
END $$;

GRANT USAGE ON SCHEMA platform TO stall_app;
GRANT SELECT ON TABLE platform.tenants TO stall_app;
REVOKE INSERT, UPDATE, DELETE, TRUNCATE, TRIGGER ON TABLE platform.tenants FROM stall_app;

DO $$
DECLARE
    tenant record;
    poster_name text;
BEGIN
    FOR tenant IN
        SELECT slug, schema_name FROM platform.tenants
    LOOP
        poster_name := CASE
            WHEN tenant.slug = 'mall' THEN 'stall_poster'
            ELSE 'poster_' || substr(tenant.schema_name, 8)
        END;
        EXECUTE format('GRANT %I TO stall_app', poster_name);
    END LOOP;
END $$;
