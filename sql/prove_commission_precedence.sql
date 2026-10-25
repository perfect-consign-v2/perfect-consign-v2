-- Commission rules win by specificity, then priority, then the price bounds.
-- A posted line keeps the commission from the rule that was in force. An equal tie is refused.

BEGIN;

INSERT INTO vendors (id, code, first_name, last_name, commission_bps)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane', 500);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES (
    '01900000-0000-7000-8000-0000000005a1',
    'Coat',
    '01900000-0000-7000-8000-000000000101',
    'Wool coat',
    10000,
    'BC-COAT',
    DATE '2096-03-01',
    2000
);

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-000000000021',
    '01900000-0000-7000-8000-000000000001',
    '2096-01-01',
    '2096-12-31',
    'open'
);

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-000000000d92', 'Commission proof');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d22',
    '01900000-0000-7000-8000-000000000d92',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

DO $$
BEGIN
    IF public.stall_commission_for('01900000-0000-7000-8000-0000000005a1', 10000, DATE '2096-03-01') IS DISTINCT FROM 2000 THEN
        RAISE EXCEPTION 'the item rate is the fallback';
    END IF;
END $$;

INSERT INTO commission_rules (id, priority, min_minor, max_minor, bps, starts_on, ends_on)
VALUES ('01900000-0000-7000-8000-00000000c001', 0, 0, NULL, 1000, DATE '2096-01-01', NULL);

INSERT INTO commission_rules (id, vendor_id, priority, min_minor, max_minor, bps, starts_on, ends_on)
VALUES ('01900000-0000-7000-8000-00000000c002', '01900000-0000-7000-8000-000000000101', 0, 0, 10000, 2500, DATE '2096-01-01', DATE '2096-12-31');

INSERT INTO commission_rules (id, item_id, priority, min_minor, max_minor, bps, starts_on, ends_on)
VALUES ('01900000-0000-7000-8000-00000000c003', '01900000-0000-7000-8000-0000000005a1', 0, 10000, 10000, 4000, DATE '2096-01-01', DATE '2096-12-31');

INSERT INTO commission_rules (id, item_id, priority, min_minor, max_minor, bps, starts_on, ends_on)
VALUES ('01900000-0000-7000-8000-00000000c004', '01900000-0000-7000-8000-0000000005a1', 1, 0, 9999, 1500, DATE '2090-01-01', DATE '2090-12-31');

DO $$
BEGIN
    IF public.stall_commission_for('01900000-0000-7000-8000-0000000005a1', 10000, DATE '2096-03-01') IS DISTINCT FROM 4000 THEN
        RAISE EXCEPTION 'the item rule wins on an exact bound';
    END IF;
    IF public.stall_commission_for('01900000-0000-7000-8000-0000000005a1', 9999, DATE '2096-03-01') IS DISTINCT FROM 2500 THEN
        RAISE EXCEPTION 'the vendor rule wins inside its bounds';
    END IF;
    IF public.stall_commission_for('01900000-0000-7000-8000-0000000005a1', 10000, DATE '2097-01-01') IS DISTINCT FROM 1000 THEN
        RAISE EXCEPTION 'an expired item rule falls through';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO commission_rules (item_id, priority, min_minor, max_minor, bps, starts_on)
    VALUES ('01900000-0000-7000-8000-0000000005a1', 0, 10000, 10000, 3000, DATE '2096-06-01');
    RAISE EXCEPTION 'an equal rule was stored';
EXCEPTION WHEN SQLSTATE '23514' THEN
    IF SQLERRM IS DISTINCT FROM 'two commission rules are equally specific' THEN
        RAISE;
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000501',
    '01900000-0000-7000-8000-000000000001',
    'sale:1001',
    '2096-03-01',
    '2096-03-01 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'sale', '1001', 'Cash sale 1001', 10000, 10000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000501', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 10000, 0),
    ('01900000-0000-7000-8000-000000000501', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 4000);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000501',
    '01900000-0000-7000-8000-000000000001',
    3,
    '01900000-0000-7000-8000-000000000014',
    0, 6000, 'vendor', '01900000-0000-7000-8000-000000000101'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-000000000501', 1, '01900000-0000-7000-8000-0000000005a1', 10000, 4000, 0);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000501');

SET CONSTRAINTS ALL IMMEDIATE;

DO $$
BEGIN
    IF (SELECT commission_minor FROM sale_lines WHERE journal_id = '01900000-0000-7000-8000-000000000501') IS DISTINCT FROM 4000 THEN
        RAISE EXCEPTION 'the posted line kept the rule';
    END IF;
END $$;

ROLLBACK;
