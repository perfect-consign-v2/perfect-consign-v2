-- Recovery email and session generation for cashier and vendor sign-ins.
-- Apply inside each tenant schema, after 056. The provisioner rewrites stall_poster.
-- Re-running this file is safe: a tenant stamp rebuild executes it again.

DO $staff$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'staff' AND column_name = 'email'
    ) THEN
        ALTER TABLE staff ADD COLUMN email text;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'staff' AND column_name = 'credentials_version'
    ) THEN
        ALTER TABLE staff ADD COLUMN credentials_version integer NOT NULL DEFAULT 1;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'staff_email_chk' AND connamespace = current_schema()::regnamespace
    ) THEN
        ALTER TABLE staff ADD CONSTRAINT staff_email_chk CHECK (
            email IS NULL
            OR (
                email = btrim(email)
                AND char_length(email) <= 200
                AND email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'
            )
        );
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'staff_credentials_version_chk' AND connamespace = current_schema()::regnamespace
    ) THEN
        ALTER TABLE staff ADD CONSTRAINT staff_credentials_version_chk CHECK (credentials_version >= 1);
    END IF;
END
$staff$;
