-- Assigning a booth takes its first rent at the desk (D14). A stay that starts
-- in the current rent period is charged and paid at once; one that starts
-- later is paid ahead and held until its period arrives, then applied. Ending
-- a booth posts nothing. A reservation is cancelled before it starts with a
-- refund or kept by the store. Today is Nov 15, 2026; cycle day 1.
-- One transaction, then ROLLBACK.

BEGIN;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000a021',
    '01900000-0000-7000-8000-000000000001',
    '2026-01-01',
    '2027-12-31',
    'open'
);

INSERT INTO booths (id, code, name)
VALUES
    ('01900000-0000-7000-8000-00000000a201', 'A1', 'Booth A1'),
    ('01900000-0000-7000-8000-00000000a202', 'B2', 'Booth B2'),
    ('01900000-0000-7000-8000-00000000a203', 'C3', 'Booth C3'),
    ('01900000-0000-7000-8000-00000000a204', 'D4', 'Booth D4'),
    ('01900000-0000-7000-8000-00000000a205', 'E5', 'Booth E5'),
    ('01900000-0000-7000-8000-00000000a206', 'F6', 'Booth F6');

INSERT INTO booth_rent_rates (booth_id, amount_minor)
VALUES
    ('01900000-0000-7000-8000-00000000a201', 5000),
    ('01900000-0000-7000-8000-00000000a202', 3100),
    ('01900000-0000-7000-8000-00000000a203', 5000),
    ('01900000-0000-7000-8000-00000000a204', 6200),
    ('01900000-0000-7000-8000-00000000a205', 4000),
    ('01900000-0000-7000-8000-00000000a206', 4000);

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000a101', 'mary', 'Mary', 'Smith');
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000a102', 'joe', 'Joe', 'Brown');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-00000000ad22',
    '01900000-0000-7000-8000-000000000d10',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

CREATE FUNCTION pg_temp.refused(attempt text, needle text) RETURNS void
    LANGUAGE plpgsql
    AS $fn$
BEGIN
    BEGIN
        EXECUTE attempt;
    EXCEPTION
        WHEN SQLSTATE '23514' OR SQLSTATE '22023' OR SQLSTATE '23505' OR SQLSTATE '23P01' THEN
            IF SQLERRM NOT LIKE '%' || needle || '%' THEN
                RAISE EXCEPTION 'refused for the wrong reason: % (wanted %)', SQLERRM, needle;
            END IF;
            RETURN;
    END;
    RAISE EXCEPTION 'accepted: %', attempt;
END;
$fn$;

-- Money handed over at the desk names the shift, the way the register does it.
CREATE FUNCTION pg_temp.at_desk(posted uuid) RETURNS uuid
    LANGUAGE plpgsql
    AS $fn$
BEGIN
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-00000000ad22', posted);
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    RETURN posted;
END;
$fn$;

CREATE FUNCTION pg_temp.stay_of(booth uuid) RETURNS uuid
    LANGUAGE sql
    AS $fn$
    SELECT id FROM booth_assignments WHERE booth_id = booth AND cancelled_on IS NULL ORDER BY starts_on DESC LIMIT 1
$fn$;

-- Mary takes A1 from Nov 1, the first day of the current period: a full
-- month, charged and paid now. Nothing is left owing.
DO $$
DECLARE
    receipt uuid;
    stay uuid;
BEGIN
    receipt := pg_temp.at_desk(stall_assign_booth(
        '01900000-0000-7000-8000-00000000a201', '01900000-0000-7000-8000-00000000a101',
        '2026-11-01', NULL, NULL, 0, 5000, 0, '2026-11-15'));
    stay := pg_temp.stay_of('01900000-0000-7000-8000-00000000a201');
    IF stall_assignment_open_rent(stay) <> 0
       OR stall_live_journal('rent-charge:' || stay || ':2026-11') IS NULL
       OR (SELECT source_type FROM journals WHERE id = receipt) <> 'rent_payment'
       OR (SELECT amount_minor FROM assignment_rent WHERE assignment_id = stay) <> 5000 THEN
        RAISE EXCEPTION 'a booth from the first of the month was not charged and paid in full';
    END IF;
END $$;

-- What the desk shows before taking payment is what assigning takes.
DO $$
BEGIN
    IF (SELECT due_minor || ' ' || first_period || ' ' || paid_ahead FROM stall_booth_due('01900000-0000-7000-8000-00000000a202', '2026-11-17', NULL, '2026-11-15'))
           <> '1446 2026-11-01 false'
       OR (SELECT due_minor || ' ' || paid_ahead FROM stall_booth_due('01900000-0000-7000-8000-00000000a203', '2026-12-01', NULL, '2026-11-15')) <> '5000 true'
       OR (SELECT due_minor FROM stall_booth_due('01900000-0000-7000-8000-00000000a203', '2024-02-15', 2900, '2024-02-10')) <> 1500
       OR (SELECT due_minor FROM stall_booth_due('01900000-0000-7000-8000-00000000a203', '2025-02-15', 2800, '2025-02-10')) <> 1400
       OR EXISTS (SELECT 1 FROM stall_booth_due('01900000-0000-7000-8000-00000000a999', '2026-12-01', NULL, '2026-11-15')) THEN
        RAISE EXCEPTION 'the amount due before assigning is not the quote';
    END IF;
END $$;

-- The refusals, each in plain words, and none of them leaves a stay behind.
DO $$
DECLARE
    stays bigint := (SELECT count(*) FROM booth_assignments);
BEGIN
    PERFORM pg_temp.refused($q$SELECT stall_assign_booth('01900000-0000-7000-8000-00000000a202', '01900000-0000-7000-8000-00000000a102', NULL, NULL, NULL, 0, 3100, 0, '2026-11-15')$q$,
        'Choose the day the booth starts.');
    PERFORM pg_temp.refused($q$SELECT stall_assign_booth('01900000-0000-7000-8000-00000000a202', '01900000-0000-7000-8000-00000000a102', '2026-10-31', NULL, NULL, 0, 3100, 0, '2026-11-15')$q$,
        'The start date can''t be before 11-01-2026.');
    PERFORM pg_temp.refused($q$SELECT stall_assign_booth('01900000-0000-7000-8000-00000000a202', '01900000-0000-7000-8000-00000000a102', '2026-11-20', '2026-11-19', NULL, 0, 3100, 0, '2026-11-15')$q$,
        'The end date can''t be before the start date.');
    PERFORM pg_temp.refused($q$SELECT stall_assign_booth('01900000-0000-7000-8000-00000000a202', '01900000-0000-7000-8000-00000000a102', '2026-11-17', NULL, NULL, 0, 3100, 0, '2026-11-15')$q$,
        'Take $14.46 to assign this booth.');
    PERFORM pg_temp.refused($q$SELECT stall_assign_booth('01900000-0000-7000-8000-00000000a201', '01900000-0000-7000-8000-00000000a102', '2026-11-20', NULL, NULL, 0, 5000, 0, '2026-11-15')$q$,
        'That booth is already taken on those dates.');
    PERFORM pg_temp.refused($q$SELECT stall_assign_booth('01900000-0000-7000-8000-00000000a999', '01900000-0000-7000-8000-00000000a102', '2026-11-20', NULL, NULL, 0, 100, 0, '2026-11-15')$q$,
        'Choose a booth.');
    PERFORM pg_temp.refused($q$SELECT stall_assign_booth('01900000-0000-7000-8000-00000000a202', '01900000-0000-7000-8000-00000000a102', '2026-11-17', NULL, NULL, -1, 1447, 0, '2026-11-15')$q$,
        'Enter amounts of zero or more.');
    IF (SELECT count(*) FROM booth_assignments) <> stays THEN
        RAISE EXCEPTION 'a refused assignment left a stay behind';
    END IF;
END $$;

-- Joe takes B2 from Nov 17: 14 of November's 30 days of $31.00, rounded down
-- to $14.46, paid now in cash. Cash without the shift is refused.
DO $$
BEGIN
    BEGIN
        PERFORM stall_assign_booth('01900000-0000-7000-8000-00000000a202', '01900000-0000-7000-8000-00000000a102',
            '2026-11-17', NULL, NULL, 1446, 0, 0, '2026-11-15');
        SET CONSTRAINTS ALL IMMEDIATE;
        RAISE EXCEPTION 'cash was taken with no shift';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM NOT LIKE '%a drawer journal needs an open register%' THEN
                RAISE;
            END IF;
    END;
END $$;

SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    stay uuid;
BEGIN
    PERFORM pg_temp.at_desk(stall_assign_booth('01900000-0000-7000-8000-00000000a202', '01900000-0000-7000-8000-00000000a102',
        '2026-11-17', NULL, NULL, 1446, 0, 0, '2026-11-15'));
    stay := pg_temp.stay_of('01900000-0000-7000-8000-00000000a202');
    IF stall_assignment_open_rent(stay) <> 0
       OR (SELECT debit_total_minor FROM journals WHERE posting_key = 'rent-charge:' || stay || ':2026-11') <> 1446 THEN
        RAISE EXCEPTION 'the move-in from the 17th was not 14/30 of the month, paid now';
    END IF;
END $$;

-- Mary reserves C3 from Dec 1 and pays $50.00 by card on Nov 15. It is held
-- for her, not income and not rent yet, and it keeps her Active.
DO $$
DECLARE
    stay uuid;
    reasons text;
BEGIN
    PERFORM pg_temp.at_desk(stall_assign_booth('01900000-0000-7000-8000-00000000a203', '01900000-0000-7000-8000-00000000a101',
        '2026-12-01', NULL, NULL, 0, 5000, 0, '2026-11-15'));
    stay := pg_temp.stay_of('01900000-0000-7000-8000-00000000a203');
    IF stall_rent_paid_ahead(stay) <> 5000 OR stall_assignment_open_rent(stay) <> 0
       OR stall_vendor_rent_paid_ahead('01900000-0000-7000-8000-00000000a101') <> 5000 THEN
        RAISE EXCEPTION 'the December reservation is not held as paid ahead';
    END IF;
    SELECT string_agg(reason, ',' ORDER BY reason) INTO reasons FROM stall_vendor_open_reasons('01900000-0000-7000-8000-00000000a101');
    IF reasons NOT LIKE '%paid_ahead%' THEN
        RAISE EXCEPTION 'money paid ahead does not keep the vendor open: %', reasons;
    END IF;
    PERFORM pg_temp.refused($q$SELECT stall_charge_rent((SELECT id FROM booth_assignments WHERE booth_id = '01900000-0000-7000-8000-00000000a203'), '2026-12-01')$q$,
        'That month has not started yet.');
END $$;

-- The nightly run does nothing before Dec 1. On Dec 1 it charges December and
-- pays it from the money held, once.
DO $$
DECLARE
    stay uuid := pg_temp.stay_of('01900000-0000-7000-8000-00000000a203');
BEGIN
    IF stall_recognize_rent_paid_in_advance('2026-11-30') <> 0 THEN
        RAISE EXCEPTION 'money paid ahead was applied before its month';
    END IF;
    IF stall_recognize_rent_paid_in_advance('2026-12-01') <> 1 THEN
        RAISE EXCEPTION 'the December reservation was not applied on Dec 1';
    END IF;
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF stall_rent_paid_ahead(stay) <> 0 OR stall_assignment_open_rent(stay) <> 0
       OR stall_live_journal('rent-charge:' || stay || ':2026-12') IS NULL
       OR stall_live_journal('rent-advance-applied:' || stay) IS NULL THEN
        RAISE EXCEPTION 'December was not charged and paid from the money held';
    END IF;
    IF stall_recognize_rent_paid_in_advance('2026-12-02') <> 0 THEN
        RAISE EXCEPTION 'a reservation was applied twice';
    END IF;
    IF (SELECT paid_by || '/' || how || '/' || months || '/' || amount_minor
        FROM stall_rent_payments('2026-12-01', '2026-12-31', '01900000-0000-7000-8000-00000000a101')) <> 'paid_ahead/Paid ahead/Dec 2026/5000' THEN
        RAISE EXCEPTION 'the applied reservation is not on the rent payments list as paid ahead';
    END IF;
    IF (SELECT balance_minor FROM stall_vendor_register('01900000-0000-7000-8000-00000000a101', '2026-11-01', '2026-11-30')
        WHERE row_kind = 'paid_ahead') <> 5000
       OR (SELECT balance_minor FROM stall_vendor_register('01900000-0000-7000-8000-00000000a101', '2026-11-01', '2026-11-30')
        WHERE row_kind = 'closing') <> 5000
       OR (SELECT balance_minor FROM stall_vendor_register('01900000-0000-7000-8000-00000000a101', '2026-11-01', '2026-12-31')
        WHERE row_kind = 'closing') <> 0 THEN
        RAISE EXCEPTION 'the vendor register does not hold the reservation for her in November and clear it in December';
    END IF;
END $$;

-- Charging the first month by hand applies the money held too, so it can
-- never be collected twice. D4 from Jan 15 is $62.00 x 17/31 = $34.00.
DO $$
DECLARE
    stay uuid;
BEGIN
    PERFORM pg_temp.at_desk(stall_assign_booth('01900000-0000-7000-8000-00000000a204', '01900000-0000-7000-8000-00000000a102',
        '2027-01-15', NULL, NULL, 3400, 0, 0, '2026-11-15'));
    stay := pg_temp.stay_of('01900000-0000-7000-8000-00000000a204');
    PERFORM stall_post_rent_charge(stay, '2027-01-01', '2027-01-15');
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF stall_rent_paid_ahead(stay) <> 0 OR stall_assignment_open_rent(stay) <> 0 THEN
        RAISE EXCEPTION 'a first month charged by hand did not take the money held';
    END IF;
END $$;

-- Ending a booth sets its last day and posts nothing. Twice, or before it
-- started, is refused.
DO $$
DECLARE
    journals_before bigint := (SELECT count(*) FROM journals);
    stay uuid := pg_temp.stay_of('01900000-0000-7000-8000-00000000a201');
BEGIN
    PERFORM stall_end_booth(stay, '2026-11-20');
    IF (SELECT ends_on FROM booth_assignments WHERE id = stay) <> '2026-11-20'
       OR (SELECT count(*) FROM journals) <> journals_before THEN
        RAISE EXCEPTION 'ending a booth did not just set its last day';
    END IF;
    PERFORM pg_temp.refused(format('SELECT stall_end_booth(%L, %L)', stay, '2026-11-25'), 'That booth has already ended.');
    PERFORM pg_temp.refused(format('SELECT stall_end_booth(%L, %L)', pg_temp.stay_of('01900000-0000-7000-8000-00000000a202'), '2026-11-16'),
        'The end date can''t be before the start date.');
    PERFORM pg_temp.refused(format('SELECT stall_end_booth(%L, NULL)', pg_temp.stay_of('01900000-0000-7000-8000-00000000a202')),
        'Choose the day the booth ends.');
END $$;

-- Mary's move: A1 ended Nov 20 keeps its full November; she adds E5 from Nov 21
-- and pays 10 of 30 days of $40.00, $13.33, when it is added. Nothing carries over.
DO $$
DECLARE
    stay uuid;
BEGIN
    PERFORM pg_temp.at_desk(stall_assign_booth('01900000-0000-7000-8000-00000000a205', '01900000-0000-7000-8000-00000000a101',
        '2026-11-21', NULL, NULL, 1333, 0, 0, '2026-11-15'));
    stay := pg_temp.stay_of('01900000-0000-7000-8000-00000000a205');
    IF (SELECT debit_total_minor FROM journals WHERE posting_key = 'rent-charge:' || pg_temp.stay_of('01900000-0000-7000-8000-00000000a201') || ':2026-11') <> 5000
       OR (SELECT debit_total_minor FROM journals WHERE posting_key = 'rent-charge:' || stay || ':2026-11') <> 1333 THEN
        RAISE EXCEPTION 'the move did not keep the old booth''s month and prorate the new one';
    END IF;
END $$;

-- Cancelling: a reason is required; a refund gives back exactly what was held;
-- the booth is free again and the stay is never charged. Keeping it moves the
-- money to forfeited income. A stay that has started cannot be cancelled.
DO $$
DECLARE
    refunded uuid;
    kept uuid;
    rival uuid;
BEGIN
    PERFORM pg_temp.at_desk(stall_assign_booth('01900000-0000-7000-8000-00000000a206', '01900000-0000-7000-8000-00000000a102',
        '2026-12-10', NULL, NULL, 2838, 0, 0, '2026-11-15'));
    refunded := pg_temp.stay_of('01900000-0000-7000-8000-00000000a206');
    PERFORM pg_temp.refused(format('SELECT stall_cancel_reservation(%L, %L, 2838, 0, 0, %L, %L)', refunded, 'refund', '2026-11-16', ' '),
        'Say why the reservation is cancelled.');
    PERFORM pg_temp.refused(format('SELECT stall_cancel_reservation(%L, %L, 2000, 0, 0, %L, %L)', refunded, 'refund', '2026-11-16', 'Moving away'),
        'Give back $28.38.');
    PERFORM pg_temp.refused(format('SELECT stall_cancel_reservation(%L, %L, 0, 0, 0, %L, %L)', refunded, 'gift', '2026-11-16', 'Moving away'),
        'Choose to refund or keep the reservation.');
    PERFORM pg_temp.at_desk(stall_cancel_reservation(refunded, 'refund', 2838, 0, 0, '2026-11-16', 'Moving away'));
    IF stall_rent_paid_ahead(refunded) <> 0 OR (SELECT cancelled_on FROM booth_assignments WHERE id = refunded) <> '2026-11-16' THEN
        RAISE EXCEPTION 'the refund did not give back the money held and cancel the stay';
    END IF;
    IF EXISTS (SELECT 1 FROM stall_rent_book('2026-12-01', '2026-12-31') WHERE assignment_id = refunded) THEN
        RAISE EXCEPTION 'a cancelled reservation is still on the rent book';
    END IF;
    PERFORM pg_temp.refused(format('SELECT stall_rent_quote(%L, %L)', refunded, '2026-12-01'), 'a cancelled reservation is not charged');
    PERFORM pg_temp.refused(format('SELECT stall_cancel_reservation(%L, %L, 2838, 0, 0, %L, %L)', refunded, 'refund', '2026-11-16', 'Again'),
        'There is no reservation to cancel.');
    PERFORM pg_temp.at_desk(stall_assign_booth('01900000-0000-7000-8000-00000000a206', '01900000-0000-7000-8000-00000000a101',
        '2026-12-10', NULL, NULL, 0, 2838, 0, '2026-11-16'));
    kept := pg_temp.stay_of('01900000-0000-7000-8000-00000000a206');
    PERFORM stall_cancel_reservation(kept, 'keep', 0, 0, 0, '2026-11-17', 'No show');
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF stall_rent_paid_ahead(kept) <> 0
       OR NOT EXISTS (
           SELECT 1 FROM journal_lines AS line
           JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'forfeited_reservation_income'
           WHERE line.journal_id = stall_live_journal('rent-advance-keep:' || kept) AND line.credit_minor = 2838
       ) THEN
        RAISE EXCEPTION 'keeping the reservation did not move it to forfeited income';
    END IF;
    rival := pg_temp.stay_of('01900000-0000-7000-8000-00000000a205');
    PERFORM pg_temp.refused(format('SELECT stall_cancel_reservation(%L, %L, 0, 0, 0, %L, %L)', rival, 'keep', '2026-11-22', 'Late'),
        'That booth has already started. End it instead.');
    PERFORM pg_temp.refused(format('UPDATE booth_assignments SET cancelled_on = %L WHERE id = %L', '2026-11-22', rival),
        'only a reservation that has not started can be cancelled, once');
END $$;

-- Hand-built journals of money paid ahead are refused unless they are one of
-- the four shapes for the database's amount.
DO $$
DECLARE
    reserved uuid;
BEGIN
    INSERT INTO booth_assignments (booth_id, vendor_id, starts_on)
    VALUES ('01900000-0000-7000-8000-00000000a201', '01900000-0000-7000-8000-00000000a102', '2027-03-01')
    RETURNING id INTO reserved;
    INSERT INTO assignment_rent (assignment_id, amount_minor) VALUES (reserved, 5000);
    BEGIN
        PERFORM pg_temp.at_desk(stall_post_lines('rent-advance:' || reserved, '2026-11-15', 'rent_advance', reserved::text, 'Short',
            jsonb_build_array(stall_line('cash', 100, 0), stall_line('rent_paid_in_advance', 0, 100, 'assignment', reserved::text))));
        RAISE EXCEPTION 'a short reservation was accepted';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM NOT LIKE '%rent paid ahead is the stay''s first rent, taken before its period%' THEN
                RAISE;
            END IF;
    END;
END $$;

SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    reserved uuid := (SELECT id FROM booth_assignments WHERE booth_id = '01900000-0000-7000-8000-00000000a201' AND starts_on = '2027-03-01');
BEGIN
    BEGIN
        PERFORM stall_post_lines('rent-advance-keep:' || reserved, '2026-11-15', 'rent_advance', reserved::text, 'Keep it',
            jsonb_build_array(stall_line('rent_paid_in_advance', 100, 0, 'assignment', reserved::text),
                              stall_line('forfeited_reservation_income', 0, 100)));
        SET CONSTRAINTS ALL IMMEDIATE;
        RAISE EXCEPTION 'money was kept from a reservation that was never paid or cancelled';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM NOT LIKE '%a cancelled reservation gives back or keeps what was paid ahead%' THEN
                RAISE;
            END IF;
    END;
END $$;

ROLLBACK;
