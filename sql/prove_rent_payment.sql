-- Proof for rent paid outside a payout. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000201', 'A1', 'Booth A1');

SELECT stall_seed_vendor('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-000000000301',
    '01900000-0000-7000-8000-000000000201',
    '01900000-0000-7000-8000-000000000101',
    '2026-01-01'
);

INSERT INTO booth_rent_rates (booth_id, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000201', 15000);
INSERT INTO assignment_rent (assignment_id, amount_minor)
SELECT assignment.id, rate.amount_minor
FROM booth_assignments AS assignment
JOIN booth_rent_rates AS rate ON rate.booth_id = assignment.booth_id
ON CONFLICT (assignment_id) DO NOTHING;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-000000000021',
    '01900000-0000-7000-8000-000000000001',
    '2026-01-01',
    '2026-12-31',
    'open'
);

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d22',
    '01900000-0000-7000-8000-000000000d10',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000700',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-000000000301:2026-03',
    '2026-03-01',
    '2026-03-01 08:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'rent_charge',
    '01900000-0000-7000-8000-000000000301:2026-03',
    'March rent for booth A1',
    15000,
    15000
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000700',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000015',
    15000,
    0,
    'assignment',
    '01900000-0000-7000-8000-000000000301'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000700',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000017',
    0,
    15000
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000701',
    '01900000-0000-7000-8000-000000000001',
    'rent-payment:7001',
    '2026-03-15',
    '2026-03-15 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'rent_payment',
    '7001',
    'Booth A1 rent paid at the counter',
    5000,
    5000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000701',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000010',
    5000,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000701',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000015',
    0,
    5000,
    'assignment',
    '01900000-0000-7000-8000-000000000301'
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000701');

SET CONSTRAINTS ALL IMMEDIATE;

DO $$
BEGIN
    IF stall_register_expected_cash('01900000-0000-7000-8000-000000000d22') IS DISTINCT FROM 5000 THEN
        RAISE EXCEPTION 'rent cash did not raise expected drawer cash';
    END IF;
END $$;

SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000702',
        '01900000-0000-7000-8000-000000000001',
        'rent-payment:7001',
        '2026-03-16',
        '2026-03-16 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'rent_payment',
        '7001-again',
        'Duplicate rent payment',
        15000,
        15000
    );
    RAISE EXCEPTION 'duplicate rent payment key was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
END $$;

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000703',
        '01900000-0000-7000-8000-000000000001',
        'rent-payment:7002',
        '2026-03-16',
        '2026-03-16 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'rent_payment',
        '7002',
        'Rent payment that debits the bank',
        15000,
        15000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000703',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000011',
        15000,
        0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000703',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000015',
        0,
        15000,
        'assignment',
        '01900000-0000-7000-8000-000000000301'
    );
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000703');
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'rent payment against the bank was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000707',
        '01900000-0000-7000-8000-000000000001',
        'rent-payment:7007',
        '2026-03-17',
        '2026-03-17 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'rent_payment',
        '7007',
        'Rent check with no number',
        100,
        100
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000707',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-00000000001b',
        100,
        0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000707',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000015',
        0,
        100,
        'assignment',
        '01900000-0000-7000-8000-000000000301'
    );
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000707');
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a rent check without a number was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000708',
        '01900000-0000-7000-8000-000000000001',
        'rent-payment:7008',
        '2026-03-17',
        '2026-03-17 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'rent_payment',
        '7008',
        'Cash rent receipt with a check number',
        100,
        100
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000708',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000010',
        100,
        0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000708',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000015',
        0,
        100,
        'assignment',
        '01900000-0000-7000-8000-000000000301'
    );
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000708');
    INSERT INTO register_checks (journal_id, check_number)
    VALUES ('01900000-0000-7000-8000-000000000708', '1008');
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a cash rent receipt with a check number was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000705',
    '01900000-0000-7000-8000-000000000001',
    'rent-payment:7005',
    '2026-03-18',
    '2026-03-18 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'rent_payment',
    '7005',
    'Booth A1 rent paid by check',
    4000,
    4000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000705',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-00000000001b',
    4000,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000705',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000015',
    0,
    4000,
    'assignment',
    '01900000-0000-7000-8000-000000000301'
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000705');

INSERT INTO register_checks (journal_id, check_number)
VALUES ('01900000-0000-7000-8000-000000000705', '1001');

SET CONSTRAINTS ALL IMMEDIATE;

DO $$
BEGIN
    IF stall_register_expected_cash('01900000-0000-7000-8000-000000000d22') IS DISTINCT FROM 5000 THEN
        RAISE EXCEPTION 'a rent check changed expected drawer cash';
    END IF;
END $$;

SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000706',
    '01900000-0000-7000-8000-000000000001',
    'rent-payment:7006',
    '2026-03-19',
    '2026-03-19 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'rent_payment',
    '7006',
    'Booth A1 rent paid by card',
    6000,
    6000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000706',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000016',
    6000,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000706',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000015',
    0,
    6000,
    'assignment',
    '01900000-0000-7000-8000-000000000301'
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000706');

SET CONSTRAINTS ALL IMMEDIATE;

DO $$
BEGIN
    IF stall_register_expected_cash('01900000-0000-7000-8000-000000000d22') IS DISTINCT FROM 5000 THEN
        RAISE EXCEPTION 'a rent card changed expected drawer cash';
    END IF;
END $$;

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000704',
        '01900000-0000-7000-8000-000000000001',
        'rent-payment:7003',
        '2026-03-17',
        '2026-03-17 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'rent_payment',
        '7003',
        'Rent payment above the open receivable',
        100,
        100
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000704',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000010',
        100,
        0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000704',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000015',
        0,
        100,
        'assignment',
        '01900000-0000-7000-8000-000000000301'
    );
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000704');
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'rent overpayment was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

SET CONSTRAINTS ALL DEFERRED;

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000202', 'B2', 'Booth B2');

SELECT stall_seed_vendor('01900000-0000-7000-8000-000000000102', 'bea', 'Bea', 'Lane');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-000000000302',
    '01900000-0000-7000-8000-000000000202',
    '01900000-0000-7000-8000-000000000102',
    '2026-01-01'
);

INSERT INTO booth_rent_rates (booth_id, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000202', 1000);

INSERT INTO assignment_rent (assignment_id, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000302', 1000);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000070c',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-000000000302:2026-03',
    '2026-03-01',
    '2026-03-01 08:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'rent_charge',
    '01900000-0000-7000-8000-000000000302:2026-03',
    'March rent for booth B2',
    1000,
    1000
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000070c',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000015',
    1000,
    0,
    'assignment',
    '01900000-0000-7000-8000-000000000302'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000070c',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000017',
    0,
    1000
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000070d',
    '01900000-0000-7000-8000-000000000001',
    'rent-payment:700d',
    '2026-03-18',
    '2026-03-18 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'rent_payment',
    '700d',
    'Booth B2 rent paid by the same check number',
    1000,
    1000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000070d',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-00000000001b',
    1000,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000070d',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000015',
    0,
    1000,
    'assignment',
    '01900000-0000-7000-8000-000000000302'
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-00000000070d');

INSERT INTO register_checks (journal_id, check_number)
VALUES ('01900000-0000-7000-8000-00000000070d', '1001');

SET CONSTRAINTS ALL IMMEDIATE;

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000070e',
        '01900000-0000-7000-8000-000000000001',
        'rent-payment:700e',
        '2026-03-19',
        '2026-03-19 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'rent_payment',
        '700e',
        'Same vendor repeats check 1001',
        1000,
        1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-00000000070e',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-00000000001b',
        1000,
        0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000070e',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000015',
        0,
        1000,
        'assignment',
        '01900000-0000-7000-8000-000000000301'
    );
    INSERT INTO register_checks (journal_id, check_number)
    VALUES ('01900000-0000-7000-8000-00000000070e', '1001');
    RAISE EXCEPTION 'the same vendor reused a received check number';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a received check number is unique for that vendor%' THEN
            RAISE;
        END IF;
END $$;

-- Through the posting functions: the database splits a receipt over the
-- vendor's stays, the stay with the oldest unpaid charge first.
INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000203', 'C3', 'Booth C3');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-000000000303',
    '01900000-0000-7000-8000-000000000203',
    '01900000-0000-7000-8000-000000000101',
    '2026-02-01'
);

INSERT INTO assignment_rent (assignment_id, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000303', 2000);

DO $$
DECLARE
    receipt uuid;
    split_c3 bigint;
    split_a1 bigint;
BEGIN
    IF stall_vendor_open_rent('01900000-0000-7000-8000-000000000101') <> 0 THEN
        RAISE EXCEPTION 'Ada should owe nothing before the new charges';
    END IF;
    PERFORM stall_post_rent_charge('01900000-0000-7000-8000-000000000303', '2026-02-01', '2026-03-20');
    PERFORM stall_post_rent_charge('01900000-0000-7000-8000-000000000301', '2026-04-01', '2026-04-01');
    PERFORM stall_post_rent_charge('01900000-0000-7000-8000-000000000303', '2026-03-01', '2026-04-02');
    SET CONSTRAINTS ALL IMMEDIATE;
    IF stall_vendor_open_rent('01900000-0000-7000-8000-000000000101') <> 19000 THEN
        RAISE EXCEPTION 'Ada open rent is not the three charges';
    END IF;

    SELECT amount_minor INTO split_c3
    FROM stall_rent_payment_split('01900000-0000-7000-8000-000000000101', 5000)
    WHERE assignment_id = '01900000-0000-7000-8000-000000000303';
    SELECT amount_minor INTO split_a1
    FROM stall_rent_payment_split('01900000-0000-7000-8000-000000000101', 5000)
    WHERE assignment_id = '01900000-0000-7000-8000-000000000301';
    IF split_c3 IS DISTINCT FROM 4000 OR split_a1 IS DISTINCT FROM 1000 THEN
        RAISE EXCEPTION 'the split did not pay the stay with the oldest unpaid charge first (C3 %, A1 %)', split_c3, split_a1;
    END IF;
    IF (SELECT count(*) FROM stall_rent_payment_split('01900000-0000-7000-8000-000000000101', 3000)) <> 1 THEN
        RAISE EXCEPTION 'a payment the oldest stay can absorb touched another stay';
    END IF;

    receipt := stall_post_rent_payment('01900000-0000-7000-8000-000000000101', 5000, 0, 0, '2026-04-03');
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d22', receipt);
    SET CONSTRAINTS ALL IMMEDIATE;

    IF stall_assignment_open_rent('01900000-0000-7000-8000-000000000303') <> 0
       OR stall_assignment_open_rent('01900000-0000-7000-8000-000000000301') <> 14000 THEN
        RAISE EXCEPTION 'the receipt did not land on the stays the split named';
    END IF;
    IF stall_register_expected_cash('01900000-0000-7000-8000-000000000d22') IS DISTINCT FROM 10000 THEN
        RAISE EXCEPTION 'a cash receipt through the function did not raise expected drawer cash';
    END IF;
END $$;

DO $$
BEGIN
    PERFORM stall_post_rent_payment('01900000-0000-7000-8000-000000000101', 14001, 0, 0, '2026-04-03');
    RAISE EXCEPTION 'the posting function took more than the open rent';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%cannot exceed the open rent%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    PERFORM stall_post_rent_payment('01900000-0000-7000-8000-000000000101', 0, 0, 0, '2026-04-03');
    RAISE EXCEPTION 'the posting function took a receipt with no tender';
EXCEPTION
    WHEN SQLSTATE '22023' THEN
        NULL;
END $$;

DO $$
BEGIN
    PERFORM stall_post_rent_payment('01900000-0000-7000-8000-000000000101', -100, 200, 0, '2026-04-03');
    RAISE EXCEPTION 'the posting function took a negative tender';
EXCEPTION
    WHEN SQLSTATE '22023' THEN
        NULL;
END $$;

-- A receipt through the function with no drawer link is refused at commit time.
DO $$
BEGIN
    PERFORM stall_post_rent_payment('01900000-0000-7000-8000-000000000101', 0, 1000, 0, '2026-04-03');
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a rent receipt with no register shift was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%register session is required%' AND SQLERRM NOT LIKE '%needs an open register%' THEN
            RAISE;
        END IF;
END $$;

ROLLBACK;
