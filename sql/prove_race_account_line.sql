-- Session B. Must block on the account row, then roll back.
BEGIN;
INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000d028',
    '01900000-0000-7000-8000-000000000001',
    '2032-01-01',
    '2032-12-31',
    'open'
);
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000d815',
    '01900000-0000-7000-8000-000000000001',
    'adjustment:race-account',
    '2032-03-01',
    '2032-03-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000d028',
    'USD',
    'adjustment',
    'race-account',
    'Race account journal',
    100,
    100
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000d815',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-00000000d011',
    100,
    0
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000d815',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000012',
    0,
    100
);
ROLLBACK;
