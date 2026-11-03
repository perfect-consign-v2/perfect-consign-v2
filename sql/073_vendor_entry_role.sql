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
