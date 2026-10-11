-- Session B waits for the same item, then rolls back.
BEGIN;
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000d0e2',
    '01900000-0000-7000-8000-000000000001',
    'sale:race-b',
    '2038-03-01',
    '2038-03-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000d0e4',
    'USD',
    'sale',
    'race-b',
    'Race sale B',
    100,
    100
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES (
    '01900000-0000-7000-8000-00000000d0e2',
    1,
    '01900000-0000-7000-8000-00000000d0e3',
    100,
    0,
    0
);
ROLLBACK;
