-- Admin sign-in spray and email-wide lockout indexes (mirror email_sign_in_failures).

CREATE INDEX IF NOT EXISTS admin_sign_in_failures_address
    ON platform.admin_sign_in_failures USING btree (client_address, failed_at);

CREATE INDEX IF NOT EXISTS admin_sign_in_failures_email
    ON platform.admin_sign_in_failures USING btree (email, failed_at);
