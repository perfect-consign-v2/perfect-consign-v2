-- Vendor clawback: return/void after payout posts receivable; apply at payout.
-- App path named for obligations: reclass( openReceivable( openPayable( apply(
-- clawbackBeforeVoid( giveBack( — SQL mirrors those posts.
-- One transaction, then ROLLBACK. Expected refusals are caught.

BEGIN;

UPDATE mall SET tax_bps = 0, cash_round_minor = 0;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES
    ('01900000-0000-7000-8000-00000000f101', 'claw', 'Clara', 'Back'),
    ('01900000-0000-7000-8000-00000000f102', 'void', 'Vera', 'Void');

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

ROLLBACK;
