-- Session A holds a settlement posting key, then rolls back.
BEGIN;
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000d8a2',
    '01900000-0000-7000-8000-000000000001',
    'settlement:01900000-0000-7000-8000-00000000d8a0:01900000-0000-7000-8000-00000000d101',
    '2028-03-31',
    '2028-03-31 12:00:00+00',
    '01900000-0000-7000-8000-00000000d022',
    'USD',
    'settlement',
    '01900000-0000-7000-8000-00000000d101',
    'Race settlement A',
    100,
    100
);
SELECT pg_sleep(6);
ROLLBACK;
