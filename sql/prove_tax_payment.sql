-- Proof for sales-tax payment. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d22',
    '01900000-0000-7000-8000-000000000d10',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane');

UPDATE mall SET tax_bps = 8000;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-000000000021',
    '01900000-0000-7000-8000-000000000001',
    '2026-01-01',
    '2026-12-31',
    'open'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000b01',
    '01900000-0000-7000-8000-000000000001',
    'sale:tax-1',
    '2026-03-01',
    '2026-03-01 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'sale',
    'tax-1',
    'Sale with tax',
    10800,
    10800
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000b01',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000010',
    10800,
    0
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000b01',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000013',
    0,
    800
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000b01',
    '01900000-0000-7000-8000-000000000001',
    3,
    '01900000-0000-7000-8000-000000000014',
    0,
    10000,
    'vendor',
    '01900000-0000-7000-8000-000000000101'
);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES (
    '01900000-0000-7000-8000-000000000ba1',
    'Coat',
    '01900000-0000-7000-8000-000000000101',
    'Wool coat',
    10000,
    'BC-TAX',
    DATE '2026-03-01',
    0
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-000000000b01', 1, '01900000-0000-7000-8000-000000000ba1', 10000, 0, 800);

INSERT INTO register_session_posts (session_id, journal_id)
SELECT '01900000-0000-7000-8000-000000000d22', journal.id
FROM journals AS journal
WHERE journal.source_type IN (
    'sale', 'rent_payment', 'payout', 'gift_certificate_issue', 'gift_card_issue',
    'store_credit_issue', 'cash_drop', 'register_variance'
)
  AND NOT EXISTS (
      SELECT 1 FROM register_session_posts AS post WHERE post.journal_id = journal.id
  );
SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000b06',
        '01900000-0000-7000-8000-000000000001',
        'tax-payment:drawer',
        '2026-03-02',
        '2026-03-02 13:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'tax_payment',
        'drawer',
        'Cash tax payment',
        100,
        100
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-000000000b06', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000013', 100, 0),
        ('01900000-0000-7000-8000-000000000b06', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000010', 0, 100);
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a tax payment that credits cash was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a tax payment debits sales tax payable and credits the bank%' THEN
            RAISE;
        END IF;
END $$;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000b02',
    '01900000-0000-7000-8000-000000000001',
    'tax-payment:t1',
    '2026-03-02',
    '2026-03-02 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'tax_payment',
    't1',
    'Pay the open sales tax',
    800,
    800
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000b02', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000013', 800, 0),
    ('01900000-0000-7000-8000-000000000b02', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 800);

INSERT INTO register_session_posts (session_id, journal_id)
SELECT '01900000-0000-7000-8000-000000000d22', journal.id
FROM journals AS journal
WHERE journal.source_type IN (
    'sale', 'rent_payment', 'payout', 'gift_certificate_issue', 'gift_card_issue',
    'store_credit_issue', 'cash_drop', 'register_variance'
)
  AND NOT EXISTS (
      SELECT 1 FROM register_session_posts AS post WHERE post.journal_id = journal.id
  );
SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000b03',
        '01900000-0000-7000-8000-000000000001',
        'tax-payment:t2',
        '2026-03-03',
        '2026-03-03 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'tax_payment',
        't2',
        'Tax payment above the open balance',
        100,
        100
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-000000000b03', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000013', 100, 0),
        ('01900000-0000-7000-8000-000000000b03', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 100);
    INSERT INTO register_session_posts (session_id, journal_id)
SELECT '01900000-0000-7000-8000-000000000d22', journal.id
FROM journals AS journal
WHERE journal.source_type IN (
    'sale', 'rent_payment', 'payout', 'gift_certificate_issue', 'gift_card_issue',
    'store_credit_issue', 'cash_drop', 'register_variance'
)
  AND NOT EXISTS (
      SELECT 1 FROM register_session_posts AS post WHERE post.journal_id = journal.id
  );
SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'tax overpayment was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000b04',
        '01900000-0000-7000-8000-000000000001',
        'tax-payment:t1',
        '2026-03-04',
        '2026-03-04 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'tax_payment',
        't1',
        'Duplicate tax payment key',
        100,
        100
    );
    RAISE EXCEPTION 'duplicate tax payment key was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
    debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000b05',
    '01900000-0000-7000-8000-000000000001',
    'tax-payment:t1:void',
    '2026-03-05',
    '2026-03-05 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'tax_payment',
    't1-void',
    'Reverse the tax payment',
    true,
    '01900000-0000-7000-8000-000000000b02',
    800,
    800
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000b05', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000011', 800, 0),
    ('01900000-0000-7000-8000-000000000b05', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000013', 0, 800);

INSERT INTO register_session_posts (session_id, journal_id)
SELECT '01900000-0000-7000-8000-000000000d22', journal.id
FROM journals AS journal
WHERE journal.source_type IN (
    'sale', 'rent_payment', 'payout', 'gift_certificate_issue', 'gift_card_issue',
    'store_credit_issue', 'cash_drop', 'register_variance'
)
  AND NOT EXISTS (
      SELECT 1 FROM register_session_posts AS post WHERE post.journal_id = journal.id
  );
SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

ROLLBACK;
