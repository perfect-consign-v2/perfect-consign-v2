-- The Phase 1 guards cannot be switched off: the Inactive refusal
-- (vendors_active_transition), the per-vendor lock (journal_lines_vendor_lock_sealed),
-- the booth deposit switch (mall_deposit_switch_transition) and the late fee assert
-- (journals_late_fee_sealed). Each is ENABLE ALWAYS, so a replica session still runs
-- it; dropping, disabling or making one replica-only is refused; the app role
-- cannot drop, alter or replace them; the table owner cannot replace their
-- bodies either (the _transition and _sealed names seal them through
-- platform.guarded_function); and their tables cannot be truncated.
-- The script rolls back.

BEGIN;

DO $$
BEGIN
    IF (
        SELECT count(*)
        FROM pg_trigger AS guard
        JOIN pg_class AS relation ON relation.oid = guard.tgrelid
        WHERE relation.relnamespace = current_schema()::regnamespace
          AND guard.tgname IN ('vendors_active_transition', 'journal_lines_vendor_lock_sealed', 'mall_deposit_switch_transition', 'journals_late_fee_sealed')
          AND guard.tgenabled = 'A'
    ) <> 4 THEN
        RAISE EXCEPTION 'a Phase 1 guard is missing or not ENABLE ALWAYS';
    END IF;
END $$;

CREATE FUNCTION pg_temp.refused(attempt text, wanted text, needle text) RETURNS void
    LANGUAGE plpgsql
    AS $fn$
BEGIN
    BEGIN
        EXECUTE attempt;
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

-- Disabling, replica-only and dropping are refused.
DO $$
DECLARE
    guard record;
BEGIN
    FOR guard IN
        SELECT * FROM (VALUES
            ('vendors', 'vendors_active_transition'),
            ('journal_lines', 'journal_lines_vendor_lock_sealed'),
            ('mall', 'mall_deposit_switch_transition'),
            ('journals', 'journals_late_fee_sealed')
        ) AS listed(relation, name)
    LOOP
        PERFORM pg_temp.refused(format('ALTER TABLE %I DISABLE TRIGGER %I', guard.relation, guard.name),
            '25006', 'append-only triggers cannot be disabled');
        PERFORM pg_temp.refused(format('ALTER TABLE %I ENABLE REPLICA TRIGGER %I', guard.relation, guard.name),
            '25006', 'append-only triggers cannot be disabled');
        PERFORM pg_temp.refused(format('ALTER TABLE %I DISABLE TRIGGER ALL', guard.relation),
            '25006', 'append-only triggers cannot be disabled');
        PERFORM pg_temp.refused(format('DROP TRIGGER %I ON %I', guard.name, guard.relation),
            '25006', 'append-only triggers cannot be dropped');
        PERFORM pg_temp.refused(format('TRUNCATE %I CASCADE', guard.relation),
            '25006', '');
    END LOOP;
END $$;

-- The table owner cannot replace a guard's body.
DO $$
DECLARE
    guard text;
BEGIN
    FOREACH guard IN ARRAY ARRAY[
        'stall_reject_inactive_with_open_balance', 'stall_lock_vendor_of_line',
        'stall_reject_deposit_switch_off', 'stall_assert_late_fee'
    ]
    LOOP
        IF NOT platform.guarded_function(to_regprocedure(guard || '()')) THEN
            RAISE EXCEPTION '% is not sealed', guard;
        END IF;
        PERFORM pg_temp.refused(
            format('CREATE OR REPLACE FUNCTION %I() RETURNS trigger LANGUAGE plpgsql AS $body$ BEGIN RETURN NULL; END; $body$', guard),
            '25006', 'a guard function cannot be replaced');
        PERFORM pg_temp.refused(format('DROP FUNCTION %I() CASCADE', guard), '25006', 'append-only triggers cannot be dropped');
    END LOOP;
END $$;

-- The app role cannot drop, alter or replace a guard.
DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    PERFORM pg_temp.refused('DROP TRIGGER vendors_active_transition ON vendors', '42501', '');
    PERFORM pg_temp.refused('ALTER TABLE vendors DISABLE TRIGGER vendors_active_transition', '42501', '');
    PERFORM pg_temp.refused(
        'CREATE OR REPLACE FUNCTION stall_reject_inactive_with_open_balance() RETURNS trigger '
        || 'LANGUAGE plpgsql AS $body$ BEGIN RETURN NEW; END; $body$',
        '42501', '');
    PERFORM pg_temp.refused(
        'CREATE OR REPLACE FUNCTION stall_lock_vendor_of_line() RETURNS trigger '
        || 'LANGUAGE plpgsql AS $body$ BEGIN RETURN NEW; END; $body$',
        '42501', '');
    PERFORM pg_temp.refused(
        'CREATE OR REPLACE FUNCTION stall_reject_deposit_switch_off() RETURNS trigger '
        || 'LANGUAGE plpgsql AS $body$ BEGIN RETURN NEW; END; $body$',
        '42501', '');
    PERFORM pg_temp.refused(
        'CREATE OR REPLACE FUNCTION stall_assert_late_fee() RETURNS trigger '
        || 'LANGUAGE plpgsql AS $body$ BEGIN RETURN NULL; END; $body$',
        '42501', '');
    RESET ROLE;
END $$;

-- A replica session still runs every guard.
INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000c921',
    '01900000-0000-7000-8000-000000000001',
    '2026-01-01',
    '2026-12-31',
    'open'
);

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000c901', 'rep', 'Rita', 'Replica');

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-00000000c902', 'c902', 'Replica booth');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on, ends_on)
VALUES (
    '01900000-0000-7000-8000-00000000c903',
    '01900000-0000-7000-8000-00000000c902',
    '01900000-0000-7000-8000-00000000c901',
    '2026-01-01',
    '2026-01-31'
);

INSERT INTO assignment_rent (assignment_id, amount_minor)
VALUES ('01900000-0000-7000-8000-00000000c903', 6000);

DO $$
BEGIN
    PERFORM stall_post_rent_charge('01900000-0000-7000-8000-00000000c903', '2026-01-01', '2026-02-05');
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
END $$;

DO $$
BEGIN
    SET LOCAL session_replication_role = replica;
    PERFORM pg_temp.refused(
        $q$UPDATE vendors SET active = false WHERE id = '01900000-0000-7000-8000-00000000c901'$q$,
        '23514', 'can''t be made inactive yet');
    UPDATE mall SET booth_deposits_enabled = true;
    UPDATE mall SET booth_deposits_enabled = false;
    UPDATE mall
    SET late_fees_enabled = true, late_fee_grace_days = 5, late_fee_first_minor = 1000,
        late_fee_next_minor = 500, late_fee_effective_on = '2026-01-01';
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, journal_no, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES
        ('01900000-0000-7000-8000-00000000c904', '01900000-0000-7000-8000-000000000001', 990001,
         'booth-deposit:replica', '2026-02-05', '2026-02-05 12:00:00+00', '01900000-0000-7000-8000-00000000c921',
         'USD', 'booth_deposit', 'replica', 'Replica deposit', 100, 100),
        ('01900000-0000-7000-8000-00000000c905', '01900000-0000-7000-8000-000000000001', 990002,
         'late-fee:01900000-0000-7000-8000-00000000c903:2026-01:1', '2026-01-06', '2026-01-06 12:00:00+00',
         '01900000-0000-7000-8000-00000000c921', 'USD', 'late_fee',
         '01900000-0000-7000-8000-00000000c903:2026-01:1', 'Replica late fee', 1, 1);
    PERFORM pg_temp.refused(
        $q$INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
           VALUES ('01900000-0000-7000-8000-00000000c904', '01900000-0000-7000-8000-000000000001', 2,
                   '01900000-0000-7000-8000-00000000002d', 0, 100, 'assignment', '01900000-0000-7000-8000-00000000c903')$q$,
        '23514', 'Booth deposits are turned off for this store.');
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
    VALUES
        ('01900000-0000-7000-8000-00000000c905', '01900000-0000-7000-8000-000000000001', 1,
         '01900000-0000-7000-8000-000000000015', 1, 0, 'assignment', '01900000-0000-7000-8000-00000000c903');
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    SELECT '01900000-0000-7000-8000-00000000c905', '01900000-0000-7000-8000-000000000001', 2, role.account_id, 0, 1
    FROM chart_roles AS role WHERE role.role = 'late_fee_income';
    PERFORM pg_temp.refused(
        $q$SET CONSTRAINTS journals_late_fee_sealed IMMEDIATE$q$,
        '23514', 'for the stored amount');
    SET LOCAL session_replication_role = origin;
END $$;

-- The lock trigger names the right vendor: a line on a stay takes its vendor's
-- shared lock, which Make inactive needs exclusively.
DO $$
BEGIN
    PERFORM stall_post_rent_charge('01900000-0000-7000-8000-00000000c903', '2026-01-01', '2026-02-06');
    IF NOT EXISTS (
        SELECT 1
        FROM pg_locks
        WHERE locktype = 'advisory'
          AND pid = pg_backend_pid()
          AND mode = 'ShareLock'
          AND classid = 87401
          AND objid = hashtext('01900000-0000-7000-8000-00000000c901')::oid
    ) THEN
        RAISE EXCEPTION 'a line on a stay did not take its vendor''s shared lock';
    END IF;
END $$;

ROLLBACK;
