-- The owner can rename the mall. Slug and schema stay fixed.
-- Apply once to database consign, after sql/057_owner_recovery.sql.
-- Re-running this file is safe: a platform stamp rebuild executes it again.

DO $name$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tenants_name_length_chk') THEN
        ALTER TABLE platform.tenants
            ADD CONSTRAINT tenants_name_length_chk CHECK (char_length(display_name) <= 120);
    END IF;
END
$name$;

GRANT UPDATE (display_name) ON TABLE platform.tenants TO stall_app;
