-- Vendor clawback: return/void after payout posts receivable; apply at payout.
-- The app asks the database: stall_clawback_reclass before a void,
-- stall_clawback_apply at payout, stall_clawback_due for what can be taken.
-- One transaction, then ROLLBACK. Expected refusals are caught.

BEGIN;

UPDATE mall SET tax_bps = 0, cash_round_minor = 0;

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000f101', 'claw', 'Clara', 'Back');
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000f102', 'void', 'Vera', 'Void');

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps, quantity)
VALUES
    ('01900000-0000-7000-8000-00000000f201', 'Coat', '01900000-0000-7000-8000-00000000f101', 'Wool coat', 5000, 'BC-CLAW-COAT', DATE '2094-04-01', 0, 1),
    ('01900000-0000-7000-8000-00000000f202', 'Hat', '01900000-0000-7000-8000-00000000f101', 'Felt hat', 2000, 'BC-CLAW-HAT', DATE '2094-04-01', 0, 1),
    ('01900000-0000-7000-8000-00000000f203', 'Bag', '01900000-0000-7000-8000-00000000f102', 'Tote bag', 3000, 'BC-CLAW-BAG', DATE '2094-04-01', 0, 1);

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000f021',
    '01900000-0000-7000-8000-000000000001',
    '2094-01-01', '2094-12-31', 'open'
);

INSERT INTO registers (id, name) VALUES ('01900000-0000-7000-8000-00000000f097', 'Clawback proof');
INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f022',
    '01900000-0000-7000-8000-00000000f097',
    'Clara',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

-- Chart role is present.
DO $$
BEGIN
    IF (SELECT code FROM accounts WHERE id = (SELECT account_id FROM chart_roles WHERE role = 'vendor_receivable'))
       IS DISTINCT FROM '1210' THEN
        RAISE EXCEPTION 'vendor receivable chart role is missing';
    END IF;
END $$;

-- Sale and full disbursement leave payable at zero.
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f301',
    '01900000-0000-7000-8000-000000000001',
    'sale:claw-coat', '2094-04-01', '2094-04-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD', 'sale', 'claw-coat', 'Coat sale', 5000, 5000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f301',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 5000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f301',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 5000, 'vendor', '01900000-0000-7000-8000-00000000f101'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-00000000f301', 1, '01900000-0000-7000-8000-00000000f201', 5000, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-00000000f022', '01900000-0000-7000-8000-00000000f301');
UPDATE items SET quantity = 0 WHERE id = '01900000-0000-7000-8000-00000000f201';

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f302',
    '01900000-0000-7000-8000-000000000001',
    'check_disbursement:claw-1:01900000-0000-7000-8000-00000000f101',
    '2094-04-02', '2094-04-02 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD', 'check_disbursement', '01900000-0000-7000-8000-00000000f101', 'Pay Clara', 5000, 5000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f302',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 5000, 0, 'vendor', '01900000-0000-7000-8000-00000000f101'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f302',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000011', 0, 5000
);

-- Debiting payable past zero after payout is refused (non-return path; return uses receivable).
SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000f3a1',
        '01900000-0000-7000-8000-000000000001',
        'booth-correction:claw-overdraw', '2094-04-03', '2094-04-03 12:00:00+00',
        '01900000-0000-7000-8000-00000000f021',
        'USD', 'booth_correction', 'claw-overdraw', 'Overdraw payable', 5000, 5000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000f3a1',
        '01900000-0000-7000-8000-000000000001',
        1, '01900000-0000-7000-8000-000000000014', 5000, 0, 'vendor', '01900000-0000-7000-8000-00000000f101'
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000f3a1',
        '01900000-0000-7000-8000-000000000001',
        2, '01900000-0000-7000-8000-000000000014', 0, 5000, 'vendor', '01900000-0000-7000-8000-00000000f102'
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    RAISE EXCEPTION 'payable overdraw after payout was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a vendor payable cannot go below zero%' THEN
            RAISE;
        END IF;
END $$;

-- Return after payout posts the excess to vendor receivable.
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f303',
    '01900000-0000-7000-8000-000000000001',
    'return:claw-coat', '2094-04-03', '2094-04-03 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD', 'item_return', 'return:01900000-0000-7000-8000-00000000f201', 'Return coat', 5000, 5000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f303',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-00000000002e', 5000, 0, 'vendor', '01900000-0000-7000-8000-00000000f101'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f303',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000010', 0, 5000
);
INSERT INTO item_returns (journal_id, sale_journal_id, item_id, price_minor, commission_minor, tax_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f303',
    '01900000-0000-7000-8000-00000000f301',
    '01900000-0000-7000-8000-00000000f201',
    5000, 0, 0
);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-00000000f022', '01900000-0000-7000-8000-00000000f303');

DO $$
DECLARE
    payable bigint;
    receivable bigint;
BEGIN
    SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) INTO payable
    FROM journal_lines AS line
    JOIN chart_roles AS role ON role.account_id = line.account_id
    WHERE role.role = 'vendor_payable'
      AND line.subledger_ref = '01900000-0000-7000-8000-00000000f101';
    SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) INTO receivable
    FROM journal_lines AS line
    JOIN chart_roles AS role ON role.account_id = line.account_id
    WHERE role.role = 'vendor_receivable'
      AND line.subledger_ref = '01900000-0000-7000-8000-00000000f101';
    IF payable <> 0 OR receivable <> 5000 THEN
        RAISE EXCEPTION 'return after payout did not post clawback (payable %, receivable %)', payable, receivable;
    END IF;
END $$;

-- Partial payable remains: return splits payable debit and receivable debit.
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f304',
    '01900000-0000-7000-8000-000000000001',
    'sale:claw-hat', '2094-04-04', '2094-04-04 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD', 'sale', 'claw-hat', 'Hat sale', 2000, 2000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f304',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 2000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f304',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 2000, 'vendor', '01900000-0000-7000-8000-00000000f101'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-00000000f304', 1, '01900000-0000-7000-8000-00000000f202', 2000, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-00000000f022', '01900000-0000-7000-8000-00000000f304');
UPDATE items SET quantity = 0 WHERE id = '01900000-0000-7000-8000-00000000f202';

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f305',
    '01900000-0000-7000-8000-000000000001',
    'check_disbursement:claw-hat-partial:01900000-0000-7000-8000-00000000f101',
    '2094-04-05', '2094-04-05 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD', 'check_disbursement', '01900000-0000-7000-8000-00000000f101', 'Pay part of hat', 1000, 1000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f305',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 1000, 0, 'vendor', '01900000-0000-7000-8000-00000000f101'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f305',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000011', 0, 1000
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f306',
    '01900000-0000-7000-8000-000000000001',
    'return:claw-hat', '2094-04-06', '2094-04-06 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD', 'item_return', 'return:01900000-0000-7000-8000-00000000f202', 'Return hat', 2000, 2000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f306',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 1000, 0, 'vendor', '01900000-0000-7000-8000-00000000f101'
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f306',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-00000000002e', 1000, 0, 'vendor', '01900000-0000-7000-8000-00000000f101'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f306',
    '01900000-0000-7000-8000-000000000001',
    3, '01900000-0000-7000-8000-000000000010', 0, 2000
);
INSERT INTO item_returns (journal_id, sale_journal_id, item_id, price_minor, commission_minor, tax_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f306',
    '01900000-0000-7000-8000-00000000f304',
    '01900000-0000-7000-8000-00000000f202',
    2000, 0, 0
);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-00000000f022', '01900000-0000-7000-8000-00000000f306');

DO $$
DECLARE
    payable bigint;
    receivable bigint;
BEGIN
    SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) INTO payable
    FROM journal_lines AS line
    JOIN chart_roles AS role ON role.account_id = line.account_id
    WHERE role.role = 'vendor_payable'
      AND line.subledger_ref = '01900000-0000-7000-8000-00000000f101';
    SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) INTO receivable
    FROM journal_lines AS line
    JOIN chart_roles AS role ON role.account_id = line.account_id
    WHERE role.role = 'vendor_receivable'
      AND line.subledger_ref = '01900000-0000-7000-8000-00000000f101';
    IF payable <> 0 OR receivable <> 6000 THEN
        RAISE EXCEPTION 'split return did not leave receivable only (payable %, receivable %)', payable, receivable;
    END IF;
END $$;

-- Apply clawback against later sales before a check can go out.
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f307',
    '01900000-0000-7000-8000-000000000001',
    'sale:claw-again', '2094-04-07', '2094-04-07 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD', 'sale', 'claw-again', 'Coat sold again', 5000, 5000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f307',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 5000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f307',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 5000, 'vendor', '01900000-0000-7000-8000-00000000f101'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-00000000f307', 1, '01900000-0000-7000-8000-00000000f201', 5000, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-00000000f022', '01900000-0000-7000-8000-00000000f307');
UPDATE items SET quantity = 0 WHERE id = '01900000-0000-7000-8000-00000000f201';

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f308',
    '01900000-0000-7000-8000-000000000001',
    'clawback_apply:run1:01900000-0000-7000-8000-00000000f101',
    '2094-04-08', '2094-04-08 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD', 'clawback_apply', 'run1:01900000-0000-7000-8000-00000000f101', 'Apply clawback', 5000, 5000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f308',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 5000, 0, 'vendor', '01900000-0000-7000-8000-00000000f101'
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f308',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-00000000002e', 0, 5000, 'vendor', '01900000-0000-7000-8000-00000000f101'
);

DO $$
DECLARE
    payable bigint;
    receivable bigint;
BEGIN
    SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) INTO payable
    FROM journal_lines AS line
    JOIN chart_roles AS role ON role.account_id = line.account_id
    WHERE role.role = 'vendor_payable'
      AND line.subledger_ref = '01900000-0000-7000-8000-00000000f101';
    SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) INTO receivable
    FROM journal_lines AS line
    JOIN chart_roles AS role ON role.account_id = line.account_id
    WHERE role.role = 'vendor_receivable'
      AND line.subledger_ref = '01900000-0000-7000-8000-00000000f101';
    IF payable <> 0 OR receivable <> 1000 THEN
        RAISE EXCEPTION 'apply did not net clawback (payable %, receivable %)', payable, receivable;
    END IF;
END $$;

-- Apply that would credit receivable past zero is refused.
DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000f3a2',
        '01900000-0000-7000-8000-000000000001',
        'clawback_apply:run2:01900000-0000-7000-8000-00000000f101',
        '2094-04-09', '2094-04-09 12:00:00+00',
        '01900000-0000-7000-8000-00000000f021',
        'USD', 'clawback_apply', 'run2:01900000-0000-7000-8000-00000000f101', 'Over-apply', 2000, 2000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000f3a2',
        '01900000-0000-7000-8000-000000000001',
        1, '01900000-0000-7000-8000-000000000014', 2000, 0, 'vendor', '01900000-0000-7000-8000-00000000f101'
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000f3a2',
        '01900000-0000-7000-8000-000000000001',
        2, '01900000-0000-7000-8000-00000000002e', 0, 2000, 'vendor', '01900000-0000-7000-8000-00000000f101'
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    RAISE EXCEPTION 'receivable over-apply was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a vendor receivable cannot go below zero%'
           AND SQLERRM NOT LIKE '%a vendor payable cannot go below zero%' THEN
            RAISE;
        END IF;
END $$;

-- Void after payout: reclass then exact reverse.
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f401',
    '01900000-0000-7000-8000-000000000001',
    'sale:claw-bag', '2094-04-10', '2094-04-10 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD', 'sale', 'claw-bag', 'Bag sale', 3000, 3000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f401',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 3000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f401',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 3000, 'vendor', '01900000-0000-7000-8000-00000000f102'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-00000000f401', 1, '01900000-0000-7000-8000-00000000f203', 3000, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-00000000f022', '01900000-0000-7000-8000-00000000f401');
UPDATE items SET quantity = 0 WHERE id = '01900000-0000-7000-8000-00000000f203';

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f402',
    '01900000-0000-7000-8000-000000000001',
    'check_disbursement:claw-bag:01900000-0000-7000-8000-00000000f102',
    '2094-04-11', '2094-04-11 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD', 'check_disbursement', '01900000-0000-7000-8000-00000000f102', 'Pay Vera', 3000, 3000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f402',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 3000, 0, 'vendor', '01900000-0000-7000-8000-00000000f102'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f402',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000011', 0, 3000
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f403',
    '01900000-0000-7000-8000-000000000001',
    'clawback_reclass:01900000-0000-7000-8000-00000000f401:01900000-0000-7000-8000-00000000f102',
    '2094-04-12', '2094-04-12 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD', 'clawback_reclass',
    '01900000-0000-7000-8000-00000000f401:01900000-0000-7000-8000-00000000f102',
    'Clawback after payout', 3000, 3000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f403',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-00000000002e', 3000, 0, 'vendor', '01900000-0000-7000-8000-00000000f102'
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f403',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 3000, 'vendor', '01900000-0000-7000-8000-00000000f102'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor,
    is_reversal, reverses_journal_id
) VALUES (
    '01900000-0000-7000-8000-00000000f404',
    '01900000-0000-7000-8000-000000000001',
    'reversal:01900000-0000-7000-8000-00000000f401',
    '2094-04-12', '2094-04-12 12:05:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD', 'sale', 'claw-bag:void', 'Void bag', 3000, 3000,
    true, '01900000-0000-7000-8000-00000000f401'
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f404',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 3000, 0, 'vendor', '01900000-0000-7000-8000-00000000f102'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f404',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000010', 0, 3000
);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-00000000f022', '01900000-0000-7000-8000-00000000f404');

DO $$
DECLARE
    payable bigint;
    receivable bigint;
BEGIN
    SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) INTO payable
    FROM journal_lines AS line
    JOIN chart_roles AS role ON role.account_id = line.account_id
    WHERE role.role = 'vendor_payable'
      AND line.subledger_ref = '01900000-0000-7000-8000-00000000f102';
    SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) INTO receivable
    FROM journal_lines AS line
    JOIN chart_roles AS role ON role.account_id = line.account_id
    WHERE role.role = 'vendor_receivable'
      AND line.subledger_ref = '01900000-0000-7000-8000-00000000f102';
    IF payable <> 0 OR receivable <> 3000 THEN
        RAISE EXCEPTION 'void reclass left wrong balances (payable %, receivable %)', payable, receivable;
    END IF;
END $$;

-- Wrong source type on vendor receivable is refused.
DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000f3a3',
        '01900000-0000-7000-8000-000000000001',
        'adjustment:claw-bad', '2094-04-13', '2094-04-13 12:00:00+00',
        '01900000-0000-7000-8000-00000000f021',
        'USD', 'adjustment', 'claw-bad', 'Bad receivable', 100, 100
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000f3a3',
        '01900000-0000-7000-8000-000000000001',
        1, '01900000-0000-7000-8000-00000000002e', 100, 0, 'vendor', '01900000-0000-7000-8000-00000000f101'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-00000000f3a3',
        '01900000-0000-7000-8000-000000000001',
        2, '01900000-0000-7000-8000-000000000018', 0, 100
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    RAISE EXCEPTION 'adjustment onto vendor receivable was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a control account line must come from a listed source type%'
           AND SQLERRM NOT LIKE '%a chart account line must come from a listed source type%' THEN
            RAISE;
        END IF;
END $$;

-- Duplicate clawback posting key is refused.
DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000f3a4',
        '01900000-0000-7000-8000-000000000001',
        'clawback_reclass:01900000-0000-7000-8000-00000000f401:01900000-0000-7000-8000-00000000f102',
        '2094-04-14', '2094-04-14 12:00:00+00',
        '01900000-0000-7000-8000-00000000f021',
        'USD', 'clawback_reclass',
        '01900000-0000-7000-8000-00000000f401:01900000-0000-7000-8000-00000000f102',
        'Duplicate reclass', 1, 1
    );
    RAISE EXCEPTION 'duplicate clawback posting key was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
    WHEN SQLSTATE '23505' THEN
        NULL;
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%journals_book_key%' AND SQLERRM NOT LIKE '%duplicate%' THEN
            RAISE;
        END IF;
END $$;

-- Bad reclass shape is refused (apply-shaped lines under reclass source).
-- Memo credits payable so the inverted reclass can debit it without going negative.
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f309',
    '01900000-0000-7000-8000-000000000001',
    'vendor-memo:claw-shape', '2094-04-15', '2094-04-15 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD', 'vendor_memo', 'claw-shape', 'Room for shape test', 500, 500
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f309',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000028', 500, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f309',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 500, 'vendor', '01900000-0000-7000-8000-00000000f101'
);
INSERT INTO vendor_memos (journal_id, vendor_id, direction, amount_minor, reason)
VALUES (
    '01900000-0000-7000-8000-00000000f309',
    '01900000-0000-7000-8000-00000000f101',
    'credit',
    500,
    'Room for clawback shape test'
);

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000f3a5',
        '01900000-0000-7000-8000-000000000001',
        'clawback_reclass:bad-shape', '2094-04-16', '2094-04-16 12:00:00+00',
        '01900000-0000-7000-8000-00000000f021',
        'USD', 'clawback_reclass', 'bad-shape', 'Wrong shape', 100, 100
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000f3a5',
        '01900000-0000-7000-8000-000000000001',
        1, '01900000-0000-7000-8000-000000000014', 100, 0, 'vendor', '01900000-0000-7000-8000-00000000f101'
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000f3a5',
        '01900000-0000-7000-8000-000000000001',
        2, '01900000-0000-7000-8000-00000000002e', 0, 100, 'vendor', '01900000-0000-7000-8000-00000000f101'
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    RAISE EXCEPTION 'inverted clawback reclass was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a clawback reclass debits receivable and credits payable%' THEN
            RAISE;
        END IF;
END $$;

-- Attack the clawback guard.
SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    ALTER TABLE journals DISABLE TRIGGER journals_clawback_no_delete;
    RAISE EXCEPTION 'disabling journals_clawback_no_delete was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE journals ENABLE REPLICA TRIGGER journals_clawback_no_delete;
    RAISE EXCEPTION 'replica-only journals_clawback_no_delete was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER journals_clawback_no_delete ON journals;
    RAISE EXCEPTION 'dropping journals_clawback_no_delete was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_assert_clawback()
    RETURNS trigger
    LANGUAGE plpgsql
    SET search_path = pg_catalog
    AS $body$
    BEGIN
        RETURN NULL;
    END;
    $body$;
    RAISE EXCEPTION 'replacing stall_assert_clawback was accepted';
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
        ALTER TABLE journals DISABLE TRIGGER journals_clawback_no_delete;
        RAISE EXCEPTION 'replica disable of journals_clawback_no_delete was accepted';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            NULL;
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

-- T5: the database works out and posts clawbacks itself. Vendor Fern is paid
-- for a sale, the sale is voided, and her next sale pays it back at payout.
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000f103', 'fern', 'Fern', 'Back');
INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps, quantity)
VALUES
    ('01900000-0000-7000-8000-00000000f204', 'Lamp', '01900000-0000-7000-8000-00000000f103', 'Brass lamp', 4000, 'BC-CLAW-LAMP', DATE '2094-05-01', 0, 1),
    ('01900000-0000-7000-8000-00000000f205', 'Vase', '01900000-0000-7000-8000-00000000f103', 'Glass vase', 1500, 'BC-CLAW-VASE', DATE '2094-05-01', 0, 1);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES
    ('01900000-0000-7000-8000-00000000f501', '01900000-0000-7000-8000-000000000001', 'sale:fern-lamp', '2094-05-01', '2094-05-01 12:00:00+00',
     '01900000-0000-7000-8000-00000000f021', 'USD', 'sale', 'fern-lamp', 'Lamp sale', 4000, 4000),
    ('01900000-0000-7000-8000-00000000f502', '01900000-0000-7000-8000-000000000001', 'check_disbursement:fern-lamp:01900000-0000-7000-8000-00000000f103',
     '2094-05-01', '2094-05-01 13:00:00+00', '01900000-0000-7000-8000-00000000f021', 'USD', 'check_disbursement',
     '01900000-0000-7000-8000-00000000f103', 'Pay Fern', 4000, 4000);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-00000000f501', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 4000, 0, NULL, NULL),
    ('01900000-0000-7000-8000-00000000f501', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 4000, 'vendor', '01900000-0000-7000-8000-00000000f103'),
    ('01900000-0000-7000-8000-00000000f502', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 4000, 0, 'vendor', '01900000-0000-7000-8000-00000000f103'),
    ('01900000-0000-7000-8000-00000000f502', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 4000, NULL, NULL);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-00000000f501', 1, '01900000-0000-7000-8000-00000000f204', 4000, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id) VALUES ('01900000-0000-7000-8000-00000000f022', '01900000-0000-7000-8000-00000000f501');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    attempt text;
    wanted text;
    attempts text[][] := ARRAY[
        ['SELECT stall_clawback_apply(''01900000-0000-7000-8000-00000000f103'', ''run-fern'', DATE ''2094-05-02'')', 'There is no clawback to take out of this payout.'],
        ['SELECT stall_clawback_apply(''01900000-0000-7000-8000-00000000f103'', ''Run Fern'', DATE ''2094-05-02'')', 'a clawback names its payout'],
        ['SELECT stall_clawback_apply(''01900000-0000-7000-8000-00000000f1ff'', ''run-fern'', DATE ''2094-05-02'')', 'That vendor was not found.'],
        ['SELECT stall_clawback_reclass(''01900000-0000-7000-8000-00000000f502'', DATE ''2094-05-02'')', 'that sale was not found'],
        ['CREATE OR REPLACE FUNCTION stall_clawback_due(uuid) RETURNS bigint LANGUAGE sql AS ''SELECT 0::bigint''', 'permission denied'],
        ['DROP FUNCTION stall_clawback_apply(uuid, text, date)', 'must be owner'],
        ['ALTER FUNCTION stall_clawback_reclass(uuid, date) SECURITY INVOKER', 'must be owner']
    ];
    i integer;
BEGIN
    SET LOCAL ROLE stall_poster;
    IF stall_clawback_due('01900000-0000-7000-8000-00000000f103') <> 0 THEN
        RAISE EXCEPTION 'a paid-out vendor with nothing owed back has a clawback due';
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
    -- Before the void: Fern was paid the $40.00, so all of it is owed back.
    IF stall_clawback_reclass('01900000-0000-7000-8000-00000000f501', DATE '2094-05-02') <> 1 THEN
        RAISE EXCEPTION 'the void did not move the paid-out share to owed back';
    END IF;
    RESET ROLE;
    IF stall_vendor_open_clawback('01900000-0000-7000-8000-00000000f103') <> 4000 THEN
        RAISE EXCEPTION 'the reclass did not move $40.00';
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor,
    is_reversal, reverses_journal_id
) VALUES (
    '01900000-0000-7000-8000-00000000f503', '01900000-0000-7000-8000-000000000001', 'reversal:01900000-0000-7000-8000-00000000f501',
    '2094-05-02', '2094-05-02 12:05:00+00', '01900000-0000-7000-8000-00000000f021', 'USD', 'sale', 'fern-lamp:void', 'Void lamp', 4000, 4000,
    true, '01900000-0000-7000-8000-00000000f501'
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-00000000f503', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 0, 4000, NULL, NULL),
    ('01900000-0000-7000-8000-00000000f503', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 4000, 0, 'vendor', '01900000-0000-7000-8000-00000000f103');
INSERT INTO register_session_posts (session_id, journal_id) VALUES ('01900000-0000-7000-8000-00000000f022', '01900000-0000-7000-8000-00000000f503');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

-- Her next sale credits $15.00.
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f504', '01900000-0000-7000-8000-000000000001', 'sale:fern-vase', '2094-05-03', '2094-05-03 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021', 'USD', 'sale', 'fern-vase', 'Vase sale', 1500, 1500
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-00000000f504', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 1500, 0, NULL, NULL),
    ('01900000-0000-7000-8000-00000000f504', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 1500, 'vendor', '01900000-0000-7000-8000-00000000f103');
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-00000000f504', 1, '01900000-0000-7000-8000-00000000f205', 1500, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id) VALUES ('01900000-0000-7000-8000-00000000f022', '01900000-0000-7000-8000-00000000f504');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

-- A hand-built clawback must name its vendor, and a reclass may move no more
-- than the sale credited that vendor.
DO $$
DECLARE
    attempt record;
BEGIN
    FOR attempt IN
        SELECT * FROM (VALUES
            ('clawback_apply', 'run-x:01900000-0000-7000-8000-00000000f101', 100::bigint, 'a clawback names its vendor'),
            ('clawback_reclass', '01900000-0000-7000-8000-00000000f504:01900000-0000-7000-8000-00000000f103', 1501::bigint, 'a clawback reclass moves no more than the sale credited'),
            ('clawback_reclass', '01900000-0000-7000-8000-00000000f502:01900000-0000-7000-8000-00000000f103', 100::bigint, 'a clawback reclass moves no more than the sale credited')
        ) AS listed (kind, reference, amount, wanted)
    LOOP
        BEGIN
            INSERT INTO journals (
                id, book_id, posting_key, posting_date, occurred_at, period_id,
                currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
            ) VALUES (
                '01900000-0000-7000-8000-00000000f5a1', '01900000-0000-7000-8000-000000000001', attempt.kind || ':' || attempt.reference,
                '2094-05-03', '2094-05-03 13:00:00+00', '01900000-0000-7000-8000-00000000f021', 'USD', attempt.kind, attempt.reference,
                'Hand-built clawback', attempt.amount, attempt.amount
            );
            INSERT INTO journal_lines (
                journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
            ) VALUES
                ('01900000-0000-7000-8000-00000000f5a1', '01900000-0000-7000-8000-000000000001', 1,
                 CASE attempt.kind WHEN 'clawback_apply' THEN '01900000-0000-7000-8000-000000000014'::uuid ELSE '01900000-0000-7000-8000-00000000002e'::uuid END,
                 attempt.amount, 0, 'vendor', '01900000-0000-7000-8000-00000000f103'),
                ('01900000-0000-7000-8000-00000000f5a1', '01900000-0000-7000-8000-000000000001', 2,
                 CASE attempt.kind WHEN 'clawback_apply' THEN '01900000-0000-7000-8000-00000000002e'::uuid ELSE '01900000-0000-7000-8000-000000000014'::uuid END,
                 0, attempt.amount, 'vendor', '01900000-0000-7000-8000-00000000f103');
            SET CONSTRAINTS ALL IMMEDIATE;
            RAISE EXCEPTION 'accepted a hand-built % for %', attempt.kind, attempt.reference;
        EXCEPTION
            WHEN check_violation THEN
                IF SQLERRM <> attempt.wanted THEN
                    RAISE EXCEPTION '% % was refused for the wrong reason: %', attempt.kind, attempt.reference, SQLERRM;
                END IF;
        END;
        SET CONSTRAINTS ALL DEFERRED;
    END LOOP;
END $$;

-- At payout the database takes the smaller of what she is owed ($15.00) and
-- what she owes back ($40.00), once.
DO $$
DECLARE
    posted uuid;
BEGIN
    SET LOCAL ROLE stall_poster;
    IF stall_clawback_due('01900000-0000-7000-8000-00000000f103') <> 1500 THEN
        RAISE EXCEPTION 'the clawback due is not the smaller balance';
    END IF;
    posted := stall_clawback_apply('01900000-0000-7000-8000-00000000f103', 'run-fern', DATE '2094-05-04');
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    BEGIN
        PERFORM stall_clawback_apply('01900000-0000-7000-8000-00000000f103', 'run-fern-2', DATE '2094-05-04');
        RAISE EXCEPTION 'a second clawback was taken with nothing left to take';
    EXCEPTION
        WHEN check_violation THEN
            IF SQLERRM <> 'There is no clawback to take out of this payout.' THEN
                RAISE;
            END IF;
    END;
    RESET ROLE;
    IF stall_vendor_open_payable('01900000-0000-7000-8000-00000000f103') <> 0
       OR stall_vendor_open_clawback('01900000-0000-7000-8000-00000000f103') <> 2500
       OR (SELECT posting_key || '/' || posting_date FROM journals WHERE id = posted)
          <> 'clawback_apply:run-fern:01900000-0000-7000-8000-00000000f103/2094-05-04' THEN
        RAISE EXCEPTION 'the clawback did not take $15.00 out of the payout';
    END IF;
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
