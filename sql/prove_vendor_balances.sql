-- One database answer for what each vendor owes and is owed, kept by booth
-- assignment so rent never moves to the next vendor in the booth.
-- Mary holds Booth 12 from Mar 17 to Oct 31 at $60. Joe holds it from Nov 1.
-- One transaction, then ROLLBACK.

BEGIN;

INSERT INTO booths (id, code, name)
VALUES
    ('01900000-0000-7000-8000-000000000201', '12', 'Booth 12'),
    ('01900000-0000-7000-8000-000000000202', '14', 'Booth 14');

SELECT stall_seed_vendor('01900000-0000-7000-8000-000000000101', 'mary', 'Mary', 'Smith');
SELECT stall_seed_vendor('01900000-0000-7000-8000-000000000102', 'joe', 'Joe', 'Brown');

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-000000000021',
    '01900000-0000-7000-8000-000000000001',
    '2026-01-01',
    '2026-12-31',
    'open'
);

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on, ends_on)
VALUES
    ('01900000-0000-7000-8000-0000000003a1', '01900000-0000-7000-8000-000000000201',
     '01900000-0000-7000-8000-000000000101', '2026-03-17', '2026-10-31'),
    ('01900000-0000-7000-8000-0000000003a2', '01900000-0000-7000-8000-000000000201',
     '01900000-0000-7000-8000-000000000102', '2026-11-01', NULL);

INSERT INTO assignment_rent (assignment_id, amount_minor)
VALUES
    ('01900000-0000-7000-8000-0000000003a1', 6000),
    ('01900000-0000-7000-8000-0000000003a2', 6000);

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d22',
    '01900000-0000-7000-8000-000000000d10',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

-- Every balance function, checked against the raw journal lines.
CREATE FUNCTION pg_temp.refuse_drift(target uuid) RETURNS void
    LANGUAGE plpgsql
    AS $fn$
DECLARE
    raw_rent bigint;
    raw_clawback bigint;
    raw_payable bigint;
    raw_deposit bigint;
    told record;
BEGIN
    SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) INTO raw_rent
    FROM journal_lines AS line
    JOIN booth_assignments AS stay ON stay.id::text = line.subledger_ref AND line.subledger_type = 'assignment'
    WHERE line.account_id = '01900000-0000-7000-8000-000000000015'
      AND stay.vendor_id = target;
    SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) INTO raw_clawback
    FROM journal_lines AS line
    WHERE line.account_id = '01900000-0000-7000-8000-00000000002e'
      AND line.subledger_type = 'vendor'
      AND line.subledger_ref = target::text;
    SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) INTO raw_payable
    FROM journal_lines AS line
    WHERE line.account_id = '01900000-0000-7000-8000-000000000014'
      AND line.subledger_type = 'vendor'
      AND line.subledger_ref = target::text;
    SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) INTO raw_deposit
    FROM journal_lines AS line
    JOIN booth_assignments AS stay ON stay.id::text = line.subledger_ref AND line.subledger_type = 'assignment'
    WHERE line.account_id = '01900000-0000-7000-8000-00000000002d'
      AND stay.vendor_id = target;
    SELECT * INTO told FROM stall_vendor_balances(target);
    IF told.rent_minor <> raw_rent OR stall_vendor_open_rent(target) <> raw_rent
       OR told.clawback_minor <> raw_clawback OR stall_vendor_open_clawback(target) <> raw_clawback
       OR told.payable_minor <> raw_payable OR stall_vendor_open_payable(target) <> raw_payable
       OR told.deposit_minor <> raw_deposit OR stall_vendor_deposit_held(target) <> raw_deposit THEN
        RAISE EXCEPTION 'the balances for % drift from the lines: % % % % against % % % %',
            target, told.rent_minor, told.clawback_minor, told.payable_minor, told.deposit_minor,
            raw_rent, raw_clawback, raw_payable, raw_deposit;
    END IF;
    IF (
        SELECT COALESCE(sum(stall_assignment_open_rent(stay.id)), 0)
        FROM booth_assignments AS stay
        WHERE stay.vendor_id = target
    ) <> raw_rent THEN
        RAISE EXCEPTION 'the stays of % do not add up to the vendor open rent', target;
    END IF;
END;
$fn$;

DO $$
BEGIN
    IF stall_money_text(8000) <> '$80.00' OR stall_money_text(-123456) <> '-$1,234.56'
       OR stall_money_text(5) <> '$0.05' OR stall_money_text(0) <> '$0.00'
       OR stall_money_text(123456789) <> '$1,234,567.89' THEN
        RAISE EXCEPTION 'money reads wrong: % % % %', stall_money_text(8000), stall_money_text(-123456),
            stall_money_text(5), stall_money_text(123456789);
    END IF;
END $$;

-- The quotes: March from the 17th is 15/31 of $60, rounded down; October, the
-- month she leaves, is full.
DO $$
BEGIN
    IF stall_rent_quote('01900000-0000-7000-8000-0000000003a1', '2026-03-01') <> 2903 THEN
        RAISE EXCEPTION 'the March move-in quote is not 15/31 of $60.00';
    END IF;
    IF stall_rent_quote('01900000-0000-7000-8000-0000000003a1', '2026-10-01') <> 6000 THEN
        RAISE EXCEPTION 'the move-out month is not full rent';
    END IF;
    IF stall_rent_quote('01900000-0000-7000-8000-0000000003a2', '2026-11-01') <> 6000 THEN
        RAISE EXCEPTION 'Joe''s first month on the 1st is not full rent';
    END IF;
END $$;

-- March is charged on Mar 20. October is charged on Nov 5, after Mary left
-- and Joe moved in: it lands on Mary's stay, not on the booth Joe now holds.
DO $$
DECLARE
    october uuid;
BEGIN
    PERFORM stall_post_rent_charge('01900000-0000-7000-8000-0000000003a1', '2026-03-01', '2026-03-20');
    october := stall_post_rent_charge('01900000-0000-7000-8000-0000000003a1', '2026-10-01', '2026-11-05');
    IF stall_post_rent_charge('01900000-0000-7000-8000-0000000003a1', '2026-10-01', '2026-11-06') IS DISTINCT FROM october THEN
        RAISE EXCEPTION 'charging October again posted a second journal';
    END IF;
    PERFORM stall_post_rent_charge('01900000-0000-7000-8000-0000000003a2', '2026-11-01', '2026-11-02');
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF stall_vendor_open_rent('01900000-0000-7000-8000-000000000101') <> 8903 THEN
        RAISE EXCEPTION 'Mary does not owe March and October';
    END IF;
    IF stall_vendor_open_rent('01900000-0000-7000-8000-000000000102') <> 6000 THEN
        RAISE EXCEPTION 'Joe was given rent that is not his';
    END IF;
    PERFORM pg_temp.refuse_drift('01900000-0000-7000-8000-000000000101');
    PERFORM pg_temp.refuse_drift('01900000-0000-7000-8000-000000000102');
END $$;

-- The rent roll is one row per stay, named for the vendor who held it.
DO $$
BEGIN
    IF (SELECT count(*) FROM stall_report_rent()) <> 2
       OR NOT EXISTS (SELECT 1 FROM stall_report_rent() WHERE booth = '12' AND vendor = 'Mary Smith' AND open_minor = 8903)
       OR NOT EXISTS (SELECT 1 FROM stall_report_rent() WHERE booth = '12' AND vendor = 'Joe Brown' AND open_minor = 6000) THEN
        RAISE EXCEPTION 'the rent roll moved Mary''s rent to Joe';
    END IF;
    PERFORM stall_refuse_rent_roll();
END $$;

-- Mary pays $50.00 by card on Nov 5, after she left. It pays her oldest charge
-- first, appears on her statement, and leaves her owing $39.03.
DO $$
DECLARE
    receipt uuid;
BEGIN
    IF (SELECT count(*) FROM stall_rent_payment_split('01900000-0000-7000-8000-000000000101', 5000)) <> 1
       OR (SELECT amount_minor FROM stall_rent_payment_split('01900000-0000-7000-8000-000000000101', 5000)
           WHERE assignment_id = '01900000-0000-7000-8000-0000000003a1') <> 5000 THEN
        RAISE EXCEPTION 'Mary''s payment was not split onto her own stay';
    END IF;
    receipt := stall_post_rent_payment('01900000-0000-7000-8000-000000000101', 0, 5000, 0, '2026-11-05');
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d22', receipt);
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF stall_vendor_open_rent('01900000-0000-7000-8000-000000000101') <> 3903 THEN
        RAISE EXCEPTION 'Mary does not owe $39.03 after paying $50.00';
    END IF;
    IF stall_vendor_open_rent('01900000-0000-7000-8000-000000000102') <> 6000 THEN
        RAISE EXCEPTION 'Mary''s payment touched Joe';
    END IF;
    IF NOT EXISTS (
        SELECT 1
        FROM vendor_statement
        WHERE vendor_id = '01900000-0000-7000-8000-000000000101'
          AND journal_id = receipt
          AND credit_minor = 5000
    ) THEN
        RAISE EXCEPTION 'Mary''s Nov 5 payment is missing from her statement';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM vendor_statement
        WHERE vendor_id = '01900000-0000-7000-8000-000000000102'
          AND journal_id = receipt
    ) THEN
        RAISE EXCEPTION 'Mary''s payment shows on Joe''s statement';
    END IF;
    PERFORM pg_temp.refuse_drift('01900000-0000-7000-8000-000000000101');
END $$;

-- The reasons Mary and Joe cannot be made inactive, in plain words.
DO $$
DECLARE
    said text;
    codes text;
BEGIN
    IF (SELECT string_agg(reason, ',') FROM stall_vendor_open_reasons('01900000-0000-7000-8000-000000000101')) <> 'rent' THEN
        RAISE EXCEPTION 'Mary has a reason besides rent';
    END IF;
    IF (SELECT string_agg(reason, ',') FROM stall_vendor_open_reasons('01900000-0000-7000-8000-000000000102')) <> 'rent,booth' THEN
        RAISE EXCEPTION 'Joe is not held by his rent and his booth';
    END IF;
    BEGIN
        UPDATE vendors SET active = false WHERE id = '01900000-0000-7000-8000-000000000101';
        RAISE EXCEPTION 'Mary was made inactive while she owes rent';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            GET STACKED DIAGNOSTICS said = MESSAGE_TEXT, codes = PG_EXCEPTION_DETAIL;
    END;
    IF said <> 'Mary Smith can''t be made inactive yet. They owe $39.03 in rent.' OR codes <> 'rent' THEN
        RAISE EXCEPTION 'the refusal read: % (%)', said, codes;
    END IF;
END $$;

-- What is refused.
DO $$
BEGIN
    PERFORM stall_post_rent_payment('01900000-0000-7000-8000-000000000101', 3904, 0, 0, '2026-11-05');
    RAISE EXCEPTION 'an overpay was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%cannot exceed the open rent%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    PERFORM stall_post_rent_charge('01900000-0000-7000-8000-0000000003a1', '2026-12-01', '2026-12-02');
    RAISE EXCEPTION 'a charge outside Mary''s stay was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a period the assignment covers%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    PERFORM stall_post_lines(
        'rent-charge:01900000-0000-7000-8000-0000000003a2:2026-12', '2026-12-01', 'rent_charge',
        '01900000-0000-7000-8000-0000000003a2:2026-12', 'Wrong amount',
        jsonb_build_array(
            stall_line('booth_rent_receivable', 1, 0, 'assignment', '01900000-0000-7000-8000-0000000003a2'),
            stall_line('rent_revenue', 0, 1)
        )
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a hand-made charge for the wrong amount was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%for the stored rate%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    PERFORM stall_post_lines(
        'rent-charge:booth-line', '2026-12-01', 'rent_charge', 'booth-line', 'Booth line',
        jsonb_build_array(
            stall_line('booth_rent_receivable', 100, 0, 'booth', '01900000-0000-7000-8000-000000000201'),
            stall_line('rent_revenue', 0, 100)
        )
    );
    RAISE EXCEPTION 'a rent line on the booth subledger was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%requires that subledger%' AND SQLERRM NOT LIKE '%names its booth assignment%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    PERFORM stall_post_lines(
        'rent-charge:no-stay', '2026-12-01', 'rent_charge', 'no-stay', 'No stay',
        jsonb_build_array(
            stall_line('booth_rent_receivable', 100, 0, 'assignment', '01900000-0000-7000-8000-000000000201'),
            stall_line('rent_revenue', 0, 100)
        )
    );
    RAISE EXCEPTION 'a rent line naming a booth id as its stay was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%must name a booth assignment%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    PERFORM stall_post_lines(
        'payable-on-stay', '2026-12-01', 'vendor_memo', 'payable-on-stay', 'Payable on a stay',
        jsonb_build_array(
            stall_line('vendor_payable', 0, 100, 'assignment', '01900000-0000-7000-8000-0000000003a1'),
            stall_line('vendor_allowance', 100, 0)
        )
    );
    RAISE EXCEPTION 'a payable line naming a stay was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%only rent and deposit lines name a booth assignment%'
           AND SQLERRM NOT LIKE '%requires that subledger%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    PERFORM stall_post_lines(
        'unknown-kind', '2026-12-01', 'vendor_memo', 'unknown-kind', 'Unknown subledger',
        jsonb_build_array(
            stall_line('vendor_payable', 0, 100, 'shelf', '01900000-0000-7000-8000-000000000101'),
            stall_line('vendor_allowance', 100, 0)
        )
    );
    RAISE EXCEPTION 'a subledger type the books do not keep was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%not one the books keep%' AND SQLERRM NOT LIKE '%requires that subledger%' THEN
            RAISE;
        END IF;
END $$;

-- The control accounts keep the assignment subledger.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM accounts AS account
        JOIN chart_roles AS role ON role.account_id = account.id
        WHERE role.role IN ('booth_rent_receivable', 'booth_deposit_held')
          AND account.subledger_type IS DISTINCT FROM 'assignment'
    ) THEN
        RAISE EXCEPTION 'a rent or deposit control account does not keep the assignment subledger';
    END IF;
END $$;

ROLLBACK;
