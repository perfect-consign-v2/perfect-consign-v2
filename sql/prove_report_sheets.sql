-- Sales, tax, tender, rent, stored value, and donations.
-- A same-month void nets to zero. A later void leaves the sale month and reduces the next.
-- The script rolls back.

BEGIN;

UPDATE mall SET tax_bps = 0, cash_round_minor = 0;

SELECT stall_seed_vendor('01900000-0000-7000-8097-000000000101', 'rpt', 'Ada', 'Lane');

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8097-000000000021',
    '01900000-0000-7000-8000-000000000001',
    '2097-01-01',
    '2097-12-31',
    'open'
);

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8097-0000000000d1',
    '01900000-0000-7000-8000-000000000d10',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES
    ('01900000-0000-7000-8097-0000000000a1', 'Apr1', '01900000-0000-7000-8097-000000000101', 'April first', 1000, 'BC-RPT-APR1', DATE '2097-04-01', 0),
    ('01900000-0000-7000-8097-0000000000a2', 'Apr2', '01900000-0000-7000-8097-000000000101', 'April second', 2000, 'BC-RPT-APR2', DATE '2097-04-02', 0),
    ('01900000-0000-7000-8097-0000000000a3', 'May', '01900000-0000-7000-8097-000000000101', 'May coat', 5000, 'BC-RPT-MAY', DATE '2097-05-01', 0);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8097-0000000000b2',
    '01900000-0000-7000-8000-000000000001',
    'sale:rpt-apr2', '2097-04-02', '2097-04-02 18:00:00+00',
    '01900000-0000-7000-8097-000000000021',
    'USD', 'sale', 'rpt-apr2', 'Later day first', 2000, 2000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8097-0000000000b2',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 2000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8097-0000000000b2',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 2000, 'vendor', '01900000-0000-7000-8097-000000000101'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8097-0000000000b2', 1, '01900000-0000-7000-8097-0000000000a2', 2000, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8097-0000000000d1', '01900000-0000-7000-8097-0000000000b2');

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8097-0000000000b1',
    '01900000-0000-7000-8000-000000000001',
    'sale:rpt-apr1', '2097-04-01', '2097-04-01 16:00:00+00',
    '01900000-0000-7000-8097-000000000021',
    'USD', 'sale', 'rpt-apr1', 'Earlier day second', 1000, 1000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8097-0000000000b1',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 1000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8097-0000000000b1',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 1000, 'vendor', '01900000-0000-7000-8097-000000000101'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8097-0000000000b1', 1, '01900000-0000-7000-8097-0000000000a1', 1000, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8097-0000000000d1', '01900000-0000-7000-8097-0000000000b1');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    first_label text;
    first_amount bigint;
    second_label text;
    second_amount bigint;
BEGIN
    SELECT sheet.label, sheet.merchandise_minor
    INTO first_label, first_amount
    FROM stall_report_sales(DATE '2097-04-01', DATE '2097-04-02', 'day')
        WITH ORDINALITY AS sheet(label, merchandise_minor, tax_minor, receipt_count, taken_minor, ordinal)
    WHERE sheet.ordinal = 1;
    SELECT sheet.label, sheet.merchandise_minor
    INTO second_label, second_amount
    FROM stall_report_sales(DATE '2097-04-01', DATE '2097-04-02', 'day')
        WITH ORDINALITY AS sheet(label, merchandise_minor, tax_minor, receipt_count, taken_minor, ordinal)
    WHERE sheet.ordinal = 2;
    IF first_label IS DISTINCT FROM '2097-04-01' OR first_amount IS DISTINCT FROM 1000
       OR second_label IS DISTINCT FROM '2097-04-02' OR second_amount IS DISTINCT FROM 2000 THEN
        RAISE EXCEPTION 'days did not come back in date order';
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8097-0000000000b3',
    '01900000-0000-7000-8000-000000000001',
    'sale:rpt-may', '2097-05-15', '2097-05-15 18:00:00+00',
    '01900000-0000-7000-8097-000000000021',
    'USD', 'sale', 'rpt-may', 'Cash sale', 5000, 5000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8097-0000000000b3',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 5000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8097-0000000000b3',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 5000, 'vendor', '01900000-0000-7000-8097-000000000101'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8097-0000000000b3', 1, '01900000-0000-7000-8097-0000000000a3', 5000, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8097-0000000000d1', '01900000-0000-7000-8097-0000000000b3');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    taken bigint;
BEGIN
    SELECT COALESCE(sum(taken_minor), 0) INTO taken
    FROM stall_report_sales(DATE '2097-05-15', DATE '2097-05-15', 'tender')
    WHERE label = 'cash';
    IF taken IS DISTINCT FROM 5000 THEN
        RAISE EXCEPTION 'cash tender did not start at the sale';
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8097-0000000000b4',
    '01900000-0000-7000-8000-000000000001',
    'return:rpt-may', '2097-05-15', '2097-05-15 19:00:00+00',
    '01900000-0000-7000-8097-000000000021',
    'USD', 'item_return', 'rpt-may-return', 'Cash return', 5000, 5000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8097-0000000000b4',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 5000, 0, 'vendor', '01900000-0000-7000-8097-000000000101'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8097-0000000000b4',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000010', 0, 5000
);
INSERT INTO item_returns (journal_id, sale_journal_id, item_id, price_minor, commission_minor, tax_minor)
VALUES (
    '01900000-0000-7000-8097-0000000000b4',
    '01900000-0000-7000-8097-0000000000b3',
    '01900000-0000-7000-8097-0000000000a3',
    5000, 0, 0
);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8097-0000000000d1', '01900000-0000-7000-8097-0000000000b4');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    taken bigint;
BEGIN
    SELECT COALESCE(sum(taken_minor), 0) INTO taken
    FROM stall_report_sales(DATE '2097-05-15', DATE '2097-05-15', 'tender')
    WHERE label = 'cash';
    IF taken IS DISTINCT FROM 0 THEN
        RAISE EXCEPTION 'a cash return did not reduce the tender';
    END IF;
END $$;

UPDATE mall SET tax_bps = 8000;

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES
    ('01900000-0000-7000-8097-0000000000a4', 'July', '01900000-0000-7000-8097-000000000101', 'July coat', 10000, 'BC-RPT-JUL', DATE '2097-07-01', 0),
    ('01900000-0000-7000-8097-0000000000a5', 'March', '01900000-0000-7000-8097-000000000101', 'March coat', 10000, 'BC-RPT-MAR', DATE '2097-03-01', 0);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8097-0000000000b5',
    '01900000-0000-7000-8000-000000000001',
    'sale:rpt-jul', '2097-07-10', '2097-07-10 18:00:00+00',
    '01900000-0000-7000-8097-000000000021',
    'USD', 'sale', 'rpt-jul', 'July sale', 10800, 10800
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8097-0000000000b5', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 10800, 0),
    ('01900000-0000-7000-8097-0000000000b5', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000013', 0, 800);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8097-0000000000b5',
    '01900000-0000-7000-8000-000000000001',
    3, '01900000-0000-7000-8000-000000000014', 0, 10000, 'vendor', '01900000-0000-7000-8097-000000000101'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8097-0000000000b5', 1, '01900000-0000-7000-8097-0000000000a4', 10000, 0, 800);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8097-0000000000d1', '01900000-0000-7000-8097-0000000000b5');

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
    debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8097-0000000000b6',
    '01900000-0000-7000-8000-000000000001',
    'sale:rpt-jul:void', '2097-07-11', '2097-07-11 18:00:00+00',
    '01900000-0000-7000-8097-000000000021',
    'USD', 'sale', 'rpt-jul-void', 'July void', true,
    '01900000-0000-7000-8097-0000000000b5',
    10800, 10800
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8097-0000000000b6', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000013', 800, 0),
    ('01900000-0000-7000-8097-0000000000b6', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000010', 0, 10800);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8097-0000000000b6',
    '01900000-0000-7000-8000-000000000001',
    3, '01900000-0000-7000-8000-000000000014', 10000, 0, 'vendor', '01900000-0000-7000-8097-000000000101'
);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8097-0000000000d1', '01900000-0000-7000-8097-0000000000b6');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    merchandise bigint;
    tax bigint;
    taxable bigint;
    collected bigint;
    returns bigint;
BEGIN
    SELECT COALESCE(sum(merchandise_minor), 0), COALESCE(sum(tax_minor), 0)
    INTO merchandise, tax
    FROM stall_report_sales(DATE '2097-07-01', DATE '2097-07-31', 'day');
    IF merchandise <> 0 OR tax <> 0 THEN
        RAISE EXCEPTION 'a same-month void did not net merchandise and tax';
    END IF;
    SELECT COALESCE(sum(subtotal_minor - discount_minor), 0) INTO taxable
    FROM stall_sales_tax_sheet(DATE '2097-07-01', DATE '2097-07-31')
    WHERE section = 'taxable' OR section = 'void';
    SELECT COALESCE(sum(tax_minor), 0) INTO collected
    FROM stall_sales_tax_sheet(DATE '2097-07-01', DATE '2097-07-31')
    WHERE section IN ('taxable', 'exempt', 'return', 'void', 'void_exempt');
    SELECT COALESCE(sum(-(subtotal_minor - discount_minor)), 0) INTO returns
    FROM stall_sales_tax_sheet(DATE '2097-07-01', DATE '2097-07-31')
    WHERE section IN ('return', 'void', 'void_exempt');
    IF taxable <> 0 OR collected <> 0 OR returns <> 10000 THEN
        RAISE EXCEPTION 'a same-month void did not net the filing strip';
    END IF;
END $$;

SELECT stall_refuse_tax_drift(DATE '2097-07-01', DATE '2097-07-31');

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8097-0000000000b7',
    '01900000-0000-7000-8000-000000000001',
    'sale:rpt-mar', '2097-03-20', '2097-03-20 18:00:00+00',
    '01900000-0000-7000-8097-000000000021',
    'USD', 'sale', 'rpt-mar', 'March sale', 10800, 10800
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8097-0000000000b7', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 10800, 0),
    ('01900000-0000-7000-8097-0000000000b7', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000013', 0, 800);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8097-0000000000b7',
    '01900000-0000-7000-8000-000000000001',
    3, '01900000-0000-7000-8000-000000000014', 0, 10000, 'vendor', '01900000-0000-7000-8097-000000000101'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8097-0000000000b7', 1, '01900000-0000-7000-8097-0000000000a5', 10000, 0, 800);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8097-0000000000d1', '01900000-0000-7000-8097-0000000000b7');

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
    debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8097-0000000000b8',
    '01900000-0000-7000-8000-000000000001',
    'sale:rpt-mar:void', '2097-08-02', '2097-08-02 18:00:00+00',
    '01900000-0000-7000-8097-000000000021',
    'USD', 'sale', 'rpt-mar-void', 'August void', true,
    '01900000-0000-7000-8097-0000000000b7',
    10800, 10800
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8097-0000000000b8', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000013', 800, 0),
    ('01900000-0000-7000-8097-0000000000b8', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000010', 0, 10800);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8097-0000000000b8',
    '01900000-0000-7000-8000-000000000001',
    3, '01900000-0000-7000-8000-000000000014', 10000, 0, 'vendor', '01900000-0000-7000-8097-000000000101'
);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8097-0000000000d1', '01900000-0000-7000-8097-0000000000b8');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    march_merchandise bigint;
    march_tax bigint;
    august_merchandise bigint;
    august_tax bigint;
BEGIN
    SELECT COALESCE(sum(merchandise_minor), 0), COALESCE(sum(tax_minor), 0)
    INTO march_merchandise, march_tax
    FROM stall_report_sales(DATE '2097-03-01', DATE '2097-03-31', 'day');
    SELECT COALESCE(sum(merchandise_minor), 0), COALESCE(sum(tax_minor), 0)
    INTO august_merchandise, august_tax
    FROM stall_report_sales(DATE '2097-08-01', DATE '2097-08-31', 'day');
    IF march_merchandise <> 10000 OR march_tax <> 800
       OR august_merchandise <> -10000 OR august_tax <> -800 THEN
        RAISE EXCEPTION 'a later void did not leave the sale month and reduce the next';
    END IF;
END $$;

SELECT stall_refuse_tax_drift(DATE '2097-03-01', DATE '2097-03-31');
SELECT stall_refuse_tax_drift(DATE '2097-08-01', DATE '2097-08-31');

UPDATE mall SET store_stock_enabled = true;

INSERT INTO items (
    id, code, vendor_id, description, price_minor, barcode, intake_on, expires_on, quantity, commission_bps
) VALUES (
    '01900000-0000-7000-8097-0000000000a6',
    'Gift',
    '01900000-0000-7000-8097-000000000101',
    'Donated coat',
    1000,
    'BC-RPT-GIFT',
    DATE '2000-01-01',
    DATE '2000-01-02',
    4,
    0
);

INSERT INTO item_dispositions (item_id, kind, posted_on)
VALUES ('01900000-0000-7000-8097-0000000000a6', 'donate', DATE '2097-06-15');

UPDATE items SET quantity = 1 WHERE id = '01900000-0000-7000-8097-0000000000a6';

DO $$
DECLARE
    frozen_name text;
    frozen_qty integer;
    live_vendor uuid;
BEGIN
    SELECT vendor_name, quantity INTO frozen_name, frozen_qty
    FROM stall_report_donated(DATE '2097-06-15', DATE '2097-06-15');
    SELECT vendor_id INTO live_vendor FROM items WHERE id = '01900000-0000-7000-8097-0000000000a6';
    IF live_vendor IS NOT NULL
       OR frozen_name IS DISTINCT FROM (SELECT name FROM vendors WHERE id = '01900000-0000-7000-8097-000000000101')
       OR frozen_qty IS DISTINCT FROM 4 THEN
        RAISE EXCEPTION 'a donation did not keep the vendor and the quantity';
    END IF;
END $$;

UPDATE item_dispositions
SET vendor_id = NULL, vendor_name = NULL, quantity = NULL
WHERE item_id = '01900000-0000-7000-8097-0000000000a6';

DO $$
DECLARE
    frozen_name text;
    frozen_qty integer;
BEGIN
    SELECT vendor_name, quantity INTO frozen_name, frozen_qty
    FROM stall_report_donated(DATE '2097-06-15', DATE '2097-06-15');
    IF frozen_name IS NOT NULL OR frozen_qty IS NOT NULL THEN
        RAISE EXCEPTION 'an old donation with no vendor did not stay unrecorded';
    END IF;
END $$;

INSERT INTO items (id, code, description, price_minor, barcode, intake_on, placement)
VALUES (
    '01900000-0000-7000-8097-0000000000a7',
    'Store',
    'Store piece',
    1000,
    'BC-RPT-STORE',
    DATE '2097-06-01',
    'store'
);

DO $$
BEGIN
    INSERT INTO item_dispositions (item_id, kind, posted_on)
    VALUES ('01900000-0000-7000-8097-0000000000a7', 'donate', DATE '2097-06-16');
    RAISE EXCEPTION 'a disposition without a vendor was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a disposition must name the vendor%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO items (
    id, code, vendor_id, description, price_minor, barcode, intake_on, expires_on, quantity, commission_bps
) VALUES (
    '01900000-0000-7000-8097-0000000000a8',
    'Empty',
    '01900000-0000-7000-8097-000000000101',
    'No quantity',
    1000,
    'BC-RPT-EMPTY',
    DATE '2000-01-01',
    DATE '2000-01-02',
    0,
    0
);

DO $$
BEGIN
    INSERT INTO item_dispositions (item_id, kind, posted_on)
    VALUES ('01900000-0000-7000-8097-0000000000a8', 'donate', DATE '2097-06-17');
    RAISE EXCEPTION 'a disposition without a quantity was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a disposition must name the quantity%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8097-0000000000c1', 'R97', 'Report booth');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8097-0000000000c9',
    '01900000-0000-7000-8097-0000000000c1',
    '01900000-0000-7000-8097-000000000101',
    '2097-05-01'
);

INSERT INTO booth_rent_rates (booth_id, amount_minor)
VALUES ('01900000-0000-7000-8097-0000000000c1', 3100);

INSERT INTO assignment_rent (assignment_id, amount_minor)
SELECT assignment.id, rate.amount_minor
FROM booth_assignments AS assignment
JOIN booth_rent_rates AS rate ON rate.booth_id = assignment.booth_id
WHERE assignment.booth_id = '01900000-0000-7000-8097-0000000000c1';

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8097-0000000000c2',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8097-0000000000c9:2097-05',
    '2097-05-01',
    '2097-05-01 12:00:00+00',
    '01900000-0000-7000-8097-000000000021',
    'USD',
    'rent_charge',
    '01900000-0000-7000-8097-0000000000c9:2097-05',
    'May rent',
    3100,
    3100
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8097-0000000000c2',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000015',
    3100,
    0,
    'assignment',
    '01900000-0000-7000-8097-0000000000c9'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8097-0000000000c2',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000017',
    0,
    3100
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

SELECT stall_refuse_rent_roll();

DO $$
DECLARE
    roll_minor bigint;
    books_minor bigint;
BEGIN
    SELECT COALESCE(sum(open_minor), 0) INTO roll_minor FROM stall_report_rent();
    SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) INTO books_minor
    FROM journal_lines AS line
    JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'booth_rent_receivable';
    IF roll_minor <> books_minor OR roll_minor <> 3100 THEN
        RAISE EXCEPTION 'the rent roll did not equal receivable';
    END IF;
END $$;

SET LOCAL session_replication_role = replica;

INSERT INTO journals (
    id, book_id, journal_no, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8097-0000000000c3',
    '01900000-0000-7000-8000-000000000001',
    9001,
    'rent-gap:not-a-booth',
    '2097-01-15',
    '2097-01-15 12:00:00+00',
    '01900000-0000-7000-8097-000000000021',
    'USD',
    'booth_charge',
    'rent-gap',
    'Rent that is not a booth',
    100,
    100
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8097-0000000000c3', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000015', 100, 0),
    ('01900000-0000-7000-8097-0000000000c3', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000019', 0, 100);

SET LOCAL session_replication_role = origin;

DO $$
BEGIN
    PERFORM stall_refuse_rent_roll();
    RAISE EXCEPTION 'a non-booth receivable line was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%The rent roll does not match the books.%' THEN
            RAISE;
        END IF;
END $$;

SELECT stall_refuse_stored_value();

CREATE OR REPLACE VIEW account_balances AS
SELECT account.book_id,
    account.id AS account_id,
    account.code,
    account.account_type,
    account.normal_balance,
    (COALESCE(sum(line.debit_minor), (0)::numeric))::bigint AS debit_minor,
    (COALESCE(sum(line.credit_minor), (0)::numeric))::bigint AS credit_minor,
    (
        CASE account.normal_balance
            WHEN 'debit'::text THEN (COALESCE(sum(line.debit_minor), (0)::numeric) - COALESCE(sum(line.credit_minor), (0)::numeric))
            ELSE (COALESCE(sum(line.credit_minor), (0)::numeric) - COALESCE(sum(line.debit_minor), (0)::numeric))
        END
        + CASE
            WHEN account.id = '01900000-0000-7000-8000-00000000001d'::uuid THEN 1
            ELSE 0
        END
    )::bigint AS balance_minor
FROM (public.accounts account
    LEFT JOIN public.journal_lines line ON (((line.account_id = account.id) AND (line.book_id = account.book_id))))
GROUP BY account.book_id, account.id, account.code, account.account_type, account.normal_balance;

DO $$
BEGIN
    PERFORM stall_refuse_stored_value();
    RAISE EXCEPTION 'a one-cent stored-value gap was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%Stored value does not match the books.%' THEN
            RAISE;
        END IF;
END $$;

-- A saved report keeps only a sheet, grouping and range that belong together.
-- Rent, stored value and expiring pieces are as of now; hourly sales are today.
INSERT INTO saved_reports (name, sheet, grouped, range_key)
VALUES
    ('Gift balances', 'stored_value', 'none', 'now'),
    ('Rent owed', 'rent', 'none', 'now'),
    ('Rush hour', 'sales', 'hour', 'today'),
    ('Vendors this month', 'sales', 'vendor', 'this_month');

DO $$
DECLARE
    bad text[];
    tried text[][] := ARRAY[
        ARRAY['stored_value', 'none', 'this_month'],
        ARRAY['stored_value', 'day', 'now'],
        ARRAY['rent', 'none', 'today'],
        ARRAY['sales', 'hour', 'yesterday'],
        ARRAY['sales', 'none', 'this_month'],
        ARRAY['payouts', 'none', 'now']
    ];
BEGIN
    FOREACH bad SLICE 1 IN ARRAY tried LOOP
        BEGIN
            INSERT INTO saved_reports (name, sheet, grouped, range_key)
            VALUES ('Mismatched', bad[1], bad[2], bad[3]);
            RAISE EXCEPTION 'saved report % / % / % was accepted', bad[1], bad[2], bad[3];
        EXCEPTION
            WHEN check_violation THEN
                IF SQLERRM NOT LIKE '%saved_reports_pair_chk%' THEN
                    RAISE;
                END IF;
        END;
    END LOOP;
END $$;

ROLLBACK;
