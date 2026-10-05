-- One owner login per mall. The web app may read it. It may not change it.
-- Apply once to database consign, after sql/015_stall_app.sql.

CREATE TABLE IF NOT EXISTS platform.owners (
    id uuid PRIMARY KEY,
    tenant_id uuid NOT NULL UNIQUE REFERENCES platform.tenants (id),
    username text NOT NULL,
    password_hash text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT owners_username_chk CHECK (username = btrim(username) AND username ~ '^[a-z][a-z0-9-]{0,62}$'),
    CONSTRAINT owners_hash_chk CHECK (password_hash <> '')
);

REVOKE ALL ON TABLE platform.owners FROM PUBLIC;
GRANT SELECT ON TABLE platform.owners TO stall_app;
REVOKE INSERT, UPDATE, DELETE, TRUNCATE, TRIGGER ON TABLE platform.owners FROM stall_app;

CREATE TABLE IF NOT EXISTS platform.owner_sign_in_failures (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    tenant_id uuid NOT NULL,
    username text NOT NULL,
    failed_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT owner_sign_in_failures_username_chk CHECK (
        username = btrim(username) AND username ~ '^[a-z][a-z0-9-]{0,62}$'
    )
);

CREATE INDEX IF NOT EXISTS owner_sign_in_failures_window
    ON platform.owner_sign_in_failures (tenant_id, username, failed_at);

REVOKE ALL ON TABLE platform.owner_sign_in_failures FROM PUBLIC;
GRANT SELECT, INSERT, DELETE ON TABLE platform.owner_sign_in_failures TO stall_app;
REVOKE UPDATE, TRUNCATE, TRIGGER ON TABLE platform.owner_sign_in_failures FROM stall_app;
