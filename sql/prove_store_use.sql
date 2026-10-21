-- A store use expenses the agreed price and leaves the piece off the floor. One transaction, then ROLLBACK.

BEGIN;

UPDATE mall SET store_stock_enabled = true;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES
    ('01900000-0000-7000-8000-00000000f101', 'use', 'Uma', 'Lane'),
    ('01900000-0000-7000-8000-00000000f102', 'use2', 'Uri', 'Lane');

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity)
VALUES
    ('01900000-0000-7000-8000-00000000f201', 'Sign', '01900000-0000-7000-8000-00000000f101', 'Open sign', 2500, 'BC-USE', DATE '2042-04-01', 2),
    ('01900000-0000-7000-8000-00000000f202', 'Other', '01900000-0000-7000-8000-00000000f102', 'Other sign', 2500, 'BC-USE-2', DATE '2042-04-01', 1),
    ('01900000-0000-7000-8000-00000000f203', 'Sold', '01900000-0000-7000-8000-00000000f101', 'Sold sign', 2500, 'BC-USE-SOLD', DATE '2042-04-01', 1),
    ('01900000-0000-7000-8000-00000000f204', 'Stock', '01900000-0000-7000-8000-00000000f101', 'Stock sign', 2500, 'BC-USE-STOCK', DATE '2042-04-01', 1);

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000f021',
    '01900000-0000-7000-8000-000000000001',
    '2042-01-01', '2042-12-31', 'open'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f801',
    '01900000-0000-7000-8000-000000000001',
    'store-purchase:sign',
    '2042-04-02', '2042-04-02 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f101', 'Store purchase', 2500, 2500
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES ('01900000-0000-7000-8000-00000000f801', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000022', 2500, 0);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES ('01900000-0000-7000-8000-00000000f801', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 2500, 'vendor', '01900000-0000-7000-8000-00000000f101');
INSERT INTO store_uses (journal_id, vendor_id, item_id, amount_minor, description, memo)
VALUES ('01900000-0000-7000-8000-00000000f801', '01900000-0000-7000-8000-00000000f101', '01900000-0000-7000-8000-00000000f201', 2500, 'Open sign', 'Bought for the business, not for resale');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    owed bigint;
    cash bigint;
    left_on_hand integer;
    place text;
    cost bigint;
BEGIN
    SELECT COALESCE(sum(credit_minor - debit_minor), 0) INTO owed
    FROM journal_lines
    WHERE journal_id = '01900000-0000-7000-8000-00000000f801'
      AND account_id = '01900000-0000-7000-8000-000000000014';
    SELECT COALESCE(sum(debit_minor), 0) INTO cash
    FROM journal_lines
    WHERE journal_id = '01900000-0000-7000-8000-00000000f801'
      AND account_id = '01900000-0000-7000-8000-000000000010';
    SELECT quantity, placement, cost_minor INTO left_on_hand, place, cost
    FROM items WHERE id = '01900000-0000-7000-8000-00000000f201';
    IF owed <> 2500 OR cash <> 0 OR left_on_hand <> 1 OR place <> 'consigned' OR cost IS NOT NULL THEN
        RAISE EXCEPTION 'store use did not keep the piece off inventory';
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor,
    is_reversal, reverses_journal_id
) VALUES (
    '01900000-0000-7000-8000-00000000f802',
    '01900000-0000-7000-8000-000000000001',
    'reversal:01900000-0000-7000-8000-00000000f801',
    '2042-04-02', '2042-04-02 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f101:void', 'Void of store-purchase:sign',
    2500, 2500, true, '01900000-0000-7000-8000-00000000f801'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES ('01900000-0000-7000-8000-00000000f802', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 2500, 0, 'vendor', '01900000-0000-7000-8000-00000000f101');
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES ('01900000-0000-7000-8000-00000000f802', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000022', 0, 2500);

DO $$
BEGIN
    IF (SELECT quantity FROM items WHERE id = '01900000-0000-7000-8000-00000000f201') <> 2 THEN
        RAISE EXCEPTION 'void did not put the piece back';
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f803',
    '01900000-0000-7000-8000-000000000001',
    'store-purchase:again',
    '2042-04-03', '2042-04-03 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f101', 'Store purchase', 2500, 2500
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES ('01900000-0000-7000-8000-00000000f803', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000022', 2500, 0);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES ('01900000-0000-7000-8000-00000000f803', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 2500, 'vendor', '01900000-0000-7000-8000-00000000f101');
INSERT INTO store_uses (journal_id, vendor_id, item_id, amount_minor, description, memo)
VALUES ('01900000-0000-7000-8000-00000000f803', '01900000-0000-7000-8000-00000000f101', '01900000-0000-7000-8000-00000000f201', 2500, 'Open sign', 'Bought for the business, not for resale');

DO $$
BEGIN
    INSERT INTO item_buys (journal_id, item_id, vendor_id, amount_minor)
    VALUES ('01900000-0000-7000-8000-00000000f803', '01900000-0000-7000-8000-00000000f201', '01900000-0000-7000-8000-00000000f101', 2500);
    RAISE EXCEPTION 'a use became inventory';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a use is not inventory%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f804',
    '01900000-0000-7000-8000-000000000001',
    'store-purchase:stock',
    '2042-04-03', '2042-04-03 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f101', 'Store purchase', 2500, 2500
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES ('01900000-0000-7000-8000-00000000f804', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000022', 2500, 0);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES ('01900000-0000-7000-8000-00000000f804', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 2500, 'vendor', '01900000-0000-7000-8000-00000000f101');
INSERT INTO item_buys (journal_id, item_id, vendor_id, amount_minor)
VALUES ('01900000-0000-7000-8000-00000000f804', '01900000-0000-7000-8000-00000000f204', '01900000-0000-7000-8000-00000000f101', 2500);

DO $$
BEGIN
    INSERT INTO store_uses (journal_id, vendor_id, item_id, amount_minor, description, memo)
    VALUES ('01900000-0000-7000-8000-00000000f804', '01900000-0000-7000-8000-00000000f101', '01900000-0000-7000-8000-00000000f204', 2500, 'Stock sign', 'Bought for the business, not for resale');
    RAISE EXCEPTION 'inventory was accepted as a use';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%inventory cannot be a use%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000f805',
        '01900000-0000-7000-8000-000000000001',
        'store-purchase:wrong',
        '2042-04-03', '2042-04-03 12:00:00+00',
        '01900000-0000-7000-8000-00000000f021',
        'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f101', 'Store purchase', 1000, 1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES ('01900000-0000-7000-8000-00000000f805', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000022', 1000, 0);
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
    VALUES ('01900000-0000-7000-8000-00000000f805', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 1000, 'vendor', '01900000-0000-7000-8000-00000000f101');
    INSERT INTO store_uses (journal_id, vendor_id, item_id, amount_minor, description, memo)
    VALUES ('01900000-0000-7000-8000-00000000f805', '01900000-0000-7000-8000-00000000f101', NULL, 2500, 'Untagged', 'Bought for the business, not for resale');
    RAISE EXCEPTION 'a mismatched amount was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a store use pays the agreed price%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000f806',
        '01900000-0000-7000-8000-000000000001',
        'store-purchase:mismatch',
        '2042-04-03', '2042-04-03 12:00:00+00',
        '01900000-0000-7000-8000-00000000f021',
        'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f101', 'Store purchase', 2500, 2500
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES ('01900000-0000-7000-8000-00000000f806', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000022', 2500, 0);
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
    VALUES ('01900000-0000-7000-8000-00000000f806', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 2500, 'vendor', '01900000-0000-7000-8000-00000000f101');
    INSERT INTO store_uses (journal_id, vendor_id, item_id, amount_minor, description, memo)
    VALUES ('01900000-0000-7000-8000-00000000f806', '01900000-0000-7000-8000-00000000f101', '01900000-0000-7000-8000-00000000f202', 2500, 'Other sign', 'Bought for the business, not for resale');
    RAISE EXCEPTION 'vendor mismatch was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a store use stays with the vendor%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO registers (id, name) VALUES ('01900000-0000-7000-8000-00000000f092', 'Store use proof');
INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f022',
    '01900000-0000-7000-8000-00000000f092',
    'Uma',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f807',
    '01900000-0000-7000-8000-000000000001',
    'sale:use-sold', '2042-04-01', '2042-04-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD', 'sale', 'use-sold', 'Sold first', 2500, 2500
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES ('01900000-0000-7000-8000-00000000f807', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 2500, 0);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES ('01900000-0000-7000-8000-00000000f807', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 2500, 'vendor', '01900000-0000-7000-8000-00000000f101');
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-00000000f807', 1, '01900000-0000-7000-8000-00000000f203', 2500, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-00000000f022', '01900000-0000-7000-8000-00000000f807');

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000f808',
        '01900000-0000-7000-8000-000000000001',
        'store-purchase:sold', '2042-04-03', '2042-04-03 12:00:00+00',
        '01900000-0000-7000-8000-00000000f021',
        'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f101', 'Store purchase', 2500, 2500
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES ('01900000-0000-7000-8000-00000000f808', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000022', 2500, 0);
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
    VALUES ('01900000-0000-7000-8000-00000000f808', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 2500, 'vendor', '01900000-0000-7000-8000-00000000f101');
    INSERT INTO store_uses (journal_id, vendor_id, item_id, amount_minor, description, memo)
    VALUES ('01900000-0000-7000-8000-00000000f808', '01900000-0000-7000-8000-00000000f101', '01900000-0000-7000-8000-00000000f203', 2500, 'Sold sign', 'Bought for the business, not for resale');
    RAISE EXCEPTION 'sold piece was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a sold piece cannot be a store use%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f809',
    '01900000-0000-7000-8000-000000000001',
    'store-purchase:untagged', '2042-04-03', '2042-04-03 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f101', 'Store purchase', 2500, 2500
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES ('01900000-0000-7000-8000-00000000f809', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000022', 2500, 0);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES ('01900000-0000-7000-8000-00000000f809', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 2500, 'vendor', '01900000-0000-7000-8000-00000000f101');
INSERT INTO store_uses (journal_id, vendor_id, item_id, amount_minor, description, memo)
VALUES ('01900000-0000-7000-8000-00000000f809', '01900000-0000-7000-8000-00000000f101', NULL, 2500, 'Window sign', 'Bought for the business, not for resale');

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000f810',
        '01900000-0000-7000-8000-000000000001',
        'store-purchase:twice', '2042-04-03', '2042-04-03 12:00:00+00',
        '01900000-0000-7000-8000-00000000f021',
        'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f101', 'Store purchase', 2500, 2500
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES ('01900000-0000-7000-8000-00000000f810', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000022', 2500, 0);
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
    VALUES ('01900000-0000-7000-8000-00000000f810', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 2500, 'vendor', '01900000-0000-7000-8000-00000000f101');
    INSERT INTO store_uses (journal_id, vendor_id, item_id, amount_minor, description, memo)
    VALUES ('01900000-0000-7000-8000-00000000f810', '01900000-0000-7000-8000-00000000f101', '01900000-0000-7000-8000-00000000f201', 2500, 'Open sign', 'Bought for the business, not for resale');
    RAISE EXCEPTION 'a second use was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%that piece is already used by the store%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000f811',
        '01900000-0000-7000-8000-000000000001',
        'store-purchase:again', '2042-04-03', '2042-04-03 12:00:00+00',
        '01900000-0000-7000-8000-00000000f021',
        'USD', 'store_purchase', 'again-2', 'Store purchase', 2500, 2500
    );
    RAISE EXCEPTION 'duplicate posting key was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
END $$;

DO $$
BEGIN
    DROP TRIGGER store_uses_apply_no_delete ON store_uses;
    RAISE EXCEPTION 'dropping the store use trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE store_uses DISABLE TRIGGER store_uses_apply_no_delete;
    RAISE EXCEPTION 'disabling the store use trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_apply_store_use()
    RETURNS trigger LANGUAGE plpgsql AS $body$
    BEGIN
        RETURN NEW;
    END;
    $body$;
    RAISE EXCEPTION 'replacing the store use guard was accepted';
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
        ALTER TABLE store_uses DISABLE TRIGGER store_uses_no_delete;
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

ROLLBACK;
