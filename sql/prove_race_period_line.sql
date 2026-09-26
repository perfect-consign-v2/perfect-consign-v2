-- Session B. Must block on the period row, then roll back once it is open again.
BEGIN;
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000d813',
    '01900000-0000-7000-8000-000000000001',
    'adjustment:race-period',
    '2030-03-01',
    '2030-03-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000d024',
    'USD',
    'adjustment',
    'race-period',
    'Race period journal',
    100,
    100
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-00000000d813', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 100, 0),
    ('01900000-0000-7000-8000-00000000d813', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 100);
ROLLBACK;
