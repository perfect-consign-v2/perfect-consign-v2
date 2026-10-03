-- Locks: a settled window, net columns, and a unique disbursement check number.

BEGIN;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-00000000f201', 'lock', 'Cal', 'Lane');

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-00000000f301', 'L1', 'Lock booth');

INSERT INTO booth_assignments (booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-00000000f301',
    '01900000-0000-7000-8000-00000000f201',
    '2032-01-01'
);

INSERT INTO booth_rent_rates (booth_id, month_start, amount_minor)
VALUES ('01900000-0000-7000-8000-00000000f301', '2032-03-01', 1000);

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
        NULL;
END $$;

ROLLBACK;
