-- Override failures are not owner sign-in failures, and they are not keyed by the till address.

CREATE TABLE platform.override_sign_in_failures (
    id uuid DEFAULT uuidv7() NOT NULL,
    tenant_id uuid NOT NULL,
    username text NOT NULL,
    failed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT override_sign_in_failures_pkey PRIMARY KEY (id),
    CONSTRAINT override_sign_in_failures_username_chk CHECK (((username = btrim(username)) AND (username ~ '^[a-z][a-z0-9-]{0,62}$'::text)))
);

CREATE INDEX override_sign_in_failures_window
    ON platform.override_sign_in_failures USING btree (tenant_id, username, failed_at);

GRANT SELECT, INSERT, DELETE ON TABLE platform.override_sign_in_failures TO stall_app;
GRANT SELECT, INSERT, DELETE ON TABLE platform.override_sign_in_failures TO stall_poster;

DO $$
DECLARE
    mall record;
    poster text;
BEGIN
    FOR mall IN SELECT slug, schema_name FROM platform.tenants LOOP
        IF mall.slug = 'mall' THEN
            poster := 'stall_poster';
        ELSE
            poster := 'poster_' || substr(mall.schema_name, 8);
        END IF;
        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = poster) THEN
            EXECUTE format(
                'GRANT SELECT, INSERT, DELETE ON TABLE platform.override_sign_in_failures TO %I',
                poster
            );
        END IF;
    END LOOP;
END $$;
