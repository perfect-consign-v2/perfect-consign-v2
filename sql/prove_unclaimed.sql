-- Unclaimed vendor money (Phase 3). Money the store owes a vendor it cannot
-- reach moves, by the owner only with a reason and after the dormancy days, to
-- a liability that still names the vendor; the vendor can then be made
-- inactive. The vendor is paid from it later (cash on the open register, or a
-- check), or it is sent to the state with the state's reference. Never more
-- than is held, never voided, never posted around the functions.
-- One transaction, then ROLLBACK.

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
            IF SQLSTATE <> wanted OR SQLERRM NOT LIKE '%' || needle || '%' THEN
                RAISE EXCEPTION '% was refused for the wrong reason: % %', attempt, SQLSTATE, SQLERRM;
            END IF;
            RETURN;
    END;
    RAISE EXCEPTION 'accepted: %', attempt;
END;
$fn$;

CREATE FUNCTION pg_temp.today() RETURNS date
    LANGUAGE sql
    AS $fn$
    SELECT (clock_timestamp() AT TIME ZONE mall.time_zone)::date FROM mall;
$fn$;

UPDATE mall SET tax_bps = 0, cash_round_minor = 0;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES ('01900000-0000-7000-8000-00000000ec21', '01900000-0000-7000-8000-000000000001', '2026-01-01', '2027-12-31', 'open');

DO $$
DECLARE
    mall_id uuid;
BEGIN
    SELECT id INTO mall_id FROM platform.tenants WHERE schema_name = current_schema();
    DELETE FROM platform.owners WHERE tenant_id = mall_id;
    INSERT INTO platform.owners (id, tenant_id, username, password_hash)
    VALUES ('01900000-0000-7000-8000-00000000ec01', mall_id, 'owner', 'hash');
END $$;

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000ec11', 'lost', 'Lou', 'Lost');
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000ec12', 'none', 'Nan', 'None');

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps, quantity)
VALUES ('01900000-0000-7000-8000-00000000ec51', 'LOU-1', '01900000-0000-7000-8000-00000000ec11', 'Lou lamp', 9000, 'BC-LOU-1', DATE '2026-06-01', 0, 1);

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
SELECT '01900000-0000-7000-8000-00000000ec22', '01900000-0000-7000-8000-000000000d10', 'Ada',
       '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb, 0;

-- Lou's lamp sold in June; the store owes Lou $90.00 and cannot reach him.
INSERT INTO journals (id, book_id, posting_key, posting_date, occurred_at, period_id, currency, source_type, source_reference, description, debit_total_minor, credit_total_minor)
VALUES ('01900000-0000-7000-8000-00000000ec61', '01900000-0000-7000-8000-000000000001', 'sale:lou-lamp', '2026-06-02', '2026-06-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000ec21', 'USD', 'sale', 'lou-lamp', 'Lamp sale', 9000, 9000);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES ('01900000-0000-7000-8000-00000000ec61', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 9000, 0);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES ('01900000-0000-7000-8000-00000000ec61', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 9000, 'vendor', '01900000-0000-7000-8000-00000000ec11');
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-00000000ec61', 1, '01900000-0000-7000-8000-00000000ec51', 9000, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id) VALUES ('01900000-0000-7000-8000-00000000ec22', '01900000-0000-7000-8000-00000000ec61');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

-- The guards are in place and cannot be switched off or replaced.
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'journals_unclaimed_sealed' AND tgenabled = 'A')
       OR NOT EXISTS (SELECT 1 FROM pg_trigger WHERE tgname = 'unclaimed_events_append_only_sealed' AND tgenabled = 'A') THEN
        RAISE EXCEPTION 'an unclaimed money guard is missing or not ENABLE ALWAYS';
    END IF;
    PERFORM pg_temp.refused('ALTER TABLE journals DISABLE TRIGGER journals_unclaimed_sealed', '25006', 'append-only triggers cannot be disabled');
    PERFORM pg_temp.refused('ALTER TABLE journals ENABLE REPLICA TRIGGER journals_unclaimed_sealed', '25006', 'append-only triggers cannot be disabled');
    PERFORM pg_temp.refused('DROP TRIGGER journals_unclaimed_sealed ON journals', '25006', 'append-only triggers cannot be dropped');
    PERFORM pg_temp.refused('ALTER TABLE unclaimed_events DISABLE TRIGGER unclaimed_events_append_only_sealed', '25006', 'append-only triggers cannot be disabled');
    PERFORM pg_temp.refused('TRUNCATE unclaimed_events CASCADE', '25006', '');
    PERFORM pg_temp.refused(
        'CREATE OR REPLACE FUNCTION stall_assert_unclaimed() RETURNS trigger LANGUAGE plpgsql AS $body$ BEGIN RETURN NULL; END; $body$',
        '25006', 'a guard function cannot be replaced');
    SET LOCAL ROLE stall_poster;
    PERFORM pg_temp.refused(
        'INSERT INTO unclaimed_events (journal_id, vendor_id, kind, reason, owner_account, owner_username) '
        || 'VALUES (''01900000-0000-7000-8000-00000000ec61'', ''01900000-0000-7000-8000-00000000ec11'', ''move'', ''x'', ''01900000-0000-7000-8000-00000000ec01'', ''owner'')',
        '42501', '');
    RESET ROLE;
END $$;

-- Owner only; a reason; something owed; and only after the dormancy days.
DO $$
BEGIN
    PERFORM pg_temp.refused('SELECT stall_move_to_unclaimed(''01900000-0000-7000-8000-00000000ec11'', ''No reply'', ''01900000-0000-7000-8000-00000000ec02'', ''owner'')',
        '42501', 'Only the owner can do that.');
    PERFORM pg_temp.refused('SELECT stall_move_to_unclaimed(''01900000-0000-7000-8000-00000000ec11'', '' '', ''01900000-0000-7000-8000-00000000ec01'', ''owner'')',
        '23514', 'Say why the store cannot pay them.');
    PERFORM pg_temp.refused('SELECT stall_move_to_unclaimed(''01900000-0000-7000-8000-00000000ec12'', ''No reply'', ''01900000-0000-7000-8000-00000000ec01'', ''owner'')',
        '23514', 'The store owes Nan None nothing.');
    IF (SELECT unclaimed_dormancy_days FROM mall) <> 1095 THEN
        RAISE EXCEPTION 'the default dormancy is not three years';
    END IF;
    PERFORM pg_temp.refused('SELECT stall_move_to_unclaimed(''01900000-0000-7000-8000-00000000ec11'', ''No reply'', ''01900000-0000-7000-8000-00000000ec01'', ''owner'')',
        '23514', 'What the store owes Lou Lost can be moved to unclaimed money from ' || to_char(DATE '2026-06-02' + 1095, 'MM-DD-YYYY') || '.');
    PERFORM pg_temp.refused('UPDATE mall SET unclaimed_dormancy_days = 3651', '23514', 'mall_unclaimed_dormancy_days_chk');
END $$;

-- After the dormancy days: everything owed moves, the vendor can be made
-- inactive, and the liability still names them.
DO $$
DECLARE
    moved uuid;
BEGIN
    UPDATE mall SET unclaimed_dormancy_days = 30;
    SET LOCAL ROLE stall_poster;
    moved := stall_move_to_unclaimed('01900000-0000-7000-8000-00000000ec11', 'Checks never cashed, no reply', '01900000-0000-7000-8000-00000000ec01', 'owner');
    RESET ROLE;
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF stall_vendor_open_payable('01900000-0000-7000-8000-00000000ec11') <> 0
       OR stall_vendor_unclaimed('01900000-0000-7000-8000-00000000ec11') <> 9000
       OR (SELECT posting_key FROM journals WHERE id = moved) <> 'payable_to_unclaimed:01900000-0000-7000-8000-00000000ec11:0'
       OR (SELECT posting_date FROM journals WHERE id = moved) <> pg_temp.today() THEN
        RAISE EXCEPTION 'the move to unclaimed money is wrong';
    END IF;
    UPDATE vendors SET active = false WHERE id = '01900000-0000-7000-8000-00000000ec11';
    PERFORM pg_temp.refused(format(
        $q$WITH undo AS (
               INSERT INTO journals (book_id, posting_key, posting_date, occurred_at, period_id, currency, source_type, source_reference, description, debit_total_minor, credit_total_minor, is_reversal, reverses_journal_id)
               SELECT book_id, 'reverse:' || posting_key, posting_date, clock_timestamp(), period_id, currency, source_type, source_reference, 'Undo', debit_total_minor, credit_total_minor, true, id
               FROM journals WHERE id = '%1$s'
               RETURNING id, book_id
           )
           INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
           SELECT undo.id, undo.book_id, line.line_no, line.account_id, line.credit_minor, line.debit_minor, line.subledger_type, line.subledger_ref
           FROM undo CROSS JOIN journal_lines AS line WHERE line.journal_id = '%1$s'$q$, moved),
        '23514', 'unclaimed money is not voided');
    PERFORM pg_temp.refused(
        $q$SELECT stall_post_lines('unclaimed_payout:01900000-0000-7000-8000-00000000ec11:0', pg_temp.today(), 'unclaimed_payout', '01900000-0000-7000-8000-00000000ec11', 'Around',
            jsonb_build_array(stall_line('unclaimed_property_payable', 100, 0, 'vendor', '01900000-0000-7000-8000-00000000ec11'), stall_line('bank', 0, 100)))$q$,
        '23514', 'unclaimed money moves only through the owner''s unclaimed money steps');
    PERFORM pg_temp.refused('UPDATE unclaimed_events SET reason = ''Changed''', '23514', 'an unclaimed money record is history and cannot change');
END $$;

-- Lou comes back: $30.00 by check, $20.00 in cash on the open register; the
-- rest, $40.00, goes to the state. Never more than is held.
DO $$
DECLARE
    cash uuid;
BEGIN
    PERFORM pg_temp.refused('SELECT stall_unclaimed_pay(''01900000-0000-7000-8000-00000000ec11'', 3000, ''check'', '''', ''01900000-0000-7000-8000-00000000ec01'', ''owner'')',
        '23514', 'Enter the check number.');
    PERFORM pg_temp.refused('SELECT stall_unclaimed_pay(''01900000-0000-7000-8000-00000000ec11'', 3000, ''gold'', NULL, ''01900000-0000-7000-8000-00000000ec01'', ''owner'')',
        '23514', 'Choose how they are paid.');
    PERFORM pg_temp.refused('SELECT stall_unclaimed_pay(''01900000-0000-7000-8000-00000000ec11'', 9001, ''check'', ''1001'', ''01900000-0000-7000-8000-00000000ec01'', ''owner'')',
        '23514', 'The store holds $90.00 for them.');
    PERFORM pg_temp.refused('SELECT stall_unclaimed_pay(''01900000-0000-7000-8000-00000000ec11'', 3000, ''check'', ''1001'', ''01900000-0000-7000-8000-00000000ec02'', ''owner'')',
        '42501', 'Only the owner can do that.');
    PERFORM stall_unclaimed_pay('01900000-0000-7000-8000-00000000ec11', 3000, 'check', '1001', '01900000-0000-7000-8000-00000000ec01', 'owner');
    PERFORM pg_temp.refused('SELECT stall_unclaimed_pay(''01900000-0000-7000-8000-00000000ec11'', 2000, ''cash'', NULL, ''01900000-0000-7000-8000-00000000ec01'', ''owner'')',
        '23514', 'a drawer journal needs an open register');
    cash := stall_unclaimed_pay('01900000-0000-7000-8000-00000000ec11', 2000, 'cash', NULL, '01900000-0000-7000-8000-00000000ec01', 'owner');
    INSERT INTO register_session_posts (session_id, journal_id) VALUES ('01900000-0000-7000-8000-00000000ec22', cash);
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF stall_vendor_unclaimed('01900000-0000-7000-8000-00000000ec11') <> 4000 THEN
        RAISE EXCEPTION 'the payouts did not come off what is held';
    END IF;
    PERFORM pg_temp.refused('SELECT stall_unclaimed_remit(''01900000-0000-7000-8000-00000000ec11'', 4000, '' '', ''01900000-0000-7000-8000-00000000ec01'', ''owner'')',
        '23514', 'Enter the state''s report number.');
    PERFORM pg_temp.refused('SELECT stall_unclaimed_remit(''01900000-0000-7000-8000-00000000ec11'', 4001, ''CA-2027-118'', ''01900000-0000-7000-8000-00000000ec01'', ''owner'')',
        '23514', 'The store holds $40.00 for them.');
    PERFORM stall_unclaimed_remit('01900000-0000-7000-8000-00000000ec11', 4000, 'CA-2027-118', '01900000-0000-7000-8000-00000000ec01', 'owner');
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF stall_vendor_unclaimed('01900000-0000-7000-8000-00000000ec11') <> 0 THEN
        RAISE EXCEPTION 'the remittance did not empty what is held';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM stall_unclaimed_balances(pg_temp.today())
        WHERE vendor = 'Lou Lost' AND moved_minor = 9000 AND paid_minor = 5000 AND remitted_minor = 4000
          AND held_minor = 0 AND days_held = 0 AND remitted_refs = 'CA-2027-118'
    ) THEN
        RAISE EXCEPTION 'the unclaimed balances report reads wrong';
    END IF;
    IF EXISTS (
        SELECT 1 FROM journals AS posted
        WHERE posted.source_type IN ('payable_to_unclaimed', 'unclaimed_payout', 'unclaimed_remittance')
          AND posted.debit_total_minor <> (SELECT sum(debit_minor) FROM journal_lines WHERE journal_id = posted.id)
    ) THEN
        RAISE EXCEPTION 'an unclaimed money journal does not balance';
    END IF;
END $$;

ROLLBACK;
