-- Session B waits for the same piece, then rolls back.
BEGIN;
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000009c4',
    '01900000-0000-7000-8000-000000000001',
    'missed-sale:race-b', '2098-04-02', '2098-04-02 12:00:00+00',
    '01900000-0000-7000-8000-0000000009c2',
    'USD', 'missed_sale', 'race-b', 'Missed sale', 1000, 1000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-0000000009c4',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000018', 1000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000009c4',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 1000, 'vendor', '01900000-0000-7000-8000-0000000009c0'
);
INSERT INTO missed_sales (journal_id, vendor_id, item_id, price_minor, description, memo)
VALUES (
    '01900000-0000-7000-8000-0000000009c4',
    '01900000-0000-7000-8000-0000000009c0',
    '01900000-0000-7000-8000-0000000009c3',
    1000, 'Race piece', 'The sale was never rung'
);
ROLLBACK;
