-- Session B waits for the opening key, then rolls back.
BEGIN;
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000d819',
    '01900000-0000-7000-8000-000000000001',
    'opening',
    '2037-06-01',
    '2037-06-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000d037',
    'USD',
    'opening',
    'race-opening-b',
    'Race opening B',
    100,
    100
);
ROLLBACK;
