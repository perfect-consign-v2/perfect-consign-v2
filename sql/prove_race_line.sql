-- Session B. Must block on the vendor row, then fail because the delete committed.
BEGIN;
INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000d021',
    '01900000-0000-7000-8000-000000000001',
    '2028-01-01',
    '2028-12-31',
    'open'
);
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000d801',
    '01900000-0000-7000-8000-000000000001',
    'race-line',
    '2028-03-01',
    '2028-03-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000d021',
    'USD',
    'sale',
    'race',
    'Race line',
    100,
    100
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000d801',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000010',
    100,
    0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000d801',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000014',
    0,
    100,
    'vendor',
    '01900000-0000-7000-8000-00000000d101'
);
COMMIT;
