-- The worker logs in as its own role and assumes each mall poster role.

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'stall_worker') THEN
        CREATE ROLE stall_worker NOINHERIT NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
    END IF;
END $$;

DO $$
BEGIN
    EXECUTE format('GRANT CONNECT ON DATABASE %I TO stall_worker', current_database());
END $$;

GRANT USAGE ON SCHEMA platform TO stall_worker;
GRANT SELECT, INSERT, UPDATE ON TABLE platform.jobs TO stall_worker;
GRANT SELECT ON TABLE platform.tenants TO stall_worker;

DO $$
DECLARE
    mall record;
    poster text;
BEGIN
    FOR mall IN SELECT slug, schema_name FROM platform.tenants LOOP
        IF mall.slug = 'mall' THEN
            poster := 'stall_poster';
        ELSE
            poster := 'poster_' || substr(mall.schema_name, 8);
        END IF;
        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = poster) THEN
            EXECUTE format('GRANT %I TO stall_worker', poster);
        END IF;
    END LOOP;
END $$;
