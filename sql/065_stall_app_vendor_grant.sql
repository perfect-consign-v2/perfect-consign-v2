-- Forward the stall_vendor membership that was briefly edited into 000_platform
-- (a725d5d). Existing demos stamped the older 000 checksum; keep 000 frozen.

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'stall_app') THEN
        GRANT stall_vendor TO stall_app;
    END IF;
    EXECUTE format('GRANT stall_vendor TO %I', current_user);
END $$;
