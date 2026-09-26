-- Session A holds the tenant slug, then commits.
BEGIN;
INSERT INTO platform.tenants (id, slug, schema_name, display_name)
VALUES (
    '01900000-0000-7000-8000-00000000da01',
    'race-tenant',
    'tenant_da0000000000000000000000000000a1',
    'Race tenant'
);
SELECT pg_sleep(6);
COMMIT;
