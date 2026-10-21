-- Session A holds the tagged piece, then rolls back.
BEGIN;
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000009e1',
    '01900000-0000-7000-8000-000000000001',
    'store-purchase:race-a', '2099-05-02', '2099-05-02 12:00:00+00',
    '01900000-0000-7000-8000-0000000009e2',
    'USD', 'store_purchase', '01900000-0000-7000-8000-0000000009e0', 'Store purchase', 1000, 1000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-0000000009e1',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000022', 1000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000009e1',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 1000, 'vendor', '01900000-0000-7000-8000-0000000009e0'
);
INSERT INTO store_uses (journal_id, vendor_id, item_id, amount_minor, description, memo)
VALUES (
    '01900000-0000-7000-8000-0000000009e1',
    '01900000-0000-7000-8000-0000000009e0',
    '01900000-0000-7000-8000-0000000009e3',
    1000, 'Race sign', 'Bought for the business, not for resale'
);
SELECT pg_sleep(6);
ROLLBACK;
