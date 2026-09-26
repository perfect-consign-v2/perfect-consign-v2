-- stall_app does not inherit poster rights. Roled as the mall poster, it cannot read another schema.
-- The script rolls back.

BEGIN;

DO $$
DECLARE
    mall_schema text;
BEGIN
    SELECT schema_name INTO mall_schema FROM platform.tenants WHERE slug = 'mall';
    IF mall_schema IS NULL THEN
        RAISE EXCEPTION 'mall tenant is missing';
    END IF;

    CREATE SCHEMA tenant_abababababababababababababababab;
    CREATE TABLE tenant_abababababababababababababababab.marker (id integer);

    EXECUTE 'SET LOCAL ROLE stall_app';
    BEGIN
        EXECUTE format('SELECT 1 FROM %I.journals', mall_schema);
        RAISE EXCEPTION 'stall_app read the mall without its poster role';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;

    EXECUTE 'SET LOCAL ROLE stall_poster';
    EXECUTE format('SELECT count(*) FROM %I.journals', mall_schema);

    BEGIN
        PERFORM 1 FROM tenant_abababababababababababababababab.marker;
        RAISE EXCEPTION 'the mall poster read another schema';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
END $$;

ROLLBACK;
