-- Late fees are decided by the database: a fee posts only when one is due, for
-- the stored amount, in order, and once. A waived fee stays waived. A late fee
-- that reduces what the store owes a vendor is refused. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000e831',
    '01900000-0000-7000-8000-000000000001',
    '2097-01-01',
    '2097-12-31',
    'open'
);

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000e832', 'e832', 'Late', 'Fee');

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-00000000e833', 'e833', 'Late booth');

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e834',
        '01900000-0000-7000-8000-000000000001',
        'late-fee:proof',
        '2097-04-01',
        '2097-04-01 12:00:00+00',
        '01900000-0000-7000-8000-00000000e831',
        'USD',
        'late_fee',
        '01900000-0000-7000-8000-00000000e833',
        'Bad late fee',
        250,
        250
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000e834',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000014',
        250,
        0,
        'vendor',
        '01900000-0000-7000-8000-00000000e832'
    );
    RAISE EXCEPTION 'a late fee reduced vendor payable';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a control account line must come from a listed source type%'
           AND SQLERRM NOT LIKE '%a control account line must come from a sale, rent charge, rent payment, or payout%' THEN
            RAISE;
        END IF;
END $$;

-- Two stays at $60.00: Late's April goes unpaid, Paid's April is paid on time.
INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-00000000e8a1',
    '01900000-0000-7000-8000-00000000e833',
    '01900000-0000-7000-8000-00000000e832',
    '2097-01-01'
);

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000e8b2', 'e8b2', 'Paid', 'Ontime');

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-00000000e8b3', 'e8b3', 'Paid booth');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-00000000e8a2',
    '01900000-0000-7000-8000-00000000e8b3',
    '01900000-0000-7000-8000-00000000e8b2',
    '2097-01-01'
);

INSERT INTO assignment_rent (assignment_id, amount_minor)
VALUES
    ('01900000-0000-7000-8000-00000000e8a1', 6000),
    ('01900000-0000-7000-8000-00000000e8a2', 6000);

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-00000000e8c0',
    '01900000-0000-7000-8000-000000000d10',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

-- A hand-built fee: the assert behind the posting function must refuse each bad shape.
CREATE FUNCTION pg_temp.hand_fee(stay uuid, suffix text, ordinal integer, on_day date, amount bigint) RETURNS void
    LANGUAGE plpgsql
    AS $fn$
DECLARE
    posted uuid := uuidv7();
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        posted, '01900000-0000-7000-8000-000000000001',
        'late-fee:' || stay::text || ':' || suffix || ':' || ordinal::text,
        on_day, on_day::timestamp AT TIME ZONE 'UTC', '01900000-0000-7000-8000-00000000e831', 'USD',
        'late_fee', stay::text || ':' || suffix || ':' || ordinal::text, 'Hand-built late fee', amount, amount
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    )
    SELECT posted, '01900000-0000-7000-8000-000000000001', 1, role.account_id, amount, 0, 'assignment', stay::text
    FROM chart_roles AS role WHERE role.role = 'booth_rent_receivable';
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    SELECT posted, '01900000-0000-7000-8000-000000000001', 2, role.account_id, 0, amount
    FROM chart_roles AS role WHERE role.role = 'late_fee_income';
    SET CONSTRAINTS ALL IMMEDIATE;
END;
$fn$;

CREATE FUNCTION pg_temp.refused(attempt text, needle text) RETURNS void
    LANGUAGE plpgsql
    AS $fn$
BEGIN
    BEGIN
        EXECUTE attempt;
    EXCEPTION
        WHEN SQLSTATE '23514' OR SQLSTATE '22023' THEN
            IF SQLERRM NOT LIKE '%' || needle || '%' THEN
                RAISE EXCEPTION 'refused for the wrong reason: % (wanted %)', SQLERRM, needle;
            END IF;
            RETURN;
    END;
    RAISE EXCEPTION 'accepted: %', attempt;
END;
$fn$;

-- Off by default: nothing is due, and a hand-built fee is refused.
DO $$
BEGIN
    PERFORM stall_post_rent_charge('01900000-0000-7000-8000-00000000e8a1', '2097-04-01', '2097-04-01');
    PERFORM stall_post_rent_charge('01900000-0000-7000-8000-00000000e8a2', '2097-04-01', '2097-04-01');
    SET CONSTRAINTS ALL IMMEDIATE;
    IF EXISTS (SELECT 1 FROM stall_late_fees_due('2097-04-30')) THEN
        RAISE EXCEPTION 'a late fee was due with late fees turned off';
    END IF;
    PERFORM pg_temp.refused(
        $q$SELECT pg_temp.hand_fee('01900000-0000-7000-8000-00000000e8a1', '2097-04', 1, '2097-04-01', 1000)$q$,
        'late fees are turned off');
END $$;

UPDATE mall
SET late_fees_enabled = true,
    late_fee_grace_days = 5,
    late_fee_first_minor = 1000,
    late_fee_next_minor = 500,
    late_fee_effective_on = '2097-01-01';

-- Paid pays April on Apr 3, inside grace.
DO $$
DECLARE
    receipt uuid;
BEGIN
    receipt := stall_post_rent_payment('01900000-0000-7000-8000-00000000e8b2', 0, 6000, 0, '2097-04-03');
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-00000000e8c0', receipt);
    SET CONSTRAINTS ALL IMMEDIATE;
END $$;

-- Nothing is due inside grace. After grace, a fee every five days through the
-- end of the period: $10.00 first, then $5.00. Paid's stay takes none.
DO $$
DECLARE
    listed text;
BEGIN
    IF EXISTS (SELECT 1 FROM stall_late_fees_due('2097-04-05')) THEN
        RAISE EXCEPTION 'a late fee was due inside grace';
    END IF;
    SELECT string_agg(due.ordinal || '@' || due.fee_date || '=' || due.amount_minor, ' ' ORDER BY due.ordinal)
    INTO listed
    FROM stall_late_fees_due('2097-04-20') AS due
    WHERE due.assignment_id = '01900000-0000-7000-8000-00000000e8a1'
      AND due.period_start = '2097-04-01';
    IF listed IS DISTINCT FROM '1@2097-04-06=1000 2@2097-04-11=500 3@2097-04-16=500' THEN
        RAISE EXCEPTION 'the fees due by Apr 20 were %', listed;
    END IF;
    IF EXISTS (
        SELECT 1 FROM stall_late_fees_due('2097-05-31')
        WHERE assignment_id = '01900000-0000-7000-8000-00000000e8a2'
    ) THEN
        RAISE EXCEPTION 'a stay with its rent paid took a late fee';
    END IF;
    IF (SELECT count(*) FROM stall_late_fees_due('2097-05-31')) <> 5 THEN
        RAISE EXCEPTION 'fees ran past the end of the period';
    END IF;
END $$;

-- Posting is once: running it again the same day posts nothing.
DO $$
BEGIN
    IF stall_post_late_fees('2097-04-20') <> 3 THEN
        RAISE EXCEPTION 'the Apr 20 run did not post three fees';
    END IF;
    SET CONSTRAINTS ALL IMMEDIATE;
    IF stall_post_late_fees('2097-04-20') <> 0 THEN
        RAISE EXCEPTION 'a second run posted a fee twice';
    END IF;
    IF stall_assignment_open_rent('01900000-0000-7000-8000-00000000e8a1') <> 8000 THEN
        RAISE EXCEPTION 'April and its fees do not total $80.00';
    END IF;
    IF stall_assignment_open_rent('01900000-0000-7000-8000-00000000e8a2') <> 0 THEN
        RAISE EXCEPTION 'the paid stay took a fee';
    END IF;
END $$;

-- What the assert refuses, each for one reason.
DO $$
BEGIN
    PERFORM pg_temp.refused(
        $q$SELECT pg_temp.hand_fee('01900000-0000-7000-8000-00000000e8a1', '2097-04', 4, '2097-04-21', 1000)$q$,
        'for the stored amount');
    PERFORM pg_temp.refused(
        $q$SELECT pg_temp.hand_fee('01900000-0000-7000-8000-00000000e8a1', '2097-04', 4, '2097-04-22', 500)$q$,
        'on the day grace makes it due');
    PERFORM pg_temp.refused(
        $q$SELECT pg_temp.hand_fee('01900000-0000-7000-8000-00000000e8a1', '2097-04', 5, '2097-04-26', 500)$q$,
        'posted in order');
    PERFORM pg_temp.refused(
        $q$SELECT pg_temp.hand_fee('01900000-0000-7000-8000-00000000e8a1', '2097-04', 6, '2097-05-01', 500)$q$,
        'on the day grace makes it due');
    PERFORM pg_temp.refused(
        $q$SELECT pg_temp.hand_fee('01900000-0000-7000-8000-00000000e8a1', '2097-05', 1, '2097-05-06', 1000)$q$,
        'needs that period''s rent charge');
    PERFORM pg_temp.refused(
        $q$SELECT pg_temp.hand_fee('01900000-0000-7000-8000-00000000e8a2', '2097-04', 1, '2097-04-06', 1000)$q$,
        'only for rent still unpaid');
    UPDATE mall SET late_fee_effective_on = '2097-05-01';
    PERFORM pg_temp.refused(
        $q$SELECT pg_temp.hand_fee('01900000-0000-7000-8000-00000000e8a1', '2097-04', 4, '2097-04-21', 500)$q$,
        'took effect');
    IF EXISTS (SELECT 1 FROM stall_late_fees_due('2097-04-30')) THEN
        RAISE EXCEPTION 'a fee was due for a period before the fee rule took effect';
    END IF;
    UPDATE mall SET late_fee_effective_on = '2097-01-01';
END $$;

-- Waiving fee 2 reverses it on that stay, once. The nightly run never posts it
-- again, and fee 3 onward still post in order.
DO $$
DECLARE
    second uuid;
    waived uuid;
BEGIN
    SELECT id INTO second FROM journals
    WHERE posting_key = 'late-fee:01900000-0000-7000-8000-00000000e8a1:2097-04:2';
    waived := stall_waive_late_fee(second, '2097-04-22', 'Called ahead');
    SET CONSTRAINTS ALL IMMEDIATE;
    IF stall_assignment_open_rent('01900000-0000-7000-8000-00000000e8a1') <> 7500 THEN
        RAISE EXCEPTION 'the waive did not take $5.00 off that stay';
    END IF;
    IF (SELECT description FROM journals WHERE id = waived) <> 'Late fee waived. Called ahead' THEN
        RAISE EXCEPTION 'the waive did not keep its note';
    END IF;
    PERFORM pg_temp.refused(format('SELECT stall_waive_late_fee(%L, %L, %L)', second, '2097-04-23', ''), 'already waived');
    PERFORM pg_temp.refused(
        format('SELECT stall_waive_late_fee(%L, %L, %L)',
            (SELECT id FROM journals WHERE posting_key = 'rent-charge:01900000-0000-7000-8000-00000000e8a1:2097-04'),
            '2097-04-23', ''),
        'Choose a late fee.');
    PERFORM pg_temp.refused(format('SELECT stall_waive_late_fee(%L, %L, %L)', second, '2097-04-23', repeat('x', 501)), 'shorter waive note');
    IF EXISTS (
        SELECT 1 FROM stall_late_fees_due('2097-04-30')
        WHERE assignment_id = '01900000-0000-7000-8000-00000000e8a1' AND ordinal = 2
    ) THEN
        RAISE EXCEPTION 'a waived fee came due again';
    END IF;
    PERFORM pg_temp.refused(
        $q$SELECT pg_temp.hand_fee('01900000-0000-7000-8000-00000000e8a1', '2097-04', 2, '2097-04-11', 500)$q$,
        'a waived late fee is not charged again');
    IF stall_post_late_fees('2097-04-30') <> 2 THEN
        RAISE EXCEPTION 'fees 4 and 5 did not post after fee 2 was waived';
    END IF;
    SET CONSTRAINTS ALL IMMEDIATE;
    IF stall_assignment_open_rent('01900000-0000-7000-8000-00000000e8a1') <> 8500 THEN
        RAISE EXCEPTION 'April and its live fees do not total $85.00';
    END IF;
    IF EXISTS (SELECT 1 FROM stall_late_fees_due('2097-05-31')) THEN
        RAISE EXCEPTION 'a fee is still due after every April fee was decided';
    END IF;
END $$;

-- A malformed key is refused.
DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e8d1', '01900000-0000-7000-8000-000000000001', 'late-fee:proof',
        '2097-04-06', '2097-04-06 12:00:00+00', '01900000-0000-7000-8000-00000000e831', 'USD',
        'late_fee', 'proof', 'Late fee with no stay', 1000, 1000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES
        ('01900000-0000-7000-8000-00000000e8d1', '01900000-0000-7000-8000-000000000001', 1,
         '01900000-0000-7000-8000-000000000015', 1000, 0, 'assignment', '01900000-0000-7000-8000-00000000e8a1');
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    SELECT '01900000-0000-7000-8000-00000000e8d1', '01900000-0000-7000-8000-000000000001', 2, role.account_id, 0, 1000
    FROM chart_roles AS role WHERE role.role = 'late_fee_income';
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a late fee with a malformed key was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%one booth assignment, one rent period and one fee number%' THEN
            RAISE;
        END IF;
END $$;

ROLLBACK;
