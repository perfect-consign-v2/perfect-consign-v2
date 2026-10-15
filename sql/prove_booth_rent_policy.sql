-- Booth rent can be off, and the cycle day is the first day of the period.
-- Day 1 stays a calendar month. The script rolls back.

BEGIN;

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-00000000b901', 'B9', 'Policy booth');

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-00000000b902', 'b9', 'Rae', 'Policy');

INSERT INTO booth_assignments (booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-00000000b901',
    '01900000-0000-7000-8000-00000000b902',
    '2096-03-20'
);

INSERT INTO booth_rent_rates (booth_id, amount_minor)
VALUES ('01900000-0000-7000-8000-00000000b901', 3100);

INSERT INTO assignment_rent (assignment_id, amount_minor)
SELECT assignment.id, rate.amount_minor
FROM booth_assignments AS assignment
JOIN booth_rent_rates AS rate ON rate.booth_id = assignment.booth_id
WHERE assignment.booth_id = '01900000-0000-7000-8000-00000000b901';

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000b903',
    '01900000-0000-7000-8000-000000000001',
    '2096-01-01',
    '2096-12-31',
    'open'
);

DO $$
BEGIN
    UPDATE mall SET rent_cycle_day = 0;
    RAISE EXCEPTION 'cycle day 0 was accepted';
EXCEPTION
    WHEN check_violation THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE mall SET rent_cycle_day = 29;
    RAISE EXCEPTION 'cycle day 29 was accepted';
EXCEPTION
    WHEN check_violation THEN
        NULL;
END $$;

DO $$
BEGIN
    IF stall_rent_period_start('2096-03-20') <> DATE '2096-03-01' THEN
        RAISE EXCEPTION 'day 1 period did not start on the first';
    END IF;
    IF stall_rent_charge_suffix(stall_rent_period_start('2096-03-20')) <> '2096-03' THEN
        RAISE EXCEPTION 'day 1 charge key did not stay YYYY-MM';
    END IF;
END $$;

SAVEPOINT day_one_charge;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000b911',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-00000000b901:2096-05',
    '2096-05-01',
    '2096-05-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000b903',
    'USD',
    'rent_charge',
    'B9:2096-05',
    'May rent',
    3100,
    3100
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000b911',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000015',
    3100,
    0,
    'booth',
    '01900000-0000-7000-8000-00000000b901'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000b911',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000017',
    0,
    3100
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

ROLLBACK TO SAVEPOINT day_one_charge;

UPDATE mall SET booth_rentals_enabled = false;

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000b931',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-00000000b901:2096-05',
        '2096-05-01',
        '2096-05-01 12:00:00+00',
        '01900000-0000-7000-8000-00000000b903',
        'USD',
        'rent_charge',
        'B9:off',
        'Rent while off',
        3100,
        3100
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000b931',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000015',
        3100,
        0,
        'booth',
        '01900000-0000-7000-8000-00000000b901'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-00000000b931',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000017',
        0,
        3100
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a rent charge was accepted while booth rentals are off';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%booth rentals are turned off%' THEN
            RAISE;
        END IF;
END $$;

UPDATE mall SET booth_rentals_enabled = true, rent_cycle_day = 15;

DO $$
BEGIN
    IF stall_rent_period_start('2096-03-20') <> DATE '2096-03-15' THEN
        RAISE EXCEPTION 'day 15 period did not start on the 15th';
    END IF;
    IF stall_rent_period_end(DATE '2096-03-15') <> DATE '2096-04-14' THEN
        RAISE EXCEPTION 'day 15 period did not end on the 14th';
    END IF;
    IF stall_rent_charge_suffix(DATE '2096-03-15') <> '2096-03-15' THEN
        RAISE EXCEPTION 'day 15 charge key did not use the period start';
    END IF;
END $$;

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000b932',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-00000000b901:2096-03-15',
        '2096-03-20',
        '2096-03-20 12:00:00+00',
        '01900000-0000-7000-8000-00000000b903',
        'USD',
        'rent_charge',
        'B9:full',
        'Full rent',
        3100,
        3100
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000b932',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000015',
        3100,
        0,
        'booth',
        '01900000-0000-7000-8000-00000000b901'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-00000000b932',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000017',
        0,
        3100
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a full charge was accepted for a move-in period';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a rent charge debits that booth receivable and credits rent revenue for the stored rate%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000b933',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-00000000b901:2096-03',
        '2096-03-20',
        '2096-03-20 12:00:00+00',
        '01900000-0000-7000-8000-00000000b903',
        'USD',
        'rent_charge',
        'B9:month',
        'Calendar key',
        2600,
        2600
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000b933',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000015',
        2600,
        0,
        'booth',
        '01900000-0000-7000-8000-00000000b901'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-00000000b933',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000017',
        0,
        2600
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a calendar-month key was accepted for day 15';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a rent charge posting key is one booth and one month%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000b912',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-00000000b901:2096-03-15',
    '2096-03-20',
    '2096-03-20 12:00:00+00',
    '01900000-0000-7000-8000-00000000b903',
    'USD',
    'rent_charge',
    'B9:2096-03-15',
    'Prorated rent',
    2600,
    2600
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000b912',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000015',
    2600,
    0,
    'booth',
    '01900000-0000-7000-8000-00000000b901'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000b912',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000017',
    0,
    2600
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    UPDATE mall SET rent_cycle_day = 1;
    RAISE EXCEPTION 'the cycle day changed after a rent charge';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%the rent cycle day cannot change after a rent charge is posted%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    UPDATE mall SET booth_rentals_enabled = false;
    RAISE EXCEPTION 'booth rentals turned off while rent was still owed';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%booth rentals stay on while a booth still owes rent%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000b913',
    '01900000-0000-7000-8000-000000000001',
    'store-purchase:b9-1',
    '2096-03-10',
    '2096-03-10 12:00:00+00',
    '01900000-0000-7000-8000-00000000b903',
    'USD',
    'store_purchase',
    '01900000-0000-7000-8000-00000000b902',
    'Policy purchase',
    4000,
    4000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000b913',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000022',
    4000,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000b913',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000014',
    0,
    4000,
    'vendor',
    '01900000-0000-7000-8000-00000000b902'
);

UPDATE mall SET rent_prepaid_from_prior_sales = false;

INSERT INTO payout_runs (
    id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date, reviewed_checksum
) VALUES (
    '01900000-0000-7000-8000-00000000b921',
    '01900000-0000-7000-8000-000000000001',
    'batch',
    'monthly',
    '2096-02-15',
    '2096-03-14',
    '2096-03-15',
    'check',
    'draft',
    '2096-03-20',
    'policy'
);

DO $$
DECLARE
    settled bigint;
    paycheck bigint;
    owes bigint;
BEGIN
    SELECT pack.settled_minor, pack.check_minor, pack.owes_minor
    INTO settled, paycheck, owes
    FROM payout_pack AS pack
    WHERE pack.run_id = '01900000-0000-7000-8000-00000000b921'
      AND pack.vendor_id = '01900000-0000-7000-8000-00000000b902';
    IF settled IS DISTINCT FROM 0 OR paycheck IS DISTINCT FROM 4000 OR owes IS DISTINCT FROM 2600 THEN
        RAISE EXCEPTION 'deduct off did not leave the rent bill';
    END IF;
END $$;

UPDATE mall SET rent_prepaid_from_prior_sales = true;

DO $$
DECLARE
    settled bigint;
    paycheck bigint;
    owes bigint;
BEGIN
    SELECT pack.settled_minor, pack.check_minor, pack.owes_minor
    INTO settled, paycheck, owes
    FROM payout_pack AS pack
    WHERE pack.run_id = '01900000-0000-7000-8000-00000000b921'
      AND pack.vendor_id = '01900000-0000-7000-8000-00000000b902';
    IF settled IS DISTINCT FROM 2600 OR paycheck IS DISTINCT FROM 1400 OR owes IS DISTINCT FROM 0 THEN
        RAISE EXCEPTION 'deduct on did not settle the smaller of payable and rent';
    END IF;
END $$;

ROLLBACK;
