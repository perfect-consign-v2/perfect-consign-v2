-- The sales-tax sheet matches the liability. A planted one-cent gap is refused. The script rolls back.
-- Source types used by the same journals: check_return, booth_charge, booth_deposit.

BEGIN;

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-00000000d221',
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
    'sale:tax-sheet',
    '2026-03-01',
    '2026-03-01 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'sale', 'tax-sheet', 'Sale with tax', 10800, 10800
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000b01', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 10800, 0),
    ('01900000-0000-7000-8000-000000000b01', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000013', 0, 800);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES (
    '01900000-0000-7000-8000-000000000b01',
    '01900000-0000-7000-8000-000000000001',
    3,
    '01900000-0000-7000-8000-000000000014',
    0, 10000, 'vendor', '01900000-0000-7000-8000-000000000101'
);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES (
    '01900000-0000-7000-8000-000000000ba1',
    'Coat',
    '01900000-0000-7000-8000-000000000101',
    'Wool coat',
    10000,
    'BC-TAX-SHEET',
    DATE '2026-03-01',
    0
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-000000000b01', 1, '01900000-0000-7000-8000-000000000ba1', 10000, 0, 800);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-00000000d221', '01900000-0000-7000-8000-000000000b01');

SELECT stall_refuse_tax_drift(DATE '2026-03-01', DATE '2026-03-31');

DO $$
DECLARE
    collected bigint;
BEGIN
    SELECT COALESCE(sum(tax_minor), 0) INTO collected
    FROM stall_sales_tax_sheet(DATE '2026-03-01', DATE '2026-03-31')
    WHERE section = 'taxable';
    IF collected <> 800 THEN
        RAISE EXCEPTION 'the sheet did not collect the posted tax';
    END IF;
END $$;

DO $$
BEGIN
    PERFORM stall_refuse_tax_pair(1, 2);
    RAISE EXCEPTION 'a one-cent tax gap was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%sales tax disagrees with the books%' THEN
            RAISE;
        END IF;
END $$;

SELECT stall_refuse_tax_pair(5, 5);

ROLLBACK;
