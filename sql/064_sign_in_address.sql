-- A failed sign-in is counted for that name at that address.
-- Apply once to database consign, after sql/060_store_name.sql.
-- Re-running this file is safe: a platform stamp rebuild executes it again.

DO $address$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'platform'
          AND table_name = 'owner_sign_in_failures'
          AND column_name = 'client_address'
    ) THEN
        ALTER TABLE platform.owner_sign_in_failures
            ADD COLUMN client_address text NOT NULL DEFAULT '';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint WHERE conname = 'owner_sign_in_failures_address_chk'
    ) THEN
        ALTER TABLE platform.owner_sign_in_failures
            ADD CONSTRAINT owner_sign_in_failures_address_chk CHECK (
                char_length(client_address) <= 64
            );
    END IF;
END
$address$;

DROP INDEX IF EXISTS platform.owner_sign_in_failures_window;

CREATE INDEX owner_sign_in_failures_window
    ON platform.owner_sign_in_failures (tenant_id, username, client_address, failed_at);
