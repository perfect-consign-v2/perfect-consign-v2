-- Session A holds the item tag, then rolls back.
BEGIN;
INSERT INTO items (id, code, vendor_id, description, price_minor)
VALUES (
    '01900000-0000-7000-8000-00000000d0c1',
    'Race item',
    '01900000-0000-7000-8000-00000000d0c0',
    'Race piece',
    100
);
SELECT pg_sleep(6);
ROLLBACK;
