-- Address-wide spray checks seek by client_address + failed_at; the window
-- index leads with email.

CREATE INDEX IF NOT EXISTS email_sign_in_failures_address
    ON platform.email_sign_in_failures USING btree (client_address, failed_at);
