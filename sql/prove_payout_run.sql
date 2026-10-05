-- Locks: a settled window, net columns, and a unique disbursement check number.

BEGIN;

UPDATE mall SET rent_prepaid_from_prior_sales = true;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-00000000f201', 'lock', 'Cal', 'Lane');

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-00000000f301', 'L1', 'Lock booth');

INSERT INTO booth_assignments (booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-00000000f301',
    '01900000-0000-7000-8000-00000000f201',
    '2032-03-01'
);

INSERT INTO booth_rent_rates (booth_id, amount_minor)
VALUES ('01900000-0000-7000-8000-00000000f301', 1000);
INSERT INTO assignment_rent (assignment_id, amount_minor)
SELECT assignment.id, rate.amount_minor
FROM booth_assignments AS assignment
JOIN booth_rent_rates AS rate ON rate.booth_id = assignment.booth_id
ON CONFLICT (assignment_id) DO NOTHING;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000f022',
    '01900000-0000-7000-8000-000000000001',
    '2032-01-01',
    '2032-12-31',
    'open'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f811',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-00000000f301:2032-03',
    '2032-03-01',
    '2032-03-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000f022',
    'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f301', 'March rent', 1000, 1000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f811',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000015', 1000, 0, 'booth', '01900000-0000-7000-8000-00000000f301'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f811',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000017', 0, 1000
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f812',
    '01900000-0000-7000-8000-000000000001',
    'store-purchase:lock-1',
    '2032-03-10',
    '2032-03-10 12:00:00+00',
    '01900000-0000-7000-8000-00000000f022',
    'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f201', 'Lock purchase', 4000, 4000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f812',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000022', 4000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f812',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 4000, 'vendor', '01900000-0000-7000-8000-00000000f201'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f813',
    '01900000-0000-7000-8000-000000000001',
    'settlement:01900000-0000-7000-8000-00000000f901:01900000-0000-7000-8000-00000000f201',
    '2032-04-01',
    '2032-04-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000f022',
    'USD', 'settlement', '01900000-0000-7000-8000-00000000f201', 'Close', 1000, 1000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-00000000f813', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 1000, 0, 'vendor', '01900000-0000-7000-8000-00000000f201'),
    ('01900000-0000-7000-8000-00000000f813', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000015', 0, 1000, 'booth', '01900000-0000-7000-8000-00000000f301');

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f814',
    '01900000-0000-7000-8000-000000000001',
    'disbursement:01900000-0000-7000-8000-00000000f901:01900000-0000-7000-8000-00000000f201',
    '2032-04-01',
    '2032-04-01 12:01:00+00',
    '01900000-0000-7000-8000-00000000f022',
    'USD', 'check_disbursement', '01900000-0000-7000-8000-00000000f201', 'Check', 3000, 3000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f814',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 3000, 0, 'vendor', '01900000-0000-7000-8000-00000000f201'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f814',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000011', 0, 3000
);
INSERT INTO register_checks (journal_id, check_number) VALUES ('01900000-0000-7000-8000-00000000f814', '1402');

INSERT INTO vendors (id, code, first_name, last_name, payout_mode)
VALUES ('01900000-0000-7000-8000-00000000f202', 'idle', 'Bea', 'Idle', 'pay');

INSERT INTO payout_runs (
    id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date, reviewed_checksum
) VALUES (
    '01900000-0000-7000-8000-00000000f901',
    '01900000-0000-7000-8000-000000000001',
    'batch', 'monthly', '2032-03-01', '2032-03-31', '2032-03-01', 'check', 'draft', '2032-04-01', 'lock'
);

UPDATE payout_runs
SET reviewed_checksum = stall_pack_checksum(id), reviewed_at = clock_timestamp()
WHERE id = '01900000-0000-7000-8000-00000000f901';

INSERT INTO payout_run_lines (
    run_id, vendor_id, mode, sales_minor, commission_minor, tax_minor, discount_minor,
    return_minor, void_minor, draw_minor, check_paid_minor, settlement_applied_minor,
    rent_minor, rent_charged_minor, rent_brought_forward_minor, rent_paid_since_minor,
    adjustment_minor, brought_forward_minor, carried_payable_minor, carried_rent_minor,
    settled_minor, check_minor, owes_minor, net_minor
)
SELECT run_id, vendor_id, mode, sales_minor, commission_minor, tax_minor, discount_minor,
       return_minor, void_minor, draw_minor, check_paid_minor, settlement_applied_minor,
       rent_minor, rent_charged_minor, rent_brought_forward_minor, rent_paid_since_minor,
       adjustment_minor, brought_forward_minor, carried_payable_minor, carried_rent_minor,
       settled_minor, check_minor, owes_minor, net_minor
FROM payout_pack
WHERE run_id = '01900000-0000-7000-8000-00000000f901';

DO $$
DECLARE
    payable bigint;
    rent bigint;
    adjustment bigint;
    idle_lines integer;
BEGIN
    SELECT carried_payable_minor, carried_rent_minor, adjustment_minor
    INTO payable, rent, adjustment
    FROM payout_pack
    WHERE run_id = '01900000-0000-7000-8000-00000000f901'
      AND vendor_id = '01900000-0000-7000-8000-00000000f201';
    IF payable <> 4000 OR rent <> 2000 OR adjustment <> 4000 THEN
        RAISE EXCEPTION 'the pack is not the ledger % % %', payable, rent, adjustment;
    END IF;
    IF (
        SELECT carried_payable_minor
        FROM payout_run_lines
        WHERE run_id = '01900000-0000-7000-8000-00000000f901'
          AND vendor_id = '01900000-0000-7000-8000-00000000f201'
    ) <> payable THEN
        RAISE EXCEPTION 'the stored report does not match the pack';
    END IF;
    IF stall_pack_checksum('01900000-0000-7000-8000-00000000f901')
       IS DISTINCT FROM stall_pack_checksum_frozen('01900000-0000-7000-8000-00000000f901') THEN
        RAISE EXCEPTION 'the live hash does not match the frozen hash';
    END IF;
    SELECT count(*) INTO idle_lines
    FROM payout_pack
    WHERE run_id = '01900000-0000-7000-8000-00000000f901'
      AND vendor_id = '01900000-0000-7000-8000-00000000f202'
      AND carried_payable_minor = 0
      AND carried_rent_minor = 0
      AND check_minor = 0;
    IF idle_lines <> 1 THEN
        RAISE EXCEPTION 'a zero-activity vendor is missing from the pack';
    END IF;
    PERFORM stall_assert_pack_checksum('01900000-0000-7000-8000-00000000f901');
    PERFORM stall_assert_frozen_checksum('01900000-0000-7000-8000-00000000f901');
END $$;

DO $$
BEGIN
    UPDATE payout_runs SET status = 'paid' WHERE id = '01900000-0000-7000-8000-00000000f901';
    RAISE EXCEPTION 'a skipped status was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

SET CONSTRAINTS ALL IMMEDIATE;
UPDATE payout_runs SET status = 'settled', settled_at = clock_timestamp() WHERE id = '01900000-0000-7000-8000-00000000f901';

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000f815',
        '01900000-0000-7000-8000-000000000001',
        'adjustment:lock-backdated',
        '2032-03-20',
        '2032-04-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000f022',
        'USD', 'adjustment', 'backdated', 'Backdated', 100, 100
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-00000000f815', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000018', 100, 0),
        ('01900000-0000-7000-8000-00000000f815', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000010', 0, 100);
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a settled window accepted a journal';
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
        '01900000-0000-7000-8000-00000000f816',
        '01900000-0000-7000-8000-000000000001',
        'disbursement:other:01900000-0000-7000-8000-00000000f201',
        '2032-04-02',
        '2032-04-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000f022',
        'USD', 'check_disbursement', '01900000-0000-7000-8000-00000000f201', 'Second check', 100, 100
    );
    INSERT INTO register_checks (journal_id, check_number) VALUES ('01900000-0000-7000-8000-00000000f816', '1402');
    RAISE EXCEPTION 'a duplicate disbursement check number was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE payout_run_lines
    SET settlement_journal_id = '01900000-0000-7000-8000-00000000f813'
    WHERE run_id = '01900000-0000-7000-8000-00000000f901'
      AND vendor_id = '01900000-0000-7000-8000-00000000f201';
    RAISE EXCEPTION 'a settled money line was rewritten';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    DELETE FROM payout_runs WHERE id = '01900000-0000-7000-8000-00000000f901';
    RAISE EXCEPTION 'a settled run was deleted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

UPDATE payout_runs SET status = 'reversed', reversed_at = clock_timestamp()
WHERE id = '01900000-0000-7000-8000-00000000f901';

DO $$
BEGIN
    UPDATE payout_runs SET note = 'nope' WHERE id = '01900000-0000-7000-8000-00000000f901';
    RAISE EXCEPTION 'a reversed run was changed';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a reversed payout run cannot change%' THEN
            RAISE;
        END IF;
END $$;

-- Section 11 cases the lock above does not cover. Dates sit outside the March 2032 window.

UPDATE mall SET tax_bps = 0, commission_bps = 0, cash_round_nickel = false;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES
    ('01900000-0000-7000-8000-00000000f023', '01900000-0000-7000-8000-000000000001', '2034-01-01', '2034-12-31', 'open'),
    ('01900000-0000-7000-8000-00000000f024', '01900000-0000-7000-8000-000000000001', '2030-01-01', '2030-12-31', 'open');

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-00000000f932', 'Payout proof');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f2c2',
    '01900000-0000-7000-8000-00000000f932',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO vendors (id, code, first_name, last_name, payout_mode, commission_bps)
VALUES
    ('01900000-0000-7000-8000-00000000f210', 'credit', 'Cora', 'Credit', 'credit_only', 0),
    ('01900000-0000-7000-8000-00000000f211', 'short', 'Sid', 'Short', 'pay', 0),
    ('01900000-0000-7000-8000-00000000f212', 'clear', 'Clem', 'Clear', 'pay', 0),
    ('01900000-0000-7000-8000-00000000f213', 'forward', 'Fern', 'Forward', 'pay', 0),
    ('01900000-0000-7000-8000-00000000f214', 'sweep', 'Sue', 'Sweep', 'pay', 0),
    ('01900000-0000-7000-8000-00000000f215', 'ident', 'Ida', 'Ident', 'pay', 0),
    ('01900000-0000-7000-8000-00000000f216', 'drift', 'Dee', 'Drift', 'pay', 0),
    ('01900000-0000-7000-8000-00000000f217', 'pair', 'Pat', 'Pair', 'pay', 0),
    ('01900000-0000-7000-8000-00000000f218', 'drawer', 'Drew', 'Drawer', 'pay', 0),
    ('01900000-0000-7000-8000-00000000f219', 'safe', 'Sam', 'Safe', 'pay', 0),
    ('01900000-0000-7000-8000-00000000f21a', 'prior', 'Pri', 'Prior', 'pay', 0),
    ('01900000-0000-7000-8000-00000000f21c', 'rentid', 'Ren', 'Rentid', 'pay', 0);

INSERT INTO booths (id, code, name)
VALUES
    ('01900000-0000-7000-8000-00000000f310', 'C1', 'Credit booth'),
    ('01900000-0000-7000-8000-00000000f311', 'S1', 'Short booth'),
    ('01900000-0000-7000-8000-00000000f312', 'K1', 'Clear booth'),
    ('01900000-0000-7000-8000-00000000f313', 'F1', 'Forward booth'),
    ('01900000-0000-7000-8000-00000000f314', 'W1', 'Sweep booth'),
    ('01900000-0000-7000-8000-00000000f315', 'I1', 'Ident booth'),
    ('01900000-0000-7000-8000-00000000f317', 'P1', 'Pair booth'),
    ('01900000-0000-7000-8000-00000000f318', 'D1', 'Drawer booth'),
    ('01900000-0000-7000-8000-00000000f31a', 'R1', 'Prior booth'),
    ('01900000-0000-7000-8000-00000000f31c', 'N1', 'Rent identity booth');

INSERT INTO booth_assignments (booth_id, vendor_id, starts_on, ends_on)
VALUES
    ('01900000-0000-7000-8000-00000000f310', '01900000-0000-7000-8000-00000000f210', '2034-06-01', '2034-06-30'),
    ('01900000-0000-7000-8000-00000000f311', '01900000-0000-7000-8000-00000000f211', '2034-06-01', '2034-06-30'),
    ('01900000-0000-7000-8000-00000000f312', '01900000-0000-7000-8000-00000000f212', '2034-06-01', '2034-06-30'),
    ('01900000-0000-7000-8000-00000000f313', '01900000-0000-7000-8000-00000000f213', '2034-06-01', '2034-06-30'),
    ('01900000-0000-7000-8000-00000000f314', '01900000-0000-7000-8000-00000000f214', '2034-05-01', '2034-06-30'),
    ('01900000-0000-7000-8000-00000000f315', '01900000-0000-7000-8000-00000000f215', '2034-06-01', '2034-06-30'),
    ('01900000-0000-7000-8000-00000000f317', '01900000-0000-7000-8000-00000000f217', '2034-06-01', '2034-06-30'),
    ('01900000-0000-7000-8000-00000000f318', '01900000-0000-7000-8000-00000000f218', '2034-09-01', '2034-09-30'),
    ('01900000-0000-7000-8000-00000000f31a', '01900000-0000-7000-8000-00000000f21a', '2034-02-01', '2034-02-28'),
    ('01900000-0000-7000-8000-00000000f31c', '01900000-0000-7000-8000-00000000f21c', '2034-05-01', '2034-07-31');

INSERT INTO booth_rent_rates (booth_id, amount_minor)
VALUES
    ('01900000-0000-7000-8000-00000000f310', 800),
    ('01900000-0000-7000-8000-00000000f311', 1000),
    ('01900000-0000-7000-8000-00000000f312', 1000),
    ('01900000-0000-7000-8000-00000000f313', 1000),
    ('01900000-0000-7000-8000-00000000f314', 1000),
    ('01900000-0000-7000-8000-00000000f315', 300),
    ('01900000-0000-7000-8000-00000000f317', 1000),
    ('01900000-0000-7000-8000-00000000f318', 800),
    ('01900000-0000-7000-8000-00000000f31a', 1000),
    ('01900000-0000-7000-8000-00000000f31c', 1000);

INSERT INTO assignment_rent (assignment_id, amount_minor)
SELECT assignment.id, rate.amount_minor
FROM booth_assignments AS assignment
JOIN booth_rent_rates AS rate ON rate.booth_id = assignment.booth_id
ON CONFLICT (assignment_id) DO NOTHING;

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES
    ('01900000-0000-7000-8000-00000000f410', 'Disc', '01900000-0000-7000-8000-00000000f215', 'Discounted', 1000, 'BC-PAY-DISC', DATE '2034-06-01', 0),
    ('01900000-0000-7000-8000-00000000f411', 'Void', '01900000-0000-7000-8000-00000000f215', 'Voided', 500, 'BC-PAY-VOID', DATE '2034-06-01', 0),
    ('01900000-0000-7000-8000-00000000f412', 'Back', '01900000-0000-7000-8000-00000000f215', 'Returned', 400, 'BC-PAY-BACK', DATE '2034-06-01', 0),
    ('01900000-0000-7000-8000-00000000f413', 'Drift', '01900000-0000-7000-8000-00000000f216', 'Drift piece', 1000, 'BC-PAY-DRIFT', DATE '2034-08-01', 0),
    ('01900000-0000-7000-8000-00000000f414', 'Swap', '01900000-0000-7000-8000-00000000f216', 'Replacement', 1000, 'BC-PAY-SWAP', DATE '2034-08-01', 0),
    ('01900000-0000-7000-8000-00000000f415', 'May', '01900000-0000-7000-8000-00000000f213', 'May piece', 4000, 'BC-PAY-MAY', DATE '2034-05-01', 0),
    ('01900000-0000-7000-8000-00000000f416', 'Jan', '01900000-0000-7000-8000-00000000f21a', 'January piece', 5000, 'BC-PAY-JAN', DATE '2034-01-01', 0);

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-00000000f710', '01900000-0000-7000-8000-00000000f2c2');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
VALUES ('01900000-0000-7000-8000-00000000f710', 1, '01900000-0000-7000-8000-00000000f410', 1000);

UPDATE ticket_lines
SET line_discount_kind = 'amount', line_discount_value = 200, line_discount_reason = 'damaged'
WHERE ticket_id = '01900000-0000-7000-8000-00000000f710';

SET CONSTRAINTS ALL DEFERRED;

-- Empty eligible set: every pay vendor has nothing to settle, so close posts no journal.

INSERT INTO payout_runs (
    id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date
) VALUES (
    '01900000-0000-7000-8000-00000000f910',
    '01900000-0000-7000-8000-000000000001',
    'batch', 'monthly', '2030-01-01', '2030-01-31', '2030-01-01', 'check', 'draft', '2030-01-31'
);

DO $$
DECLARE
    eligible integer;
    payable bigint;
BEGIN
    SELECT count(*) INTO eligible
    FROM payout_pack
    WHERE run_id = '01900000-0000-7000-8000-00000000f910'
      AND mode = 'pay'
      AND settled_minor > 0;
    IF eligible <> 0 THEN
        RAISE EXCEPTION 'an empty eligible set still had % vendors to settle', eligible;
    END IF;
    SELECT COALESCE(sum(credit_minor - debit_minor), 0) INTO payable
    FROM journal_lines
    WHERE subledger_ref = '01900000-0000-7000-8000-00000000f201'
      AND account_id = '01900000-0000-7000-8000-000000000014';
    IF payable <> 0 THEN
        RAISE EXCEPTION 'the lock vendor payable was not clear before the empty close';
    END IF;
END $$;

UPDATE payout_runs
SET status = 'settled', settled_at = clock_timestamp()
WHERE id = '01900000-0000-7000-8000-00000000f910';

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM journals
        WHERE posting_key LIKE 'settlement:01900000-0000-7000-8000-00000000f910:%'
           OR posting_key LIKE 'disbursement:01900000-0000-7000-8000-00000000f910:%'
    ) THEN
        RAISE EXCEPTION 'an empty eligible set posted a journal';
    END IF;
    IF (
        SELECT COALESCE(sum(settled_minor), 0)
        FROM payout_pack
        WHERE run_id = '01900000-0000-7000-8000-00000000f910'
          AND mode = 'pay'
    ) <> 0 OR (
        SELECT status FROM payout_runs WHERE id = '01900000-0000-7000-8000-00000000f910'
    ) <> 'settled' THEN
        RAISE EXCEPTION 'an empty close did not settle with zero lines';
    END IF;
END $$;

-- credit_only keeps the payable and gets no settlement or disbursement.

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa01',
    '01900000-0000-7000-8000-000000000001',
    'store-purchase:credit',
    '2034-06-02', '2034-06-02 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f210', 'Credit purchase', 800, 800
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fa01',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000022', 800, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000fa01',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 800, 'vendor', '01900000-0000-7000-8000-00000000f210'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa02',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-00000000f310:2034-06',
    '2034-06-01', '2034-06-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f310', 'Credit rent', 800, 800
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000fa02',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000015', 800, 0, 'booth', '01900000-0000-7000-8000-00000000f310'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fa02',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000017', 0, 800
);

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000fa03',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-00000000f310:2034-06',
        '2034-06-01', '2034-06-01 13:00:00+00',
        '01900000-0000-7000-8000-00000000f023',
        'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f310', 'Credit rent again', 800, 800
    );
    RAISE EXCEPTION 'a second rent charge for the same booth month was accepted';
EXCEPTION
    WHEN SQLSTATE '23505' THEN
        IF SQLERRM NOT LIKE '%journals_book_key%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO payout_runs (
    id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date
) VALUES (
    '01900000-0000-7000-8000-00000000f911',
    '01900000-0000-7000-8000-000000000001',
    'batch', 'monthly', '2034-06-01', '2034-06-30', '2034-06-01', 'check', 'draft', '2034-06-30'
);

DO $$
DECLARE
    mode text;
    payable bigint;
    settled bigint;
BEGIN
    SELECT pack.mode, pack.carried_payable_minor, pack.settled_minor
    INTO mode, payable, settled
    FROM payout_pack AS pack
    WHERE pack.run_id = '01900000-0000-7000-8000-00000000f911'
      AND pack.vendor_id = '01900000-0000-7000-8000-00000000f210';
    IF mode <> 'credit_only' OR payable <> 800 OR settled <> 800 THEN
        RAISE EXCEPTION 'a credit_only vendor was not owed on the pack % % %', mode, payable, settled;
    END IF;
    IF EXISTS (
        SELECT 1 FROM journals
        WHERE source_reference = '01900000-0000-7000-8000-00000000f210'
          AND source_type IN ('settlement', 'check_disbursement', 'cash_disbursement', 'safe_disbursement')
    ) THEN
        RAISE EXCEPTION 'a credit_only vendor got a payout journal';
    END IF;
END $$;

-- Shortfall: payable below rent settles rent only and leaves the difference open.

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa10',
    '01900000-0000-7000-8000-000000000001',
    'store-purchase:short',
    '2034-06-03', '2034-06-03 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f211', 'Short purchase', 500, 500
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fa10',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000022', 500, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000fa10',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 500, 'vendor', '01900000-0000-7000-8000-00000000f211'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa11',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-00000000f311:2034-06',
    '2034-06-01', '2034-06-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f311', 'Short rent', 1000, 1000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000fa11',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000015', 1000, 0, 'booth', '01900000-0000-7000-8000-00000000f311'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fa11',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000017', 0, 1000
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa12',
    '01900000-0000-7000-8000-000000000001',
    'settlement:01900000-0000-7000-8000-00000000f912:01900000-0000-7000-8000-00000000f211',
    '2034-06-30', '2034-06-30 18:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'settlement', '01900000-0000-7000-8000-00000000f211', 'Short settlement', 500, 500
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-00000000fa12', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 500, 0, 'vendor', '01900000-0000-7000-8000-00000000f211'),
    ('01900000-0000-7000-8000-00000000fa12', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000015', 0, 500, 'booth', '01900000-0000-7000-8000-00000000f311');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    payable bigint;
    rent bigint;
BEGIN
    IF EXISTS (
        SELECT 1 FROM journal_lines AS line
        JOIN journals AS journal ON journal.id = line.journal_id
        JOIN chart_roles AS role ON role.account_id = line.account_id
        WHERE journal.id = '01900000-0000-7000-8000-00000000fa12'
          AND role.role IN ('cash', 'bank', 'cash_in_safe')
    ) THEN
        RAISE EXCEPTION 'a shortfall settlement moved cash';
    END IF;
    IF EXISTS (
        SELECT 1 FROM journals
        WHERE source_reference = '01900000-0000-7000-8000-00000000f211'
          AND source_type IN ('check_disbursement', 'cash_disbursement', 'safe_disbursement')
    ) THEN
        RAISE EXCEPTION 'a shortfall posted a disbursement';
    END IF;
    SELECT COALESCE(sum(credit_minor - debit_minor), 0) INTO payable
    FROM journal_lines
    WHERE account_id = '01900000-0000-7000-8000-000000000014'
      AND subledger_ref = '01900000-0000-7000-8000-00000000f211';
    SELECT COALESCE(sum(debit_minor - credit_minor), 0) INTO rent
    FROM journal_lines
    WHERE account_id = '01900000-0000-7000-8000-000000000015'
      AND subledger_ref = '01900000-0000-7000-8000-00000000f311';
    IF payable <> 0 OR rent <> 500 THEN
        RAISE EXCEPTION 'a shortfall did not leave rent of 500 (payable % rent %)', payable, rent;
    END IF;
END $$;

-- Settlement then disbursement. Payable equals the check, then the check clears it.

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa20',
    '01900000-0000-7000-8000-000000000001',
    'store-purchase:clear',
    '2034-06-04', '2034-06-04 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f212', 'Clear purchase', 3000, 3000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fa20',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000022', 3000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000fa20',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 3000, 'vendor', '01900000-0000-7000-8000-00000000f212'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa21',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-00000000f312:2034-06',
    '2034-06-01', '2034-06-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f312', 'Clear rent', 1000, 1000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000fa21',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000015', 1000, 0, 'booth', '01900000-0000-7000-8000-00000000f312'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fa21',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000017', 0, 1000
);

UPDATE payout_runs
SET status = 'settled', settled_at = clock_timestamp()
WHERE status = 'draft'
  AND kind = 'batch'
  AND sales_from = DATE '2034-06-01'
  AND sales_to = DATE '2034-06-30'
  AND rent_month = DATE '2034-06-01';

INSERT INTO payout_runs (
    id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date
) VALUES (
    '01900000-0000-7000-8000-00000000f913',
    '01900000-0000-7000-8000-000000000001',
    'batch', 'monthly', '2034-06-01', '2034-06-30', '2034-06-01', 'check', 'draft', '2034-06-30'
);

DO $$
DECLARE
    check_amount bigint;
    settled bigint;
BEGIN
    SELECT check_minor, settled_minor INTO check_amount, settled
    FROM payout_pack
    WHERE run_id = '01900000-0000-7000-8000-00000000f913'
      AND vendor_id = '01900000-0000-7000-8000-00000000f212';
    IF check_amount <> 2000 OR settled <> 1000 THEN
        RAISE EXCEPTION 'the clear pack was not a 2000 check on 1000 rent (% %)', check_amount, settled;
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa22',
    '01900000-0000-7000-8000-000000000001',
    'settlement:01900000-0000-7000-8000-00000000f913:01900000-0000-7000-8000-00000000f212',
    '2034-06-30', '2034-06-30 18:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'settlement', '01900000-0000-7000-8000-00000000f212', 'Clear settlement', 1000, 1000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-00000000fa22', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 1000, 0, 'vendor', '01900000-0000-7000-8000-00000000f212'),
    ('01900000-0000-7000-8000-00000000fa22', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000015', 0, 1000, 'booth', '01900000-0000-7000-8000-00000000f312');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    payable bigint;
BEGIN
    SELECT COALESCE(sum(credit_minor - debit_minor), 0) INTO payable
    FROM journal_lines
    WHERE account_id = '01900000-0000-7000-8000-000000000014'
      AND subledger_ref = '01900000-0000-7000-8000-00000000f212';
    IF payable <> 2000 THEN
        RAISE EXCEPTION 'after settlement the payable was not the check (% )', payable;
    END IF;
    IF EXISTS (
        SELECT 1 FROM journal_lines AS line
        JOIN chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = '01900000-0000-7000-8000-00000000fa22'
          AND role.role IN ('cash', 'bank', 'cash_in_safe')
    ) THEN
        RAISE EXCEPTION 'settlement moved cash before the check';
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa23',
    '01900000-0000-7000-8000-000000000001',
    'disbursement:01900000-0000-7000-8000-00000000f913:01900000-0000-7000-8000-00000000f212',
    '2034-07-01', '2034-07-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'check_disbursement', '01900000-0000-7000-8000-00000000f212', 'Clear check', 2000, 2000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000fa23',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 2000, 0, 'vendor', '01900000-0000-7000-8000-00000000f212'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fa23',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000011', 0, 2000
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    payable bigint;
    bank bigint;
BEGIN
    SELECT COALESCE(sum(credit_minor - debit_minor), 0) INTO payable
    FROM journal_lines
    WHERE account_id = '01900000-0000-7000-8000-000000000014'
      AND subledger_ref = '01900000-0000-7000-8000-00000000f212';
    SELECT COALESCE(sum(credit_minor), 0) INTO bank
    FROM journal_lines
    WHERE journal_id = '01900000-0000-7000-8000-00000000fa23'
      AND account_id = '01900000-0000-7000-8000-000000000011';
    IF payable <> 0 OR bank <> 2000 THEN
        RAISE EXCEPTION 'the check did not clear the payable (payable % bank %)', payable, bank;
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000fa24',
        '01900000-0000-7000-8000-000000000001',
        'settlement:01900000-0000-7000-8000-00000000f913:01900000-0000-7000-8000-00000000f212',
        '2034-07-02', '2034-07-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000f023',
        'USD', 'settlement', '01900000-0000-7000-8000-00000000f212', 'Second settlement', 1000, 1000
    );
    RAISE EXCEPTION 'the same run and vendor settled twice';
EXCEPTION
    WHEN SQLSTATE '23505' THEN
        IF SQLERRM NOT LIKE '%journals_book_key%' THEN
            RAISE;
        END IF;
END $$;

-- Brought forward: May is left open, June's run shows it, and July's check clears it.

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa30',
    '01900000-0000-7000-8000-000000000001',
    'sale:forward-may',
    '2034-05-15', '2034-05-15 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'sale', 'forward-may', 'May sale', 4000, 4000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fa30',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 4000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000fa30',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 4000, 'vendor', '01900000-0000-7000-8000-00000000f213'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor, discount_minor)
VALUES ('01900000-0000-7000-8000-00000000fa30', 1, '01900000-0000-7000-8000-00000000f415', 4000, 0, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-00000000f2c2', '01900000-0000-7000-8000-00000000fa30');

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa31',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-00000000f313:2034-06',
    '2034-06-01', '2034-06-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f313', 'Forward rent', 1000, 1000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000fa31',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000015', 1000, 0, 'booth', '01900000-0000-7000-8000-00000000f313'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fa31',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000017', 0, 1000
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa32',
    '01900000-0000-7000-8000-000000000001',
    'settlement:01900000-0000-7000-8000-00000000f914:01900000-0000-7000-8000-00000000f213',
    '2034-06-01', '2034-06-01 18:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'settlement', '01900000-0000-7000-8000-00000000f213', 'Forward settlement', 1000, 1000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-00000000fa32', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 1000, 0, 'vendor', '01900000-0000-7000-8000-00000000f213'),
    ('01900000-0000-7000-8000-00000000fa32', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000015', 0, 1000, 'booth', '01900000-0000-7000-8000-00000000f313');

INSERT INTO payout_runs (
    id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date
) VALUES (
    '01900000-0000-7000-8000-00000000f915',
    '01900000-0000-7000-8000-000000000001',
    'batch', 'monthly', '2034-06-01', '2034-06-30', '2034-07-01', 'check', 'draft', '2034-07-01'
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    brought bigint;
    carried bigint;
    settlement bigint;
BEGIN
    SELECT brought_forward_minor, carried_payable_minor, settlement_applied_minor
    INTO brought, carried, settlement
    FROM payout_pack
    WHERE run_id = '01900000-0000-7000-8000-00000000f915'
      AND vendor_id = '01900000-0000-7000-8000-00000000f213';
    IF brought <> 4000 OR settlement <> 1000 OR carried <> 3000 THEN
        RAISE EXCEPTION 'brought forward was not left open (% % %)', brought, settlement, carried;
    END IF;
    IF carried <> brought - settlement THEN
        RAISE EXCEPTION 'the brought-forward identity failed';
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa33',
    '01900000-0000-7000-8000-000000000001',
    'disbursement:01900000-0000-7000-8000-00000000f915:01900000-0000-7000-8000-00000000f213',
    '2034-07-02', '2034-07-02 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'check_disbursement', '01900000-0000-7000-8000-00000000f213', 'Forward check', 3000, 3000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000fa33',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 3000, 0, 'vendor', '01900000-0000-7000-8000-00000000f213'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fa33',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000011', 0, 3000
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    payable bigint;
BEGIN
    SELECT COALESCE(sum(credit_minor - debit_minor), 0) INTO payable
    FROM journal_lines
    WHERE account_id = '01900000-0000-7000-8000-000000000014'
      AND subledger_ref = '01900000-0000-7000-8000-00000000f213';
    IF payable <> 0 THEN
        RAISE EXCEPTION 'the brought-forward payable did not clear (% )', payable;
    END IF;
END $$;

-- Older rent and the new charge are both in carried rent, then both clear.

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES
    ('01900000-0000-7000-8000-00000000fa40', '01900000-0000-7000-8000-000000000001', 'store-purchase:sweep-may', '2034-05-10', '2034-05-10 12:00:00+00', '01900000-0000-7000-8000-00000000f023', 'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f214', 'Sweep May', 400, 400),
    ('01900000-0000-7000-8000-00000000fa41', '01900000-0000-7000-8000-000000000001', 'rent-charge:01900000-0000-7000-8000-00000000f314:2034-05', '2034-05-01', '2034-05-01 12:00:00+00', '01900000-0000-7000-8000-00000000f023', 'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f314', 'Sweep May rent', 1000, 1000),
    ('01900000-0000-7000-8000-00000000fa42', '01900000-0000-7000-8000-000000000001', 'settlement:01900000-0000-7000-8000-00000000f90a:01900000-0000-7000-8000-00000000f214', '2034-05-31', '2034-05-31 18:00:00+00', '01900000-0000-7000-8000-00000000f023', 'USD', 'settlement', '01900000-0000-7000-8000-00000000f214', 'Sweep May settlement', 400, 400);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-00000000fa40', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000022', 400, 0),
    ('01900000-0000-7000-8000-00000000fa41', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000017', 0, 1000);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-00000000fa40', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 400, 'vendor', '01900000-0000-7000-8000-00000000f214'),
    ('01900000-0000-7000-8000-00000000fa41', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000015', 1000, 0, 'booth', '01900000-0000-7000-8000-00000000f314'),
    ('01900000-0000-7000-8000-00000000fa42', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 400, 0, 'vendor', '01900000-0000-7000-8000-00000000f214'),
    ('01900000-0000-7000-8000-00000000fa42', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000015', 0, 400, 'booth', '01900000-0000-7000-8000-00000000f314');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES
    ('01900000-0000-7000-8000-00000000fa43', '01900000-0000-7000-8000-000000000001', 'rent-charge:01900000-0000-7000-8000-00000000f314:2034-06', '2034-06-01', '2034-06-01 12:00:00+00', '01900000-0000-7000-8000-00000000f023', 'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f314', 'Sweep June rent', 1000, 1000),
    ('01900000-0000-7000-8000-00000000fa44', '01900000-0000-7000-8000-000000000001', 'store-purchase:sweep-june', '2034-06-05', '2034-06-05 12:00:00+00', '01900000-0000-7000-8000-00000000f023', 'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f214', 'Sweep June', 3000, 3000);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-00000000fa43', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000017', 0, 1000),
    ('01900000-0000-7000-8000-00000000fa44', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000022', 3000, 0);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-00000000fa43', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000015', 1000, 0, 'booth', '01900000-0000-7000-8000-00000000f314'),
    ('01900000-0000-7000-8000-00000000fa44', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 3000, 'vendor', '01900000-0000-7000-8000-00000000f214');

UPDATE payout_runs
SET status = 'settled', settled_at = clock_timestamp()
WHERE status = 'draft'
  AND kind = 'batch'
  AND sales_from = DATE '2034-06-01'
  AND sales_to = DATE '2034-06-30'
  AND rent_month = DATE '2034-06-01';

INSERT INTO payout_runs (
    id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date
) VALUES (
    '01900000-0000-7000-8000-00000000f916',
    '01900000-0000-7000-8000-000000000001',
    'batch', 'monthly', '2034-06-01', '2034-06-30', '2034-06-01', 'check', 'draft', '2034-06-30'
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    carried_rent bigint;
    brought_rent bigint;
    month_rent bigint;
BEGIN
    SELECT carried_rent_minor, rent_brought_forward_minor, rent_minor
    INTO carried_rent, brought_rent, month_rent
    FROM payout_pack
    WHERE run_id = '01900000-0000-7000-8000-00000000f916'
      AND vendor_id = '01900000-0000-7000-8000-00000000f214';
    IF brought_rent <> 600 OR month_rent <> 1000 OR carried_rent <> 1600 THEN
        RAISE EXCEPTION 'open rent was not swept (% % %)', brought_rent, month_rent, carried_rent;
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa45',
    '01900000-0000-7000-8000-000000000001',
    'settlement:01900000-0000-7000-8000-00000000f916:01900000-0000-7000-8000-00000000f214',
    '2034-06-30', '2034-06-30 18:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'settlement', '01900000-0000-7000-8000-00000000f214', 'Sweep June settlement', 1600, 1600
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-00000000fa45', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 1600, 0, 'vendor', '01900000-0000-7000-8000-00000000f214'),
    ('01900000-0000-7000-8000-00000000fa45', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000015', 0, 1600, 'booth', '01900000-0000-7000-8000-00000000f314');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    rent bigint;
BEGIN
    SELECT COALESCE(sum(debit_minor - credit_minor), 0) INTO rent
    FROM journal_lines
    WHERE account_id = '01900000-0000-7000-8000-000000000015'
      AND subledger_ref = '01900000-0000-7000-8000-00000000f314';
    IF rent <> 0 THEN
        RAISE EXCEPTION 'a shortfall was left stranded (% )', rent;
    END IF;
END $$;

-- Discount, return, void, and a check already written stay in the identity.

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES
    ('01900000-0000-7000-8000-00000000fa50', '01900000-0000-7000-8000-000000000001', 'sale:ident-discount', '2034-06-04', '2034-06-04 12:00:00+00', '01900000-0000-7000-8000-00000000f023', 'USD', 'sale', 'ident-discount', 'Discounted sale', 800, 800),
    ('01900000-0000-7000-8000-00000000fa51', '01900000-0000-7000-8000-000000000001', 'sale:ident-voided', '2034-06-05', '2034-06-05 12:00:00+00', '01900000-0000-7000-8000-00000000f023', 'USD', 'sale', 'ident-voided', 'Sale later voided', 500, 500),
    ('01900000-0000-7000-8000-00000000fa53', '01900000-0000-7000-8000-000000000001', 'sale:ident-return', '2034-06-07', '2034-06-07 12:00:00+00', '01900000-0000-7000-8000-00000000f023', 'USD', 'sale', 'ident-return', 'Sale later returned', 400, 400),
    ('01900000-0000-7000-8000-00000000fa54', '01900000-0000-7000-8000-000000000001', 'return:ident', '2034-06-08', '2034-06-08 12:00:00+00', '01900000-0000-7000-8000-00000000f023', 'USD', 'piece_return', 'ident-return', 'Return', 400, 400),
    ('01900000-0000-7000-8000-00000000fa55', '01900000-0000-7000-8000-000000000001', 'disbursement:prior-check:01900000-0000-7000-8000-00000000f215', '2034-06-09', '2034-06-09 12:00:00+00', '01900000-0000-7000-8000-00000000f023', 'USD', 'check_disbursement', '01900000-0000-7000-8000-00000000f215', 'Prior check', 100, 100),
    ('01900000-0000-7000-8000-00000000fa56', '01900000-0000-7000-8000-000000000001', 'rent-charge:01900000-0000-7000-8000-00000000f315:2034-06', '2034-06-01', '2034-06-01 12:00:00+00', '01900000-0000-7000-8000-00000000f023', 'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f315', 'Ident rent', 300, 300);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
    debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa52',
    '01900000-0000-7000-8000-000000000001',
    'sale:ident-void',
    '2034-06-06', '2034-06-06 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'sale', 'ident-void', 'Void', true,
    '01900000-0000-7000-8000-00000000fa51',
    500, 500
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-00000000fa50', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 800, 0),
    ('01900000-0000-7000-8000-00000000fa51', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 500, 0),
    ('01900000-0000-7000-8000-00000000fa52', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000010', 0, 500),
    ('01900000-0000-7000-8000-00000000fa53', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 400, 0),
    ('01900000-0000-7000-8000-00000000fa54', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000010', 0, 400),
    ('01900000-0000-7000-8000-00000000fa55', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 100),
    ('01900000-0000-7000-8000-00000000fa56', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000017', 0, 300);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-00000000fa50', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 800, 'vendor', '01900000-0000-7000-8000-00000000f215'),
    ('01900000-0000-7000-8000-00000000fa51', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 500, 'vendor', '01900000-0000-7000-8000-00000000f215'),
    ('01900000-0000-7000-8000-00000000fa52', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 500, 0, 'vendor', '01900000-0000-7000-8000-00000000f215'),
    ('01900000-0000-7000-8000-00000000fa53', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 400, 'vendor', '01900000-0000-7000-8000-00000000f215'),
    ('01900000-0000-7000-8000-00000000fa54', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 400, 0, 'vendor', '01900000-0000-7000-8000-00000000f215'),
    ('01900000-0000-7000-8000-00000000fa55', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 100, 0, 'vendor', '01900000-0000-7000-8000-00000000f215'),
    ('01900000-0000-7000-8000-00000000fa56', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000015', 300, 0, 'booth', '01900000-0000-7000-8000-00000000f315');

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor, discount_minor)
VALUES
    ('01900000-0000-7000-8000-00000000fa50', 1, '01900000-0000-7000-8000-00000000f410', 1000, 0, 0, 200),
    ('01900000-0000-7000-8000-00000000fa51', 1, '01900000-0000-7000-8000-00000000f411', 500, 0, 0, 0),
    ('01900000-0000-7000-8000-00000000fa53', 1, '01900000-0000-7000-8000-00000000f412', 400, 0, 0, 0);

INSERT INTO piece_returns (journal_id, sale_journal_id, item_id, price_minor, commission_minor, tax_minor, discount_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fa54',
    '01900000-0000-7000-8000-00000000fa53',
    '01900000-0000-7000-8000-00000000f412',
    400, 0, 0, 0
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES
    ('01900000-0000-7000-8000-00000000f2c2', '01900000-0000-7000-8000-00000000fa50'),
    ('01900000-0000-7000-8000-00000000f2c2', '01900000-0000-7000-8000-00000000fa51'),
    ('01900000-0000-7000-8000-00000000f2c2', '01900000-0000-7000-8000-00000000fa52'),
    ('01900000-0000-7000-8000-00000000f2c2', '01900000-0000-7000-8000-00000000fa53'),
    ('01900000-0000-7000-8000-00000000f2c2', '01900000-0000-7000-8000-00000000fa54');

UPDATE tickets
SET status = 'posted', posted_journal_id = '01900000-0000-7000-8000-00000000fa50'
WHERE id = '01900000-0000-7000-8000-00000000f710';

UPDATE payout_runs
SET status = 'settled', settled_at = clock_timestamp()
WHERE status = 'draft'
  AND kind = 'batch'
  AND sales_from = DATE '2034-06-01'
  AND sales_to = DATE '2034-06-30'
  AND rent_month = DATE '2034-06-01';

INSERT INTO payout_runs (
    id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date
) VALUES (
    '01900000-0000-7000-8000-00000000f917',
    '01900000-0000-7000-8000-000000000001',
    'batch', 'monthly', '2034-06-01', '2034-06-30', '2034-06-01', 'check', 'draft', '2034-06-30'
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    row record;
BEGIN
    SELECT * INTO row
    FROM payout_pack
    WHERE run_id = '01900000-0000-7000-8000-00000000f917'
      AND vendor_id = '01900000-0000-7000-8000-00000000f215';
    IF row.discount_minor <> 200 OR row.return_minor <> 400 OR row.void_minor <> 500 OR row.check_paid_minor <> 100 THEN
        RAISE EXCEPTION 'the identity columns dropped a movement % % % %',
            row.discount_minor, row.return_minor, row.void_minor, row.check_paid_minor;
    END IF;
    IF row.carried_payable_minor <> row.brought_forward_minor
        + row.sales_minor - row.discount_minor - row.commission_minor
        - row.return_minor - row.void_minor - row.draw_minor
        - row.check_paid_minor - row.settlement_applied_minor
        + row.adjustment_minor
        OR row.adjustment_minor <> 0
        OR row.carried_payable_minor <> 700 THEN
        RAISE EXCEPTION 'the payable identity failed %', row.carried_payable_minor;
    END IF;
    IF row.carried_rent_minor <> row.rent_brought_forward_minor + row.rent_charged_minor + row.rent_minor
        - row.rent_paid_since_minor - row.settlement_applied_minor THEN
        RAISE EXCEPTION 'the rent identity failed';
    END IF;
END $$;

-- Rent brought forward plus the charge inside the window, with no new month and no settlement.

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES
    ('01900000-0000-7000-8000-00000000fa60', '01900000-0000-7000-8000-000000000001', 'rent-charge:01900000-0000-7000-8000-00000000f31c:2034-05', '2034-05-01', '2034-05-01 12:00:00+00', '01900000-0000-7000-8000-00000000f023', 'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f31c', 'May rent', 1000, 1000),
    ('01900000-0000-7000-8000-00000000fa61', '01900000-0000-7000-8000-000000000001', 'rent-charge:01900000-0000-7000-8000-00000000f31c:2034-06', '2034-06-01', '2034-06-01 12:00:00+00', '01900000-0000-7000-8000-00000000f023', 'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f31c', 'June rent', 1000, 1000),
    ('01900000-0000-7000-8000-00000000fa62', '01900000-0000-7000-8000-000000000001', 'rent-charge:01900000-0000-7000-8000-00000000f31c:2034-07', '2034-07-10', '2034-07-10 12:00:00+00', '01900000-0000-7000-8000-00000000f023', 'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f31c', 'July rent', 1000, 1000);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-00000000fa60', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000015', 1000, 0, 'booth', '01900000-0000-7000-8000-00000000f31c'),
    ('01900000-0000-7000-8000-00000000fa61', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000015', 1000, 0, 'booth', '01900000-0000-7000-8000-00000000f31c'),
    ('01900000-0000-7000-8000-00000000fa62', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000015', 1000, 0, 'booth', '01900000-0000-7000-8000-00000000f31c');

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-00000000fa60', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000017', 0, 1000),
    ('01900000-0000-7000-8000-00000000fa61', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000017', 0, 1000),
    ('01900000-0000-7000-8000-00000000fa62', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000017', 0, 1000);

INSERT INTO payout_runs (
    id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date
) VALUES (
    '01900000-0000-7000-8000-00000000f91c',
    '01900000-0000-7000-8000-000000000001',
    'batch', 'monthly', '2034-07-01', '2034-07-31', '2034-08-01', 'check', 'draft', '2034-07-31'
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    brought bigint;
    charged bigint;
    paid bigint;
    carried bigint;
    month_rent bigint;
    applied bigint;
BEGIN
    SELECT rent_brought_forward_minor, rent_charged_minor, rent_paid_since_minor, carried_rent_minor,
           rent_minor, settlement_applied_minor
    INTO brought, charged, paid, carried, month_rent, applied
    FROM payout_pack
    WHERE run_id = '01900000-0000-7000-8000-00000000f91c'
      AND vendor_id = '01900000-0000-7000-8000-00000000f21c';
    IF month_rent <> 0 OR applied <> 0 OR brought + charged - paid <> carried THEN
        RAISE EXCEPTION 'the rent breakdown did not reconcile % % % %', brought, charged, paid, carried;
    END IF;
END $$;

-- A prior settlement dated inside the next window is on both sides.
-- Reversing it, then re-charging the month, leaves the four breakdown columns equal to the ledger.

SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa80',
    '01900000-0000-7000-8000-000000000001',
    'sale:prior-january',
    '2034-01-15', '2034-01-15 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'sale', 'prior-january', 'January sale', 5000, 5000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fa80',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 5000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000fa80',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 5000, 'vendor', '01900000-0000-7000-8000-00000000f21a'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor, discount_minor)
VALUES ('01900000-0000-7000-8000-00000000fa80', 1, '01900000-0000-7000-8000-00000000f416', 5000, 0, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-00000000f2c2', '01900000-0000-7000-8000-00000000fa80');

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa81',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-00000000f31a:2034-02',
    '2034-02-01', '2034-02-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f31a', 'February rent', 1000, 1000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000fa81',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000015', 1000, 0, 'booth', '01900000-0000-7000-8000-00000000f31a'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fa81',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000017', 0, 1000
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa82',
    '01900000-0000-7000-8000-000000000001',
    'settlement:01900000-0000-7000-8000-00000000f91d:01900000-0000-7000-8000-00000000f21a',
    '2034-02-01', '2034-02-01 18:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'settlement', '01900000-0000-7000-8000-00000000f21a', 'February settlement', 1000, 1000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-00000000fa82', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 1000, 0, 'vendor', '01900000-0000-7000-8000-00000000f21a'),
    ('01900000-0000-7000-8000-00000000fa82', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000015', 0, 1000, 'booth', '01900000-0000-7000-8000-00000000f31a');

INSERT INTO payout_runs (
    id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date
) VALUES (
    '01900000-0000-7000-8000-00000000f91e',
    '01900000-0000-7000-8000-000000000001',
    'batch', 'monthly', '2034-02-01', '2034-02-28', '2034-03-01', 'check', 'draft', '2034-03-01'
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    row record;
BEGIN
    SELECT * INTO row
    FROM payout_pack
    WHERE run_id = '01900000-0000-7000-8000-00000000f91e'
      AND vendor_id = '01900000-0000-7000-8000-00000000f21a';
    IF row.settlement_applied_minor <> 1000 OR row.rent_charged_minor <> 1000
        OR row.carried_payable_minor <> 4000 OR row.carried_rent_minor <> 0 THEN
        RAISE EXCEPTION 'a prior settlement inside the window did not reconcile % % % %',
            row.settlement_applied_minor, row.rent_charged_minor, row.carried_payable_minor, row.carried_rent_minor;
    END IF;
    IF row.carried_payable_minor <> row.brought_forward_minor + row.sales_minor - row.discount_minor
        - row.commission_minor - row.return_minor - row.void_minor - row.draw_minor
        - row.check_paid_minor - row.settlement_applied_minor + row.adjustment_minor
        OR row.carried_rent_minor <> row.rent_brought_forward_minor + row.rent_charged_minor + row.rent_minor
            - row.rent_paid_since_minor - row.settlement_applied_minor THEN
        RAISE EXCEPTION 'the prior-settlement identities failed';
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
    debit_total_minor, credit_total_minor
) VALUES
    (
        '01900000-0000-7000-8000-00000000fa83',
        '01900000-0000-7000-8000-000000000001',
        'settlement:prior-reverse',
        '2034-02-20', '2034-02-20 12:00:00+00',
        '01900000-0000-7000-8000-00000000f023',
        'USD', 'settlement', '01900000-0000-7000-8000-00000000f21a', 'Reverse settlement',
        true, '01900000-0000-7000-8000-00000000fa82', 1000, 1000
    ),
    (
        '01900000-0000-7000-8000-00000000fa84',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:prior-reverse',
        '2034-02-20', '2034-02-20 12:01:00+00',
        '01900000-0000-7000-8000-00000000f023',
        'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f31a', 'Reverse rent',
        true, '01900000-0000-7000-8000-00000000fa81', 1000, 1000
    );

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-00000000fa83', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000015', 1000, 0, 'booth', '01900000-0000-7000-8000-00000000f31a'),
    ('01900000-0000-7000-8000-00000000fa83', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 1000, 'vendor', '01900000-0000-7000-8000-00000000f21a'),
    ('01900000-0000-7000-8000-00000000fa84', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000015', 0, 1000, 'booth', '01900000-0000-7000-8000-00000000f31a');

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fa84',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000017', 1000, 0
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa85',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-00000000f31a:2034-02',
    '2034-02-01', '2034-02-21 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f31a', 'February rent again', 1000, 1000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000fa85',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000015', 1000, 0, 'booth', '01900000-0000-7000-8000-00000000f31a'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fa85',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000017', 0, 1000
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    row record;
    settlement_net bigint;
    rent_charge_net bigint;
    check_net bigint;
    rent_paid_net bigint;
BEGIN
    SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) INTO settlement_net
    FROM journal_lines AS line
    JOIN journals AS journal ON journal.id = line.journal_id
    JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'vendor_payable'
    WHERE line.subledger_ref = '01900000-0000-7000-8000-00000000f21a'
      AND journal.source_type = 'settlement'
      AND journal.posting_date BETWEEN '2034-02-01' AND '2034-02-28';
    SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) INTO rent_charge_net
    FROM journal_lines AS line
    JOIN journals AS journal ON journal.id = line.journal_id
    JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'booth_rent_receivable'
    WHERE line.subledger_ref = '01900000-0000-7000-8000-00000000f31a'
      AND journal.source_type = 'rent_charge'
      AND journal.posting_date BETWEEN '2034-02-01' AND '2034-02-28';
    SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) INTO check_net
    FROM journal_lines AS line
    JOIN journals AS journal ON journal.id = line.journal_id
    JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'vendor_payable'
    WHERE line.subledger_ref = '01900000-0000-7000-8000-00000000f21a'
      AND journal.source_type IN ('check_disbursement', 'cash_disbursement', 'safe_disbursement')
      AND journal.posting_date BETWEEN '2034-02-01' AND '2034-02-28';
    SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) INTO rent_paid_net
    FROM journal_lines AS line
    JOIN journals AS journal ON journal.id = line.journal_id
    JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'booth_rent_receivable'
    WHERE line.subledger_ref = '01900000-0000-7000-8000-00000000f31a'
      AND journal.source_type = 'rent_payment'
      AND journal.posting_date BETWEEN '2034-02-01' AND '2034-02-28';
    SELECT * INTO row
    FROM payout_pack
    WHERE run_id = '01900000-0000-7000-8000-00000000f91e'
      AND vendor_id = '01900000-0000-7000-8000-00000000f21a';
    IF row.settlement_applied_minor <> settlement_net
        OR row.rent_charged_minor <> rent_charge_net
        OR row.check_paid_minor <> check_net
        OR row.rent_paid_since_minor <> rent_paid_net
        OR settlement_net <> 0 OR rent_charge_net <> 1000
        OR row.carried_payable_minor <> 5000 OR row.carried_rent_minor <> 1000 THEN
        RAISE EXCEPTION 'a reversed prior run did not net (% % % %)',
            row.settlement_applied_minor, row.rent_charged_minor, row.carried_payable_minor, row.carried_rent_minor;
    END IF;
    IF row.carried_payable_minor <> row.brought_forward_minor + row.sales_minor - row.discount_minor
        - row.commission_minor - row.return_minor - row.void_minor - row.draw_minor
        - row.check_paid_minor - row.settlement_applied_minor + row.adjustment_minor
        OR row.carried_rent_minor <> row.rent_brought_forward_minor + row.rent_charged_minor + row.rent_minor
            - row.rent_paid_since_minor - row.settlement_applied_minor THEN
        RAISE EXCEPTION 'the reversed-run identities failed';
    END IF;
END $$;

-- Two runs in one month post under different keys.

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa90',
    '01900000-0000-7000-8000-000000000001',
    'store-purchase:pair',
    '2034-06-08', '2034-06-08 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f217', 'Pair purchase', 3000, 3000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fa90',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000022', 3000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000fa90',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 3000, 'vendor', '01900000-0000-7000-8000-00000000f217'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa91',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-00000000f317:2034-06',
    '2034-06-01', '2034-06-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f317', 'Pair rent', 1000, 1000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000fa91',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000015', 1000, 0, 'booth', '01900000-0000-7000-8000-00000000f317'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fa91',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000017', 0, 1000
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fa92',
    '01900000-0000-7000-8000-000000000001',
    'settlement:01900000-0000-7000-8000-00000000f919:01900000-0000-7000-8000-00000000f217',
    '2034-06-30', '2034-06-30 18:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'settlement', '01900000-0000-7000-8000-00000000f217', 'Pair settlement', 1000, 1000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-00000000fa92', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 1000, 0, 'vendor', '01900000-0000-7000-8000-00000000f217'),
    ('01900000-0000-7000-8000-00000000fa92', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000015', 0, 1000, 'booth', '01900000-0000-7000-8000-00000000f317');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES
    (
        '01900000-0000-7000-8000-00000000fa93',
        '01900000-0000-7000-8000-000000000001',
        'disbursement:01900000-0000-7000-8000-00000000f919:01900000-0000-7000-8000-00000000f217',
        '2034-07-01', '2034-07-01 12:00:00+00',
        '01900000-0000-7000-8000-00000000f023',
        'USD', 'check_disbursement', '01900000-0000-7000-8000-00000000f217', 'Pair check one', 500, 500
    ),
    (
        '01900000-0000-7000-8000-00000000fa94',
        '01900000-0000-7000-8000-000000000001',
        'disbursement:01900000-0000-7000-8000-00000000f91a:01900000-0000-7000-8000-00000000f217',
        '2034-07-01', '2034-07-01 12:01:00+00',
        '01900000-0000-7000-8000-00000000f023',
        'USD', 'check_disbursement', '01900000-0000-7000-8000-00000000f217', 'Pair check two', 1500, 1500
    );

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-00000000fa93', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 500, 0, 'vendor', '01900000-0000-7000-8000-00000000f217'),
    ('01900000-0000-7000-8000-00000000fa94', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 1500, 0, 'vendor', '01900000-0000-7000-8000-00000000f217');

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-00000000fa93', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 500),
    ('01900000-0000-7000-8000-00000000fa94', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 1500);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    IF (
        SELECT count(*) FROM journals
        WHERE id IN ('01900000-0000-7000-8000-00000000fa93', '01900000-0000-7000-8000-00000000fa94')
    ) <> 2 OR (
        SELECT posting_key FROM journals WHERE id = '01900000-0000-7000-8000-00000000fa93'
    ) = (
        SELECT posting_key FROM journals WHERE id = '01900000-0000-7000-8000-00000000fa94'
    ) THEN
        RAISE EXCEPTION 'two runs in one month did not post under different keys';
    END IF;
END $$;

-- Drawer cash needs an open session. Safe cash does not. They do not share a tender.

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000faa0',
    '01900000-0000-7000-8000-000000000001',
    'store-purchase:drawer',
    '2034-09-02', '2034-09-02 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f218', 'Drawer purchase', 2000, 2000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000faa0',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000022', 2000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000faa0',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 2000, 'vendor', '01900000-0000-7000-8000-00000000f218'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000faa1',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-00000000f318:2034-09',
    '2034-09-01', '2034-09-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f318', 'Drawer rent', 800, 800
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000faa1',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000015', 800, 0, 'booth', '01900000-0000-7000-8000-00000000f318'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000faa1',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000017', 0, 800
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000faa2',
    '01900000-0000-7000-8000-000000000001',
    'settlement:01900000-0000-7000-8000-00000000f91b:01900000-0000-7000-8000-00000000f218',
    '2034-09-30', '2034-09-30 18:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'settlement', '01900000-0000-7000-8000-00000000f218', 'Drawer settlement', 800, 800
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-00000000faa2', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 800, 0, 'vendor', '01900000-0000-7000-8000-00000000f218'),
    ('01900000-0000-7000-8000-00000000faa2', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000015', 0, 800, 'booth', '01900000-0000-7000-8000-00000000f318');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000faa3',
        '01900000-0000-7000-8000-000000000001',
        'disbursement:drawer-closed:01900000-0000-7000-8000-00000000f218',
        '2034-09-30', '2034-09-30 19:00:00+00',
        '01900000-0000-7000-8000-00000000f023',
        'USD', 'cash_disbursement', '01900000-0000-7000-8000-00000000f218', 'Drawer without a session', 1200, 1200
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000faa3',
        '01900000-0000-7000-8000-000000000001',
        1, '01900000-0000-7000-8000-000000000014', 1200, 0, 'vendor', '01900000-0000-7000-8000-00000000f218'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-00000000faa3',
        '01900000-0000-7000-8000-000000000001',
        2, '01900000-0000-7000-8000-000000000010', 0, 1200
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a drawer payout without a session was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a drawer journal needs an open register%' THEN
            RAISE;
        END IF;
END $$;

SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000faa4',
    '01900000-0000-7000-8000-000000000001',
    'disbursement:01900000-0000-7000-8000-00000000f91b:01900000-0000-7000-8000-00000000f218',
    '2034-09-30', '2034-09-30 19:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'cash_disbursement', '01900000-0000-7000-8000-00000000f218', 'Drawer payout', 1200, 1200
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000faa4',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 1200, 0, 'vendor', '01900000-0000-7000-8000-00000000f218'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000faa4',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000010', 0, 1200
);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-00000000f2c2', '01900000-0000-7000-8000-00000000faa4');

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000faa5',
        '01900000-0000-7000-8000-000000000001',
        'disbursement:drawer-safe:01900000-0000-7000-8000-00000000f218',
        '2034-09-30', '2034-09-30 20:00:00+00',
        '01900000-0000-7000-8000-00000000f023',
        'USD', 'cash_disbursement', '01900000-0000-7000-8000-00000000f218', 'Drawer credited the safe', 1, 1
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-00000000faa5',
        '01900000-0000-7000-8000-000000000001',
        1, '01900000-0000-7000-8000-00000000001a', 0, 1
    );
    RAISE EXCEPTION 'a drawer payout credited the safe';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a chart account line must come from a listed source type%'
           AND SQLERRM NOT LIKE '%a disbursement credits only its tender%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000faa6',
    '01900000-0000-7000-8000-000000000001',
    'store-purchase:safe',
    '2034-09-03', '2034-09-03 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f219', 'Safe purchase', 900, 900
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000faa6',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000022', 900, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000faa6',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 900, 'vendor', '01900000-0000-7000-8000-00000000f219'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000faa7',
    '01900000-0000-7000-8000-000000000001',
    'disbursement:01900000-0000-7000-8000-00000000f91c:01900000-0000-7000-8000-00000000f219',
    '2034-09-04', '2034-09-04 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'safe_disbursement', '01900000-0000-7000-8000-00000000f219', 'Safe payout', 900, 900
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000faa7',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 900, 0, 'vendor', '01900000-0000-7000-8000-00000000f219'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000faa7',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-00000000001a', 0, 900
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    IF (
        SELECT source_type FROM journals WHERE id = '01900000-0000-7000-8000-00000000faa4'
    ) <> 'cash_disbursement' OR (
        SELECT source_type FROM journals WHERE id = '01900000-0000-7000-8000-00000000faa7'
    ) <> 'safe_disbursement' OR EXISTS (
        SELECT 1 FROM register_session_posts WHERE journal_id = '01900000-0000-7000-8000-00000000faa7'
    ) THEN
        RAISE EXCEPTION 'drawer and safe payouts did not keep their own sources';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000faa8',
        '01900000-0000-7000-8000-000000000001',
        'disbursement:safe-cash:01900000-0000-7000-8000-00000000f219',
        '2034-09-05', '2034-09-05 12:00:00+00',
        '01900000-0000-7000-8000-00000000f023',
        'USD', 'safe_disbursement', '01900000-0000-7000-8000-00000000f219', 'Safe credited the drawer', 1, 1
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-00000000faa8',
        '01900000-0000-7000-8000-000000000001',
        1, '01900000-0000-7000-8000-000000000010', 0, 1
    );
    RAISE EXCEPTION 'a safe payout credited the drawer';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a chart account line must come from a listed source type%'
           AND SQLERRM NOT LIKE '%a disbursement credits only its tender%' THEN
            RAISE;
        END IF;
END $$;

-- A sale changed after review, including one with the same net, refuses the checksum.

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fab0',
    '01900000-0000-7000-8000-000000000001',
    'sale:drift',
    '2034-08-05', '2034-08-05 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'sale', 'drift', 'Drift sale', 1000, 1000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fab0',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 1000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000fab0',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 1000, 'vendor', '01900000-0000-7000-8000-00000000f216'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor, discount_minor)
VALUES ('01900000-0000-7000-8000-00000000fab0', 1, '01900000-0000-7000-8000-00000000f413', 1000, 0, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-00000000f2c2', '01900000-0000-7000-8000-00000000fab0');

INSERT INTO payout_runs (
    id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date, reviewed_checksum
) VALUES (
    '01900000-0000-7000-8000-00000000f918',
    '01900000-0000-7000-8000-000000000001',
    'batch', 'monthly', '2034-08-01', '2034-08-31', '2034-08-01', 'check', 'draft', '2034-08-31', 'drift'
);

UPDATE payout_runs
SET reviewed_checksum = stall_pack_checksum(id), reviewed_at = clock_timestamp()
WHERE id = '01900000-0000-7000-8000-00000000f918';

DO $$
DECLARE
    before_hash text;
    after_hash text;
BEGIN
    before_hash := stall_pack_checksum('01900000-0000-7000-8000-00000000f918');
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
        debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000fab1',
        '01900000-0000-7000-8000-000000000001',
        'sale:drift-void',
        '2034-08-06', '2034-08-06 12:00:00+00',
        '01900000-0000-7000-8000-00000000f023',
        'USD', 'sale', 'drift-void', 'Void the drift sale', true,
        '01900000-0000-7000-8000-00000000fab0',
        1000, 1000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000fab1',
        '01900000-0000-7000-8000-000000000001',
        1, '01900000-0000-7000-8000-000000000014', 1000, 0, 'vendor', '01900000-0000-7000-8000-00000000f216'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-00000000fab1',
        '01900000-0000-7000-8000-000000000001',
        2, '01900000-0000-7000-8000-000000000010', 0, 1000
    );
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-00000000f2c2', '01900000-0000-7000-8000-00000000fab1');
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000fab2',
        '01900000-0000-7000-8000-000000000001',
        'sale:drift-swap',
        '2034-08-07', '2034-08-07 12:00:00+00',
        '01900000-0000-7000-8000-00000000f023',
        'USD', 'sale', 'drift-swap', 'Replacement sale', 1000, 1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-00000000fab2',
        '01900000-0000-7000-8000-000000000001',
        1, '01900000-0000-7000-8000-000000000010', 1000, 0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000fab2',
        '01900000-0000-7000-8000-000000000001',
        2, '01900000-0000-7000-8000-000000000014', 0, 1000, 'vendor', '01900000-0000-7000-8000-00000000f216'
    );
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor, discount_minor)
    VALUES ('01900000-0000-7000-8000-00000000fab2', 1, '01900000-0000-7000-8000-00000000f414', 1000, 0, 0, 0);
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-00000000f2c2', '01900000-0000-7000-8000-00000000fab2');
    after_hash := stall_pack_checksum('01900000-0000-7000-8000-00000000f918');
    IF before_hash IS NOT DISTINCT FROM after_hash THEN
        RAISE EXCEPTION 'a same-net replacement left the checksum unchanged';
    END IF;
    PERFORM stall_assert_pack_checksum('01900000-0000-7000-8000-00000000f918');
    RAISE EXCEPTION 'a drifted checksum was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%the ledger moved since you reviewed%' THEN
            RAISE;
        END IF;
END $$;

-- A settled sale row, check number, and settlement journal are written once.

INSERT INTO payout_runs (
    id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date
) VALUES (
    '01900000-0000-7000-8000-00000000f91f',
    '01900000-0000-7000-8000-000000000001',
    'batch', 'monthly', '2034-12-01', '2034-12-31', '2034-12-01', 'check', 'draft', '2034-12-31'
);

INSERT INTO payout_run_lines (
    run_id, vendor_id, mode, sales_minor, commission_minor, tax_minor, discount_minor,
    return_minor, void_minor, draw_minor, check_paid_minor, settlement_applied_minor,
    rent_minor, rent_charged_minor, rent_brought_forward_minor, rent_paid_since_minor,
    adjustment_minor, brought_forward_minor, carried_payable_minor, carried_rent_minor,
    settled_minor, check_minor, owes_minor, net_minor
)
SELECT '01900000-0000-7000-8000-00000000f91f', vendor_id, mode, sales_minor, commission_minor, tax_minor, discount_minor,
       return_minor, void_minor, draw_minor, check_paid_minor, settlement_applied_minor,
       rent_minor, rent_charged_minor, rent_brought_forward_minor, rent_paid_since_minor,
       adjustment_minor, brought_forward_minor, carried_payable_minor, carried_rent_minor,
       settled_minor, check_minor, owes_minor, net_minor
FROM payout_pack
WHERE run_id = '01900000-0000-7000-8000-00000000f91f'
  AND vendor_id = '01900000-0000-7000-8000-00000000f215';

INSERT INTO payout_run_sales (
    run_id, vendor_id, journal_id, line_no, item_id, sold_on, item_code, description,
    price_minor, commission_minor, tax_minor, discount_minor, quantity
) VALUES (
    '01900000-0000-7000-8000-00000000f91f',
    '01900000-0000-7000-8000-00000000f215',
    '01900000-0000-7000-8000-00000000fa50',
    1,
    '01900000-0000-7000-8000-00000000f410',
    '2034-06-04',
    'Disc',
    'Discounted',
    1000, 0, 0, 200, 1
);

UPDATE payout_runs
SET status = 'settled', settled_at = clock_timestamp()
WHERE id = '01900000-0000-7000-8000-00000000f91f';

DO $$
BEGIN
    UPDATE payout_run_sales
    SET price_minor = 1
    WHERE run_id = '01900000-0000-7000-8000-00000000f91f';
    RAISE EXCEPTION 'a settled sale row was rewritten';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a settled payout run sale row cannot change%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DELETE FROM payout_run_sales WHERE run_id = '01900000-0000-7000-8000-00000000f91f';
    RAISE EXCEPTION 'a settled sale row was deleted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a settled payout run sale row cannot change%' THEN
            RAISE;
        END IF;
END $$;

UPDATE payout_run_lines
SET check_number = '77'
WHERE run_id = '01900000-0000-7000-8000-00000000f91f'
  AND vendor_id = '01900000-0000-7000-8000-00000000f215';

DO $$
BEGIN
    UPDATE payout_run_lines
    SET check_number = '78'
    WHERE run_id = '01900000-0000-7000-8000-00000000f91f'
      AND vendor_id = '01900000-0000-7000-8000-00000000f215';
    RAISE EXCEPTION 'a check number was written twice';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a check number is written once%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    UPDATE payout_run_lines
    SET settlement_journal_id = '01900000-0000-7000-8000-00000000fa22'
    WHERE run_id = '01900000-0000-7000-8000-00000000f91f'
      AND vendor_id = '01900000-0000-7000-8000-00000000f215';
    RAISE EXCEPTION 'a settlement journal was written after settle';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a settled payout run money line cannot change%' THEN
            RAISE;
        END IF;
END $$;

-- Unchecked: rent is not taken from the share. The check is the share. The rent stays owed.

UPDATE mall SET rent_prepaid_from_prior_sales = false;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-00000000f220', 'apart', 'Ada', 'Separate');

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-00000000f320', 'Z9', 'Separate booth');

INSERT INTO booth_assignments (booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-00000000f320',
    '01900000-0000-7000-8000-00000000f220',
    '2034-06-01'
);

INSERT INTO booth_rent_rates (booth_id, amount_minor)
VALUES ('01900000-0000-7000-8000-00000000f320', 1000);
INSERT INTO assignment_rent (assignment_id, amount_minor)
SELECT assignment.id, rate.amount_minor
FROM booth_assignments AS assignment
JOIN booth_rent_rates AS rate ON rate.booth_id = assignment.booth_id
ON CONFLICT (assignment_id) DO NOTHING;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000fae1',
    '01900000-0000-7000-8000-000000000001',
    'store-purchase:separate',
    '2034-06-04', '2034-06-04 12:00:00+00',
    '01900000-0000-7000-8000-00000000f023',
    'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f220', 'Separate purchase', 3000, 3000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fae1',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000022', 3000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000fae1',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 3000, 'vendor', '01900000-0000-7000-8000-00000000f220'
);

UPDATE payout_runs
SET status = 'settled', settled_at = clock_timestamp()
WHERE status = 'draft'
  AND kind = 'batch'
  AND sales_from = DATE '2034-06-01'
  AND sales_to = DATE '2034-06-30'
  AND rent_month = DATE '2034-06-01';

INSERT INTO payout_runs (
    id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date
) VALUES (
    '01900000-0000-7000-8000-00000000f920',
    '01900000-0000-7000-8000-000000000001',
    'batch', 'monthly', '2034-06-01', '2034-06-30', '2034-06-01', 'check', 'draft', '2034-06-30'
);

DO $$
DECLARE
    check_amount bigint;
    settled_amount bigint;
    owed bigint;
BEGIN
    SELECT pack."check", pack.settled_minor, pack.owes
    INTO check_amount, settled_amount, owed
    FROM payout_pack AS pack
    WHERE pack.run_id = '01900000-0000-7000-8000-00000000f920'
      AND pack.vendor_id = '01900000-0000-7000-8000-00000000f220';
    IF check_amount <> 3000 OR settled_amount <> 0 OR owed <> 1000 THEN
        RAISE EXCEPTION 'rent paid separately was still taken from the share (% % %)', check_amount, settled_amount, owed;
    END IF;
END $$;

INSERT INTO payout_runs (
    id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date
) VALUES (
    '01900000-0000-7000-8000-00000000f930',
    '01900000-0000-7000-8000-000000000001',
    'batch', 'monthly', '2035-01-01', '2035-01-31', '2035-01-01', 'check', 'draft', '2035-01-31'
);

DO $$
BEGIN
    INSERT INTO payout_runs (
        id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date
    ) VALUES (
        '01900000-0000-7000-8000-00000000f931',
        '01900000-0000-7000-8000-000000000001',
        'batch', 'monthly', '2035-01-01', '2035-01-31', '2035-01-01', 'check', 'draft', '2035-01-31'
    );
    RAISE EXCEPTION 'a second draft for the same window was accepted';
EXCEPTION
    WHEN unique_violation THEN
        IF SQLERRM NOT LIKE '%payout_runs_one_draft_window%' THEN
            RAISE;
        END IF;
END $$;

ROLLBACK;
