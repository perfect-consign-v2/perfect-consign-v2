-- A wrong-booth correction (T6). The database moves the piece's vendor share
-- (price less discount and the store's take), never the store's take, from
-- the vendor the sale paid to the one who should have been paid, once. A
-- hand-built correction must do the same, and the guard cannot be switched
-- off. A correction that pays cash instead of a vendor is refused. One
-- transaction, then ROLLBACK.

BEGIN;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000e821',
    '01900000-0000-7000-8000-000000000001',
    '2096-01-01',
    '2096-12-31',
    'open'
);

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000e822', 'e822', 'Wrong', 'Booth');
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000e823', 'e823', 'Right', 'Booth');

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e824',
        '01900000-0000-7000-8000-000000000001',
        'booth-correction:proof',
        '2096-03-01',
        '2096-03-01 12:00:00+00',
        '01900000-0000-7000-8000-00000000e821',
        'USD',
        'booth_correction',
        'proof',
        'Bad booth correction',
        500,
        500
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000e824',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000014',
        500,
        0,
        'vendor',
        '01900000-0000-7000-8000-00000000e822'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-00000000e824',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000010',
        0,
        500
    );
    RAISE EXCEPTION 'a booth correction that paid cash was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a chart account line must come from a listed source type%' THEN
            RAISE;
        END IF;
END $$;


CREATE FUNCTION pg_temp.refused(attempt text, wanted text, needle text) RETURNS void
    LANGUAGE plpgsql
    AS $fn$
BEGIN
    BEGIN
        EXECUTE attempt;
        SET CONSTRAINTS ALL IMMEDIATE;
    EXCEPTION
        WHEN OTHERS THEN
            SET CONSTRAINTS ALL DEFERRED;
            IF SQLSTATE <> wanted OR SQLERRM NOT LIKE '%' || needle || '%' THEN
                RAISE EXCEPTION '% was refused for the wrong reason: % %', attempt, SQLSTATE, SQLERRM;
            END IF;
            RETURN;
    END;
    RAISE EXCEPTION 'accepted: %', attempt;
END;
$fn$;

UPDATE mall SET tax_bps = 0, cash_round_minor = 0;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps, quantity)
VALUES
    ('01900000-0000-7000-8000-00000000e831', 'Mirror', '01900000-0000-7000-8000-00000000e822', 'Hall mirror', 10000, 'BC-WRONG-MIRROR', DATE '2096-02-01', 4000, 1),
    ('01900000-0000-7000-8000-00000000e832', 'Clock', '01900000-0000-7000-8000-00000000e822', 'Shelf clock', 5000, 'BC-WRONG-CLOCK', DATE '2096-02-01', 4000, 1);

INSERT INTO registers (id, name) VALUES ('01900000-0000-7000-8000-00000000e833', 'Wrong booth proof');
INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES ('01900000-0000-7000-8000-00000000e834', '01900000-0000-7000-8000-00000000e833', 'Wren',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb, 0);

-- One sale of both pieces: $150.00, the store keeps $60.00, the wrong vendor
-- is credited $90.00.
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000e840', '01900000-0000-7000-8000-000000000001', 'sale:wrong-booth', '2096-03-02', '2096-03-02 12:00:00+00',
    '01900000-0000-7000-8000-00000000e821', 'USD', 'sale', 'wrong-booth', 'Mirror and clock', 15000, 15000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES
    ('01900000-0000-7000-8000-00000000e840', '01900000-0000-7000-8000-000000000001', 1, (SELECT account_id FROM chart_roles WHERE role = 'cash'), 15000, 0, NULL, NULL),
    ('01900000-0000-7000-8000-00000000e840', '01900000-0000-7000-8000-000000000001', 2, (SELECT account_id FROM chart_roles WHERE role = 'commission_revenue'), 0, 6000, NULL, NULL),
    ('01900000-0000-7000-8000-00000000e840', '01900000-0000-7000-8000-000000000001', 3, (SELECT account_id FROM chart_roles WHERE role = 'vendor_payable'), 0, 9000, 'vendor', '01900000-0000-7000-8000-00000000e822');
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES
    ('01900000-0000-7000-8000-00000000e840', 1, '01900000-0000-7000-8000-00000000e831', 10000, 4000, 0),
    ('01900000-0000-7000-8000-00000000e840', 2, '01900000-0000-7000-8000-00000000e832', 5000, 2000, 0);
INSERT INTO register_session_posts (session_id, journal_id) VALUES ('01900000-0000-7000-8000-00000000e834', '01900000-0000-7000-8000-00000000e840');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

-- The guard runs in every session and cannot be switched off, dropped or replaced.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgname = 'journals_wrong_booth_sealed' AND tgrelid = 'journals'::regclass AND tgenabled = 'A'
    ) OR NOT platform.guarded_function(to_regprocedure('stall_assert_booth_correction()')) THEN
        RAISE EXCEPTION 'the wrong-booth guard is missing, not ENABLE ALWAYS, or not sealed';
    END IF;
    PERFORM pg_temp.refused('ALTER TABLE journals DISABLE TRIGGER journals_wrong_booth_sealed', '25006', 'append-only triggers cannot be disabled');
    PERFORM pg_temp.refused('ALTER TABLE journals ENABLE REPLICA TRIGGER journals_wrong_booth_sealed', '25006', 'append-only triggers cannot be disabled');
    PERFORM pg_temp.refused('DROP TRIGGER journals_wrong_booth_sealed ON journals', '25006', 'append-only triggers cannot be dropped');
    PERFORM pg_temp.refused('DROP FUNCTION stall_assert_booth_correction() CASCADE', '25006', '');
    PERFORM pg_temp.refused(
        'CREATE OR REPLACE FUNCTION stall_assert_booth_correction() RETURNS trigger LANGUAGE plpgsql AS $body$ BEGIN RETURN NULL; END; $body$',
        '25006', 'a guard function cannot be replaced');
END $$;

-- The application role: plain refusals, then the move of the vendor share.
DO $$
DECLARE
    posted uuid;
BEGIN
    SET LOCAL ROLE stall_poster;
    PERFORM pg_temp.refused('SELECT stall_booth_correction(''01900000-0000-7000-8000-00000000e840'', ''01900000-0000-7000-8000-00000000e831'', ''01900000-0000-7000-8000-00000000e822'', DATE ''2096-03-03'')', '23514', 'Choose the booth that should have been paid.');
    PERFORM pg_temp.refused('SELECT stall_booth_correction(''01900000-0000-7000-8000-00000000e840'', ''01900000-0000-7000-8000-00000000e831'', ''01900000-0000-7000-8000-00000000e8ff'', DATE ''2096-03-03'')', '23514', 'Choose the booth that should have been paid.');
    PERFORM pg_temp.refused('SELECT stall_booth_correction(''01900000-0000-7000-8000-00000000e840'', ''01900000-0000-7000-8000-00000000e833'', ''01900000-0000-7000-8000-00000000e823'', DATE ''2096-03-03'')', '23514', 'that item is not on the ticket');
    PERFORM pg_temp.refused('SELECT stall_booth_correction(''01900000-0000-7000-8000-00000000e831'', ''01900000-0000-7000-8000-00000000e831'', ''01900000-0000-7000-8000-00000000e823'', DATE ''2096-03-03'')', '23514', 'that sale is not posted');
    PERFORM pg_temp.refused('CREATE OR REPLACE FUNCTION stall_booth_correction(uuid, uuid, uuid, date) RETURNS uuid LANGUAGE sql AS ''SELECT NULL::uuid''', '42501', '');
    PERFORM pg_temp.refused('DROP FUNCTION stall_booth_correction(uuid, uuid, uuid, date)', '42501', '');

    posted := stall_booth_correction('01900000-0000-7000-8000-00000000e840', '01900000-0000-7000-8000-00000000e831', '01900000-0000-7000-8000-00000000e823', DATE '2096-03-03');
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    PERFORM pg_temp.refused('SELECT stall_booth_correction(''01900000-0000-7000-8000-00000000e840'', ''01900000-0000-7000-8000-00000000e831'', ''01900000-0000-7000-8000-00000000e823'', DATE ''2096-03-03'')', '23514', 'That item was already moved to another booth.');
    RESET ROLE;

    -- The mirror's share is $60.00 ($100.00 less the store's $40.00), not the
    -- store's $40.00.
    IF stall_vendor_open_payable('01900000-0000-7000-8000-00000000e822') <> 3000
       OR stall_vendor_open_payable('01900000-0000-7000-8000-00000000e823') <> 6000
       OR (SELECT posting_key || '/' || posting_date FROM journals WHERE id = posted)
          <> 'booth-correction:01900000-0000-7000-8000-00000000e840:01900000-0000-7000-8000-00000000e831/2096-03-03' THEN
        RAISE EXCEPTION 'the correction did not move the mirror''s vendor share';
    END IF;
END $$;

-- A hand-built correction must name a sale and a piece on it and move exactly
-- that piece's vendor share from the vendor the sale paid.
DO $$
DECLARE
    attempt record;
BEGIN
    FOR attempt IN
        SELECT * FROM (VALUES
            ('proof-clock', '01900000-0000-7000-8000-00000000e822', 3000::bigint, 'a booth correction names a sale and a piece on it'),
            ('01900000-0000-7000-8000-00000000e840' || ':' || '01900000-0000-7000-8000-00000000e832', '01900000-0000-7000-8000-00000000e822', 2000::bigint, 'a booth correction moves that piece''s vendor share to another vendor'),
            ('01900000-0000-7000-8000-00000000e840' || ':' || '01900000-0000-7000-8000-00000000e832', '01900000-0000-7000-8000-00000000e823', 3000::bigint, 'a booth correction moves that piece''s vendor share to another vendor'),
            ('01900000-0000-7000-8000-00000000e831' || ':' || '01900000-0000-7000-8000-00000000e832', '01900000-0000-7000-8000-00000000e822', 3000::bigint, 'a booth correction moves that piece''s vendor share to another vendor')
        ) AS listed (reference, taken_from, amount, wanted)
    LOOP
        BEGIN
            INSERT INTO journals (
                id, book_id, posting_key, posting_date, occurred_at, period_id,
                currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
            ) VALUES (
                '01900000-0000-7000-8000-00000000e841', '01900000-0000-7000-8000-000000000001', 'booth-correction:' || attempt.reference,
                '2096-03-03', '2096-03-03 13:00:00+00', '01900000-0000-7000-8000-00000000e821', 'USD', 'booth_correction', attempt.reference,
                'Hand-built correction', attempt.amount, attempt.amount
            );
            INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
            VALUES
                ('01900000-0000-7000-8000-00000000e841', '01900000-0000-7000-8000-000000000001', 1, (SELECT account_id FROM chart_roles WHERE role = 'vendor_payable'),
                 attempt.amount, 0, 'vendor', attempt.taken_from),
                ('01900000-0000-7000-8000-00000000e841', '01900000-0000-7000-8000-000000000001', 2, (SELECT account_id FROM chart_roles WHERE role = 'vendor_payable'),
                 0, attempt.amount, 'vendor', CASE attempt.taken_from WHEN '01900000-0000-7000-8000-00000000e822' THEN '01900000-0000-7000-8000-00000000e823' ELSE '01900000-0000-7000-8000-00000000e822' END);
            SET CONSTRAINTS ALL IMMEDIATE;
            RAISE EXCEPTION 'accepted a hand-built correction for %', attempt.reference;
        EXCEPTION
            WHEN check_violation THEN
                IF SQLERRM <> attempt.wanted THEN
                    RAISE EXCEPTION '% was refused for the wrong reason: %', attempt.reference, SQLERRM;
                END IF;
        END;
        SET CONSTRAINTS ALL DEFERRED;
    END LOOP;
END $$;

ROLLBACK;
