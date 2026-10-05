-- A reversed payout restores the balances, and a reversed posting key can be used again.

BEGIN;

SET CONSTRAINTS ALL DEFERRED;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
SELECT '01900000-0000-7000-8000-00000000f024', id, '2034-01-01', '2034-12-31', 'open'
FROM ledger_books WHERE is_default;

SAVEPOINT keys;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    )
    SELECT
        '01900000-0000-7000-8000-00000000f830',
        id, 'probe-live-key', '2034-04-02', '2034-04-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000f024',
        'USD', 'adjustment', 'probe-live', 'Live key', 1, 1
    FROM ledger_books WHERE is_default;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    )
    SELECT
        '01900000-0000-7000-8000-00000000f831',
        id, 'probe-live-key', '2034-04-02', '2034-04-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000f024',
        'USD', 'adjustment', 'probe-live-2', 'Live key again', 1, 1
    FROM ledger_books WHERE is_default;
    RAISE EXCEPTION 'a second live posting key was accepted';
EXCEPTION
    WHEN unique_violation THEN
        IF SQLERRM NOT LIKE '%journals_book_key%' THEN
            RAISE;
        END IF;
END $$;

ROLLBACK TO SAVEPOINT keys;

SAVEPOINT replica_key;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
)
SELECT
    '01900000-0000-7000-8000-00000000f832',
    id, 'probe-replica-key', '2034-04-02', '2034-04-02 12:00:00+00',
    '01900000-0000-7000-8000-00000000f024',
    'USD', 'adjustment', 'probe-replica', 'Replica key', 1, 1
FROM ledger_books WHERE is_default;

DO $$
BEGIN
    SET LOCAL session_replication_role = replica;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    )
    SELECT
        '01900000-0000-7000-8000-00000000f833',
        id, 'probe-replica-key', '2034-04-02', '2034-04-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000f024',
        'USD', 'adjustment', 'probe-replica-2', 'Replica key again', 1, 1
    FROM ledger_books WHERE is_default;
    RAISE EXCEPTION 'a replica session inserted a second live posting key';
EXCEPTION
    WHEN unique_violation THEN
        IF SQLERRM NOT LIKE '%journals_book_key%' THEN
            RAISE;
        END IF;
END $$;

SET LOCAL session_replication_role = origin;
ROLLBACK TO SAVEPOINT replica_key;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-00000000f401', 'undo', 'Ned', 'Undo');

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-00000000f501', 'U1', 'Undo booth');

INSERT INTO booth_assignments (booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-00000000f501',
    '01900000-0000-7000-8000-00000000f401',
    '2034-03-01'
);

INSERT INTO booth_rent_rates (booth_id, amount_minor)
VALUES ('01900000-0000-7000-8000-00000000f501', 1000);
INSERT INTO assignment_rent (assignment_id, amount_minor)
SELECT assignment.id, rate.amount_minor
FROM booth_assignments AS assignment
JOIN booth_rent_rates AS rate ON rate.booth_id = assignment.booth_id
ON CONFLICT (assignment_id) DO NOTHING;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
)
SELECT
    '01900000-0000-7000-8000-00000000f821',
    id, 'store-purchase:undo', '2034-04-02', '2034-04-02 12:00:00+00',
    '01900000-0000-7000-8000-00000000f024',
    'USD', 'store_purchase', 'undo-buy', 'Store purchase', 1000, 1000
FROM ledger_books WHERE is_default;

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
)
SELECT
    '01900000-0000-7000-8000-00000000f821', id, 1,
    '01900000-0000-7000-8000-000000000022', 1000, 0, NULL, NULL
FROM ledger_books WHERE is_default;

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
)
SELECT
    '01900000-0000-7000-8000-00000000f821', id, 2,
    '01900000-0000-7000-8000-000000000014', 0, 1000, 'vendor', '01900000-0000-7000-8000-00000000f401'
FROM ledger_books WHERE is_default;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
)
SELECT
    '01900000-0000-7000-8000-00000000f822',
    id, 'rent-charge:01900000-0000-7000-8000-00000000f501:2034-03', '2034-03-01', '2034-03-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000f024',
    'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f501:2034-03', 'March rent', 1000, 1000
FROM ledger_books WHERE is_default;

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
)
SELECT
    '01900000-0000-7000-8000-00000000f822', id, 1,
    '01900000-0000-7000-8000-000000000015', 1000, 0, 'booth', '01900000-0000-7000-8000-00000000f501'
FROM ledger_books WHERE is_default;

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
)
SELECT
    '01900000-0000-7000-8000-00000000f822', id, 2,
    '01900000-0000-7000-8000-000000000017', 0, 1000, NULL, NULL
FROM ledger_books WHERE is_default;

INSERT INTO payout_runs (
    id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date
)
SELECT
    '01900000-0000-7000-8000-00000000f902',
    id, 'batch', 'monthly', '2034-03-01', '2034-03-31', '2034-03-01', 'check', 'draft', '2034-04-02'
FROM ledger_books WHERE is_default;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
)
SELECT
    '01900000-0000-7000-8000-00000000f823',
    id, 'settlement:01900000-0000-7000-8000-00000000f902:01900000-0000-7000-8000-00000000f401',
    '2034-04-02', '2034-04-02 12:05:00+00',
    '01900000-0000-7000-8000-00000000f024',
    'USD', 'settlement', '01900000-0000-7000-8000-00000000f401', 'Close', 1000, 1000
FROM ledger_books WHERE is_default;

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
)
SELECT
    '01900000-0000-7000-8000-00000000f823', id, 1,
    '01900000-0000-7000-8000-000000000014', 1000, 0, 'vendor', '01900000-0000-7000-8000-00000000f401'
FROM ledger_books WHERE is_default;

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
)
SELECT
    '01900000-0000-7000-8000-00000000f823', id, 2,
    '01900000-0000-7000-8000-000000000015', 0, 1000, 'booth', '01900000-0000-7000-8000-00000000f501'
FROM ledger_books WHERE is_default;

INSERT INTO payout_run_journals (run_id, journal_id, role) VALUES
    ('01900000-0000-7000-8000-00000000f902', '01900000-0000-7000-8000-00000000f822', 'rent_charge'),
    ('01900000-0000-7000-8000-00000000f902', '01900000-0000-7000-8000-00000000f823', 'settlement');

SET CONSTRAINTS ALL IMMEDIATE;

UPDATE payout_runs SET status = 'settled', settled_at = clock_timestamp()
WHERE id = '01900000-0000-7000-8000-00000000f902';

SELECT stall_reverse_payout_run(
    '01900000-0000-7000-8000-00000000f902',
    '2034-04-03',
    'owner',
    'wrong vendor'
);

DO $$
DECLARE
    payable bigint;
    rent bigint;
    reversal uuid;
    actor text;
    why text;
BEGIN
    SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) INTO payable
    FROM journal_lines AS line
    JOIN chart_roles AS role ON role.account_id = line.account_id
    WHERE role.role = 'vendor_payable'
      AND line.subledger_ref = '01900000-0000-7000-8000-00000000f401';
    SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) INTO rent
    FROM journal_lines AS line
    JOIN chart_roles AS role ON role.account_id = line.account_id
    WHERE role.role = 'booth_rent_receivable'
      AND line.subledger_ref = '01900000-0000-7000-8000-00000000f501';
    IF payable <> 1000 OR rent <> 0 THEN
        RAISE EXCEPTION 'the reversal did not restore the balances';
    END IF;
    SELECT id, reversed_by, reversal_reason INTO reversal, actor, why
    FROM payout_runs
    WHERE reverses_run_id = '01900000-0000-7000-8000-00000000f902';
    IF reversal IS NULL OR actor <> 'owner' OR why <> 'wrong vendor' THEN
        RAISE EXCEPTION 'the reversal run is missing its operator or reason';
    END IF;
    IF (SELECT status FROM payout_runs WHERE id = '01900000-0000-7000-8000-00000000f902') <> 'reversed' THEN
        RAISE EXCEPTION 'the original run is not reversed';
    END IF;
END $$;

INSERT INTO payout_runs (
    id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date
)
SELECT
    '01900000-0000-7000-8000-00000000f903',
    id, 'batch', 'monthly', '2034-03-01', '2034-03-31', '2034-03-01', 'check', 'draft', '2034-03-31'
FROM ledger_books WHERE is_default;

DO $$
DECLARE
    owed bigint;
BEGIN
    SELECT carried_rent_minor INTO owed
    FROM payout_pack
    WHERE run_id = '01900000-0000-7000-8000-00000000f903'
      AND vendor_id = '01900000-0000-7000-8000-00000000f401';
    IF owed IS DISTINCT FROM 1000 THEN
        RAISE EXCEPTION 'a reversed rent charge is not missing on the pack (owed %)', owed;
    END IF;
END $$;

CREATE TEMP TABLE reversal_pack_check (checksum text) ON COMMIT DROP;
INSERT INTO reversal_pack_check
SELECT stall_pack_checksum('01900000-0000-7000-8000-00000000f903');

SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
)
SELECT
    '01900000-0000-7000-8000-00000000f824',
    id, 'rent-charge:01900000-0000-7000-8000-00000000f501:2034-03', '2034-03-01', '2034-03-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000f024',
    'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f501:2034-03', 'March rent again', 1000, 1000
FROM ledger_books WHERE is_default;

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
)
SELECT
    '01900000-0000-7000-8000-00000000f824', id, 1,
    '01900000-0000-7000-8000-000000000015', 1000, 0, 'booth', '01900000-0000-7000-8000-00000000f501'
FROM ledger_books WHERE is_default;

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
)
SELECT
    '01900000-0000-7000-8000-00000000f824', id, 2,
    '01900000-0000-7000-8000-000000000017', 0, 1000, NULL, NULL
FROM ledger_books WHERE is_default;

INSERT INTO payout_run_journals (run_id, journal_id, role)
VALUES (
    '01900000-0000-7000-8000-00000000f903',
    '01900000-0000-7000-8000-00000000f824',
    'rent_charge'
);

DO $$
DECLARE
    before text;
    after text;
    owed bigint;
BEGIN
    SELECT checksum INTO before FROM reversal_pack_check;
    after := stall_pack_checksum('01900000-0000-7000-8000-00000000f903');
    IF before IS NULL OR before IS DISTINCT FROM after THEN
        RAISE EXCEPTION 'recharging a reversed rent month moved the pack checksum';
    END IF;
    SELECT carried_rent_minor INTO owed
    FROM payout_pack
    WHERE run_id = '01900000-0000-7000-8000-00000000f903'
      AND vendor_id = '01900000-0000-7000-8000-00000000f401';
    IF owed IS DISTINCT FROM 1000 THEN
        RAISE EXCEPTION 'the recharged month is not on the pack';
    END IF;
END $$;

UPDATE payout_runs
SET reviewed_checksum = (SELECT checksum FROM reversal_pack_check),
    reviewed_at = clock_timestamp()
WHERE id = '01900000-0000-7000-8000-00000000f903';

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
WHERE run_id = '01900000-0000-7000-8000-00000000f903';

INSERT INTO payout_run_sales (
    run_id, vendor_id, journal_id, line_no, item_id, sold_on, item_code, description,
    price_minor, commission_minor, tax_minor, discount_minor, quantity
)
SELECT run_id, vendor_id, journal_id, line_no, item_id, sold_on, item_code, description,
       price_minor, commission_minor, tax_minor, discount_minor, quantity
FROM payout_pack_sales
WHERE run_id = '01900000-0000-7000-8000-00000000f903';

SELECT stall_assert_frozen_checksum('01900000-0000-7000-8000-00000000f903');

SET CONSTRAINTS ALL IMMEDIATE;

UPDATE payout_runs
SET status = 'settled', settled_at = clock_timestamp()
WHERE id = '01900000-0000-7000-8000-00000000f903';

SELECT stall_assert_frozen_checksum('01900000-0000-7000-8000-00000000f903');

UPDATE payout_runs
SET status = 'paid', paid_at = clock_timestamp()
WHERE id = '01900000-0000-7000-8000-00000000f903';

DO $$
BEGIN
    IF (SELECT status FROM payout_runs WHERE id = '01900000-0000-7000-8000-00000000f903') <> 'paid' THEN
        RAISE EXCEPTION 'the replacement run could not be paid';
    END IF;
END $$;

DO $$
BEGIN
    PERFORM stall_reverse_payout_run(
        '01900000-0000-7000-8000-00000000f902',
        '2034-04-03',
        'owner',
        'again'
    );
    RAISE EXCEPTION 'a second undo was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

ROLLBACK;
