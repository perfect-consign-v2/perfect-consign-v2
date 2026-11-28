-- A missed sale pays the vendor their share and expenses it. One transaction, then ROLLBACK.

BEGIN;

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000e101', 'miss', 'Mina', 'Lane', 4000);
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000e102', 'other', 'Otto', 'Lane', 4000);
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000e104', 'full', 'Faye', 'Lane', 10000);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity)
VALUES
    ('01900000-0000-7000-8000-00000000e201', 'Coat', '01900000-0000-7000-8000-00000000e101', 'Wool coat', 10000, 'BC-MISS', DATE '2041-03-01', 2),
    ('01900000-0000-7000-8000-00000000e202', 'Hat', '01900000-0000-7000-8000-00000000e102', 'Felt hat', 10000, 'BC-MISS-HAT', DATE '2041-03-01', 1),
    ('01900000-0000-7000-8000-00000000e203', 'Sold', '01900000-0000-7000-8000-00000000e101', 'Sold coat', 10000, 'BC-MISS-SOLD', DATE '2041-03-01', 1);

UPDATE mall SET store_stock_enabled = true;

INSERT INTO items (id, code, description, price_minor, barcode, intake_on, quantity, placement, cost_minor)
VALUES (
    '01900000-0000-7000-8000-00000000e209',
    'Shelf',
    'Store shelf',
    10000,
    'BC-MISS-STORE',
    DATE '2041-03-01',
    1,
    'store',
    1000
);

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000e021',
    '01900000-0000-7000-8000-000000000001',
    '2041-01-01',
    '2041-12-31',
    'open'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000e801',
    '01900000-0000-7000-8000-000000000001',
    'missed-sale:coat',
    '2041-03-02',
    '2041-03-02 12:00:00+00',
    '01900000-0000-7000-8000-00000000e021',
    'USD',
    'missed_sale',
    'coat',
    'Missed sale',
    6000,
    6000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000e801',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000018',
    6000,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000e801',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000014',
    0,
    6000,
    'vendor',
    '01900000-0000-7000-8000-00000000e101'
);

INSERT INTO missed_sales (journal_id, vendor_id, item_id, price_minor, description, memo)
VALUES (
    '01900000-0000-7000-8000-00000000e801',
    '01900000-0000-7000-8000-00000000e101',
    '01900000-0000-7000-8000-00000000e201',
    10000,
    'Wool coat',
    'The sale was never rung'
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    owed bigint;
    cash bigint;
    left_on_hand integer;
BEGIN
    SELECT COALESCE(sum(credit_minor - debit_minor), 0) INTO owed
    FROM journal_lines
    WHERE subledger_ref = '01900000-0000-7000-8000-00000000e101'
      AND account_id = '01900000-0000-7000-8000-000000000014';
    SELECT COALESCE(sum(debit_minor - credit_minor), 0) INTO cash
    FROM journal_lines
    WHERE journal_id = '01900000-0000-7000-8000-00000000e801'
      AND account_id = '01900000-0000-7000-8000-000000000010';
    SELECT quantity INTO left_on_hand
    FROM items WHERE id = '01900000-0000-7000-8000-00000000e201';
    IF owed <> 6000 OR cash <> 0 OR left_on_hand <> 1 THEN
        RAISE EXCEPTION 'missed sale did not expense the vendor share';
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor,
    is_reversal, reverses_journal_id
) VALUES (
    '01900000-0000-7000-8000-00000000e802',
    '01900000-0000-7000-8000-000000000001',
    'reversal:01900000-0000-7000-8000-00000000e801',
    '2041-03-02',
    '2041-03-02 13:00:00+00',
    '01900000-0000-7000-8000-00000000e021',
    'USD',
    'missed_sale',
    'coat:void',
    'Void of missed-sale:coat',
    6000,
    6000,
    true,
    '01900000-0000-7000-8000-00000000e801'
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000e802',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000014',
    6000,
    0,
    'vendor',
    '01900000-0000-7000-8000-00000000e101'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000e802',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000018',
    0,
    6000
);

DO $$
BEGIN
    IF (SELECT quantity FROM items WHERE id = '01900000-0000-7000-8000-00000000e201') <> 2 THEN
        RAISE EXCEPTION 'void did not put the piece back';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e803',
        '01900000-0000-7000-8000-000000000001',
        'missed-sale:full',
        '2041-03-02', '2041-03-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000e021',
        'USD', 'missed_sale', 'full', 'Missed sale', 10000, 10000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES ('01900000-0000-7000-8000-00000000e803', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000018', 10000, 0);
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
    VALUES ('01900000-0000-7000-8000-00000000e803', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 10000, 'vendor', '01900000-0000-7000-8000-00000000e101');
    INSERT INTO missed_sales (journal_id, vendor_id, item_id, price_minor, description, memo)
    VALUES ('01900000-0000-7000-8000-00000000e803', '01900000-0000-7000-8000-00000000e101', '01900000-0000-7000-8000-00000000e201', 10000, 'Wool coat', 'The sale was never rung');
    RAISE EXCEPTION 'full price credit was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a missed sale debits the loss and credits the vendor%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e804',
        '01900000-0000-7000-8000-000000000001',
        'missed-sale:cash',
        '2041-03-02', '2041-03-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000e021',
        'USD', 'missed_sale', 'cash', 'Missed sale', 6000, 6000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES ('01900000-0000-7000-8000-00000000e804', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 6000, 0);
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
    VALUES ('01900000-0000-7000-8000-00000000e804', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 6000, 'vendor', '01900000-0000-7000-8000-00000000e101');
    INSERT INTO missed_sales (journal_id, vendor_id, item_id, price_minor, description, memo)
    VALUES ('01900000-0000-7000-8000-00000000e804', '01900000-0000-7000-8000-00000000e101', '01900000-0000-7000-8000-00000000e201', 10000, 'Wool coat', 'The sale was never rung');
    RAISE EXCEPTION 'cash line was accepted';
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
        '01900000-0000-7000-8000-00000000e805',
        '01900000-0000-7000-8000-000000000001',
        'missed-sale:store',
        '2041-03-02', '2041-03-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000e021',
        'USD', 'missed_sale', 'store', 'Missed sale', 6000, 6000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES ('01900000-0000-7000-8000-00000000e805', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000018', 6000, 0);
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
    VALUES ('01900000-0000-7000-8000-00000000e805', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 6000, 'vendor', '01900000-0000-7000-8000-00000000e101');
    INSERT INTO missed_sales (journal_id, vendor_id, item_id, price_minor, description, memo)
    VALUES ('01900000-0000-7000-8000-00000000e805', '01900000-0000-7000-8000-00000000e101', '01900000-0000-7000-8000-00000000e209', 10000, 'Shelf', 'The sale was never rung');
    RAISE EXCEPTION 'store piece was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a missed sale stays with the vendor%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e806',
        '01900000-0000-7000-8000-000000000001',
        'missed-sale:mismatch',
        '2041-03-02', '2041-03-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000e021',
        'USD', 'missed_sale', 'mismatch', 'Missed sale', 6000, 6000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES ('01900000-0000-7000-8000-00000000e806', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000018', 6000, 0);
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
    VALUES ('01900000-0000-7000-8000-00000000e806', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 6000, 'vendor', '01900000-0000-7000-8000-00000000e101');
    INSERT INTO missed_sales (journal_id, vendor_id, item_id, price_minor, description, memo)
    VALUES ('01900000-0000-7000-8000-00000000e806', '01900000-0000-7000-8000-00000000e101', '01900000-0000-7000-8000-00000000e202', 10000, 'Felt hat', 'The sale was never rung');
    RAISE EXCEPTION 'vendor mismatch was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a missed sale stays with the vendor%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO registers (id, name) VALUES ('01900000-0000-7000-8000-00000000e092', 'Missed sale proof');
INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-00000000e022',
    '01900000-0000-7000-8000-00000000e092',
    'Mina',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000e807',
    '01900000-0000-7000-8000-000000000001',
    'sale:missed-sold',
    '2041-03-01', '2041-03-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000e021',
    'USD', 'sale', 'missed-sold', 'Sold first', 10000, 10000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-00000000e807', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 10000, 0),
    ('01900000-0000-7000-8000-00000000e807', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 4000);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES (
    '01900000-0000-7000-8000-00000000e807',
    '01900000-0000-7000-8000-000000000001',
    3,
    '01900000-0000-7000-8000-000000000014',
    0, 6000, 'vendor', '01900000-0000-7000-8000-00000000e101'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-00000000e807', 1, '01900000-0000-7000-8000-00000000e203', 10000, 4000, 0);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-00000000e022', '01900000-0000-7000-8000-00000000e807');

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e808',
        '01900000-0000-7000-8000-000000000001',
        'missed-sale:sold',
        '2041-03-02', '2041-03-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000e021',
        'USD', 'missed_sale', 'sold', 'Missed sale', 6000, 6000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES ('01900000-0000-7000-8000-00000000e808', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000018', 6000, 0);
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
    VALUES ('01900000-0000-7000-8000-00000000e808', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 6000, 'vendor', '01900000-0000-7000-8000-00000000e101');
    INSERT INTO missed_sales (journal_id, vendor_id, item_id, price_minor, description, memo)
    VALUES ('01900000-0000-7000-8000-00000000e808', '01900000-0000-7000-8000-00000000e101', '01900000-0000-7000-8000-00000000e203', 10000, 'Sold coat', 'The sale was never rung');
    RAISE EXCEPTION 'sold piece was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a sold piece cannot be a missed sale%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000e809',
    '01900000-0000-7000-8000-000000000001',
    'missed-sale:untagged',
    '2041-03-02', '2041-03-02 12:00:00+00',
    '01900000-0000-7000-8000-00000000e021',
    'USD', 'missed_sale', 'untagged', 'Missed sale', 6000, 6000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES ('01900000-0000-7000-8000-00000000e809', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000018', 6000, 0);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES ('01900000-0000-7000-8000-00000000e809', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 6000, 'vendor', '01900000-0000-7000-8000-00000000e101');
INSERT INTO missed_sales (journal_id, vendor_id, item_id, price_minor, description, memo)
VALUES ('01900000-0000-7000-8000-00000000e809', '01900000-0000-7000-8000-00000000e101', NULL, 10000, 'Untagged lamp', 'The sale was never rung');

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e810',
        '01900000-0000-7000-8000-000000000001',
        'missed-sale:zero',
        '2041-03-02', '2041-03-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000e021',
        'USD', 'missed_sale', 'zero', 'Missed sale', 1, 1
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES ('01900000-0000-7000-8000-00000000e810', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000018', 1, 0);
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
    VALUES ('01900000-0000-7000-8000-00000000e810', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 1, 'vendor', '01900000-0000-7000-8000-00000000e104');
    INSERT INTO missed_sales (journal_id, vendor_id, item_id, price_minor, description, memo)
    VALUES ('01900000-0000-7000-8000-00000000e810', '01900000-0000-7000-8000-00000000e104', NULL, 1000, 'Full take', 'The sale was never rung');
    RAISE EXCEPTION 'a zero vendor share was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a missed sale owes the vendor%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000e811',
    '01900000-0000-7000-8000-000000000001',
    'missed-sale:again',
    '2041-03-03', '2041-03-03 12:00:00+00',
    '01900000-0000-7000-8000-00000000e021',
    'USD', 'missed_sale', 'again', 'Missed sale', 6000, 6000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES ('01900000-0000-7000-8000-00000000e811', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000018', 6000, 0);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES ('01900000-0000-7000-8000-00000000e811', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 6000, 'vendor', '01900000-0000-7000-8000-00000000e101');
INSERT INTO missed_sales (journal_id, vendor_id, item_id, price_minor, description, memo)
VALUES ('01900000-0000-7000-8000-00000000e811', '01900000-0000-7000-8000-00000000e101', '01900000-0000-7000-8000-00000000e201', 10000, 'Wool coat', 'The sale was never rung');

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e812',
        '01900000-0000-7000-8000-000000000001',
        'missed-sale:twice',
        '2041-03-03', '2041-03-03 12:00:00+00',
        '01900000-0000-7000-8000-00000000e021',
        'USD', 'missed_sale', 'twice', 'Missed sale', 6000, 6000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES ('01900000-0000-7000-8000-00000000e812', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000018', 6000, 0);
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
    VALUES ('01900000-0000-7000-8000-00000000e812', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 6000, 'vendor', '01900000-0000-7000-8000-00000000e101');
    INSERT INTO missed_sales (journal_id, vendor_id, item_id, price_minor, description, memo)
    VALUES ('01900000-0000-7000-8000-00000000e812', '01900000-0000-7000-8000-00000000e101', '01900000-0000-7000-8000-00000000e201', 10000, 'Wool coat', 'The sale was never rung');
    RAISE EXCEPTION 'a second missed sale was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a missed sale is already posted for that piece%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e813',
        '01900000-0000-7000-8000-000000000001',
        'missed-sale:again',
        '2041-03-03', '2041-03-03 12:00:00+00',
        '01900000-0000-7000-8000-00000000e021',
        'USD', 'missed_sale', 'again-2', 'Missed sale', 6000, 6000
    );
    RAISE EXCEPTION 'duplicate posting key was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
END $$;

SET CONSTRAINTS ALL IMMEDIATE;

DO $$
BEGIN
    DROP TRIGGER journals_missed_sale_no_delete ON journals;
    RAISE EXCEPTION 'dropping the missed sale trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE journals DISABLE TRIGGER journals_missed_sale_no_delete;
    RAISE EXCEPTION 'disabling the missed sale trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_assert_missed_sale()
    RETURNS trigger LANGUAGE plpgsql AS $body$
    BEGIN
        RETURN NULL;
    END;
    $body$;
    RAISE EXCEPTION 'replacing the missed sale guard was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    SET LOCAL session_replication_role = replica;
    BEGIN
        ALTER TABLE missed_sales DISABLE TRIGGER missed_sales_apply_no_delete;
        RAISE EXCEPTION 'replica disable was accepted';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
                RAISE;
            END IF;
    END;
    SET LOCAL session_replication_role = origin;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    BEGIN
        SET LOCAL session_replication_role = replica;
        RAISE EXCEPTION 'poster replica mode was accepted';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        SET LOCAL event_triggers = false;
        RAISE EXCEPTION 'poster turned event triggers off';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    RESET ROLE;
END $$;

-- T2: the database posts a missed sale itself. The application names the
-- vendor, the piece and the price; the share comes from the rate the database
-- keeps. The application role cannot write a missed sale any other way.
INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity)
VALUES ('01900000-0000-7000-8000-00000000e2f1', 'FnCoat', '01900000-0000-7000-8000-00000000e101', 'Rain coat', 10000, 'BC-MISS-FN', DATE '2041-03-01', 1);

DO $$
DECLARE
    posted uuid;
    again uuid;
    attempt text;
    wanted text;
    attempts text[][] := ARRAY[
        ['SELECT stall_post_missed_sale(''01900000-0000-7000-8000-00000000e101'', '''', 1000, DATE ''2041-03-05'', '' '', ''gone'', ''aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'')', 'a missed sale names the piece'],
        ['SELECT stall_post_missed_sale(''01900000-0000-7000-8000-00000000e101'', '''', 1000, DATE ''2041-03-05'', ''Lamp'', '''', ''aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'')', 'a missed sale names the piece'],
        ['SELECT stall_post_missed_sale(''01900000-0000-7000-8000-00000000e101'', '''', 1000, DATE ''2041-03-05'', ''Lamp'', ''gone'', ''short'')', 'this form has expired'],
        ['SELECT stall_post_missed_sale(''01900000-0000-7000-8000-00000000e101'', '''', 1000, DATE ''2041-03-05'', ''Lamp'', ''gone'', NULL)', 'this form has expired'],
        ['SELECT stall_post_missed_sale(''01900000-0000-7000-8000-00000000e1ff'', '''', 1000, DATE ''2041-03-05'', ''Lamp'', ''gone'', ''aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'')', 'a missed sale needs a vendor'],
        ['SELECT stall_post_missed_sale(''01900000-0000-7000-8000-00000000e101'', ''Hat'', 1000, DATE ''2041-03-05'', ''Lamp'', ''gone'', ''aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'')', 'a missed sale needs an item'],
        ['SELECT stall_post_missed_sale(''01900000-0000-7000-8000-00000000e101'', '''', 0, DATE ''2041-03-05'', ''Lamp'', ''gone'', ''aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'')', 'a missed sale owes the vendor'],
        ['SELECT stall_post_missed_sale(''01900000-0000-7000-8000-00000000e104'', '''', 1000, DATE ''2041-03-05'', ''Lamp'', ''gone'', ''aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'')', 'a missed sale owes the vendor'],
        ['SELECT stall_missed_sale_quote(''01900000-0000-7000-8000-00000000e101'', '''', NULL)', 'a missed sale owes the vendor'],
        ['INSERT INTO missed_sales (journal_id, vendor_id, price_minor, description, memo) VALUES (''01900000-0000-7000-8000-00000000e2f9'', ''01900000-0000-7000-8000-00000000e101'', 1000, ''Lamp'', ''gone'')', 'permission denied'],
        ['CREATE OR REPLACE FUNCTION stall_missed_sale_quote(uuid, text, bigint) RETURNS bigint LANGUAGE sql AS ''SELECT 1::bigint''', 'permission denied'],
        ['ALTER FUNCTION stall_post_missed_sale(uuid, text, bigint, date, text, text, text) SECURITY INVOKER', 'must be owner'],
        ['DROP FUNCTION stall_post_missed_sale(uuid, text, bigint, date, text, text, text)', 'must be owner']
    ];
    i integer;
BEGIN
    SET LOCAL ROLE stall_poster;
    IF stall_missed_sale_quote('01900000-0000-7000-8000-00000000e101', ' fncoat ', 10000) <> 6000
       OR stall_missed_sale_quote('01900000-0000-7000-8000-00000000e101', '', 999) <> 599 THEN
        RAISE EXCEPTION 'the missed sale quote is not the vendor share';
    END IF;
    FOR i IN 1 .. array_length(attempts, 1) LOOP
        attempt := attempts[i][1];
        wanted := attempts[i][2];
        BEGIN
            EXECUTE attempt;
            RAISE EXCEPTION 'accepted: %', attempt;
        EXCEPTION
            WHEN OTHERS THEN
                IF SQLERRM NOT LIKE '%' || wanted || '%' THEN
                    RAISE EXCEPTION '% was refused for the wrong reason: %', attempt, SQLERRM;
                END IF;
        END;
    END LOOP;

    posted := stall_post_missed_sale('01900000-0000-7000-8000-00000000e101', ' FnCoat ', 10000, DATE '2041-03-05', ' Rain coat ', ' walked out ', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    again := stall_post_missed_sale('01900000-0000-7000-8000-00000000e101', 'FnCoat', 10000, DATE '2041-03-05', 'Rain coat', 'walked out', 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb');
    RESET ROLE;
    IF again IS DISTINCT FROM posted
       OR (SELECT count(*) FROM journals WHERE posting_key = 'missed-sale:bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb') <> 1 THEN
        RAISE EXCEPTION 'a second press posted a second missed sale';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM missed_sales
        WHERE journal_id = posted
          AND vendor_id = '01900000-0000-7000-8000-00000000e101'
          AND item_id = '01900000-0000-7000-8000-00000000e2f1'
          AND price_minor = 10000 AND description = 'Rain coat' AND memo = 'walked out'
    ) OR (SELECT source_type || '/' || source_reference || '/' || posting_date FROM journals WHERE id = posted)
         <> 'missed_sale/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb/2041-03-05' THEN
        RAISE EXCEPTION 'the missed sale row does not name the piece';
    END IF;
    IF (SELECT sum(line.debit_minor) FROM journal_lines AS line JOIN chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = posted AND role.role = 'operating_expense') <> 6000
       OR (SELECT sum(line.credit_minor) FROM journal_lines AS line JOIN chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = posted AND role.role = 'vendor_payable' AND line.subledger_type = 'vendor'
          AND line.subledger_ref = '01900000-0000-7000-8000-00000000e101') <> 6000
       OR (SELECT count(*) FROM journal_lines WHERE journal_id = posted) <> 2 THEN
        RAISE EXCEPTION 'the missed sale did not post the vendor share';
    END IF;
    IF (SELECT quantity FROM items WHERE id = '01900000-0000-7000-8000-00000000e2f1') <> 0 THEN
        RAISE EXCEPTION 'the missed piece is still counted';
    END IF;
    -- The piece is gone now, so a second missed sale for it is refused.
    SET LOCAL ROLE stall_poster;
    BEGIN
        PERFORM stall_post_missed_sale('01900000-0000-7000-8000-00000000e101', 'FnCoat', 10000, DATE '2041-03-05', 'Rain coat', 'again', 'cccccccccccccccccccccccccccccccc');
        RAISE EXCEPTION 'a second missed sale for one piece was accepted';
    EXCEPTION
        WHEN check_violation THEN
            IF SQLERRM NOT IN ('a missed sale is already posted for that piece', 'there are not enough on hand') THEN
                RAISE;
            END IF;
    END;
    RESET ROLE;
END $$;

-- Item counts (D10): every count change above wrote exactly one row, used by
-- that change; each item's rows run from one count to the next and end at its
-- count now; and each ending wrote one row per event.
DO $$
BEGIN
    SET CONSTRAINTS item_quantity_changes_used_sealed IMMEDIATE;
    IF EXISTS (SELECT 1 FROM item_quantity_changes WHERE NOT applied) THEN
        RAISE EXCEPTION 'a count change row was never used';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM (
            SELECT change.item_id, change.old_quantity, change.new_quantity,
                   lag(change.new_quantity) OVER (PARTITION BY change.item_id ORDER BY change.changed_at, change.id) AS before,
                   lead(change.id) OVER (PARTITION BY change.item_id ORDER BY change.changed_at, change.id) AS later
            FROM item_quantity_changes AS change
        ) AS chain
        JOIN items AS item ON item.id = chain.item_id
        WHERE (chain.before IS NOT NULL AND chain.before <> chain.old_quantity)
           OR (chain.later IS NULL AND chain.new_quantity <> item.quantity)
    ) THEN
        RAISE EXCEPTION 'an item count history does not match its counts';
    END IF;
    IF (SELECT count(*) FROM item_quantity_changes WHERE ending = 'sold') <> (SELECT count(*) FROM sale_lines)
       OR (SELECT count(*) FROM item_quantity_changes WHERE ending = 'customer_return') <> (SELECT count(*) FROM item_returns)
       OR (SELECT count(*) FROM item_quantity_changes WHERE ending = 'missed_sale') <> (SELECT count(*) FROM missed_sales WHERE item_id IS NOT NULL)
       OR (SELECT count(*) FROM item_quantity_changes WHERE ending = 'store_use') <> (SELECT count(*) FROM store_uses WHERE item_id IS NOT NULL)
       OR (SELECT count(*) FROM item_quantity_changes WHERE ending = 'missed_sale_undone') <> (
           SELECT count(*) FROM missed_sales AS missed
           JOIN journals AS reversal ON reversal.reverses_journal_id = missed.journal_id AND reversal.is_reversal
           WHERE missed.item_id IS NOT NULL)
       OR (SELECT count(*) FROM item_quantity_changes WHERE ending = 'store_use_undone') <> (
           SELECT count(*) FROM store_uses AS used
           JOIN journals AS reversal ON reversal.reverses_journal_id = used.journal_id AND reversal.is_reversal
           WHERE used.item_id IS NOT NULL)
       OR (SELECT count(*) FROM item_quantity_changes WHERE ending = 'customer_return_undone') <> (
           SELECT count(*) FROM item_returns AS returned
           JOIN journals AS reversal ON reversal.reverses_journal_id = returned.journal_id AND reversal.is_reversal) THEN
        RAISE EXCEPTION 'an ending did not write exactly one count row per event';
    END IF;
END $$;

ROLLBACK;
