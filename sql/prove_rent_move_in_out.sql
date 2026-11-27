-- Becca's rent rules, with the rent cycle on the 1st. Moving in on the 1st is a
-- full month; moving in on any other day is prorated from the start date,
-- rounded down in the vendor's favor; moving out on any day is a full month.
-- Every charge lands on the stay it is for. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES
    ('01900000-0000-7000-8000-00000000c021', '01900000-0000-7000-8000-000000000001', '2027-01-01', '2027-12-31', 'open'),
    ('01900000-0000-7000-8000-00000000c022', '01900000-0000-7000-8000-000000000001', '2028-01-01', '2028-12-31', 'open');

DO $$
BEGIN
    IF stall_rent_cycle_day() <> 1 THEN
        RAISE EXCEPTION 'a new store does not bill rent from the 1st';
    END IF;
END $$;

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000c101', 'mary', 'Mary', 'Smith');
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000c102', 'joe', 'Joe', 'Brown');
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000c103', 'ann', 'Ann', 'Leap');
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000c104', 'bo', 'Bo', 'Common');
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000c105', 'cy', 'Cy', 'First');

INSERT INTO booths (id, code, name)
VALUES
    ('01900000-0000-7000-8000-00000000c201', '1', 'Booth 1'),
    ('01900000-0000-7000-8000-00000000c202', '2', 'Booth 2'),
    ('01900000-0000-7000-8000-00000000c203', '3', 'Booth 3'),
    ('01900000-0000-7000-8000-00000000c204', '4', 'Booth 4'),
    ('01900000-0000-7000-8000-00000000c205', '5', 'Booth 5'),
    ('01900000-0000-7000-8000-00000000c206', '6', 'Booth 6'),
    ('01900000-0000-7000-8000-00000000c207', '7', 'Booth 7'),
    ('01900000-0000-7000-8000-00000000c212', '12', 'Booth 12'),
    ('01900000-0000-7000-8000-00000000c214', '14', 'Booth 14');

-- Stays at $60.00 a month unless named. Booth 14 is $90.00.
INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on, ends_on)
VALUES
    -- Move in on the 1st, and out on the 1st.
    ('01900000-0000-7000-8000-00000000c301', '01900000-0000-7000-8000-00000000c201', '01900000-0000-7000-8000-00000000c105', '2027-03-01', '2027-04-01'),
    -- Move in on the 17th, and out on the 17th of the next month.
    ('01900000-0000-7000-8000-00000000c302', '01900000-0000-7000-8000-00000000c202', '01900000-0000-7000-8000-00000000c105', '2027-03-17', '2027-04-17'),
    -- Move out on the last day.
    ('01900000-0000-7000-8000-00000000c303', '01900000-0000-7000-8000-00000000c203', '01900000-0000-7000-8000-00000000c105', '2027-01-01', '2027-03-31'),
    -- Feb 15 in a leap year and in a common year.
    ('01900000-0000-7000-8000-00000000c304', '01900000-0000-7000-8000-00000000c204', '01900000-0000-7000-8000-00000000c103', '2028-02-15', NULL),
    ('01900000-0000-7000-8000-00000000c305', '01900000-0000-7000-8000-00000000c205', '01900000-0000-7000-8000-00000000c104', '2027-02-15', NULL),
    -- Mary out on Mar 31, Joe in on Apr 1, in the same booth.
    ('01900000-0000-7000-8000-00000000c306', '01900000-0000-7000-8000-00000000c206', '01900000-0000-7000-8000-00000000c101', '2027-01-01', '2027-03-31'),
    ('01900000-0000-7000-8000-00000000c307', '01900000-0000-7000-8000-00000000c206', '01900000-0000-7000-8000-00000000c102', '2027-04-01', NULL),
    -- Mary leaves Booth 7 on Mar 17 with March uncharged; Joe moves in Mar 18.
    ('01900000-0000-7000-8000-00000000c308', '01900000-0000-7000-8000-00000000c207', '01900000-0000-7000-8000-00000000c101', '2027-01-01', '2027-03-17'),
    ('01900000-0000-7000-8000-00000000c309', '01900000-0000-7000-8000-00000000c207', '01900000-0000-7000-8000-00000000c102', '2027-03-18', NULL),
    -- Mary ends Booth 12 on Mar 16 and adds Booth 14 from Mar 17.
    ('01900000-0000-7000-8000-00000000c312', '01900000-0000-7000-8000-00000000c212', '01900000-0000-7000-8000-00000000c101', '2027-01-01', '2027-03-16'),
    ('01900000-0000-7000-8000-00000000c314', '01900000-0000-7000-8000-00000000c214', '01900000-0000-7000-8000-00000000c101', '2027-03-17', NULL);

INSERT INTO assignment_rent (assignment_id, amount_minor)
SELECT stay.id, CASE WHEN stay.id = '01900000-0000-7000-8000-00000000c314' THEN 9000 ELSE 6000 END
FROM booth_assignments AS stay
WHERE stay.id::text LIKE '01900000-0000-7000-8000-00000000c3%';

CREATE FUNCTION pg_temp.expect_charge(stay uuid, period date, on_day date, expected bigint, said text) RETURNS void
    LANGUAGE plpgsql
    AS $fn$
DECLARE
    posted uuid;
    charged bigint;
BEGIN
    IF stall_rent_quote(stay, period) <> expected THEN
        RAISE EXCEPTION '%: quoted % for %, expected %', said, stall_rent_quote(stay, period), period, expected;
    END IF;
    posted := stall_post_rent_charge(stay, period, on_day);
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    SELECT line.debit_minor INTO charged
    FROM journal_lines AS line
    WHERE line.journal_id = posted
      AND line.account_id = '01900000-0000-7000-8000-000000000015'
      AND line.subledger_type = 'assignment'
      AND line.subledger_ref = stay::text;
    IF charged IS DISTINCT FROM expected THEN
        RAISE EXCEPTION '%: charged % on that stay, expected %', said, charged, expected;
    END IF;
END;
$fn$;

DO $$
BEGIN
    PERFORM pg_temp.expect_charge('01900000-0000-7000-8000-00000000c301', '2027-03-01', '2027-03-01', 6000, 'in on Mar 1');
    PERFORM pg_temp.expect_charge('01900000-0000-7000-8000-00000000c301', '2027-04-01', '2027-04-01', 6000, 'out on Apr 1');
    -- 15 of 31 days of $60.00 is $29.032..., rounded down.
    PERFORM pg_temp.expect_charge('01900000-0000-7000-8000-00000000c302', '2027-03-01', '2027-03-17', 2903, 'in on Mar 17');
    PERFORM pg_temp.expect_charge('01900000-0000-7000-8000-00000000c302', '2027-04-01', '2027-04-01', 6000, 'out on Apr 17');
    PERFORM pg_temp.expect_charge('01900000-0000-7000-8000-00000000c303', '2027-03-01', '2027-03-01', 6000, 'out on Mar 31');
    -- 2028 is a leap year: Feb 15 to Feb 29 is 15 of 29 days, $31.034..., rounded down.
    PERFORM pg_temp.expect_charge('01900000-0000-7000-8000-00000000c304', '2028-02-01', '2028-02-15', 3103, 'in on Feb 15, leap year');
    -- 2027 is not: Feb 15 to Feb 28 is 14 of 28 days, exactly half.
    PERFORM pg_temp.expect_charge('01900000-0000-7000-8000-00000000c305', '2027-02-01', '2027-02-15', 3000, 'in on Feb 15, common year');
END $$;

-- Mary out on Mar 31, Joe in on Apr 1: each is charged only their own month.
DO $$
BEGIN
    PERFORM pg_temp.expect_charge('01900000-0000-7000-8000-00000000c306', '2027-03-01', '2027-03-01', 6000, 'Mary''s March');
    PERFORM pg_temp.expect_charge('01900000-0000-7000-8000-00000000c307', '2027-04-01', '2027-04-01', 6000, 'Joe''s April');
    BEGIN
        PERFORM stall_post_rent_charge('01900000-0000-7000-8000-00000000c306', '2027-04-01', '2027-04-01');
        RAISE EXCEPTION 'Mary was charged April after she left';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM NOT LIKE '%a period the assignment covers%' THEN
                RAISE;
            END IF;
    END;
    BEGIN
        PERFORM stall_post_rent_charge('01900000-0000-7000-8000-00000000c307', '2027-03-01', '2027-04-01');
        RAISE EXCEPTION 'Joe was charged March before he came';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM NOT LIKE '%a period the assignment covers%' THEN
                RAISE;
            END IF;
    END;
END $$;

-- Mary leaves Booth 7 on Mar 17 with March uncharged, and Joe moves in on
-- Mar 18. March is charged on Mar 20: Mary's is her full month on her stay,
-- and Joe's is 14 of 31 days on his.
DO $$
BEGIN
    PERFORM pg_temp.expect_charge('01900000-0000-7000-8000-00000000c308', '2027-03-01', '2027-03-20', 6000, 'Mary''s last month, charged after she left');
    PERFORM pg_temp.expect_charge('01900000-0000-7000-8000-00000000c309', '2027-03-01', '2027-03-20', 2709, 'Joe''s first March');
END $$;

-- Mary ends Booth 12 on Mar 16 and adds Booth 14 from Mar 17. Booth 12 keeps
-- the full March; Booth 14 is 15 of 31 days of $90.00, $43.548..., rounded down.
DO $$
BEGIN
    PERFORM pg_temp.expect_charge('01900000-0000-7000-8000-00000000c312', '2027-03-01', '2027-03-17', 6000, 'old booth keeps the full month');
    PERFORM pg_temp.expect_charge('01900000-0000-7000-8000-00000000c314', '2027-03-01', '2027-03-17', 4354, 'new booth from the 17th');
END $$;

-- The days a move-in pays for, as the payout note words them.
DO $$
BEGIN
    IF stall_rent_move_in_days('01900000-0000-7000-8000-00000000c302', '2027-03-01') <> 15
       OR stall_rent_move_in_days('01900000-0000-7000-8000-00000000c301', '2027-03-01') <> 0
       OR stall_rent_move_in_days('01900000-0000-7000-8000-00000000c302', '2027-04-01') <> 0
       OR stall_rent_move_in_days('01900000-0000-7000-8000-00000000c304', '2028-02-01') <> 15
       OR stall_rent_move_in_days('01900000-0000-7000-8000-00000000c305', '2027-02-01') <> 14 THEN
        RAISE EXCEPTION 'the move-in days are wrong';
    END IF;
END $$;

-- Nothing credits unused days: rent moves only through the charges above.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM journal_lines AS line
        JOIN journals AS journal ON journal.id = line.journal_id
        WHERE line.account_id = '01900000-0000-7000-8000-000000000015'
          AND (journal.source_type <> 'rent_charge' OR line.credit_minor <> 0)
    ) THEN
        RAISE EXCEPTION 'a move-out posted a credit for unused days';
    END IF;
    -- Mary: March on Booths 6, 7 and 12 at $60.00, and $43.54 on Booth 14.
    IF stall_vendor_open_rent('01900000-0000-7000-8000-00000000c101') <> 22354 THEN
        RAISE EXCEPTION 'Mary owes % across her stays', stall_vendor_open_rent('01900000-0000-7000-8000-00000000c101');
    END IF;
    -- Joe: April on Booth 6 and his first March on Booth 7.
    IF stall_vendor_open_rent('01900000-0000-7000-8000-00000000c102') <> 8709 THEN
        RAISE EXCEPTION 'Joe owes % across his stays', stall_vendor_open_rent('01900000-0000-7000-8000-00000000c102');
    END IF;
END $$;

ROLLBACK;
