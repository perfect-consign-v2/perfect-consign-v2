-- Proof for a rent charge. One transaction, then ROLLBACK.
-- Charges are posted through stall_post_rent_charge, which computes the amount.
-- Hand-built journals then attack the assert that stands behind it.

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
VALUES ('01900000-0000-7000-8000-000000000301', 15000);

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-000000000021',
    '01900000-0000-7000-8000-000000000001',
    '2026-01-01',
    '2026-12-31',
    'open'
);

-- March, through the posting function: the stored rate, on that stay.
DO $$
DECLARE
    posted uuid;
    again uuid;
BEGIN
    posted := stall_post_rent_charge('01900000-0000-7000-8000-000000000301', '2026-03-01', '2026-03-01');
    SET CONSTRAINTS ALL IMMEDIATE;
    IF NOT EXISTS (
        SELECT 1
        FROM journals
        WHERE id = posted
          AND posting_key = 'rent-charge:01900000-0000-7000-8000-000000000301:2026-03'
          AND source_reference = '01900000-0000-7000-8000-000000000301:2026-03'
          AND debit_total_minor = 15000
    ) THEN
        RAISE EXCEPTION 'the March charge did not name its stay and period for the stored rate';
    END IF;
    IF stall_assignment_open_rent('01900000-0000-7000-8000-000000000301') <> 15000 THEN
        RAISE EXCEPTION 'the March charge did not land on that stay';
    END IF;
    again := stall_post_rent_charge('01900000-0000-7000-8000-000000000301', '2026-03-01', '2026-03-09');
    IF again IS DISTINCT FROM posted THEN
        RAISE EXCEPTION 'charging March twice posted a second journal';
    END IF;
    IF (SELECT count(*) FROM journals WHERE source_type = 'rent_charge') <> 1 THEN
        RAISE EXCEPTION 'charging March twice left more than one charge';
    END IF;
END $$;

-- A hand-built second charge on the same key is refused by the key.
DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000602',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-000000000301:2026-03',
        '2026-03-02',
        '2026-03-02 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'rent_charge',
        '01900000-0000-7000-8000-000000000301:2026-03',
        'Second March rent charge',
        15000,
        15000
    );
    RAISE EXCEPTION 'second rent charge for the same stay and month was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
END $$;

-- A rent line on the booth, not the stay, is refused.
DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000060a',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-000000000301:2026-04',
        '2026-04-01',
        '2026-04-01 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'rent_charge',
        '01900000-0000-7000-8000-000000000301:2026-04',
        'April rent kept on the booth',
        15000,
        15000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000060a',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000015',
        15000,
        0,
        'booth',
        '01900000-0000-7000-8000-000000000201'
    );
    RAISE EXCEPTION 'a rent line on the booth subledger was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%requires that subledger%' THEN
            RAISE;
        END IF;
END $$;

UPDATE booth_rent_rates
SET amount_minor = 16000
WHERE booth_id = '01900000-0000-7000-8000-000000000201';

-- A charge at the booth's later asking rate, not the stay's rate, is refused.
DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000604',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-000000000301:2026-04',
        '2026-04-01',
        '2026-04-01 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'rent_charge',
        '01900000-0000-7000-8000-000000000301:2026-04',
        'April rent at a later booth rate',
        16000,
        16000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000604',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000015',
        16000,
        0,
        'assignment',
        '01900000-0000-7000-8000-000000000301'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000604',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000017',
        0,
        16000
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'rent charge that ignores the assignment rate was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%for the stored rate%' THEN
            RAISE;
        END IF;
END $$;

-- A charge dated before the first day of the period it charges is refused.
DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000060b',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-000000000301:2026-04',
        '2026-03-31',
        '2026-03-31 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'rent_charge',
        '01900000-0000-7000-8000-000000000301:2026-04',
        'April rent charged in March',
        15000,
        15000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000060b',
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
        '01900000-0000-7000-8000-00000000060b',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000017',
        0,
        15000
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a rent charge dated before its period was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%on or after the first day of its period%' THEN
            RAISE;
        END IF;
END $$;

-- A charge whose reference names another period than its key is refused.
DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000060c',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-000000000301:2026-04',
        '2026-04-01',
        '2026-04-01 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'rent_charge',
        '01900000-0000-7000-8000-000000000301:2026-05',
        'April rent that says May',
        15000,
        15000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000060c',
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
        '01900000-0000-7000-8000-00000000060c',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000017',
        0,
        15000
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a rent charge whose reference disagrees with its key was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%one booth assignment and one rent period%' THEN
            RAISE;
        END IF;
END $$;

-- April, through the function: the stay's rate, not the booth's new asking rate.
DO $$
DECLARE
    posted uuid;
BEGIN
    posted := stall_post_rent_charge('01900000-0000-7000-8000-000000000301', '2026-04-01', '2026-04-01');
    SET CONSTRAINTS ALL IMMEDIATE;
    IF (SELECT debit_total_minor FROM journals WHERE id = posted) <> 15000 THEN
        RAISE EXCEPTION 'April rent followed the booth asking rate';
    END IF;
END $$;

UPDATE booth_rent_rates
SET amount_minor = 1
WHERE booth_id = '01900000-0000-7000-8000-000000000201';

-- A hand-built May charge at the new asking price is refused.
DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000608',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-000000000301:2026-05',
        '2026-05-01',
        '2026-05-01 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'rent_charge',
        '01900000-0000-7000-8000-000000000301:2026-05',
        'May rent followed the new asking price',
        1,
        1
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000608',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000015',
        1,
        0,
        'assignment',
        '01900000-0000-7000-8000-000000000301'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000608',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000017',
        0,
        1
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a later asking rate changed the open stay';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%for the stored rate%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000202', 'B2', 'Booth B2');

INSERT INTO booth_rent_rates (booth_id, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000202', 15000);

-- A rent line that names no real stay is refused.
DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000605',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-000000000302:2026-05',
        '2026-05-01',
        '2026-05-01 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'rent_charge',
        '01900000-0000-7000-8000-000000000302:2026-05',
        'May rent with no assignment',
        15000,
        15000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000605',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000015',
        15000,
        0,
        'assignment',
        '01900000-0000-7000-8000-000000000302'
    );
    RAISE EXCEPTION 'rent charge without an assignment was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%must name a booth assignment%' THEN
            RAISE;
        END IF;
END $$;

-- The posting function refuses a stay that does not exist.
DO $$
BEGIN
    PERFORM stall_post_rent_charge('01900000-0000-7000-8000-000000000302', '2026-05-01', '2026-05-01');
    RAISE EXCEPTION 'the posting function charged a stay that does not exist';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%requires an assignment%' THEN
            RAISE;
        END IF;
END $$;

UPDATE booth_rent_rates
SET amount_minor = 18000
WHERE booth_id = '01900000-0000-7000-8000-000000000202';

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-000000000302',
    '01900000-0000-7000-8000-000000000202',
    '01900000-0000-7000-8000-000000000101',
    '2026-06-01'
);

INSERT INTO assignment_rent (assignment_id, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000302', 18000);

-- The posting function refuses a period before the stay starts.
DO $$
BEGIN
    PERFORM stall_post_rent_charge('01900000-0000-7000-8000-000000000302', '2026-05-01', '2026-06-01');
    RAISE EXCEPTION 'the posting function charged a period before the stay';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a period the assignment covers%' THEN
            RAISE;
        END IF;
END $$;

-- The posting function refuses a period that does not start on the cycle day.
DO $$
BEGIN
    PERFORM stall_post_rent_charge('01900000-0000-7000-8000-000000000302', '2026-06-02', '2026-06-02');
    RAISE EXCEPTION 'the posting function charged a period off the cycle day';
EXCEPTION
    WHEN SQLSTATE '22023' THEN
        IF SQLERRM NOT LIKE '%starts on the rent cycle day%' THEN
            RAISE;
        END IF;
END $$;

-- June on the new stay: Ada's second booth is charged on that stay alone.
DO $$
BEGIN
    PERFORM stall_post_rent_charge('01900000-0000-7000-8000-000000000302', '2026-06-01', '2026-06-01');
    SET CONSTRAINTS ALL IMMEDIATE;
    IF stall_assignment_open_rent('01900000-0000-7000-8000-000000000302') <> 18000 THEN
        RAISE EXCEPTION 'June rent did not land on the new stay';
    END IF;
    IF stall_vendor_open_rent('01900000-0000-7000-8000-000000000101') <> 48000 THEN
        RAISE EXCEPTION 'the vendor open rent is not the sum of the stays';
    END IF;
END $$;

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000221', 'B3', 'Booth B3');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-000000000321',
    '01900000-0000-7000-8000-000000000221',
    '01900000-0000-7000-8000-000000000101',
    '2026-03-15'
);

INSERT INTO booth_rent_rates (booth_id, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000221', 3100);

INSERT INTO assignment_rent (assignment_id, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000321', 6200);

-- A mid-month move-in charged the full override is refused.
DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000606',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-000000000321:2026-03',
        '2026-03-15',
        '2026-03-15 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'rent_charge',
        '01900000-0000-7000-8000-000000000321:2026-03',
        'March rent ignored the move-in and the override',
        6200,
        6200
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000606',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000015',
        6200,
        0,
        'assignment',
        '01900000-0000-7000-8000-000000000321'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000606',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000017',
        0,
        6200
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a mid-month move-in was charged the full override';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%for the stored rate%' THEN
            RAISE;
        END IF;
END $$;

-- March from the 15th: 17 of 31 days of the stay's $62.00 override is $34.00.
DO $$
DECLARE
    posted uuid;
BEGIN
    IF stall_rent_quote('01900000-0000-7000-8000-000000000321', '2026-03-01') <> 3400 THEN
        RAISE EXCEPTION 'the quote for a move-in on the 15th is not 17/31 of the override';
    END IF;
    posted := stall_post_rent_charge('01900000-0000-7000-8000-000000000321', '2026-03-01', '2026-03-15');
    SET CONSTRAINTS ALL IMMEDIATE;
    IF (SELECT debit_total_minor FROM journals WHERE id = posted) <> 3400 THEN
        RAISE EXCEPTION 'the move-in charge is not the quote';
    END IF;
END $$;

DO $$
BEGIN
    UPDATE assignment_rent
    SET amount_minor = 1
    WHERE assignment_id = '01900000-0000-7000-8000-000000000321';
    RAISE EXCEPTION 'an assignment rent changed after the month was charged';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

ROLLBACK;
