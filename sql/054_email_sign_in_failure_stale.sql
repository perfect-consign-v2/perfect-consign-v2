-- Stale failure cleanup seeks by failed_at alone; the window index starts with email.

CREATE INDEX email_sign_in_failures_stale
    ON platform.email_sign_in_failures USING btree (failed_at);
