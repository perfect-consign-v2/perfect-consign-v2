-- Bad debt write-off and recovery (D4-D9). Only the store's owner writes a
-- vendor's debts off, with a reason, after the store has applied what it owes
-- them, once every debt is old enough; each debt is written off in full and
-- nothing more. A write-off is never voided. If the vendor pays later, the
-- owner takes back up to what that write-off took, and the payment posts as an
-- ordinary rent payment. A second write-off after a recovery is still
-- possible. The guards cannot be switched off. One transaction, then ROLLBACK.

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

CREATE FUNCTION pg_temp.open_rent(stay uuid) RETURNS bigint
    LANGUAGE sql
    AS $fn$
    SELECT stall_assignment_open_rent(stay);
$fn$;

UPDATE mall SET tax_bps = 0, cash_round_minor = 0;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES ('01900000-0000-7000-8000-00000000ef21', '01900000-0000-7000-8000-000000000001', '2026-01-01', '2027-12-31', 'open');

DO $$
DECLARE
    mall_id uuid;
BEGIN
    SELECT id INTO mall_id FROM platform.tenants WHERE schema_name = current_schema();
    DELETE FROM platform.owners WHERE tenant_id = mall_id;
    INSERT INTO platform.owners (id, tenant_id, username, password_hash)
    VALUES ('01900000-0000-7000-8000-00000000ef01', mall_id, 'owner', 'hash');
END $$;

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000ef11', 'gone', 'Gail', 'Gone');
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000ef12', 'owed', 'Otto', 'Owed');
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000ef13', 'fresh', 'Fran', 'Fresh');
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000ef14', 'claw', 'Clem', 'Claw');

INSERT INTO booths (id, code, name)
VALUES
    ('01900000-0000-7000-8000-00000000ef31', 'W1', 'Booth W1'),
    ('01900000-0000-7000-8000-00000000ef32', 'W2', 'Booth W2'),
    ('01900000-0000-7000-8000-00000000ef33', 'W3', 'Booth W3');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on, ends_on)
VALUES
    ('01900000-0000-7000-8000-00000000ef41', '01900000-0000-7000-8000-00000000ef31', '01900000-0000-7000-8000-00000000ef11', '2026-01-01', '2026-07-31'),
    ('01900000-0000-7000-8000-00000000ef42', '01900000-0000-7000-8000-00000000ef32', '01900000-0000-7000-8000-00000000ef12', '2026-01-01', '2026-07-31'),
    ('01900000-0000-7000-8000-00000000ef43', '01900000-0000-7000-8000-00000000ef33', '01900000-0000-7000-8000-00000000ef13', '2026-01-01', NULL);

INSERT INTO assignment_rent (assignment_id, amount_minor)
VALUES
    ('01900000-0000-7000-8000-00000000ef41', 8000),
    ('01900000-0000-7000-8000-00000000ef42', 8000),
    ('01900000-0000-7000-8000-00000000ef43', 8000);

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
SELECT '01900000-0000-7000-8000-00000000ef22', '01900000-0000-7000-8000-000000000d10', 'Ada',
       '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb, 0;

-- Gail owes June and July (July is the newest, well past 90 days). Fran owes
-- July and this month (too new). Otto owes July but the store owes him from a sale.
SELECT stall_charge_rent('01900000-0000-7000-8000-00000000ef41', '2026-06-01');
SELECT stall_charge_rent('01900000-0000-7000-8000-00000000ef41', '2026-07-01');
SELECT stall_charge_rent('01900000-0000-7000-8000-00000000ef42', '2026-07-01');
SELECT stall_charge_rent('01900000-0000-7000-8000-00000000ef43', '2026-07-01');
SELECT stall_charge_rent('01900000-0000-7000-8000-00000000ef43', date_trunc('month', pg_temp.today())::date);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps, quantity)
VALUES
    ('01900000-0000-7000-8000-00000000ef51', 'OTTO-1', '01900000-0000-7000-8000-00000000ef12', 'Otto vase', 1250, 'BC-OTTO-1', DATE '2026-06-01', 0, 1),
    ('01900000-0000-7000-8000-00000000ef52', 'CLEM-1', '01900000-0000-7000-8000-00000000ef14', 'Clem coat', 4000, 'BC-CLEM-1', DATE '2026-06-01', 0, 1);

-- Otto's sale leaves the store owing him $12.50.
INSERT INTO journals (id, book_id, posting_key, posting_date, occurred_at, period_id, currency, source_type, source_reference, description, debit_total_minor, credit_total_minor)
VALUES ('01900000-0000-7000-8000-00000000ef61', '01900000-0000-7000-8000-000000000001', 'sale:otto-vase', '2026-06-02', '2026-06-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000ef21', 'USD', 'sale', 'otto-vase', 'Vase sale', 1250, 1250);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES ('01900000-0000-7000-8000-00000000ef61', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 1250, 0);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES ('01900000-0000-7000-8000-00000000ef61', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 1250, 'vendor', '01900000-0000-7000-8000-00000000ef12');
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-00000000ef61', 1, '01900000-0000-7000-8000-00000000ef51', 1250, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id) VALUES ('01900000-0000-7000-8000-00000000ef22', '01900000-0000-7000-8000-00000000ef61');

-- Clem is paid for a sale, then the customer brings it back: Clem owes $40.00 from returns.
INSERT INTO journals (id, book_id, posting_key, posting_date, occurred_at, period_id, currency, source_type, source_reference, description, debit_total_minor, credit_total_minor)
VALUES ('01900000-0000-7000-8000-00000000ef62', '01900000-0000-7000-8000-000000000001', 'sale:clem-coat', '2026-06-02', '2026-06-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000ef21', 'USD', 'sale', 'clem-coat', 'Coat sale', 4000, 4000);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES ('01900000-0000-7000-8000-00000000ef62', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 4000, 0);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES ('01900000-0000-7000-8000-00000000ef62', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 4000, 'vendor', '01900000-0000-7000-8000-00000000ef14');
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-00000000ef62', 1, '01900000-0000-7000-8000-00000000ef52', 4000, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id) VALUES ('01900000-0000-7000-8000-00000000ef22', '01900000-0000-7000-8000-00000000ef62');

INSERT INTO journals (id, book_id, posting_key, posting_date, occurred_at, period_id, currency, source_type, source_reference, description, debit_total_minor, credit_total_minor)
VALUES ('01900000-0000-7000-8000-00000000ef63', '01900000-0000-7000-8000-000000000001', 'check_disbursement:clem-1:01900000-0000-7000-8000-00000000ef14', '2026-06-03', '2026-06-03 12:00:00+00',
        '01900000-0000-7000-8000-00000000ef21', 'USD', 'check_disbursement', '01900000-0000-7000-8000-00000000ef14', 'Pay Clem', 4000, 4000);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES ('01900000-0000-7000-8000-00000000ef63', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 4000, 0, 'vendor', '01900000-0000-7000-8000-00000000ef14');
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES ('01900000-0000-7000-8000-00000000ef63', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 4000);

INSERT INTO journals (id, book_id, posting_key, posting_date, occurred_at, period_id, currency, source_type, source_reference, description, debit_total_minor, credit_total_minor)
VALUES ('01900000-0000-7000-8000-00000000ef64', '01900000-0000-7000-8000-000000000001', 'return:clem-coat', '2026-06-04', '2026-06-04 12:00:00+00',
        '01900000-0000-7000-8000-00000000ef21', 'USD', 'item_return', 'return:01900000-0000-7000-8000-00000000ef52', 'Return coat', 4000, 4000);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES ('01900000-0000-7000-8000-00000000ef64', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-00000000002e', 4000, 0, 'vendor', '01900000-0000-7000-8000-00000000ef14');
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES ('01900000-0000-7000-8000-00000000ef64', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000010', 0, 4000);
INSERT INTO item_returns (journal_id, sale_journal_id, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-00000000ef64', '01900000-0000-7000-8000-00000000ef62', '01900000-0000-7000-8000-00000000ef52', 4000, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id) VALUES ('01900000-0000-7000-8000-00000000ef22', '01900000-0000-7000-8000-00000000ef64');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

-- The guard is in place, runs in every session, and cannot be switched off,
-- dropped or replaced; the events cannot be rewritten or emptied.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgname = 'journals_bad_debt_sealed' AND tgrelid = 'journals'::regclass AND tgenabled = 'A'
    ) OR NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgname = 'bad_debt_events_append_only_sealed' AND tgrelid = 'bad_debt_events'::regclass AND tgenabled = 'A'
    ) THEN
        RAISE EXCEPTION 'a write-off guard is missing or not ENABLE ALWAYS';
    END IF;
    PERFORM pg_temp.refused('ALTER TABLE journals DISABLE TRIGGER journals_bad_debt_sealed', '25006', 'append-only triggers cannot be disabled');
    PERFORM pg_temp.refused('ALTER TABLE journals ENABLE REPLICA TRIGGER journals_bad_debt_sealed', '25006', 'append-only triggers cannot be disabled');
    PERFORM pg_temp.refused('DROP TRIGGER journals_bad_debt_sealed ON journals', '25006', 'append-only triggers cannot be dropped');
    PERFORM pg_temp.refused('ALTER TABLE bad_debt_events DISABLE TRIGGER bad_debt_events_append_only_sealed', '25006', 'append-only triggers cannot be disabled');
    PERFORM pg_temp.refused('TRUNCATE bad_debt_events CASCADE', '25006', '');
    PERFORM pg_temp.refused(
        'CREATE OR REPLACE FUNCTION stall_assert_bad_debt() RETURNS trigger LANGUAGE plpgsql AS $body$ BEGIN RETURN NULL; END; $body$',
        '25006', 'a guard function cannot be replaced');
    IF NOT platform.guarded_function(to_regprocedure('stall_assert_bad_debt()'))
       OR NOT platform.guarded_function(to_regprocedure('stall_reject_bad_debt_rewrite()')) THEN
        RAISE EXCEPTION 'a write-off guard is not sealed';
    END IF;
END $$;

-- The app role reaches the write-off only through the two functions.
DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    PERFORM pg_temp.refused(
        'INSERT INTO bad_debt_events (journal_id, vendor_id, kind, reason, owner_account, owner_username) '
        || 'VALUES (''01900000-0000-7000-8000-00000000ef61'', ''01900000-0000-7000-8000-00000000ef11'', ''write_off'', ''x'', ''01900000-0000-7000-8000-00000000ef01'', ''owner'')',
        '42501', '');
    PERFORM pg_temp.refused('SELECT stall_refuse_unless_owner(''01900000-0000-7000-8000-00000000ef01'', ''owner'')', '42501', '');
    PERFORM pg_temp.refused('DROP TRIGGER journals_bad_debt_sealed ON journals', '42501', '');
    RESET ROLE;
END $$;

-- Owner only; a reason; nothing owed to them first; every debt old enough.
DO $$
BEGIN
    PERFORM pg_temp.refused('SELECT stall_write_off(''01900000-0000-7000-8000-00000000ef11'', ''Moved away'', ''01900000-0000-7000-8000-00000000ef02'', ''owner'')',
        '42501', 'Only the owner can do that.');
    PERFORM pg_temp.refused('SELECT stall_write_off(''01900000-0000-7000-8000-00000000ef11'', ''Moved away'', ''01900000-0000-7000-8000-00000000ef01'', ''manager'')',
        '42501', 'Only the owner can do that.');
    PERFORM pg_temp.refused('SELECT stall_write_off(''01900000-0000-7000-8000-00000000ef11'', ''Moved away'', NULL, NULL)',
        '42501', 'Only the owner can do that.');
    PERFORM pg_temp.refused('SELECT stall_write_off(''01900000-0000-7000-8000-00000000ef11'', ''   '', ''01900000-0000-7000-8000-00000000ef01'', ''owner'')',
        '23514', 'Say why it is written off.');
    PERFORM pg_temp.refused('SELECT stall_write_off(''01900000-0000-7000-8000-00000000ef11'', ''' || repeat('x', 501) || ''', ''01900000-0000-7000-8000-00000000ef01'', ''owner'')',
        '23514', 'Keep the reason to 500 letters or fewer.');
    PERFORM pg_temp.refused('SELECT stall_write_off(''01900000-0000-7000-8000-00000000ef12'', ''Moved away'', ''01900000-0000-7000-8000-00000000ef01'', ''owner'')',
        '23514', 'The store owes Otto Owed $12.50. Apply that first.');
    PERFORM pg_temp.refused('SELECT stall_write_off(''01900000-0000-7000-8000-00000000ef13'', ''Moved away'', ''01900000-0000-7000-8000-00000000ef01'', ''owner'')',
        '23514', 'Fran Fresh''s debts can be written off from ' || to_char(date_trunc('month', pg_temp.today())::date + 90, 'MM-DD-YYYY') || '.');
    PERFORM pg_temp.refused('UPDATE mall SET write_off_wait_days = -1', '23514', 'mall_write_off_wait_days_chk');
    PERFORM pg_temp.refused('UPDATE mall SET write_off_wait_days = 3651', '23514', 'mall_write_off_wait_days_chk');
END $$;

-- A held booth deposit is applied to the rent first when the store says so.
DO $$
BEGIN
    UPDATE mall SET booth_deposits_enabled = true;
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-00000000ef22',
            stall_post_booth_deposit('01900000-0000-7000-8000-00000000ef41', 2500, 'gail-deposit', '2026-06-01', false));
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    UPDATE mall SET deposit_covers_rent = true;
    PERFORM pg_temp.refused('SELECT stall_write_off(''01900000-0000-7000-8000-00000000ef11'', ''Moved away'', ''01900000-0000-7000-8000-00000000ef01'', ''owner'')',
        '23514', 'The store holds a $25.00 booth deposit for Gail Gone. Apply it to the rent first.');
    UPDATE mall SET deposit_covers_rent = false;
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-00000000ef22',
            stall_post_booth_deposit('01900000-0000-7000-8000-00000000ef41', 2500, 'gail-deposit-back', pg_temp.today(), true));
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    UPDATE mall SET booth_deposits_enabled = false;
END $$;

-- Gail: both months written off in full, in one journal for her stay, with who
-- and why; she can then be made inactive.
DO $$
DECLARE
    posted journals%ROWTYPE;
    line_total bigint;
BEGIN
    IF (SELECT count(*) FROM stall_vendor_debts('01900000-0000-7000-8000-00000000ef11', pg_temp.today())) <> 1
       OR (SELECT open_minor FROM stall_vendor_debts('01900000-0000-7000-8000-00000000ef11', pg_temp.today())) <> 16000
       OR (SELECT since FROM stall_vendor_debts('01900000-0000-7000-8000-00000000ef11', pg_temp.today())) <> DATE '2026-07-01'
       OR (SELECT eligible_on FROM stall_vendor_debts('01900000-0000-7000-8000-00000000ef11', pg_temp.today())) <> DATE '2026-07-01' + 90 THEN
        RAISE EXCEPTION 'Gail''s debts read wrong';
    END IF;
    SET LOCAL ROLE stall_poster;
    IF stall_write_off('01900000-0000-7000-8000-00000000ef11', 'Moved out of state, no forwarding address',
                       '01900000-0000-7000-8000-00000000ef01', 'owner') <> 1 THEN
        RAISE EXCEPTION 'Gail''s write-off did not post one journal';
    END IF;
    RESET ROLE;
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    SELECT * INTO posted FROM journals WHERE source_type = 'rent_write_off' AND source_reference = '01900000-0000-7000-8000-00000000ef41';
    IF posted.posting_key <> 'rent_write_off:01900000-0000-7000-8000-00000000ef41:0'
       OR posted.posting_date <> pg_temp.today()
       OR posted.debit_total_minor <> 16000
       OR posted.description <> 'Written off: Moved out of state, no forwarding address'
       OR pg_temp.open_rent('01900000-0000-7000-8000-00000000ef41') <> 0 THEN
        RAISE EXCEPTION 'Gail''s write-off has the wrong shape';
    END IF;
    SELECT sum(line.debit_minor) INTO line_total
    FROM journal_lines AS line JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'bad_debt_expense'
    WHERE line.journal_id = posted.id;
    IF line_total <> 16000 OR NOT EXISTS (
        SELECT 1 FROM bad_debt_events
        WHERE journal_id = posted.id AND kind = 'write_off' AND owner_username = 'owner'
          AND reason = 'Moved out of state, no forwarding address'
    ) THEN
        RAISE EXCEPTION 'Gail''s write-off lost its expense or its record';
    END IF;
    IF (SELECT said FROM stall_vendor_write_offs('01900000-0000-7000-8000-00000000ef11'))
       <> 'Written off $160.00 on ' || to_char(pg_temp.today(), 'Mon FMDD, YYYY') || ': Moved out of state, no forwarding address.' THEN
        RAISE EXCEPTION 'the write-off line reads: %', (SELECT said FROM stall_vendor_write_offs('01900000-0000-7000-8000-00000000ef11'));
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM stall_bad_debts(pg_temp.today(), pg_temp.today())
        WHERE vendor = 'Gail Gone' AND booth = 'W1' AND kind = 'rent' AND amount_minor = 16000 AND recovered_minor = 0 AND who = 'owner'
    ) THEN
        RAISE EXCEPTION 'the Bad debts report leaves Gail out';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM stall_rent_payments(pg_temp.today(), pg_temp.today(), '01900000-0000-7000-8000-00000000ef11')
        WHERE paid_by = 'written_off' AND how = 'Written off' AND amount_minor = 16000
    ) THEN
        RAISE EXCEPTION 'Rent payments does not show the write-off';
    END IF;
    PERFORM pg_temp.refused('SELECT stall_write_off(''01900000-0000-7000-8000-00000000ef11'', ''Again'', ''01900000-0000-7000-8000-00000000ef01'', ''owner'')',
        '23514', 'Gail Gone owes nothing to write off.');
    UPDATE vendors SET active = false WHERE id = '01900000-0000-7000-8000-00000000ef11';
END $$;

-- A write-off is never voided, and one cannot be posted around the function.
DO $$
DECLARE
    written uuid := (SELECT id FROM journals WHERE source_type = 'rent_write_off' AND source_reference = '01900000-0000-7000-8000-00000000ef41');
BEGIN
    PERFORM pg_temp.refused(format(
        $q$SELECT stall_post_lines('undo:%s', pg_temp.today(), 'rent_write_off', '01900000-0000-7000-8000-00000000ef41', 'Undo',
            jsonb_build_array(stall_line('booth_rent_receivable', 100, 0, 'assignment', '01900000-0000-7000-8000-00000000ef41'), stall_line('bad_debt_expense', 0, 100)))$q$, written),
        '23514', 'a write-off posts only through the owner''s write-off');
    PERFORM pg_temp.refused(format(
        $q$INSERT INTO journals (book_id, posting_key, posting_date, occurred_at, period_id, currency, source_type, source_reference, description, debit_total_minor, credit_total_minor, is_reversal, reverses_journal_id)
           SELECT book_id, 'reverse:' || posting_key, posting_date, clock_timestamp(), period_id, currency, source_type, source_reference, 'Undo', debit_total_minor, credit_total_minor, true, id
           FROM journals WHERE id = '%s'$q$, written),
        '23514', 'a write-off is not voided');
    PERFORM pg_temp.refused('UPDATE bad_debt_events SET reason = ''Changed''', '23514', 'a write-off record is history and cannot change');
    PERFORM pg_temp.refused('DELETE FROM bad_debt_events', '23514', 'a write-off record is history and cannot change');
END $$;

-- Gail pays $50.00 later: the owner takes back $50.00, then the payment posts
-- as ordinary rent. The history reads charge, write-off, recovery, payment.
DO $$
DECLARE
    written uuid := (SELECT id FROM journals WHERE source_type = 'rent_write_off' AND source_reference = '01900000-0000-7000-8000-00000000ef41');
    back uuid;
BEGIN
    PERFORM pg_temp.refused(format('SELECT stall_write_off_recover(''%s'', 5000, ''01900000-0000-7000-8000-00000000ef02'', ''owner'')', written),
        '42501', 'Only the owner can do that.');
    PERFORM pg_temp.refused(format('SELECT stall_write_off_recover(''%s'', 0, ''01900000-0000-7000-8000-00000000ef01'', ''owner'')', written),
        '23514', 'Enter the amount they paid.');
    PERFORM pg_temp.refused(format('SELECT stall_write_off_recover(''%s'', 16001, ''01900000-0000-7000-8000-00000000ef01'', ''owner'')', written),
        '23514', 'You can take back up to $160.00 for this write-off.');
    PERFORM pg_temp.refused('SELECT stall_write_off_recover(''01900000-0000-7000-8000-00000000ef61'', 100, ''01900000-0000-7000-8000-00000000ef01'', ''owner'')',
        '23514', 'That write-off was not found.');
    SET LOCAL ROLE stall_poster;
    back := stall_write_off_recover(written, 5000, '01900000-0000-7000-8000-00000000ef01', 'owner');
    RESET ROLE;
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF (SELECT posting_key FROM journals WHERE id = back) <> 'rent_write_off_recovery:' || written::text || ':0'
       OR pg_temp.open_rent('01900000-0000-7000-8000-00000000ef41') <> 5000 THEN
        RAISE EXCEPTION 'the recovery did not reinstate $50.00';
    END IF;
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-00000000ef22', stall_post_rent_payment('01900000-0000-7000-8000-00000000ef11', 5000, 0, 0, pg_temp.today()));
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF pg_temp.open_rent('01900000-0000-7000-8000-00000000ef41') <> 0 THEN
        RAISE EXCEPTION 'the payment after the recovery did not clear the rent';
    END IF;
    IF ARRAY(
        SELECT source_type FROM stall_vendor_rent_moves('01900000-0000-7000-8000-00000000ef11')
        ORDER BY posting_date, journal_no
    ) <> ARRAY['rent_charge', 'rent_charge', 'rent_write_off', 'rent_write_off_recovery', 'rent_payment'] THEN
        RAISE EXCEPTION 'the history does not read charge, write-off, recovery, payment';
    END IF;
    IF (SELECT recovered_minor FROM stall_vendor_write_offs('01900000-0000-7000-8000-00000000ef11')) <> 5000
       OR (SELECT said FROM stall_vendor_write_offs('01900000-0000-7000-8000-00000000ef11')) NOT LIKE '% Taken back so far: $50.00.' THEN
        RAISE EXCEPTION 'the write-off line does not show what was taken back';
    END IF;
    -- At most what is left of that write-off.
    PERFORM pg_temp.refused(format('SELECT stall_write_off_recover(''%s'', 11001, ''01900000-0000-7000-8000-00000000ef01'', ''owner'')', written),
        '23514', 'You can take back up to $110.00 for this write-off.');
    -- A second write-off after a recovery: the recovery is the newest debt.
    PERFORM stall_write_off_recover(written, 3000, '01900000-0000-7000-8000-00000000ef01', 'owner');
    PERFORM pg_temp.refused('SELECT stall_write_off(''01900000-0000-7000-8000-00000000ef11'', ''Stopped paying'', ''01900000-0000-7000-8000-00000000ef01'', ''owner'')',
        '23514', 'Gail Gone''s debts can be written off from ' || to_char(pg_temp.today() + 90, 'MM-DD-YYYY') || '.');
    UPDATE mall SET write_off_wait_days = 0;
    PERFORM stall_write_off('01900000-0000-7000-8000-00000000ef11', 'Stopped paying', '01900000-0000-7000-8000-00000000ef01', 'owner');
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF NOT EXISTS (
        SELECT 1 FROM journals
        WHERE posting_key = 'rent_write_off:01900000-0000-7000-8000-00000000ef41:1' AND debit_total_minor = 3000
    ) OR pg_temp.open_rent('01900000-0000-7000-8000-00000000ef41') <> 0
      OR (SELECT count(*) FROM stall_vendor_write_offs('01900000-0000-7000-8000-00000000ef11')) <> 2 THEN
        RAISE EXCEPTION 'a second write-off after a recovery did not post';
    END IF;
    UPDATE mall SET write_off_wait_days = 90;
END $$;

-- Returns: Clem's $40.00 is written off against vendor receivable and taken back.
DO $$
DECLARE
    written uuid;
BEGIN
    UPDATE mall SET write_off_wait_days = 30;
    PERFORM stall_write_off('01900000-0000-7000-8000-00000000ef14', 'Closed the business', '01900000-0000-7000-8000-00000000ef01', 'owner');
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    SELECT id INTO written FROM journals
    WHERE posting_key = 'clawback_write_off:01900000-0000-7000-8000-00000000ef14:0' AND debit_total_minor = 4000;
    IF written IS NULL OR stall_vendor_open_clawback('01900000-0000-7000-8000-00000000ef14') <> 0 THEN
        RAISE EXCEPTION 'Clem''s returns were not written off';
    END IF;
    PERFORM stall_write_off_recover(written, 4000, '01900000-0000-7000-8000-00000000ef01', 'owner');
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF stall_vendor_open_clawback('01900000-0000-7000-8000-00000000ef14') <> 4000
       OR NOT EXISTS (
           SELECT 1 FROM journals
           WHERE source_type = 'clawback_write_off_recovery'
             AND posting_key = 'clawback_write_off_recovery:' || written::text || ':0'
             AND source_reference = '01900000-0000-7000-8000-00000000ef14'
       ) THEN
        RAISE EXCEPTION 'Clem''s recovery did not reinstate the returns';
    END IF;
    PERFORM pg_temp.refused(format('SELECT stall_write_off_recover(''%s'', 1, ''01900000-0000-7000-8000-00000000ef01'', ''owner'')', written),
        '23514', 'You can take back up to $0.00 for this write-off.');
    UPDATE mall SET write_off_wait_days = 90;
END $$;

-- Every journal still balances, and bad debt and its recovery are their own lines.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM journals AS posted
        WHERE posted.debit_total_minor <> (SELECT sum(debit_minor) FROM journal_lines WHERE journal_id = posted.id)
           OR posted.credit_total_minor <> (SELECT sum(credit_minor) FROM journal_lines WHERE journal_id = posted.id)
    ) THEN
        RAISE EXCEPTION 'a journal does not balance';
    END IF;
    IF (SELECT sum(line.debit_minor - line.credit_minor) FROM journal_lines AS line
        JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'bad_debt_expense') <> 23000
       OR (SELECT sum(line.credit_minor - line.debit_minor) FROM journal_lines AS line
        JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'bad_debt_recovered') <> 12000 THEN
        RAISE EXCEPTION 'bad debt and recoveries do not add up';
    END IF;
END $$;

ROLLBACK;
