-- Forgot-password spray: client address + prune indexes.

ALTER TABLE platform.password_reset_requests
    ADD COLUMN IF NOT EXISTS client_address text DEFAULT ''::text NOT NULL;

ALTER TABLE platform.password_reset_requests
    DROP CONSTRAINT IF EXISTS password_reset_requests_address_chk;

ALTER TABLE platform.password_reset_requests
    ADD CONSTRAINT password_reset_requests_address_chk CHECK ((char_length(client_address) <= 64));

ALTER TABLE platform.admin_password_reset_requests
    ADD COLUMN IF NOT EXISTS client_address text DEFAULT ''::text NOT NULL;

ALTER TABLE platform.admin_password_reset_requests
    DROP CONSTRAINT IF EXISTS admin_password_reset_requests_address_chk;

ALTER TABLE platform.admin_password_reset_requests
    ADD CONSTRAINT admin_password_reset_requests_address_chk CHECK ((char_length(client_address) <= 64));

CREATE INDEX IF NOT EXISTS password_reset_requests_stale
    ON platform.password_reset_requests USING btree (requested_at);

CREATE INDEX IF NOT EXISTS password_reset_requests_address
    ON platform.password_reset_requests USING btree (client_address, requested_at);

CREATE INDEX IF NOT EXISTS admin_password_reset_requests_stale
    ON platform.admin_password_reset_requests USING btree (requested_at);

CREATE INDEX IF NOT EXISTS admin_password_reset_requests_address
    ON platform.admin_password_reset_requests USING btree (client_address, requested_at);
