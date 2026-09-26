-- Session A holds the opening key, then rolls back.
BEGIN;
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000d818',
    '01900000-0000-7000-8000-000000000001',
    'opening',
    '2037-06-01',
    '2037-06-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000d037',
    'USD',
    'opening',
    'race-opening',
    'Race opening A',
    100,
    100
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-00000000d818', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 100, 0),
    ('01900000-0000-7000-8000-00000000d818', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 100);
SELECT pg_sleep(6);
ROLLBACK;
