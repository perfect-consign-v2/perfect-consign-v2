-- A vendor memo credits or debits the payable. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO vendors (id, code, first_name, last_name, commission_bps)
VALUES
    ('01900000-0000-7000-8000-00000000e301', 'memo', 'Mina', 'Memo', 0),
    ('01900000-0000-7000-8000-00000000e302', 'zero', 'Otto', 'Zero', 0);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity, commission_bps)
VALUES (
    '01900000-0000-7000-8000-00000000e401',
    'MemoCoat',
    '01900000-0000-7000-8000-00000000e301',
    'Wool coat',
    4000,
    'BC-MEMO',
    DATE '2042-06-01',
    1,
    0
);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity, commission_bps)
VALUES (
    '01900000-0000-7000-8000-00000000e402',
    'DrawCoat',
    '01900000-0000-7000-8000-00000000e302',
    'Draw coat',
    1000,
    'BC-MEMO-DRAW',
    DATE '2042-06-01',
    1,
    0
);

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000e321',
    '01900000-0000-7000-8000-000000000001',
    '2042-01-01',
    '2042-12-31',
    'open'
);

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-00000000e392', 'Memo proof');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-00000000e322',
    '01900000-0000-7000-8000-00000000e392',
    'Mina',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000e801',
    '01900000-0000-7000-8000-000000000001',
    'vendor-memo:credit',
    '2042-06-15',
    '2042-06-15 12:00:00+00',
    '01900000-0000-7000-8000-00000000e321',
    'USD',
    'vendor_memo',
    'credit',
    'Vendor memo',
    5000,
    5000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000e801',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000028',
    5000,
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
    5000,
    'vendor',
    '01900000-0000-7000-8000-00000000e301'
);

INSERT INTO vendor_memos (journal_id, vendor_id, direction, amount_minor, reason)
VALUES (
    '01900000-0000-7000-8000-00000000e801',
    '01900000-0000-7000-8000-00000000e301',
    'credit',
    5000,
    'We owe her for a repair'
);

INSERT INTO payout_runs (
    id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date
) VALUES (
    '01900000-0000-7000-8000-00000000e901',
    '01900000-0000-7000-8000-000000000001',
    'batch', 'monthly', '2042-06-01', '2042-06-30', '2042-06-01', 'check', 'draft', '2042-07-01'
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    owed bigint;
    rent bigint;
    carried bigint;
    adjustment bigint;
    check_amount bigint;
BEGIN
    SELECT COALESCE(sum(credit_minor - debit_minor), 0) INTO owed
    FROM journal_lines
    WHERE subledger_ref = '01900000-0000-7000-8000-00000000e301'
      AND account_id = '01900000-0000-7000-8000-000000000014';
    SELECT COALESCE(sum(debit_minor - credit_minor), 0) INTO rent
    FROM journal_lines
    WHERE subledger_type = 'booth'
      AND account_id = '01900000-0000-7000-8000-000000000015';
    SELECT carried_payable_minor, adjustment_minor, check_minor
    INTO carried, adjustment, check_amount
    FROM payout_pack
    WHERE run_id = '01900000-0000-7000-8000-00000000e901'
      AND vendor_id = '01900000-0000-7000-8000-00000000e301';
    IF owed <> 5000 OR rent <> 0 OR carried <> 5000 OR adjustment <> 5000 OR check_amount <> 5000 THEN
        RAISE EXCEPTION 'credit memo did not raise the payable % % % % %', owed, rent, carried, adjustment, check_amount;
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000e802',
    '01900000-0000-7000-8000-000000000001',
    'vendor-memo:debit',
    '2042-06-15',
    '2042-06-15 13:00:00+00',
    '01900000-0000-7000-8000-00000000e321',
    'USD',
    'vendor_memo',
    'debit',
    'Vendor memo',
    8000,
    8000
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000e802',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000014',
    8000,
    0,
    'vendor',
    '01900000-0000-7000-8000-00000000e301'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000e802',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000029',
    0,
    8000
);

INSERT INTO vendor_memos (journal_id, vendor_id, direction, amount_minor, reason)
VALUES (
    '01900000-0000-7000-8000-00000000e802',
    '01900000-0000-7000-8000-00000000e301',
    'debit',
    8000,
    'She owes us for supplies'
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    owed bigint;
    carried bigint;
    brought bigint;
    adjustment bigint;
    check_amount bigint;
    settled bigint;
    sales bigint;
    commission bigint;
    discount bigint;
    return_minor bigint;
    void_minor bigint;
    draw_minor bigint;
    check_paid bigint;
    settlement bigint;
BEGIN
    SELECT COALESCE(sum(credit_minor - debit_minor), 0) INTO owed
    FROM journal_lines
    WHERE subledger_ref = '01900000-0000-7000-8000-00000000e301'
      AND account_id = '01900000-0000-7000-8000-000000000014';
    SELECT pack.carried_payable_minor, pack.brought_forward_minor, pack.adjustment_minor, pack.check_minor, pack.settled_minor,
           pack.sales_minor, pack.commission_minor, pack.discount_minor, pack.return_minor, pack.void_minor, pack.draw_minor,
           pack.check_paid_minor, pack.settlement_applied_minor
    INTO carried, brought, adjustment, check_amount, settled,
         sales, commission, discount, return_minor, void_minor, draw_minor,
         check_paid, settlement
    FROM payout_pack AS pack
    WHERE run_id = '01900000-0000-7000-8000-00000000e901'
      AND vendor_id = '01900000-0000-7000-8000-00000000e301';
    IF owed <> -3000 OR carried <> -3000 OR check_amount <> 0 OR settled <> 0 OR adjustment <> -3000 THEN
        RAISE EXCEPTION 'debit memo did not leave a negative payable % % % % %', owed, carried, check_amount, settled, adjustment;
    END IF;
    IF carried <> brought + (sales - discount - commission - return_minor - void_minor - draw_minor - check_paid - settlement) + adjustment THEN
        RAISE EXCEPTION 'a negative payable does not match the payout identity';
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000e803',
    '01900000-0000-7000-8000-000000000001',
    'sale:memo',
    '2042-06-16',
    '2042-06-16 12:00:00+00',
    '01900000-0000-7000-8000-00000000e321',
    'USD',
    'sale',
    'memo',
    'Cash sale memo',
    4000,
    4000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000e803',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000010',
    4000,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000e803',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000014',
    0,
    4000,
    'vendor',
    '01900000-0000-7000-8000-00000000e301'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES (
    '01900000-0000-7000-8000-00000000e803',
    1,
    '01900000-0000-7000-8000-00000000e401',
    4000,
    0,
    0
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-00000000e322', '01900000-0000-7000-8000-00000000e803');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    owed bigint;
BEGIN
    SELECT COALESCE(sum(credit_minor - debit_minor), 0) INTO owed
    FROM journal_lines
    WHERE subledger_ref = '01900000-0000-7000-8000-00000000e301'
      AND account_id = '01900000-0000-7000-8000-000000000014';
    IF owed <> 1000 THEN
        RAISE EXCEPTION 'the next sale did not pay the memo down %', owed;
    END IF;
END $$;

UPDATE payout_runs SET status = 'settled' WHERE id = '01900000-0000-7000-8000-00000000e901';

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e806',
        '01900000-0000-7000-8000-000000000001',
        'vendor-memo:sealed',
        '2042-06-20', '2042-06-20 12:00:00+00',
        '01900000-0000-7000-8000-00000000e321',
        'USD', 'vendor_memo', 'sealed', 'Vendor memo', 100, 100
    );
    RAISE EXCEPTION 'a sealed window accepted a memo';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a settled payout window refuses a journal%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e807',
        '01900000-0000-7000-8000-000000000001',
        'sale:memo-draw',
        '2042-07-02', '2042-07-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000e321',
        'USD', 'sale', 'memo-draw', 'Draw past zero', 1000, 1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES ('01900000-0000-7000-8000-00000000e807', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 500, 0);
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
    VALUES
        ('01900000-0000-7000-8000-00000000e807', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 500, 0, 'vendor', '01900000-0000-7000-8000-00000000e302'),
        ('01900000-0000-7000-8000-00000000e807', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000014', 0, 1000, 'vendor', '01900000-0000-7000-8000-00000000e302');
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
    VALUES ('01900000-0000-7000-8000-00000000e807', 1, '01900000-0000-7000-8000-00000000e402', 1000, 0, 0);
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-00000000e322', '01900000-0000-7000-8000-00000000e807');
    SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;
    RAISE EXCEPTION 'a draw past zero was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a vendor draw is limited to the open payable%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e808',
        '01900000-0000-7000-8000-000000000001',
        'disbursement:memo-check',
        '2042-07-02', '2042-07-02 12:10:00+00',
        '01900000-0000-7000-8000-00000000e321',
        'USD', 'check_disbursement', '01900000-0000-7000-8000-00000000e302', 'Check', 100, 100
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
    VALUES ('01900000-0000-7000-8000-00000000e808', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 100, 0, 'vendor', '01900000-0000-7000-8000-00000000e302');
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES ('01900000-0000-7000-8000-00000000e808', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 100);
    SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;
    RAISE EXCEPTION 'a check past zero was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a disbursement pays the open payable%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e809',
        '01900000-0000-7000-8000-000000000001',
        'booth-correction:memo',
        '2042-07-02', '2042-07-02 12:20:00+00',
        '01900000-0000-7000-8000-00000000e321',
        'USD', 'booth_correction', 'memo', 'Wrong booth', 100, 100
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
    VALUES
        ('01900000-0000-7000-8000-00000000e809', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 100, 0, 'vendor', '01900000-0000-7000-8000-00000000e302'),
        ('01900000-0000-7000-8000-00000000e809', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 100, 'vendor', '01900000-0000-7000-8000-00000000e301');
    SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;
    RAISE EXCEPTION 'a non-memo journal past zero was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a vendor payable cannot go below zero%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e810',
        '01900000-0000-7000-8000-000000000001',
        'vendor-memo:credit',
        '2042-07-03', '2042-07-03 12:00:00+00',
        '01900000-0000-7000-8000-00000000e321',
        'USD', 'vendor_memo', 'again', 'Vendor memo', 100, 100
    );
    RAISE EXCEPTION 'duplicate posting key was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor,
    is_reversal, reverses_journal_id
) VALUES (
    '01900000-0000-7000-8000-00000000e804',
    '01900000-0000-7000-8000-000000000001',
    'reversal:01900000-0000-7000-8000-00000000e801',
    '2042-07-02',
    '2042-07-02 15:00:00+00',
    '01900000-0000-7000-8000-00000000e321',
    'USD',
    'vendor_memo',
    'credit:void',
    'Void of vendor-memo:credit',
    5000,
    5000,
    true,
    '01900000-0000-7000-8000-00000000e801'
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000e804',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000014',
    5000,
    0,
    'vendor',
    '01900000-0000-7000-8000-00000000e301'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000e804',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000028',
    0,
    5000
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor,
        is_reversal, reverses_journal_id
    ) VALUES (
        '01900000-0000-7000-8000-00000000e805',
        '01900000-0000-7000-8000-000000000001',
        'reversal:01900000-0000-7000-8000-00000000e801:again',
        '2042-07-02', '2042-07-02 16:00:00+00',
        '01900000-0000-7000-8000-00000000e321',
        'USD', 'vendor_memo', 'credit:void-2', 'Void again', 5000, 5000,
        true, '01900000-0000-7000-8000-00000000e801'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
    VALUES ('01900000-0000-7000-8000-00000000e805', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 5000, 0, 'vendor', '01900000-0000-7000-8000-00000000e301');
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES ('01900000-0000-7000-8000-00000000e805', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000028', 0, 5000);
    RAISE EXCEPTION 'a second void was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a second void is refused%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DELETE FROM vendor_memos WHERE journal_id = '01900000-0000-7000-8000-00000000e801';
    RAISE EXCEPTION 'deleting a vendor memo was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a vendor memo cannot be changed%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    TRUNCATE vendor_memos;
    RAISE EXCEPTION 'truncating vendor memos was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        NULL;
END $$;

DO $$
BEGIN
    ALTER TABLE vendor_memos DISABLE TRIGGER vendor_memos_no_delete;
    RAISE EXCEPTION 'disabling the memo trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER journals_payable_floor_no_delete ON journals;
    RAISE EXCEPTION 'dropping the floor trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_assert_subledger_non_negative()
    RETURNS trigger LANGUAGE plpgsql AS $body$
    BEGIN
        RETURN NULL;
    END;
    $body$;
    RAISE EXCEPTION 'replacing the floor guard was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_apply_vendor_memo()
    RETURNS trigger LANGUAGE plpgsql AS $body$
    BEGIN
        RETURN NEW;
    END;
    $body$;
    RAISE EXCEPTION 'replacing the memo guard was accepted';
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
        UPDATE vendor_memos SET reason = 'changed' WHERE journal_id = '01900000-0000-7000-8000-00000000e801';
        RAISE EXCEPTION 'replica edit was accepted';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%a vendor memo cannot be changed%' THEN
                RAISE;
            END IF;
    END;
    BEGIN
        ALTER TABLE vendor_memos DISABLE TRIGGER vendor_memos_no_delete;
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
