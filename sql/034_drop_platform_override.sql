-- Override failures now live in each mall.

DO $$
DECLARE
    mall record;
    poster text;
BEGIN
    IF to_regclass('platform.override_sign_in_failures') IS NULL THEN
        NULL;
    ELSIF EXISTS (
        SELECT 1
        FROM platform.tenants AS tenant_row
        WHERE NOT EXISTS (
            SELECT 1
            FROM pg_catalog.pg_class AS cls
            JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = cls.relnamespace
            WHERE nsp.nspname = tenant_row.schema_name
              AND cls.relname = 'override_sign_in_failures'
              AND cls.relkind = 'r'
        )
    ) THEN
        RAISE EXCEPTION '033_override_lockout.sql must be applied to every tenant before dropping platform.override_sign_in_failures';
    ELSE
        FOR mall IN SELECT slug, schema_name FROM platform.tenants LOOP
            IF mall.slug = 'mall' THEN
                poster := 'stall_poster';
            ELSE
                poster := 'poster_' || substr(mall.schema_name, 8);
            END IF;
            IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = poster) THEN
                EXECUTE format(
                    'REVOKE SELECT, INSERT, DELETE ON TABLE platform.override_sign_in_failures FROM %I',
                    poster
                );
            END IF;
        END LOOP;
        REVOKE SELECT, INSERT, DELETE ON TABLE platform.override_sign_in_failures FROM stall_app;
        REVOKE SELECT, INSERT, DELETE ON TABLE platform.override_sign_in_failures FROM stall_poster;
        DROP TABLE platform.override_sign_in_failures;
    END IF;
END $$;
