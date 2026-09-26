-- Session A. Holds the journal counter, then rolls back.
BEGIN;
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000d816',
    '01900000-0000-7000-8000-000000000001',
    'race-number-a',
    '2033-03-01',
    '2033-03-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000d029',
    'USD',
    'proof',
    'race-number-a',
    'Race number A',
    100,
    100
);
SELECT pg_sleep(6);
ROLLBACK;
