-- Session B sells the same last unit. It waits on A's row lock, then rolls back.
BEGIN;
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000009d2',
    '01900000-0000-7000-8000-000000000001',
    'sale:on-hand-b', '2094-05-02', '2094-05-02 12:00:00+00',
    '01900000-0000-7000-8000-0000000009b2',
    'USD', 'sale', 'on-hand-b', 'Last cola b', 1000, 1000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-0000000009d2',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 1000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000009d2',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 1000, 'vendor', '01900000-0000-7000-8000-0000000009b0'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, quantity, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-0000000009d2', 1, '01900000-0000-7000-8000-0000000009b1', 1000, 1, 0, 0);
ROLLBACK;
