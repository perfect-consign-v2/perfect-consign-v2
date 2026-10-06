CREATE TABLE IF NOT EXISTS platform.schema_migrations (
    filename text PRIMARY KEY,
    checksum text NOT NULL,
    applied_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT schema_migrations_filename_chk CHECK (filename ~ '^[0-9]{3}_[a-z0-9_]+\.sql$'),
    CONSTRAINT schema_migrations_checksum_chk CHECK (checksum ~ '^[0-9a-f]{64}$')
);

REVOKE ALL ON TABLE platform.schema_migrations FROM PUBLIC;
