-- Session B. Must block on the assignment row, then fail because March is uncovered.
BEGIN;
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000d812',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-00000000d302:2029-03',
    '2029-03-01',
    '2029-03-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000d026',
    'USD',
    'rent_charge',
    'R2:2029-03',
    'Race assignment charge',
    15000,
    15000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000d812',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000015',
    15000,
    0,
    'booth',
    '01900000-0000-7000-8000-00000000d302'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000d812',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000017',
    0,
    15000
);
COMMIT;
