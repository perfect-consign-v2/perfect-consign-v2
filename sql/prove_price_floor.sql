-- A price changed on a ticket line never goes below the item's floor (T4,
-- M3), for the app role and the database owner alike, and the guard cannot
-- be switched off. One transaction, then ROLLBACK.

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

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000f701', 'floor', 'Flo', 'Lane');

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity)
VALUES
    ('01900000-0000-7000-8000-00000000f711', 'FloorLamp', '01900000-0000-7000-8000-00000000f701', 'Floor lamp', 10000, 'BC-FLOOR-LAMP', DATE '2026-01-01', 1),
    ('01900000-0000-7000-8000-00000000f712', 'FreeChair', '01900000-0000-7000-8000-00000000f701', 'Free chair', 10000, 'BC-FLOOR-CHAIR', DATE '2026-01-01', 1);

INSERT INTO item_floors (item_id, floor_minor) VALUES ('01900000-0000-7000-8000-00000000f711', 8000);

INSERT INTO registers (id, name) VALUES ('01900000-0000-7000-8000-00000000f721', 'Floor proof');
INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES ('01900000-0000-7000-8000-00000000f722', '01900000-0000-7000-8000-00000000f721', 'Flo',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb, 0);
INSERT INTO tickets (id, session_id) VALUES ('01900000-0000-7000-8000-00000000f731', '01900000-0000-7000-8000-00000000f722');
INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
VALUES
    ('01900000-0000-7000-8000-00000000f731', 1, '01900000-0000-7000-8000-00000000f711', 10000),
    ('01900000-0000-7000-8000-00000000f731', 2, '01900000-0000-7000-8000-00000000f712', 10000);

-- The guard runs in every session and cannot be switched off, dropped or replaced.
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgname = 'ticket_lines_price_floor_sealed' AND tgrelid = 'ticket_lines'::regclass AND tgenabled = 'A'
    ) OR NOT platform.guarded_function(to_regprocedure('stall_refuse_price_below_floor()')) THEN
        RAISE EXCEPTION 'the price floor guard is missing, not ENABLE ALWAYS, or not sealed';
    END IF;
    PERFORM pg_temp.refused('ALTER TABLE ticket_lines DISABLE TRIGGER ticket_lines_price_floor_sealed', '25006', 'append-only triggers cannot be disabled');
    PERFORM pg_temp.refused('ALTER TABLE ticket_lines ENABLE REPLICA TRIGGER ticket_lines_price_floor_sealed', '25006', 'append-only triggers cannot be disabled');
    PERFORM pg_temp.refused('DROP TRIGGER ticket_lines_price_floor_sealed ON ticket_lines', '25006', 'append-only triggers cannot be dropped');
    PERFORM pg_temp.refused('DROP FUNCTION stall_refuse_price_below_floor() CASCADE', '25006', '');
    PERFORM pg_temp.refused(
        'CREATE OR REPLACE FUNCTION stall_refuse_price_below_floor() RETURNS trigger LANGUAGE plpgsql AS $body$ BEGIN RETURN NEW; END; $body$',
        '25006', 'a guard function cannot be replaced');
END $$;

-- Below the floor is refused for the database owner and the app role; at
-- the floor, above it, and on a piece with no floor, the change stands.
DO $$
BEGIN
    PERFORM pg_temp.refused(
        'UPDATE ticket_lines SET price_minor = 7999 WHERE ticket_id = ''01900000-0000-7000-8000-00000000f731'' AND line_no = 1',
        '23514', 'that price is below the item floor');
    SET LOCAL ROLE stall_poster;
    PERFORM pg_temp.refused(
        'UPDATE ticket_lines SET price_minor = 1 WHERE ticket_id = ''01900000-0000-7000-8000-00000000f731'' AND line_no = 1',
        '23514', 'that price is below the item floor');
    -- Moving a floor piece's low price onto it by swapping the item is refused too.
    UPDATE ticket_lines SET price_minor = 500 WHERE ticket_id = '01900000-0000-7000-8000-00000000f731' AND line_no = 2;
    PERFORM pg_temp.refused(
        'UPDATE ticket_lines SET item_id = ''01900000-0000-7000-8000-00000000f711'' WHERE ticket_id = ''01900000-0000-7000-8000-00000000f731'' AND line_no = 2',
        '23514', 'that price is below the item floor');
    UPDATE ticket_lines SET price_minor = 8000 WHERE ticket_id = '01900000-0000-7000-8000-00000000f731' AND line_no = 1;
    UPDATE ticket_lines SET price_minor = 9000 WHERE ticket_id = '01900000-0000-7000-8000-00000000f731' AND line_no = 1;
    RESET ROLE;
    IF (SELECT string_agg(price_minor::text, ',' ORDER BY line_no) FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-00000000f731') <> '9000,500' THEN
        RAISE EXCEPTION 'a price at or above the floor, or on a piece with no floor, did not stand';
    END IF;
END $$;

ROLLBACK;
