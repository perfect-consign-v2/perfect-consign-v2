-- A consignor reads through this role. The role has no login of its own.

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'stall_vendor') THEN
        CREATE ROLE stall_vendor NOINHERIT NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
    END IF;
END $$;

DO $$
BEGIN
    EXECUTE format('GRANT stall_vendor TO %I', current_user);
END $$;
