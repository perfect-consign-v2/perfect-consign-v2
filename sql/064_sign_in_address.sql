-- A failed sign-in is counted for that name at that address.
-- Apply once to database consign, after sql/060_store_name.sql.

ALTER TABLE platform.owner_sign_in_failures
    ADD COLUMN client_address text NOT NULL DEFAULT '';

ALTER TABLE platform.owner_sign_in_failures
    ADD CONSTRAINT owner_sign_in_failures_address_chk CHECK (
        char_length(client_address) <= 64
    );

DROP INDEX IF EXISTS platform.owner_sign_in_failures_window;

CREATE INDEX owner_sign_in_failures_window
    ON platform.owner_sign_in_failures (tenant_id, username, client_address, failed_at);
