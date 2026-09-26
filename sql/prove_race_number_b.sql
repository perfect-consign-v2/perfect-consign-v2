-- Session B. Must block on the counter, then roll back.
BEGIN;
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000d817',
    '01900000-0000-7000-8000-000000000001',
    'race-number-b',
    '2033-03-02',
    '2033-03-02 12:00:00+00',
    '01900000-0000-7000-8000-00000000d029',
    'USD',
    'proof',
    'race-number-b',
    'Race number B',
    100,
    100
);
ROLLBACK;
