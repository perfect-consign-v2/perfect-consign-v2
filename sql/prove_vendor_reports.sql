-- The staff reports for vendor money and rent: the vendor register, rent
-- payments, and the rent history card. All three read the same journal lines
-- through one allocation, so they cannot disagree with each other or with the
-- vendor's balances.
-- Mary holds Booth 12 from Mar 17 to Oct 31 at $60 and Booth 14 from Nov 1 at
-- $40. Joe holds Booth 12 from Nov 1 at $60. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO booths (id, code, name)
VALUES
    ('01900000-0000-7000-8000-00000000f201', '12', 'Booth 12'),
    ('01900000-0000-7000-8000-00000000f202', '14', 'Booth 14');

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000f101', 'mary', 'Mary', 'Smith');
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000f102', 'joe', 'Joe', 'Brown');

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000f021',
    '01900000-0000-7000-8000-000000000001',
    '2026-01-01',
    '2026-12-31',
    'open'
);

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on, ends_on)
VALUES
    ('01900000-0000-7000-8000-00000000f3a1', '01900000-0000-7000-8000-00000000f201',
     '01900000-0000-7000-8000-00000000f101', '2026-03-17', '2026-10-31'),
    ('01900000-0000-7000-8000-00000000f3a2', '01900000-0000-7000-8000-00000000f201',
     '01900000-0000-7000-8000-00000000f102', '2026-11-01', NULL),
    ('01900000-0000-7000-8000-00000000f3a3', '01900000-0000-7000-8000-00000000f202',
     '01900000-0000-7000-8000-00000000f101', '2026-11-01', NULL);

INSERT INTO assignment_rent (assignment_id, amount_minor)
VALUES
    ('01900000-0000-7000-8000-00000000f3a1', 6000),
    ('01900000-0000-7000-8000-00000000f3a2', 6000),
    ('01900000-0000-7000-8000-00000000f3a3', 4000);

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-00000000fd22',
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
        WHEN SQLSTATE '23514' OR SQLSTATE '22023' OR SQLSTATE '23505' THEN
            IF SQLERRM NOT LIKE '%' || needle || '%' THEN
                RAISE EXCEPTION 'refused for the wrong reason: % (wanted %)', SQLERRM, needle;
            END IF;
            RETURN;
    END;
    RAISE EXCEPTION 'accepted: %', attempt;
END;
$fn$;

-- A desk payment, tied to the open shift the way the register does it.
CREATE FUNCTION pg_temp.pay(cash bigint, card bigint, checks bigint, on_date date) RETURNS uuid
    LANGUAGE plpgsql
    AS $fn$
DECLARE
    receipt uuid;
BEGIN
    receipt := stall_post_rent_payment('01900000-0000-7000-8000-00000000f101', cash, card, checks, on_date);
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-00000000fd22', receipt);
    IF checks > 0 THEN
        INSERT INTO register_checks (journal_id, check_number) VALUES (receipt, '1042');
    END IF;
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    RETURN receipt;
END;
$fn$;

-- What the raw lines say a vendor's net balance was at the end of a day:
-- positive, the store owes them; negative, they owe the store.
CREATE FUNCTION pg_temp.net_on(target uuid, through date, booth uuid DEFAULT NULL) RETURNS bigint
    LANGUAGE sql
    AS $fn$
    SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0)::bigint
    FROM journal_lines AS line
    JOIN journals AS posted ON posted.id = line.journal_id
    JOIN chart_roles AS role ON role.account_id = line.account_id
    LEFT JOIN booth_assignments AS stay ON line.subledger_type = 'assignment' AND stay.id::text = line.subledger_ref
    WHERE posted.posting_date <= through
      AND (
          (role.role IN ('vendor_payable', 'vendor_receivable') AND line.subledger_type = 'vendor'
           AND line.subledger_ref = target::text AND booth IS NULL)
          OR (role.role IN ('booth_rent_receivable', 'booth_deposit_held') AND stay.vendor_id = target
              AND (booth IS NULL OR stay.booth_id = booth))
      )
$fn$;

-- The register for a range ties to the raw lines: the opening is everything
-- before the first day, each line's balance follows from the last, and the
-- closing is the opening plus the lines.
CREATE FUNCTION pg_temp.refuse_register_drift(target uuid, from_date date, to_date date, booth uuid DEFAULT NULL) RETURNS void
    LANGUAGE plpgsql
    AS $fn$
DECLARE
    opening bigint;
    closing bigint;
    running bigint;
    entry record;
    debits bigint := 0;
    credits bigint := 0;
BEGIN
    SELECT balance_minor INTO opening FROM stall_vendor_register(target, from_date, to_date, booth) WHERE row_kind = 'opening';
    IF opening IS DISTINCT FROM pg_temp.net_on(target, from_date - 1, booth) THEN
        RAISE EXCEPTION 'the % opening on % is % but the lines say %', target, from_date, opening, pg_temp.net_on(target, from_date - 1, booth);
    END IF;
    running := opening;
    FOR entry IN SELECT * FROM stall_vendor_register(target, from_date, to_date, booth) WHERE row_kind = 'line'
    LOOP
        IF (entry.debit_minor > 0) = (entry.credit_minor > 0) THEN
            RAISE EXCEPTION 'a register line is both or neither a debit and a credit: %', entry.what;
        END IF;
        running := running + entry.credit_minor - entry.debit_minor;
        debits := debits + entry.debit_minor;
        credits := credits + entry.credit_minor;
        IF entry.balance_minor <> running THEN
            RAISE EXCEPTION 'the balance after % is % but the lines add to %', entry.what, entry.balance_minor, running;
        END IF;
        IF entry.posted_on NOT BETWEEN from_date AND to_date THEN
            RAISE EXCEPTION 'a line dated % is outside % to %', entry.posted_on, from_date, to_date;
        END IF;
    END LOOP;
    SELECT balance_minor INTO closing FROM stall_vendor_register(target, from_date, to_date, booth) WHERE row_kind = 'closing';
    IF closing <> running OR closing <> pg_temp.net_on(target, to_date, booth) THEN
        RAISE EXCEPTION 'the % closing on % is %, the lines add to %, the books say %', target, to_date, closing, running, pg_temp.net_on(target, to_date, booth);
    END IF;
    IF (SELECT debit_minor FROM stall_vendor_register(target, from_date, to_date, booth) WHERE row_kind = 'closing') <> debits
       OR (SELECT credit_minor FROM stall_vendor_register(target, from_date, to_date, booth) WHERE row_kind = 'closing') <> credits THEN
        RAISE EXCEPTION 'the register totals do not add up the lines';
    END IF;
    IF COALESCE((
           SELECT sum(CASE WHEN row_kind IN ('payable', 'deposit') THEN balance_minor ELSE -balance_minor END)
           FROM stall_vendor_register(target, from_date, to_date, booth)
           WHERE row_kind IN ('payable', 'clawback', 'rent', 'deposit')
       ), 0) <> closing THEN
        RAISE EXCEPTION 'the split does not add up to the closing balance';
    END IF;
END;
$fn$;

-- The rent history ties to the stays: paid plus owed is the charge, and what
-- each stay still owes is the stay's open rent.
CREATE FUNCTION pg_temp.refuse_history_drift(target uuid) RETURNS void
    LANGUAGE plpgsql
    AS $fn$
DECLARE
    entry record;
BEGIN
    FOR entry IN SELECT * FROM stall_vendor_rent_history(target)
    LOOP
        IF entry.waived THEN
            IF entry.owed_minor <> 0 OR entry.paid_minor <> 0 THEN
                RAISE EXCEPTION 'a waived fee shows money paid or owed';
            END IF;
        ELSIF entry.paid_minor + entry.owed_minor <> entry.charged_minor THEN
            RAISE EXCEPTION 'paid % and owed % do not make the % charged for %', entry.paid_minor, entry.owed_minor, entry.charged_minor, entry.what;
        END IF;
        IF entry.paid_minor <> COALESCE((
            SELECT sum((payment ->> 'amount_minor')::bigint) FROM jsonb_array_elements(entry.payments) AS payment
        ), 0) THEN
            RAISE EXCEPTION 'the payments listed under % do not add to what it shows paid', entry.what;
        END IF;
    END LOOP;
    IF EXISTS (
        SELECT 1
        FROM booth_assignments AS stay
        WHERE stay.vendor_id = target
          AND stall_assignment_open_rent(stay.id) <> COALESCE((
              SELECT sum(history.owed_minor) FROM stall_vendor_rent_history(target) AS history
              WHERE history.assignment_id = stay.id
          ), 0)
    ) THEN
        RAISE EXCEPTION 'the rent history does not owe what the stays owe';
    END IF;
END;
$fn$;

UPDATE mall
SET late_fees_enabled = true,
    late_fee_grace_days = 28,
    late_fee_first_minor = 1000,
    late_fee_next_minor = 500,
    late_fee_effective_on = '2026-03-01',
    booth_deposits_enabled = true;

-- March is charged on Mar 20, and its late fee falls on Mar 29. The fee is
-- waived on Apr 2. October is charged on Nov 5, after Mary left. Joe's and
-- Mary's new booth are charged for November, and Booth 14 takes a sign.
DO $$
DECLARE
    fee uuid;
BEGIN
    PERFORM stall_post_rent_charge('01900000-0000-7000-8000-00000000f3a1', '2026-03-01', '2026-03-20');
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF stall_post_late_fees('2026-03-31') <> 1 THEN
        RAISE EXCEPTION 'March did not take one late fee';
    END IF;
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    SELECT id INTO fee FROM journals WHERE posting_key = 'late-fee:01900000-0000-7000-8000-00000000f3a1:2026-03:1';
    PERFORM stall_waive_late_fee(fee, '2026-04-02', 'First month');
    PERFORM stall_post_rent_charge('01900000-0000-7000-8000-00000000f3a1', '2026-10-01', '2026-11-05');
    PERFORM stall_post_rent_charge('01900000-0000-7000-8000-00000000f3a2', '2026-11-01', '2026-11-02');
    PERFORM stall_post_rent_charge('01900000-0000-7000-8000-00000000f3a3', '2026-11-01', '2026-11-03');
    PERFORM stall_post_booth_charge('01900000-0000-7000-8000-00000000f3a3', 1500, 'Sign', '2026-11-04');
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF stall_vendor_open_rent('01900000-0000-7000-8000-00000000f101') <> 2903 + 6000 + 4000 + 1500 THEN
        RAISE EXCEPTION 'Mary does not owe March, October, November and the sign';
    END IF;
END $$;

-- Nov 5: $50.00 by card. Her oldest unpaid charge is March on Booth 12, so the
-- split puts it all there: March, then $20.97 of October. The allocation of
-- that receipt is the same answer the split gave.
DO $$
DECLARE
    receipt uuid;
    split_said text;
BEGIN
    SELECT string_agg(assignment_id || '=' || amount_minor, ' ' ORDER BY assignment_id)
    INTO split_said
    FROM stall_rent_payment_split('01900000-0000-7000-8000-00000000f101', 5000);
    IF split_said IS DISTINCT FROM '01900000-0000-7000-8000-00000000f3a1=5000' THEN
        RAISE EXCEPTION 'the Nov 5 split was %', split_said;
    END IF;
    receipt := pg_temp.pay(0, 5000, 0, '2026-11-05');
    IF (
        SELECT string_agg(assignment_id || '=' || total, ' ' ORDER BY assignment_id)
        FROM (
            SELECT assignment_id, sum(amount_minor) AS total
            FROM stall_rent_allocation('01900000-0000-7000-8000-00000000f101')
            WHERE payment_journal = receipt
            GROUP BY assignment_id
        ) AS shares
    ) IS DISTINCT FROM split_said THEN
        RAISE EXCEPTION 'the allocation of the Nov 5 receipt is not the split';
    END IF;
    IF (
        SELECT string_agg(charge.posting_key || '=' || share.amount_minor, ' ' ORDER BY charge.posting_date)
        FROM stall_rent_allocation('01900000-0000-7000-8000-00000000f101') AS share
        JOIN journals AS charge ON charge.id = share.charge_journal
        WHERE share.payment_journal = receipt
    ) IS DISTINCT FROM 'rent-charge:01900000-0000-7000-8000-00000000f3a1:2026-03=2903 rent-charge:01900000-0000-7000-8000-00000000f3a1:2026-10=2097' THEN
        RAISE EXCEPTION 'the Nov 5 receipt did not pay March and then October';
    END IF;
    PERFORM pg_temp.refuse_history_drift('01900000-0000-7000-8000-00000000f101');
END $$;

-- Nov 6: $30.00 cash and a $30.00 check. Booth 14's November (charged Nov 3)
-- is now older than what is left of October (charged Nov 5), so it is paid
-- first, with the sign, and October takes the last $5.00.
DO $$
DECLARE
    receipt uuid;
BEGIN
    receipt := pg_temp.pay(3000, 0, 3000, '2026-11-06');
    IF stall_assignment_open_rent('01900000-0000-7000-8000-00000000f3a3') <> 0
       OR stall_assignment_open_rent('01900000-0000-7000-8000-00000000f3a1') <> 3403 THEN
        RAISE EXCEPTION 'the Nov 6 payment did not pay Booth 14 first';
    END IF;
    IF (SELECT how FROM stall_rent_credit_source(receipt)) <> 'Cash and Check'
       OR (SELECT paid_by FROM stall_rent_credit_source(receipt)) <> 'split' THEN
        RAISE EXCEPTION 'a cash and check receipt reads as %', (SELECT how FROM stall_rent_credit_source(receipt));
    END IF;
    PERFORM pg_temp.refuse_history_drift('01900000-0000-7000-8000-00000000f101');
END $$;

-- Nov 7: $10.00 by card, then voided. The void gives the money back to the
-- books: the charges it paid are owed again, and the history no longer shows it.
DO $$
DECLARE
    receipt uuid;
    voided uuid := uuidv7();
BEGIN
    receipt := pg_temp.pay(0, 1000, 0, '2026-11-07');
    IF stall_assignment_open_rent('01900000-0000-7000-8000-00000000f3a1') <> 2403 THEN
        RAISE EXCEPTION 'the Nov 7 card payment did not pay October';
    END IF;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id, currency, source_type,
        source_reference, description, is_reversal, reverses_journal_id, debit_total_minor, credit_total_minor
    )
    SELECT voided, book_id, 'reversal:' || id::text, '2026-11-07', clock_timestamp(), period_id, currency, source_type,
           source_reference, 'Void', true, id, credit_total_minor, debit_total_minor
    FROM journals WHERE id = receipt;
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
    SELECT voided, book_id, line_no, account_id, credit_minor, debit_minor, subledger_type, subledger_ref
    FROM journal_lines WHERE journal_id = receipt;
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-00000000fd22', voided);
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF stall_assignment_open_rent('01900000-0000-7000-8000-00000000f3a1') <> 3403 THEN
        RAISE EXCEPTION 'the void did not leave October owed again';
    END IF;
    IF EXISTS (SELECT 1 FROM stall_rent_allocation('01900000-0000-7000-8000-00000000f101')
               WHERE payment_journal IN (receipt, voided)) THEN
        RAISE EXCEPTION 'a voided receipt still pays a charge';
    END IF;
    PERFORM pg_temp.refuse_history_drift('01900000-0000-7000-8000-00000000f101');
END $$;

-- Nov 8: a $20.00 deposit on Booth 14, and Nov 9 a $12.00 credit memo.
DO $$
DECLARE
    taken uuid;
    memo uuid;
BEGIN
    taken := stall_post_booth_deposit('01900000-0000-7000-8000-00000000f3a3', 2000, 'Key', '2026-11-08', false);
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-00000000fd22', taken);
    memo := stall_post_lines(
        'vendor-memo:reports', '2026-11-09', 'vendor_memo', 'reports', 'Vendor memo',
        jsonb_build_array(
            stall_line('vendor_allowance', 1200, 0),
            stall_line('vendor_payable', 0, 1200, 'vendor', '01900000-0000-7000-8000-00000000f101')
        )
    );
    INSERT INTO vendor_memos (journal_id, vendor_id, direction, amount_minor, reason)
    VALUES (memo, '01900000-0000-7000-8000-00000000f101', 'credit', 1200, 'Fixed a shelf');
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF NOT EXISTS (
        SELECT 1 FROM vendor_statement
        WHERE vendor_id = '01900000-0000-7000-8000-00000000f101' AND role = 'booth_deposit_held' AND credit_minor = 2000
    ) THEN
        RAISE EXCEPTION 'the vendor statement does not carry her deposit';
    END IF;
END $$;

-- The rent history: newest month first, March's waived fee under March, the
-- Nov 5 card payment named with its register and cashier.
DO $$
DECLARE
    march record;
    fee record;
    first_paid jsonb;
BEGIN
    PERFORM pg_temp.refuse_history_drift('01900000-0000-7000-8000-00000000f101');
    PERFORM pg_temp.refuse_history_drift('01900000-0000-7000-8000-00000000f102');
    IF (SELECT period_start FROM stall_vendor_rent_history('01900000-0000-7000-8000-00000000f101') LIMIT 1) <> '2026-11-01' THEN
        RAISE EXCEPTION 'the rent history does not start with the newest month';
    END IF;
    SELECT * INTO march FROM stall_vendor_rent_history('01900000-0000-7000-8000-00000000f101')
    WHERE period_start = '2026-03-01' AND kind = 'rent';
    SELECT * INTO fee FROM stall_vendor_rent_history('01900000-0000-7000-8000-00000000f101')
    WHERE period_start = '2026-03-01' AND kind = 'late_fee';
    IF march.charged_minor <> 2903 OR march.owed_minor <> 0 OR march.booth_code <> '12' THEN
        RAISE EXCEPTION 'March reads % charged, % owed', march.charged_minor, march.owed_minor;
    END IF;
    IF NOT fee.waived OR fee.charged_minor <> 1000 THEN
        RAISE EXCEPTION 'the waived March fee is not under March';
    END IF;
    first_paid := march.payments -> 0;
    IF jsonb_array_length(march.payments) <> 1
       OR first_paid ->> 'how' <> 'Card'
       OR first_paid ->> 'register' <> 'Register'
       OR first_paid ->> 'cashier' <> 'Ada'
       OR (first_paid ->> 'amount_minor')::bigint <> 2903 THEN
        RAISE EXCEPTION 'March was not shown paid by card at the register: %', march.payments;
    END IF;
    IF EXISTS (SELECT 1 FROM stall_vendor_rent_history('01900000-0000-7000-8000-00000000f101') WHERE booth_code = '12'
               AND period_start = '2026-11-01') THEN
        RAISE EXCEPTION 'Joe''s November shows on Mary''s history';
    END IF;
    IF (SELECT count(*) FROM stall_vendor_rent_history('01900000-0000-7000-8000-00000000f101')
        WHERE period_start = '2026-11-01' AND booth_code = '14') <> 2 THEN
        RAISE EXCEPTION 'Booth 14''s November rent and sign are not both under November';
    END IF;
END $$;

-- Rent payments in November: each desk receipt with its register and cashier,
-- the months it paid, the voided one marked, and the totals equal to the
-- credits to rent in the range.
DO $$
DECLARE
    card_row record;
    listed bigint;
    credited bigint;
BEGIN
    SELECT * INTO card_row FROM stall_rent_payments('2026-11-01', '2026-11-30')
    WHERE posted_on = '2026-11-05';
    IF card_row.months IS DISTINCT FROM 'Mar 2026, Oct 2026' OR card_row.paid_by <> 'card'
       OR card_row.register_name <> 'Register' OR card_row.cashier <> 'Ada'
       OR card_row.booth_code <> '12' OR card_row.voided OR card_row.amount_minor <> 5000 THEN
        RAISE EXCEPTION 'the Nov 5 receipt reads %', card_row;
    END IF;
    IF EXISTS (
        SELECT 1 FROM stall_rent_payments('2026-11-01', '2026-11-30')
        WHERE paid_by IN ('cash', 'card', 'check', 'split') AND (register_name IS NULL OR cashier IS NULL)
    ) THEN
        RAISE EXCEPTION 'a desk payment does not name its register and cashier';
    END IF;
    IF NOT (SELECT voided AND months IS NULL FROM stall_rent_payments('2026-11-01', '2026-11-30') WHERE posted_on = '2026-11-07') THEN
        RAISE EXCEPTION 'the voided Nov 7 receipt is not marked voided';
    END IF;
    IF (SELECT string_agg(booth_code || ':' || months, ' ' ORDER BY booth_code)
        FROM stall_rent_payments('2026-11-01', '2026-11-30') WHERE posted_on = '2026-11-06') <> '12:Oct 2026 14:Nov 2026' THEN
        RAISE EXCEPTION 'the Nov 6 receipt does not show one row per booth with its months';
    END IF;
    SELECT sum(amount_minor) INTO listed FROM stall_rent_payments('2026-11-01', '2026-11-30');
    SELECT sum(line.credit_minor - line.debit_minor) INTO credited
    FROM journal_lines AS line
    JOIN journals AS posted ON posted.id = line.journal_id
    JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'booth_rent_receivable'
    WHERE posted.posting_date BETWEEN '2026-11-01' AND '2026-11-30'
      AND NOT posted.is_reversal
      AND posted.source_type IN ('rent_payment', 'settlement', 'sale');
    IF listed <> credited OR listed <> 5000 + 6000 + 1000 THEN
        RAISE EXCEPTION 'rent payments list % but rent was credited %', listed, credited;
    END IF;
    IF EXISTS (SELECT 1 FROM stall_rent_payments('2026-11-01', '2026-11-30', NULL, NULL, 'card') WHERE paid_by <> 'card')
       OR (SELECT count(*) FROM stall_rent_payments('2026-11-01', '2026-11-30', NULL, NULL, 'card')) <> 2 THEN
        RAISE EXCEPTION 'the card filter does not keep only the card receipts';
    END IF;
    IF EXISTS (SELECT 1 FROM stall_rent_payments('2026-11-01', '2026-11-30', NULL, '01900000-0000-7000-8000-00000000f202')
               WHERE booth_code <> '14') THEN
        RAISE EXCEPTION 'the booth filter let in another booth';
    END IF;
    IF EXISTS (SELECT 1 FROM stall_rent_payments('2026-11-01', '2026-11-30', '01900000-0000-7000-8000-00000000f102')) THEN
        RAISE EXCEPTION 'Joe has rent payments he never made';
    END IF;
    IF (SELECT string_agg(paid_by || '/' || how || '/' || months || '/' || amount_minor, ' ')
        FROM stall_rent_payments('2026-04-01', '2026-04-30')) IS DISTINCT FROM 'waived/Waived/Mar 2026/1000' THEN
        RAISE EXCEPTION 'the April waive is not on the rent payments list';
    END IF;
    IF (SELECT count(*) FROM stall_rent_payments('2026-11-01', '2026-11-30', NULL, NULL, NULL, 'MARY')) <> 4
       OR EXISTS (SELECT 1 FROM stall_rent_payments('2026-11-01', '2026-11-30', NULL, NULL, NULL, '%'))
       OR (SELECT count(*) FROM stall_rent_payments('2026-11-01', '2026-11-30', NULL, NULL, NULL, card_row.journal_no::text)) <> 1
       OR (SELECT count(*) FROM stall_rent_payments('2026-11-01', '2026-11-30', NULL, NULL, NULL, ' 14 ')) <> 1 THEN
        RAISE EXCEPTION 'the search does not find by name, receipt number and booth, as typed';
    END IF;
    -- The totals: the voided receipt is left out and counted on its own; the
    -- subtotals by how paid add up to the total.
    IF (SELECT payments || '=' || amount_minor FROM stall_rent_payment_totals('2026-11-01', '2026-11-30') WHERE total_kind = 'all') <> '3=11000'
       OR (SELECT sum(amount_minor) FROM stall_rent_payment_totals('2026-11-01', '2026-11-30') WHERE total_kind = 'paid_by') <> 11000
       OR (SELECT amount_minor FROM stall_rent_payment_totals('2026-11-01', '2026-11-30') WHERE total_kind = 'paid_by' AND label = 'Card') <> 5000
       OR (SELECT amount_minor FROM stall_rent_payment_totals('2026-11-01', '2026-11-30') WHERE total_kind = 'paid_by' AND label = 'More than one way') <> 6000
       OR (SELECT label || '=' || amount_minor FROM stall_rent_payment_totals('2026-11-01', '2026-11-30') WHERE total_kind = 'register') <> 'Register=11000'
       OR (SELECT payments || '=' || amount_minor FROM stall_rent_payment_totals('2026-11-01', '2026-11-30') WHERE total_kind = 'voided') <> '1=1000' THEN
        RAISE EXCEPTION 'the rent payment totals are wrong: %', (SELECT string_agg(total_kind || ':' || label || ':' || payments || ':' || amount_minor, ' ') FROM stall_rent_payment_totals('2026-11-01', '2026-11-30'));
    END IF;
    PERFORM pg_temp.refused($q$SELECT * FROM stall_rent_payments('2026-11-30', '2026-11-01')$q$, 'Choose a start date on or before the end date.');
    PERFORM pg_temp.refused($q$SELECT * FROM stall_rent_payments('2026-11-01', '2026-11-30', NULL, NULL, 'gold')$q$, 'Choose how it was paid.');
END $$;

-- The vendor register ties to the books for every range, with and without a
-- booth, and its closing balance is the vendor's balances to the penny.
DO $$
DECLARE
    told record;
    closing bigint;
    ranges daterange[] := ARRAY[
        daterange('2026-01-01', '2026-12-31', '[]'),
        daterange('2026-03-01', '2026-03-31', '[]'),
        daterange('2026-04-02', '2026-04-02', '[]'),
        daterange('2026-11-01', '2026-11-30', '[]'),
        daterange('2026-11-06', '2026-11-07', '[]')
    ];
    span daterange;
    vendor uuid;
BEGIN
    FOREACH span IN ARRAY ranges
    LOOP
        FOREACH vendor IN ARRAY ARRAY['01900000-0000-7000-8000-00000000f101'::uuid, '01900000-0000-7000-8000-00000000f102'::uuid]
        LOOP
            PERFORM pg_temp.refuse_register_drift(vendor, lower(span), upper(span) - 1);
            PERFORM pg_temp.refuse_register_drift(vendor, lower(span), upper(span) - 1, '01900000-0000-7000-8000-00000000f201');
            PERFORM pg_temp.refuse_register_drift(vendor, lower(span), upper(span) - 1, '01900000-0000-7000-8000-00000000f202');
        END LOOP;
    END LOOP;
    SELECT * INTO told FROM stall_vendor_balances('01900000-0000-7000-8000-00000000f101');
    SELECT balance_minor INTO closing
    FROM stall_vendor_register('01900000-0000-7000-8000-00000000f101', '2026-01-01', '2026-12-31')
    WHERE row_kind = 'closing';
    IF closing <> told.payable_minor - told.rent_minor - told.clawback_minor + told.deposit_minor
       OR closing <> 1200 - 3403 + 2000 THEN
        RAISE EXCEPTION 'the register closes at % but her balances say %', closing,
            told.payable_minor - told.rent_minor - told.clawback_minor + told.deposit_minor;
    END IF;
    IF (SELECT balance_minor FROM stall_vendor_register('01900000-0000-7000-8000-00000000f101', '2026-01-01', '2026-12-31')
        WHERE row_kind = 'rent') <> 3403
       OR (SELECT balance_minor FROM stall_vendor_register('01900000-0000-7000-8000-00000000f101', '2026-01-01', '2026-12-31')
        WHERE row_kind = 'payable') <> 1200
       OR (SELECT balance_minor FROM stall_vendor_register('01900000-0000-7000-8000-00000000f101', '2026-01-01', '2026-12-31')
        WHERE row_kind = 'deposit') <> 2000 THEN
        RAISE EXCEPTION 'the register split does not match what she owes and is owed';
    END IF;
    IF EXISTS (
        SELECT 1 FROM stall_vendor_register('01900000-0000-7000-8000-00000000f101', '2026-01-01', '2026-12-31', '01900000-0000-7000-8000-00000000f202')
        WHERE row_kind = 'line' AND booth_code IS DISTINCT FROM '14'
    ) THEN
        RAISE EXCEPTION 'the Booth 14 register shows a line from another booth';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM stall_vendor_register('01900000-0000-7000-8000-00000000f101', '2026-01-01', '2026-12-31')
        WHERE row_kind = 'line' AND what = 'Rent for October 2026' AND booth_code = '12' AND debit_minor = 6000
    ) OR NOT EXISTS (
        SELECT 1 FROM stall_vendor_register('01900000-0000-7000-8000-00000000f101', '2026-01-01', '2026-12-31')
        WHERE row_kind = 'line' AND what LIKE 'Rent payment, receipt #%' AND credit_minor = 5000
    ) OR NOT EXISTS (
        SELECT 1 FROM stall_vendor_register('01900000-0000-7000-8000-00000000f101', '2026-01-01', '2026-12-31')
        WHERE row_kind = 'line' AND what = 'Booth deposit taken' AND credit_minor = 2000
    ) THEN
        RAISE EXCEPTION 'the register does not say what happened in plain words';
    END IF;
    IF EXISTS (
        SELECT 1 FROM stall_vendor_register('01900000-0000-7000-8000-00000000f102', '2026-01-01', '2026-12-31')
        WHERE row_kind = 'line' AND booth_code = '14'
    ) THEN
        RAISE EXCEPTION 'Mary''s Booth 14 shows on Joe''s register';
    END IF;
    PERFORM pg_temp.refused($q$SELECT * FROM stall_vendor_register('01900000-0000-7000-8000-00000000f999', '2026-01-01', '2026-01-31')$q$, 'Choose a vendor.');
    PERFORM pg_temp.refused($q$SELECT * FROM stall_vendor_register('01900000-0000-7000-8000-00000000f101', '2026-02-01', '2026-01-31')$q$, 'Choose a start date on or before the end date.');
    PERFORM pg_temp.refused($q$SELECT * FROM stall_vendor_register('01900000-0000-7000-8000-00000000f101', '2026-01-01', '2026-01-31', '01900000-0000-7000-8000-00000000f999')$q$, 'Choose a booth.');
END $$;

-- Rent and deposit lines name a stay. A line that names a booth is refused.
DO $$
BEGIN
    PERFORM pg_temp.refused(
        $q$SELECT stall_post_lines('booth-line:rent', '2026-11-10', 'rent_charge', 'booth-line', 'Rent',
            jsonb_build_array(
                stall_line('booth_rent_receivable', 100, 0, 'booth', '01900000-0000-7000-8000-00000000f201'),
                stall_line('rent_revenue', 0, 100)
            ))$q$,
        'a line on a control account requires that subledger');
    PERFORM pg_temp.refused(
        $q$SELECT stall_post_lines('booth-line:deposit', '2026-11-10', 'booth_deposit', 'booth-line', 'Deposit',
            jsonb_build_array(
                stall_line('cash', 100, 0),
                stall_line('booth_deposit_held', 0, 100, 'booth', '01900000-0000-7000-8000-00000000f201')
            ))$q$,
        'a line on a control account requires that subledger');
END $$;

ROLLBACK;
