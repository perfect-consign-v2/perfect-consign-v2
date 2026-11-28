-- Ceilings and the second signature (T3). The database decides whether a
-- second person must sign, whether their ceiling covers the amount, and keeps
-- every sign-off; a discount or tax-free sale on a ticket stands only with a
-- sign-off for that shift in the same transaction. The guards cannot be
-- switched off and the sign-offs cannot be rewritten. One transaction, then
-- ROLLBACK.

BEGIN;

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

CREATE FUNCTION pg_temp.sign(wanted_action text, entity text, who uuid, who_name text, signer uuid, how_much bigint) RETURNS text
    LANGUAGE sql
    AS $fn$
    SELECT format('SELECT stall_sign_off(%L, %L, %L::uuid, %L, %L::uuid, %s)', wanted_action, entity, who, who_name, signer, COALESCE(how_much::text, 'NULL'));
$fn$;

INSERT INTO staff (id, username, display_name, password_hash, role, discount_ceiling_bps, tax_free_ceiling_minor)
VALUES
    ('01900000-0000-7000-8000-00000000a601', 'cash-low', 'Low Cashier', '$2y$10$proofproofproofproofproofproo', 'cashier', 1000, 0),
    ('01900000-0000-7000-8000-00000000a602', 'lead-mid', 'Mid Manager', '$2y$10$proofproofproofproofproofproo', 'manager', 1500, 5000),
    ('01900000-0000-7000-8000-00000000a603', 'lead-high', 'High Manager', '$2y$10$proofproofproofproofproofproo', 'manager', 3000, 5000),
    ('01900000-0000-7000-8000-00000000a604', 'books-one', 'Books Person', '$2y$10$proofproofproofproofproofproo', 'books', 10000, 100000);

INSERT INTO registers (id, name) VALUES ('01900000-0000-7000-8000-00000000a611', 'Sign-off proof');
INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES ('01900000-0000-7000-8000-00000000a612', '01900000-0000-7000-8000-00000000a611', 'cash-low',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb, 0);

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000a621', 'signoff', 'Sig', 'Lane');
INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity)
VALUES ('01900000-0000-7000-8000-00000000a622', 'SignLamp', '01900000-0000-7000-8000-00000000a621', 'Lamp', 1000, 'BC-SIGN-LAMP', DATE '2026-01-01', 1);

-- The guards run in every session and cannot be switched off, dropped or
-- replaced; the sign-offs cannot be rewritten or emptied.
DO $$
DECLARE
    guarded record;
BEGIN
    FOR guarded IN SELECT * FROM (VALUES
        ('tickets', 'tickets_signed_sealed', 'stall_require_ticket_sign_off()'),
        ('ticket_lines', 'ticket_lines_signed_sealed', 'stall_require_ticket_sign_off()'),
        ('sign_offs', 'sign_offs_check_sealed', 'stall_check_sign_off()')
    ) AS listed (relation, trigger_name, function_name)
    LOOP
        IF NOT EXISTS (
            SELECT 1 FROM pg_trigger
            WHERE tgname = guarded.trigger_name AND tgrelid = guarded.relation::regclass AND tgenabled = 'A'
        ) OR NOT platform.guarded_function(to_regprocedure(guarded.function_name)) THEN
            RAISE EXCEPTION '% is missing, not ENABLE ALWAYS, or not sealed', guarded.trigger_name;
        END IF;
        PERFORM pg_temp.refused(format('ALTER TABLE %I DISABLE TRIGGER %I', guarded.relation, guarded.trigger_name), '25006', 'append-only triggers cannot be disabled');
        PERFORM pg_temp.refused(format('ALTER TABLE %I ENABLE REPLICA TRIGGER %I', guarded.relation, guarded.trigger_name), '25006', 'append-only triggers cannot be disabled');
        PERFORM pg_temp.refused(format('DROP TRIGGER %I ON %I', guarded.trigger_name, guarded.relation), '25006', 'append-only triggers cannot be dropped');
        PERFORM pg_temp.refused(format('DROP FUNCTION %s CASCADE', guarded.function_name), '25006', '');
        PERFORM pg_temp.refused(
            format('CREATE OR REPLACE FUNCTION %s RETURNS trigger LANGUAGE plpgsql AS $body$ BEGIN RETURN NEW; END; $body$', guarded.function_name),
            '25006', 'a guard function cannot be replaced');
    END LOOP;
    PERFORM pg_temp.refused('TRUNCATE sign_offs', '25006', '');
END $$;

-- With second signatures off, the signed-in cashier signs alone; a second
-- person is refused as not needed; an unknown account is refused.
DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    PERFORM pg_temp.refused(
        'INSERT INTO sign_offs (action, entity_id, actor_account, actor_username, amount) VALUES (''sale.discount'', ''x'', ''01900000-0000-7000-8000-00000000a601'', ''cash-low'', 10000)',
        '42501', '');
    EXECUTE pg_temp.sign('sale.discount', '01900000-0000-7000-8000-00000000a612', '01900000-0000-7000-8000-00000000a601', 'cash-low', NULL, 10000);
    PERFORM pg_temp.refused(pg_temp.sign('sale.discount', 'x', '01900000-0000-7000-8000-00000000a601', 'cash-low', '01900000-0000-7000-8000-00000000a603', 10000), '23514', 'a second person is not needed for that');
    PERFORM pg_temp.refused(pg_temp.sign('sale.void', 'x', '01900000-0000-7000-8000-00000000a601', 'someone-else', NULL, NULL), '23514', 'the signed-in account is missing');
    PERFORM pg_temp.refused(pg_temp.sign('sale.void', 'x', '01900000-0000-7000-8000-00000000a604', 'books-one', NULL, NULL), '23514', 'the signed-in account is missing');
    PERFORM pg_temp.refused('UPDATE sign_offs SET amount = 1', '42501', '');
    RESET ROLE;
    PERFORM pg_temp.refused('UPDATE sign_offs SET amount = 1', '25006', 'a sign-off cannot be changed');
    PERFORM pg_temp.refused('DELETE FROM sign_offs', '25006', 'a sign-off cannot be changed');
END $$;

-- With second signatures on: within the cashier's own ceiling they sign
-- alone; above it a second manager or cashier must sign and their ceiling
-- must cover it; a void always needs a second person.
UPDATE mall SET require_second_signature = true;

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    IF stall_sign_off_needed('sale.discount', '01900000-0000-7000-8000-00000000a601', 'cash-low', 1000)
       OR NOT stall_sign_off_needed('sale.discount', '01900000-0000-7000-8000-00000000a601', 'cash-low', 1001)
       OR NOT stall_sign_off_needed('sale.tax_free', '01900000-0000-7000-8000-00000000a601', 'cash-low', 1)
       OR NOT stall_sign_off_needed('sale.void', '01900000-0000-7000-8000-00000000a601', 'cash-low', NULL) THEN
        RAISE EXCEPTION 'the second-signature rule did not follow the ceilings';
    END IF;
    EXECUTE pg_temp.sign('sale.discount', 'a', '01900000-0000-7000-8000-00000000a601', 'cash-low', NULL, 1000);
    PERFORM pg_temp.refused(pg_temp.sign('sale.discount', 'a', '01900000-0000-7000-8000-00000000a601', 'cash-low', NULL, 2000), '23514', 'a second person has to sign this');
    PERFORM pg_temp.refused(pg_temp.sign('sale.discount', 'a', '01900000-0000-7000-8000-00000000a601', 'cash-low', '01900000-0000-7000-8000-00000000a601', 2000), '23514', 'a second person has to sign this');
    PERFORM pg_temp.refused(pg_temp.sign('sale.discount', 'a', '01900000-0000-7000-8000-00000000a601', 'cash-low', '01900000-0000-7000-8000-00000000a604', 2000), '23514', 'a second person has to sign this');
    PERFORM pg_temp.refused(pg_temp.sign('sale.discount', 'a', '01900000-0000-7000-8000-00000000a601', 'cash-low', '01900000-0000-7000-8000-00000000a602', 2000), '23514', 'that amount is above the ceiling');
    EXECUTE pg_temp.sign('sale.discount', 'a', '01900000-0000-7000-8000-00000000a601', 'cash-low', '01900000-0000-7000-8000-00000000a603', 2000);
    PERFORM pg_temp.refused(pg_temp.sign('sale.void', 'a', '01900000-0000-7000-8000-00000000a601', 'cash-low', NULL, NULL), '23514', 'a second person has to sign this');
    EXECUTE pg_temp.sign('sale.void', 'a', '01900000-0000-7000-8000-00000000a601', 'cash-low', '01900000-0000-7000-8000-00000000a602', NULL);
    PERFORM pg_temp.refused(pg_temp.sign('sale.discount', 'a', '01900000-0000-7000-8000-00000000a601', 'cash-low', NULL, 0), '23514', '');
    RESET ROLE;
END $$;

UPDATE mall SET require_second_signature = false;

-- On the ticket: a discount or a tax-free sale without a sign-off for that
-- shift in this transaction is refused, and so is one bigger than the
-- sign-off; clearing a discount needs none.
INSERT INTO registers (id, name) VALUES ('01900000-0000-7000-8000-00000000a614', 'Sign-off proof two');
INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES ('01900000-0000-7000-8000-00000000a613', '01900000-0000-7000-8000-00000000a614', 'cash-low',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb, 0);
INSERT INTO tickets (id, session_id) VALUES ('01900000-0000-7000-8000-00000000a631', '01900000-0000-7000-8000-00000000a613');
INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
VALUES ('01900000-0000-7000-8000-00000000a631', 1, '01900000-0000-7000-8000-00000000a622', 1000);

DO $$
BEGIN
    PERFORM pg_temp.refused(
        'UPDATE tickets SET discount_kind = ''percent'', discount_value = 2000, discount_reason = ''other'' WHERE id = ''01900000-0000-7000-8000-00000000a631''',
        '23514', 'a discount needs the signed-in account''s sign-off');
    PERFORM pg_temp.refused(
        'UPDATE tickets SET discount_kind = ''amount'', discount_value = 100, discount_reason = ''other'' WHERE id = ''01900000-0000-7000-8000-00000000a631''',
        '23514', 'a discount needs the signed-in account''s sign-off');
    PERFORM pg_temp.refused(
        'UPDATE tickets SET tax_waiver_reason = ''resale'' WHERE id = ''01900000-0000-7000-8000-00000000a631''',
        '23514', 'a tax-free sale needs the signed-in account''s sign-off');
    PERFORM pg_temp.refused(
        'UPDATE ticket_lines SET line_discount_kind = ''amount'', line_discount_value = 100, line_discount_reason = ''other'', line_discount_minor = 100 WHERE ticket_id = ''01900000-0000-7000-8000-00000000a631''',
        '23514', 'a discount needs the signed-in account''s sign-off');
END $$;

-- A sign-off for that shift allows them; one for 10% does not cover 20%.
SELECT stall_sign_off('sale.discount', '01900000-0000-7000-8000-00000000a613', '01900000-0000-7000-8000-00000000a601', 'cash-low', NULL, 1000);

DO $$
BEGIN
    PERFORM pg_temp.refused(
        'UPDATE tickets SET discount_kind = ''percent'', discount_value = 2000, discount_reason = ''other'' WHERE id = ''01900000-0000-7000-8000-00000000a631''',
        '23514', 'a discount needs the signed-in account''s sign-off');
END $$;

SELECT stall_sign_off('sale.discount', '01900000-0000-7000-8000-00000000a613', '01900000-0000-7000-8000-00000000a601', 'cash-low', NULL, 10000);
SELECT stall_sign_off('sale.tax_free', '01900000-0000-7000-8000-00000000a613', '01900000-0000-7000-8000-00000000a601', 'cash-low', NULL, 1);
UPDATE tickets SET discount_kind = 'percent', discount_value = 2000, discount_reason = 'other' WHERE id = '01900000-0000-7000-8000-00000000a631';
UPDATE tickets SET tax_waiver_reason = 'resale' WHERE id = '01900000-0000-7000-8000-00000000a631';
UPDATE ticket_lines SET line_discount_kind = 'amount', line_discount_value = 100, line_discount_reason = 'other', line_discount_minor = 100
WHERE ticket_id = '01900000-0000-7000-8000-00000000a631';
UPDATE tickets SET discount_kind = NULL, discount_value = NULL, discount_reason = NULL, tax_waiver_reason = NULL
WHERE id = '01900000-0000-7000-8000-00000000a631';

DO $$
BEGIN
    IF (SELECT count(*) FROM sign_offs WHERE written_xid = pg_current_xact_id()) <> 7 THEN
        RAISE EXCEPTION 'the sign-offs were not all kept: %', (SELECT count(*) FROM sign_offs WHERE written_xid = pg_current_xact_id());
    END IF;
END $$;

ROLLBACK;
