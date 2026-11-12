-- An item marked tax exempt posts with no tax. A taxable tax on that line is refused.
-- Vendor resale and ticket waiver still zero tax. The sales-tax sheet names item exempt.
-- The script rolls back.

BEGIN;

UPDATE mall SET tax_bps = 8000, commission_bps = 0, store_stock_enabled = true;

SELECT stall_seed_vendor('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane');
SELECT stall_seed_vendor('01900000-0000-7000-8000-000000000102', 'bea', 'Bea', 'Lane', NULL, true, 'EX-ITEM');

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-000000000021',
    '01900000-0000-7000-8000-000000000001',
    '2097-01-01',
    '2097-12-31',
    'open'
);

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-000000000d91', 'Item tax till');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d22',
    '01900000-0000-7000-8000-000000000d91',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, tax_exempt, quantity)
VALUES
    (
        '01900000-0000-7000-8000-000000000ea1',
        'Coat',
        '01900000-0000-7000-8000-000000000101',
        'Wool coat',
        10000,
        'BC-ITEM-COAT',
        DATE '2097-03-01',
        false,
        2
    ),
    (
        '01900000-0000-7000-8000-000000000ea2',
        'Hat',
        '01900000-0000-7000-8000-000000000101',
        'Felt hat',
        10000,
        'BC-ITEM-HAT',
        DATE '2097-03-01',
        true,
        2
    ),
    (
        '01900000-0000-7000-8000-000000000ea3',
        'Lamp',
        '01900000-0000-7000-8000-000000000102',
        'Brass lamp',
        4000,
        'BC-ITEM-LAMP',
        DATE '2097-03-01',
        false,
        1
    );

INSERT INTO items (
    id, code, vendor_id, description, price_minor, barcode, intake_on, tax_exempt, quantity, placement, commission_bps, expires_on
) VALUES (
    '01900000-0000-7000-8000-000000000ea4',
    'Mug',
    NULL,
    'Store mug',
    5000,
    'BC-ITEM-MUG',
    DATE '2097-03-01',
    true,
    1,
    'store',
    NULL,
    NULL
);

DO $$
BEGIN
    IF stall_item_tax_exempt('01900000-0000-7000-8000-000000000ea2') IS DISTINCT FROM true THEN
        RAISE EXCEPTION 'the hat was not item tax exempt';
    END IF;
    IF stall_item_tax_exempt('01900000-0000-7000-8000-000000000ea1') IS DISTINCT FROM false THEN
        RAISE EXCEPTION 'the coat was treated as tax exempt';
    END IF;
    IF stall_item_tax_exempt('01900000-0000-7000-8000-000000000ea3') IS DISTINCT FROM true THEN
        RAISE EXCEPTION 'a tax-exempt vendor did not exempt the lamp';
    END IF;
    IF stall_item_tax_exempt('01900000-0000-7000-8000-000000000ea4') IS DISTINCT FROM true THEN
        RAISE EXCEPTION 'a store item did not keep its tax exempt flag';
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000eb1',
    '01900000-0000-7000-8000-000000000001',
    'sale:item-exempt',
    '2097-03-01',
    '2097-03-01 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'sale', 'item-exempt', 'Exempt hat', 10000, 10000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000eb1',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000010',
    10000,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000eb1',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000014',
    0,
    10000,
    'vendor',
    '01900000-0000-7000-8000-000000000101'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-000000000eb1', 1, '01900000-0000-7000-8000-000000000ea2', 10000, 0, 0);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000eb1');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000eb2',
        '01900000-0000-7000-8000-000000000001',
        'sale:item-taxed',
        '2097-03-01',
        '2097-03-01 12:10:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD', 'sale', 'item-taxed', 'Tax on an exempt hat', 10800, 10800
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-000000000eb2', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 10800, 0),
        ('01900000-0000-7000-8000-000000000eb2', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000013', 0, 800);
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000eb2',
        '01900000-0000-7000-8000-000000000001',
        3,
        '01900000-0000-7000-8000-000000000014',
        0, 10000, 'vendor', '01900000-0000-7000-8000-000000000101'
    );
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
    VALUES ('01900000-0000-7000-8000-000000000eb2', 1, '01900000-0000-7000-8000-000000000ea2', 10000, 0, 800);
    RAISE EXCEPTION 'tax on an exempt item was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a sale tax does not match the mall rate%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000eb3',
    '01900000-0000-7000-8000-000000000001',
    'sale:item-mix',
    '2097-03-01',
    '2097-03-01 12:20:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'sale', 'item-mix', 'Coat and hat', 20800, 20800
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000eb3', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 20800, 0),
    ('01900000-0000-7000-8000-000000000eb3', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000013', 0, 800);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000eb3',
    '01900000-0000-7000-8000-000000000001',
    3,
    '01900000-0000-7000-8000-000000000014',
    0,
    20000,
    'vendor',
    '01900000-0000-7000-8000-000000000101'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES
    ('01900000-0000-7000-8000-000000000eb3', 1, '01900000-0000-7000-8000-000000000ea1', 10000, 0, 800),
    ('01900000-0000-7000-8000-000000000eb3', 2, '01900000-0000-7000-8000-000000000ea2', 10000, 0, 0);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000eb3');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000eb4',
    '01900000-0000-7000-8000-000000000001',
    'sale:vendor-exempt',
    '2097-03-01',
    '2097-03-01 12:30:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'sale', 'vendor-exempt', 'Vendor resale lamp', 4000, 4000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000eb4',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000010',
    4000,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000eb4',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000014',
    0,
    4000,
    'vendor',
    '01900000-0000-7000-8000-000000000102'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-000000000eb4', 1, '01900000-0000-7000-8000-000000000ea3', 4000, 0, 0);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000eb4');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    exempt_label text;
    taxable_tax bigint;
    exempt_subtotal bigint;
BEGIN
    SELECT label, subtotal_minor INTO exempt_label, exempt_subtotal
    FROM stall_sales_tax_sheet(DATE '2097-03-01', DATE '2097-03-31')
    WHERE section = 'exempt' AND label = 'item exempt';
    IF exempt_label IS DISTINCT FROM 'item exempt' OR exempt_subtotal <> 20000 THEN
        RAISE EXCEPTION 'the sheet did not keep item exempt sales';
    END IF;
    SELECT COALESCE(sum(tax_minor), 0) INTO taxable_tax
    FROM stall_sales_tax_sheet(DATE '2097-03-01', DATE '2097-03-31')
    WHERE section = 'taxable';
    IF taxable_tax <> 800 THEN
        RAISE EXCEPTION 'the sheet did not keep taxable tax';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM stall_sales_tax_sheet(DATE '2097-03-01', DATE '2097-03-31')
        WHERE section = 'exempt' AND label = 'vendor resale' AND subtotal_minor = 4000
    ) THEN
        RAISE EXCEPTION 'the sheet did not keep vendor resale';
    END IF;
END $$;

DO $$
BEGIN
    UPDATE sale_lines
    SET tax_minor = 800
    WHERE journal_id = '01900000-0000-7000-8000-000000000eb1';
    RAISE EXCEPTION 'a posted sale tax was edited';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a sale line cannot be changed%' THEN
            RAISE;
        END IF;
END $$;

UPDATE items SET tax_exempt = false WHERE id = '01900000-0000-7000-8000-000000000ea2';

DO $$
BEGIN
    IF (SELECT tax_minor FROM sale_lines WHERE journal_id = '01900000-0000-7000-8000-000000000eb1') <> 0 THEN
        RAISE EXCEPTION 'clearing the item flag rewrote a posted tax';
    END IF;
END $$;

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-000000000ec1', '01900000-0000-7000-8000-000000000d22');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor, quantity)
VALUES ('01900000-0000-7000-8000-000000000ec1', 1, '01900000-0000-7000-8000-000000000ea4', 5000, 1);

DO $$
DECLARE
    minted text;
BEGIN
    SELECT public.stall_mint_register_snaps(
        '01900000-0000-7000-8000-000000000d22',
        '01900000-0000-7000-8000-000000000ec1'
    ) INTO minted;
    IF (SELECT tax_minor FROM register_snaps WHERE posting_key = minted) IS DISTINCT FROM 0 THEN
        RAISE EXCEPTION 'an exempt store item minted tax';
    END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE sale_lines DISABLE TRIGGER sale_lines_append_only;
    RAISE EXCEPTION 'disabling the sale line trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

ROLLBACK;
