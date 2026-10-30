-- Stale indexes for owner/admin sign-in failure prune (15-minute lock window).

CREATE INDEX IF NOT EXISTS owner_sign_in_failures_stale
    ON platform.owner_sign_in_failures USING btree (failed_at);

CREATE INDEX IF NOT EXISTS admin_sign_in_failures_stale
    ON platform.admin_sign_in_failures USING btree (failed_at);
