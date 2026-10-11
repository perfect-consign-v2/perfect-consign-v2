-- Session B waits for the same item tag, then rolls back.
BEGIN;
INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on)
VALUES (
    '01900000-0000-7000-8000-00000000d0c2',
    'Race item',
    '01900000-0000-7000-8000-00000000d0c0',
    'Race item',
    100,
    'Race tag b',
    DATE '2026-09-27'
);
ROLLBACK;
