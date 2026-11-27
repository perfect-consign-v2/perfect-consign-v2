-- The rent book and the desk charge. One row for each stay in each rent period
-- that touches the days asked for, so Mary, who leaves Booth 12 mid-month, keeps
-- her own row and charge beside Joe, who moves in. Empty booths with an asking
-- rate are listed with nothing to charge. The database dates the charge and
-- refuses a month that has not started. Then booth extras: one stay, one day,
-- one reference. Later the rent cycle moves to the 15th: a calendar month then
-- touches two rent periods, and late fees follow those periods.
-- One transaction, then ROLLBACK.

BEGIN;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES
    ('01900000-0000-7000-8000-00000000b021', '01900000-0000-7000-8000-000000000001', '2026-01-01', '2026-12-31', 'open'),
    ('01900000-0000-7000-8000-00000000b022', '01900000-0000-7000-8000-000000000001', '2027-01-01', '2027-12-31', 'open'),
    ('01900000-0000-7000-8000-00000000b023', '01900000-0000-7000-8000-000000000001', '2099-01-01', '2099-12-31', 'open');

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000b101', 'mary', 'Mary', 'Smith');
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000b102', 'joe', 'Joe', 'Brown');

INSERT INTO booths (id, code, name)
VALUES
    ('01900000-0000-7000-8000-00000000b212', '12', 'Booth 12'),
    ('01900000-0000-7000-8000-00000000b220', '20', 'Booth 20');

INSERT INTO booth_rent_rates (booth_id, amount_minor)
VALUES ('01900000-0000-7000-8000-00000000b220', 4500);

-- Mary holds Booth 12 Jan 1 to Mar 17, 2026; Joe from Mar 18. A stay in 2099
-- has not started.
INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on, ends_on)
VALUES
    ('01900000-0000-7000-8000-00000000b312', '01900000-0000-7000-8000-00000000b212',
     '01900000-0000-7000-8000-00000000b101', '2026-01-01', '2026-03-17'),
    ('01900000-0000-7000-8000-00000000b313', '01900000-0000-7000-8000-00000000b212',
     '01900000-0000-7000-8000-00000000b102', '2026-03-18', '2098-12-31'),
    ('01900000-0000-7000-8000-00000000b314', '01900000-0000-7000-8000-00000000b212',
     '01900000-0000-7000-8000-00000000b101', '2099-01-01', NULL);

INSERT INTO assignment_rent (assignment_id, amount_minor)
VALUES
    ('01900000-0000-7000-8000-00000000b312', 6000),
    ('01900000-0000-7000-8000-00000000b313', 6000),
    ('01900000-0000-7000-8000-00000000b314', 6000);

CREATE FUNCTION pg_temp.refused(attempt text, needle text) RETURNS void
    LANGUAGE plpgsql
    AS $fn$
BEGIN
    BEGIN
        EXECUTE attempt;
    EXCEPTION
        WHEN SQLSTATE '23514' OR SQLSTATE '22023' OR SQLSTATE '23505' THEN
            IF SQLERRM NOT LIKE '%' || needle || '%' THEN
                RAISE EXCEPTION 'refused for the wrong reason: % (wanted %)', SQLERRM, needle;
            END IF;
            RETURN;
    END;
    RAISE EXCEPTION 'accepted: %', attempt;
END;
$fn$;

-- March 2026: one row for Mary, one for Joe, and the empty Booth 20.
DO $$
DECLARE
    listed text;
BEGIN
    SELECT string_agg(
        book.booth_code || '/' || COALESCE(book.vendor_name, 'empty') || '/' || book.period_suffix
            || '/' || COALESCE(book.quote_minor::text, '-') || '/' || book.chargeable::text
            || '/' || COALESCE(to_char(book.charge_date, 'YYYY-MM-DD'), '-'),
        ' ' ORDER BY book.booth_code, book.starts_on NULLS FIRST
    )
    INTO listed
    FROM stall_rent_book('2026-03-01', '2026-03-31') AS book;
    -- Mary's last month is full; Joe's first is 14 of 31 days of $60.00.
    IF listed IS DISTINCT FROM
        '12/Mary Smith/2026-03/6000/true/2026-03-01 12/Joe Brown/2026-03/2709/true/2026-03-18 20/empty/2026-03/4500/false/-' THEN
        RAISE EXCEPTION 'the March rent book read: %', listed;
    END IF;
    IF EXISTS (
        SELECT 1 FROM stall_rent_book('2026-03-01', '2026-03-31')
        WHERE assignment_id = '01900000-0000-7000-8000-00000000b314'
    ) THEN
        RAISE EXCEPTION 'a stay that starts in 2099 is on the March 2026 book';
    END IF;
END $$;

-- The desk charge: dated by the database, on that stay, once.
DO $$
DECLARE
    mary uuid;
    again uuid;
BEGIN
    mary := stall_charge_rent('01900000-0000-7000-8000-00000000b312', '2026-03-01');
    PERFORM stall_charge_rent('01900000-0000-7000-8000-00000000b313', '2026-03-01');
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF (SELECT posting_date FROM journals WHERE id = mary) <> '2026-03-01' THEN
        RAISE EXCEPTION 'a past month was not dated on its first day';
    END IF;
    IF (SELECT posting_date FROM journals
        WHERE posting_key = 'rent-charge:01900000-0000-7000-8000-00000000b313:2026-03') <> '2026-03-18' THEN
        RAISE EXCEPTION 'a move-in month was not dated on the move-in day';
    END IF;
    again := stall_charge_rent('01900000-0000-7000-8000-00000000b312', '2026-03-01');
    IF again IS DISTINCT FROM mary THEN
        RAISE EXCEPTION 'charging Mary''s March twice posted again';
    END IF;
    IF EXISTS (
        SELECT 1 FROM stall_rent_book('2026-03-01', '2026-03-31')
        WHERE assignment_id IS NOT NULL AND (charge_journal IS NULL OR chargeable)
    ) THEN
        RAISE EXCEPTION 'a charged stay is still offered to charge';
    END IF;
    IF (SELECT charge_minor FROM stall_rent_book('2026-03-01', '2026-03-31')
        WHERE assignment_id = '01900000-0000-7000-8000-00000000b313') <> 2709
       OR (SELECT open_minor FROM stall_rent_book('2026-03-01', '2026-03-31')
        WHERE assignment_id = '01900000-0000-7000-8000-00000000b312') <> 6000 THEN
        RAISE EXCEPTION 'the book does not show each stay''s own charge and balance';
    END IF;
    IF stall_rent_charge_date('01900000-0000-7000-8000-00000000b313',
            stall_rent_period_start((clock_timestamp() AT TIME ZONE 'America/Los_Angeles')::date))
       <> (SELECT (clock_timestamp() AT TIME ZONE mall.time_zone)::date FROM mall) THEN
        RAISE EXCEPTION 'the current month is not dated today';
    END IF;
END $$;

-- A voided charge leaves the month to charge again, at the same amount.
DO $$
DECLARE
    charge uuid;
    voided uuid := uuidv7();
BEGIN
    SELECT id INTO charge FROM journals
    WHERE posting_key = 'rent-charge:01900000-0000-7000-8000-00000000b313:2026-03';
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id, currency, source_type,
        source_reference, description, is_reversal, reverses_journal_id, debit_total_minor, credit_total_minor
    )
    SELECT voided, book_id, 'reversal:' || id::text, '2026-03-20', clock_timestamp(), period_id, currency, source_type,
           source_reference, 'Void', true, id, credit_total_minor, debit_total_minor
    FROM journals WHERE id = charge;
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
    SELECT voided, book_id, line_no, account_id, credit_minor, debit_minor, subledger_type, subledger_ref
    FROM journal_lines WHERE journal_id = charge;
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF NOT (SELECT chargeable AND charge_journal IS NULL AND quote_minor = 2709
            FROM stall_rent_book('2026-03-01', '2026-03-31')
            WHERE assignment_id = '01900000-0000-7000-8000-00000000b313') THEN
        RAISE EXCEPTION 'a voided charge did not leave the month to charge again';
    END IF;
    IF stall_charge_rent('01900000-0000-7000-8000-00000000b313', '2026-03-01') = charge THEN
        RAISE EXCEPTION 'charging again returned the voided journal';
    END IF;
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
END $$;

-- A month that has not started is refused, a stay that does not exist is refused,
-- and with booth rentals off nothing can be charged.
DO $$
BEGIN
    PERFORM pg_temp.refused(
        $q$SELECT stall_charge_rent('01900000-0000-7000-8000-00000000b314', '2099-01-01')$q$,
        'That month has not started yet.');
    PERFORM pg_temp.refused(
        $q$SELECT stall_charge_rent('01900000-0000-7000-8000-00000000b212', '2026-03-01')$q$,
        'requires an assignment');
    IF EXISTS (SELECT 1 FROM stall_rent_book('2099-01-01', '2099-01-31') WHERE chargeable) THEN
        RAISE EXCEPTION 'a month that has not started is offered to charge';
    END IF;
END $$;

-- Booth extras: the same words on another stay or another day post; the same
-- stay, day and words is refused as a double click.
DO $$
BEGIN
    PERFORM stall_post_booth_charge('01900000-0000-7000-8000-00000000b313', 1500, 'Electric', '2026-04-02');
    PERFORM stall_post_booth_charge('01900000-0000-7000-8000-00000000b313', 1500, 'Electric', '2026-05-02');
    PERFORM stall_post_booth_charge('01900000-0000-7000-8000-00000000b312', 1500, 'Electric', '2026-04-02');
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    PERFORM pg_temp.refused(
        $q$SELECT stall_post_booth_charge('01900000-0000-7000-8000-00000000b313', 1500, ' electric ', '2026-04-02')$q$,
        'That charge is already posted today.');
    PERFORM pg_temp.refused(
        $q$SELECT stall_post_booth_charge('01900000-0000-7000-8000-00000000b313', 0, 'Sign', '2026-04-02')$q$,
        'Enter the amount.');
    PERFORM pg_temp.refused(
        $q$SELECT stall_post_booth_charge('01900000-0000-7000-8000-00000000b313', 100, '  ', '2026-04-02')$q$,
        'a booth and a reference are required');
    PERFORM pg_temp.refused(
        $q$SELECT stall_post_booth_charge('01900000-0000-7000-8000-00000000b212', 100, 'Sign', '2026-04-02')$q$,
        'must name a booth assignment');
    IF stall_assignment_open_rent('01900000-0000-7000-8000-00000000b313') <> 2709 + 3000 THEN
        RAISE EXCEPTION 'Joe''s two extras did not land on his stay';
    END IF;
    IF (SELECT description FROM journals
        WHERE posting_key = 'booth-charge:01900000-0000-7000-8000-00000000b313:2026-04-02:electric') <> 'Booth charge: Electric' THEN
        RAISE EXCEPTION 'a booth charge does not say what it is for';
    END IF;
END $$;

ROLLBACK;

-- Cycle day 15: a calendar month touches two rent periods; each is its own row,
-- charged and late-feed on its own.
BEGIN;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES ('01900000-0000-7000-8000-00000000b031', '01900000-0000-7000-8000-000000000001', '2026-01-01', '2026-12-31', 'open');

-- With booth rentals off, nothing is offered or charged.
INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-00000000b252', '16', 'Booth 16');

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000b152', 'otto', 'Otto', 'Off');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES ('01900000-0000-7000-8000-00000000b352', '01900000-0000-7000-8000-00000000b252',
        '01900000-0000-7000-8000-00000000b152', '2026-01-01');

INSERT INTO assignment_rent (assignment_id, amount_minor)
VALUES ('01900000-0000-7000-8000-00000000b352', 1000);

UPDATE mall SET booth_rentals_enabled = false;

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM stall_rent_book('2026-01-01', '2026-02-28') WHERE chargeable) THEN
        RAISE EXCEPTION 'rent is offered to charge with booth rentals off';
    END IF;
    BEGIN
        PERFORM stall_charge_rent('01900000-0000-7000-8000-00000000b352', '2026-01-01');
        SET CONSTRAINTS ALL IMMEDIATE;
        RAISE EXCEPTION 'rent was charged with booth rentals off';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM NOT LIKE '%booth rentals are turned off%' THEN
                RAISE;
            END IF;
    END;
END $$;

UPDATE mall SET booth_rentals_enabled = true;

UPDATE mall SET rent_cycle_day = 15;

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000b151', 'fifi', 'Fifi', 'Fifteen');

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-00000000b251', '15', 'Booth 15');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES ('01900000-0000-7000-8000-00000000b351', '01900000-0000-7000-8000-00000000b251',
        '01900000-0000-7000-8000-00000000b151', '2026-02-20');

INSERT INTO assignment_rent (assignment_id, amount_minor)
VALUES ('01900000-0000-7000-8000-00000000b351', 2800);

-- A stay that starts on the last day of a period pays one day of it.
INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-00000000b253', '17', 'Booth 17');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES ('01900000-0000-7000-8000-00000000b353', '01900000-0000-7000-8000-00000000b253',
        '01900000-0000-7000-8000-00000000b151', '2026-04-14');

INSERT INTO assignment_rent (assignment_id, amount_minor)
VALUES ('01900000-0000-7000-8000-00000000b353', 3100);

DO $$
BEGIN
    IF stall_rent_quote('01900000-0000-7000-8000-00000000b353', '2026-03-15') <> 100
       OR stall_rent_quote('01900000-0000-7000-8000-00000000b353', '2026-04-15') <> 3100 THEN
        RAISE EXCEPTION 'a start on the last day of a period is not one day of it';
    END IF;
    IF stall_rent_charge_date('01900000-0000-7000-8000-00000000b353', '2026-03-15') <> '2026-04-14' THEN
        RAISE EXCEPTION 'a start on the last day is not dated on that day';
    END IF;
END $$;

DO $$
DECLARE
    listed text;
    due text;
BEGIN
    -- March touches Feb 15 to Mar 14 (moved in Feb 20: 23 of 28 days) and Mar 15 to Apr 14.
    SELECT string_agg(book.period_suffix || '=' || book.quote_minor, ' ' ORDER BY book.period_start)
    INTO listed
    FROM stall_rent_book('2026-03-01', '2026-03-31') AS book
    WHERE book.assignment_id = '01900000-0000-7000-8000-00000000b351';
    IF listed IS DISTINCT FROM '2026-02-15=2300 2026-03-15=2800' THEN
        RAISE EXCEPTION 'the cycle-15 March book read: %', listed;
    END IF;
    PERFORM stall_charge_rent('01900000-0000-7000-8000-00000000b351', '2026-02-15');
    PERFORM stall_charge_rent('01900000-0000-7000-8000-00000000b351', '2026-03-15');
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF (SELECT posting_date FROM journals
        WHERE posting_key = 'rent-charge:01900000-0000-7000-8000-00000000b351:2026-02-15') <> '2026-02-20' THEN
        RAISE EXCEPTION 'the first cycle-15 period was not dated on the move-in day';
    END IF;
    UPDATE mall
    SET late_fees_enabled = true, late_fee_grace_days = 10, late_fee_first_minor = 1000,
        late_fee_next_minor = 500, late_fee_effective_on = '2026-01-01';
    -- Each period's fees run from its own first day plus grace, through its own
    -- last day, and the second period starts again at the first amount.
    SELECT string_agg(due_fee.period_start || '#' || due_fee.ordinal || '@' || due_fee.fee_date || '=' || due_fee.amount_minor,
                      ' ' ORDER BY due_fee.period_start, due_fee.ordinal)
    INTO due
    FROM stall_late_fees_due('2026-04-20') AS due_fee;
    IF due IS DISTINCT FROM
        '2026-02-15#1@2026-02-25=1000 2026-02-15#2@2026-03-07=500 2026-03-15#1@2026-03-25=1000 2026-03-15#2@2026-04-04=500 2026-03-15#3@2026-04-14=500' THEN
        RAISE EXCEPTION 'the cycle-15 late fees were %', due;
    END IF;
    IF stall_post_late_fees('2026-04-20') <> 5 THEN
        RAISE EXCEPTION 'the cycle-15 fees did not post';
    END IF;
    SET CONSTRAINTS ALL IMMEDIATE;
END $$;

ROLLBACK;
