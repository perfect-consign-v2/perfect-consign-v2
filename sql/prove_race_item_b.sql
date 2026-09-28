-- Session B waits for the same item tag, then rolls back.
BEGIN;
INSERT INTO items (id, code, vendor_id, description, price_minor)
VALUES (
    '01900000-0000-7000-8000-00000000d0c2',
    'Race item',
    '01900000-0000-7000-8000-00000000d0c0',
    'Race piece',
    100
);
ROLLBACK;
