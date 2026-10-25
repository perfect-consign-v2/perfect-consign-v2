-- Owner lockout rows are only for stall_app. A poster must not clear them.

REVOKE SELECT, INSERT, DELETE ON TABLE platform.owner_sign_in_failures FROM stall_poster;

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
                'REVOKE SELECT, INSERT, DELETE ON TABLE platform.owner_sign_in_failures FROM %I',
                poster
            );
        END IF;
    END LOOP;
END $$;
