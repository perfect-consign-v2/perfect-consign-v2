-- Session B inserts the same tenant slug. Both committing is a failure.
BEGIN;
INSERT INTO platform.tenants (id, slug, schema_name, display_name)
VALUES (
    '01900000-0000-7000-8000-00000000da02',
    'race-tenant',
    'tenant_da0000000000000000000000000000b2',
    'Race tenant two'
);
COMMIT;
