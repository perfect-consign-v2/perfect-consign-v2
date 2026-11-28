-- A bounced check (T7). The store marks a check it took as returned by the
-- bank: the database finds the check by its number, posts exactly its amount
-- from checks to returned checks, and only once while that bounce stands. A
-- check from a voided sale is not one the store still holds. A hand-built
-- bounce must do the same, and the guard cannot be switched off. One
-- transaction, then ROLLBACK.

BEGIN;

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

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES ('01900000-0000-7000-8000-00000000c701', '01900000-0000-7000-8000-000000000001', '2097-01-01', '2097-12-31', 'open');

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000c702', 'nsf', 'Nell', 'Check', 0);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps, quantity)
VALUES
    ('01900000-0000-7000-8000-00000000c703', 'Quilt', '01900000-0000-7000-8000-00000000c702', 'Patch quilt', 5000, 'BC-NSF-QUILT', DATE '2097-03-01', 0, 1),
    ('01900000-0000-7000-8000-00000000c704', 'Rug', '01900000-0000-7000-8000-00000000c702', 'Braided rug', 3000, 'BC-NSF-RUG', DATE '2097-03-01', 0, 1);

INSERT INTO registers (id, name) VALUES ('01900000-0000-7000-8000-00000000c705', 'Bounced check proof');
INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES ('01900000-0000-7000-8000-00000000c706', '01900000-0000-7000-8000-00000000c705', 'Nell',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb, 0);

-- Two sales paid by check: the quilt by check 7781, the rug by check 7782.
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES
    ('01900000-0000-7000-8000-00000000c711', '01900000-0000-7000-8000-000000000001', 'sale:nsf-quilt', '2097-03-02', '2097-03-02 12:00:00+00', '01900000-0000-7000-8000-00000000c701', 'USD', 'sale', 'nsf-quilt', 'Quilt sale', 5000, 5000),
    ('01900000-0000-7000-8000-00000000c712', '01900000-0000-7000-8000-000000000001', 'sale:nsf-rug', '2097-03-02', '2097-03-02 12:10:00+00', '01900000-0000-7000-8000-00000000c701', 'USD', 'sale', 'nsf-rug', 'Rug sale', 3000, 3000);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES
    ('01900000-0000-7000-8000-00000000c711', '01900000-0000-7000-8000-000000000001', 1, (SELECT account_id FROM chart_roles WHERE role = 'checks'), 5000, 0, NULL, NULL),
    ('01900000-0000-7000-8000-00000000c711', '01900000-0000-7000-8000-000000000001', 2, (SELECT account_id FROM chart_roles WHERE role = 'vendor_payable'), 0, 5000, 'vendor', '01900000-0000-7000-8000-00000000c702'),
    ('01900000-0000-7000-8000-00000000c712', '01900000-0000-7000-8000-000000000001', 1, (SELECT account_id FROM chart_roles WHERE role = 'checks'), 3000, 0, NULL, NULL),
    ('01900000-0000-7000-8000-00000000c712', '01900000-0000-7000-8000-000000000001', 2, (SELECT account_id FROM chart_roles WHERE role = 'vendor_payable'), 0, 3000, 'vendor', '01900000-0000-7000-8000-00000000c702');
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-00000000c711', 1, '01900000-0000-7000-8000-00000000c703', 5000, 0, 0), ('01900000-0000-7000-8000-00000000c712', 1, '01900000-0000-7000-8000-00000000c704', 3000, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id) VALUES ('01900000-0000-7000-8000-00000000c706', '01900000-0000-7000-8000-00000000c711'), ('01900000-0000-7000-8000-00000000c706', '01900000-0000-7000-8000-00000000c712');
INSERT INTO register_checks (journal_id, check_number) VALUES ('01900000-0000-7000-8000-00000000c711', '7781'), ('01900000-0000-7000-8000-00000000c712', '7782');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

-- The rug sale is voided, so check 7782 is no longer one the store holds.
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor,
    is_reversal, reverses_journal_id
) VALUES (
    '01900000-0000-7000-8000-00000000c713', '01900000-0000-7000-8000-000000000001', 'reversal:01900000-0000-7000-8000-00000000c712', '2097-03-02', '2097-03-02 12:20:00+00', '01900000-0000-7000-8000-00000000c701',
    'USD', 'sale', 'nsf-rug:void', 'Void rug', 3000, 3000, true, '01900000-0000-7000-8000-00000000c712'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES
    ('01900000-0000-7000-8000-00000000c713', '01900000-0000-7000-8000-000000000001', 1, (SELECT account_id FROM chart_roles WHERE role = 'checks'), 0, 3000, NULL, NULL),
    ('01900000-0000-7000-8000-00000000c713', '01900000-0000-7000-8000-000000000001', 2, (SELECT account_id FROM chart_roles WHERE role = 'vendor_payable'), 3000, 0, 'vendor', '01900000-0000-7000-8000-00000000c702');
INSERT INTO register_session_posts (session_id, journal_id) VALUES ('01900000-0000-7000-8000-00000000c706', '01900000-0000-7000-8000-00000000c713');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

-- The guard runs in every session and cannot be switched off, dropped or replaced.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgname = 'journals_check_return_sealed' AND tgrelid = 'journals'::regclass AND tgenabled = 'A'
    ) OR NOT platform.guarded_function(to_regprocedure('stall_assert_check_return()')) THEN
        RAISE EXCEPTION 'the bounced check guard is missing, not ENABLE ALWAYS, or not sealed';
    END IF;
    PERFORM pg_temp.refused('ALTER TABLE journals DISABLE TRIGGER journals_check_return_sealed', '25006', 'append-only triggers cannot be disabled');
    PERFORM pg_temp.refused('ALTER TABLE journals ENABLE REPLICA TRIGGER journals_check_return_sealed', '25006', 'append-only triggers cannot be disabled');
    PERFORM pg_temp.refused('DROP TRIGGER journals_check_return_sealed ON journals', '25006', 'append-only triggers cannot be dropped');
    PERFORM pg_temp.refused('DROP FUNCTION stall_assert_check_return() CASCADE', '25006', '');
    PERFORM pg_temp.refused(
        'CREATE OR REPLACE FUNCTION stall_assert_check_return() RETURNS trigger LANGUAGE plpgsql AS $body$ BEGIN RETURN NULL; END; $body$',
        '25006', 'a guard function cannot be replaced');
END $$;

-- Found by its number (spaces and letter case do not matter); a voided
-- sale's check is not listed.
DO $$
BEGIN
    IF (SELECT count(*) FROM stall_received_checks(' 7781 ')) <> 1
       OR (SELECT amount_minor || '/' || received_on || '/' || bounced FROM stall_received_checks('7781')) <> '5000/2097-03-02/false'
       OR EXISTS (SELECT 1 FROM stall_received_checks('7782'))
       OR EXISTS (SELECT 1 FROM stall_received_checks('')) THEN
        RAISE EXCEPTION 'the received checks are not the checks the store holds';
    END IF;
END $$;

-- The application role: plain refusals, then the bounce of check 7781.
DO $$
DECLARE
    posted uuid;
BEGIN
    SET LOCAL ROLE stall_poster;
    PERFORM pg_temp.refused('SELECT stall_return_check(''01900000-0000-7000-8000-00000000c799'', DATE ''2097-03-10'')', '23514', 'That check was not found.');
    PERFORM pg_temp.refused('SELECT stall_return_check(''01900000-0000-7000-8000-00000000c712'', DATE ''2097-03-10'')', '23514', 'That check was not found.');
    PERFORM pg_temp.refused('SELECT stall_return_check(''01900000-0000-7000-8000-00000000c711'', DATE ''2097-03-01'')', '23514', 'A check cannot bounce before the day it was taken.');
    PERFORM pg_temp.refused('SELECT stall_return_check(''01900000-0000-7000-8000-00000000c711'', NULL)', '23514', 'A check cannot bounce before the day it was taken.');
    PERFORM pg_temp.refused('CREATE OR REPLACE FUNCTION stall_return_check(uuid, date) RETURNS uuid LANGUAGE sql AS ''SELECT NULL::uuid''', '42501', '');
    PERFORM pg_temp.refused('DROP FUNCTION stall_return_check(uuid, date)', '42501', '');

    posted := stall_return_check('01900000-0000-7000-8000-00000000c711', DATE '2097-03-10');
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    PERFORM pg_temp.refused('SELECT stall_return_check(''01900000-0000-7000-8000-00000000c711'', DATE ''2097-03-11'')', '23514', 'That check is already marked as bounced.');
    RESET ROLE;

    IF (SELECT posting_key || '/' || posting_date || '/' || description FROM journals WHERE id = posted)
           <> 'check-return:01900000-0000-7000-8000-00000000c711:0/2097-03-10/Bounced check 7781'
       OR (SELECT sum(line.debit_minor) FROM journal_lines AS line JOIN chart_roles AS role ON role.account_id = line.account_id
           WHERE line.journal_id = posted AND role.role = 'returned_checks') <> 5000
       OR (SELECT sum(line.credit_minor) FROM journal_lines AS line JOIN chart_roles AS role ON role.account_id = line.account_id
           WHERE line.journal_id = posted AND role.role = 'checks') <> 5000
       OR (SELECT bounced FROM stall_received_checks('7781')) IS NOT TRUE THEN
        RAISE EXCEPTION 'the bounce did not move the check''s own amount';
    END IF;
END $$;

-- Marked by mistake and undone: the check can be marked bounced again, under
-- the next key.
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor,
    is_reversal, reverses_journal_id
)
SELECT '01900000-0000-7000-8000-00000000c721', '01900000-0000-7000-8000-000000000001', 'reversal:' || bounce.id, '2097-03-11', '2097-03-11 12:00:00+00', '01900000-0000-7000-8000-00000000c701',
       'USD', 'check_return', bounce.source_reference || ':undo', 'Undo bounce', 5000, 5000, true, bounce.id
FROM journals AS bounce
WHERE bounce.posting_key = 'check-return:01900000-0000-7000-8000-00000000c711:0';
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES
    ('01900000-0000-7000-8000-00000000c721', '01900000-0000-7000-8000-000000000001', 1, (SELECT account_id FROM chart_roles WHERE role = 'returned_checks'), 0, 5000, NULL, NULL),
    ('01900000-0000-7000-8000-00000000c721', '01900000-0000-7000-8000-000000000001', 2, (SELECT account_id FROM chart_roles WHERE role = 'checks'), 5000, 0, NULL, NULL);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    again uuid;
BEGIN
    IF (SELECT bounced FROM stall_received_checks('7781')) IS NOT FALSE THEN
        RAISE EXCEPTION 'an undone bounce still reads as bounced';
    END IF;
    SET LOCAL ROLE stall_poster;
    again := stall_return_check('01900000-0000-7000-8000-00000000c711', DATE '2097-03-12');
    RESET ROLE;
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF (SELECT posting_key FROM journals WHERE id = again) <> 'check-return:01900000-0000-7000-8000-00000000c711:1' THEN
        RAISE EXCEPTION 'the second bounce did not take the next key';
    END IF;
END $$;

-- A hand-built bounce must name a check the store took, move exactly its
-- amount, and be its only standing bounce.
DO $$
DECLARE
    attempt record;
BEGIN
    FOR attempt IN
        SELECT * FROM (VALUES
            ('bad-ref', 'check-return:bad-ref:0', 5000::bigint, 'a bounced check names a check the store took'),
            ('01900000-0000-7000-8000-00000000c712', 'check-return:01900000-0000-7000-8000-00000000c712:0', 3000::bigint, 'a bounced check names a check the store took'),
            ('01900000-0000-7000-8000-00000000c713', 'check-return:01900000-0000-7000-8000-00000000c713:0', 3000::bigint, 'a bounced check names a check the store took'),
            ('01900000-0000-7000-8000-00000000c711', 'check-return:01900000-0000-7000-8000-00000000c711', 5000::bigint, 'a bounced check names a check the store took'),
            ('01900000-0000-7000-8000-00000000c711', 'check-return:01900000-0000-7000-8000-00000000c711:7', 4000::bigint, 'a bounced check moves exactly the check''s amount'),
            ('01900000-0000-7000-8000-00000000c711', 'check-return:01900000-0000-7000-8000-00000000c711:8', 5000::bigint, 'That check is already marked as bounced.')
        ) AS listed (reference, posting_key, amount, wanted)
    LOOP
        BEGIN
            INSERT INTO journals (
                id, book_id, posting_key, posting_date, occurred_at, period_id,
                currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
            ) VALUES (
                '01900000-0000-7000-8000-00000000c731', '01900000-0000-7000-8000-000000000001', attempt.posting_key, '2097-03-13', '2097-03-13 12:00:00+00', '01900000-0000-7000-8000-00000000c701',
                'USD', 'check_return', attempt.reference, 'Hand-built bounce', attempt.amount, attempt.amount
            );
            INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
            VALUES
                ('01900000-0000-7000-8000-00000000c731', '01900000-0000-7000-8000-000000000001', 1, (SELECT account_id FROM chart_roles WHERE role = 'returned_checks'), attempt.amount, 0, NULL, NULL),
                ('01900000-0000-7000-8000-00000000c731', '01900000-0000-7000-8000-000000000001', 2, (SELECT account_id FROM chart_roles WHERE role = 'checks'), 0, attempt.amount, NULL, NULL);
            SET CONSTRAINTS ALL IMMEDIATE;
            RAISE EXCEPTION 'accepted a hand-built bounce %', attempt.posting_key;
        EXCEPTION
            WHEN check_violation THEN
                IF SQLERRM <> attempt.wanted THEN
                    RAISE EXCEPTION '% was refused for the wrong reason: %', attempt.posting_key, SQLERRM;
                END IF;
        END;
        SET CONSTRAINTS ALL DEFERRED;
    END LOOP;
END $$;

ROLLBACK;
