-- Item counts and move-out (D10, D11). An item's count changes only with a row
-- that says why, who and when, written in the same transaction by the
-- database's own functions; each row is used once. A lower count by hand names
-- one of the store's reasons that this person may use. A vendor who has left
-- takes no new item and no sale, and cannot be made inactive with pieces still
-- counted or on an open sale. A piece left behind becomes store stock only
-- after the owner's waiting days. The guards cannot be switched off.
-- One transaction, then ROLLBACK.

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

CREATE FUNCTION pg_temp.today() RETURNS date
    LANGUAGE sql
    AS $fn$
    SELECT (clock_timestamp() AT TIME ZONE mall.time_zone)::date FROM mall;
$fn$;

CREATE FUNCTION pg_temp.count_of(target uuid) RETURNS integer
    LANGUAGE sql
    AS $fn$
    SELECT quantity FROM items WHERE id = target;
$fn$;

CREATE FUNCTION pg_temp.rows_of(target uuid) RETURNS bigint
    LANGUAGE sql
    AS $fn$
    SELECT count(*) FROM item_quantity_changes WHERE item_id = target;
$fn$;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000e021',
    '01900000-0000-7000-8000-000000000001',
    '2026-01-01',
    '2027-12-31',
    'open'
);

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000e101', 'mary', 'Mary', 'Smith');
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000e102', 'joe', 'Joe', 'Brown');
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000e103', 'pat', 'Pat', 'Gone');
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000e104', 'lee', 'Lee', 'Left');
SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000e105', 'kim', 'Kim', 'Recent');

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity)
VALUES
    ('01900000-0000-7000-8000-00000000e201', 'MARY-1', '01900000-0000-7000-8000-00000000e101', 'Blue vase', 1500, 'BC-E201', DATE '2026-11-01', 3),
    ('01900000-0000-7000-8000-00000000e202', 'JOE-1', '01900000-0000-7000-8000-00000000e102', 'Lamp', 2500, 'BC-E202', DATE '2026-11-01', 1),
    ('01900000-0000-7000-8000-00000000e203', 'PAT-1', '01900000-0000-7000-8000-00000000e103', 'Teapot', 900, 'BC-E203', DATE '2026-11-01', 2),
    ('01900000-0000-7000-8000-00000000e204', 'LEE-1', '01900000-0000-7000-8000-00000000e104', 'Quilt', 4000, 'BC-E204', DATE '2026-09-01', 1),
    ('01900000-0000-7000-8000-00000000e205', 'KIM-1', '01900000-0000-7000-8000-00000000e105', 'Mirror', 3000, 'BC-E205', DATE '2026-09-01', 1);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, expires_on, quantity)
VALUES ('01900000-0000-7000-8000-00000000e206', 'MARY-2', '01900000-0000-7000-8000-00000000e101', 'Old chair', 2000, 'BC-E206', DATE '2026-01-01', DATE '2026-01-10', 2);

-- The guards are in place and run in every session.
DO $$
BEGIN
    IF (
        SELECT count(*)
        FROM pg_trigger AS guard
        JOIN pg_class AS relation ON relation.oid = guard.tgrelid
        WHERE relation.relnamespace = current_schema()::regnamespace
          AND guard.tgname IN (
              'items_quantity_sealed', 'item_quantity_changes_rewrite_sealed', 'item_quantity_changes_used_sealed',
              'item_quantity_changes_no_truncate', 'quantity_reasons_keep_sealed', 'quantity_reasons_no_truncate',
              'floor_findings_held_sealed'
          )
          AND guard.tgenabled = 'A'
    ) <> 7 THEN
        RAISE EXCEPTION 'an item count guard is missing or not ENABLE ALWAYS';
    END IF;
END $$;

-- Disabling, replica-only, dropping and truncating are refused.
DO $$
DECLARE
    guard record;
BEGIN
    FOR guard IN
        SELECT * FROM (VALUES
            ('items', 'items_quantity_sealed'),
            ('item_quantity_changes', 'item_quantity_changes_rewrite_sealed'),
            ('item_quantity_changes', 'item_quantity_changes_used_sealed'),
            ('quantity_reasons', 'quantity_reasons_keep_sealed'),
            ('floor_findings', 'floor_findings_held_sealed')
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
        PERFORM pg_temp.refused(format('TRUNCATE %I CASCADE', guard.relation), '25006', '');
    END LOOP;
END $$;

-- The table owner cannot replace a guard's body.
DO $$
DECLARE
    guard text;
BEGIN
    FOREACH guard IN ARRAY ARRAY[
        'stall_require_count_row', 'stall_reject_count_row_rewrite', 'stall_assert_count_row_used',
        'stall_guard_quantity_reason', 'stall_guard_held_piece'
    ]
    LOOP
        IF NOT platform.guarded_function(to_regprocedure(guard || '()')) THEN
            RAISE EXCEPTION '% is not sealed', guard;
        END IF;
        PERFORM pg_temp.refused(
            format('CREATE OR REPLACE FUNCTION %I() RETURNS trigger LANGUAGE plpgsql AS $body$ BEGIN RETURN NEW; END; $body$', guard),
            '25006', 'a guard function cannot be replaced');
        PERFORM pg_temp.refused(format('DROP FUNCTION %I() CASCADE', guard), '25006', 'append-only triggers cannot be dropped');
    END LOOP;
END $$;

-- The app role cannot set a count, write or change a count row, call the
-- writer, or touch a guard.
DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    PERFORM pg_temp.refused('UPDATE items SET quantity = 9 WHERE id = ''01900000-0000-7000-8000-00000000e201''', '42501', '');
    PERFORM pg_temp.refused(
        'INSERT INTO item_quantity_changes (item_id, old_quantity, new_quantity, ending, actor_kind) '
        || 'VALUES (''01900000-0000-7000-8000-00000000e201'', 3, 9, ''sold'', ''system'')',
        '42501', '');
    PERFORM pg_temp.refused('UPDATE item_quantity_changes SET applied = true', '42501', '');
    PERFORM pg_temp.refused('DELETE FROM item_quantity_changes', '42501', '');
    PERFORM pg_temp.refused(
        'SELECT stall_count_item(''01900000-0000-7000-8000-00000000e201'', 6, ''added'', NULL, NULL, ''staff'', ''Ada'')',
        '42501', '');
    PERFORM pg_temp.refused('DROP TRIGGER items_quantity_sealed ON items', '42501', '');
    PERFORM pg_temp.refused(
        'CREATE OR REPLACE FUNCTION stall_require_count_row() RETURNS trigger LANGUAGE plpgsql AS $body$ BEGIN RETURN NEW; END; $body$',
        '42501', '');
    -- The app may still edit everything else on an item.
    UPDATE items SET description = 'Blue glass vase' WHERE id = '01900000-0000-7000-8000-00000000e201';
    RESET ROLE;
END $$;

-- Without its row, a count change is refused: for the owner, with every stall.*
-- session setting set, and in a replica session.
DO $$
BEGIN
    PERFORM pg_temp.refused('UPDATE items SET quantity = 2 WHERE id = ''01900000-0000-7000-8000-00000000e201''',
        '23514', 'an item count changes only with its recorded reason');
    PERFORM set_config(setting, '1', true)
    FROM unnest(ARRAY[
        'stall.line_tax_exempt', 'stall.pack_from', 'stall.pack_posting', 'stall.pack_rent', 'stall.pack_run',
        'stall.pack_to', 'stall.pack_vendor', 'stall.prior_bps', 'stall.prior_tax', 'stall.snap_priced',
        'stall.vendor_id', 'stall.migration', 'stall.count_reason'
    ]) AS setting;
    PERFORM pg_temp.refused('UPDATE items SET quantity = 2 WHERE id = ''01900000-0000-7000-8000-00000000e201''',
        '23514', 'an item count changes only with its recorded reason');
    SET LOCAL session_replication_role = replica;
    PERFORM pg_temp.refused('UPDATE items SET quantity = 2 WHERE id = ''01900000-0000-7000-8000-00000000e201''',
        '23514', 'an item count changes only with its recorded reason');
    SET LOCAL session_replication_role = origin;
    PERFORM set_config(setting, '', true)
    FROM unnest(ARRAY[
        'stall.line_tax_exempt', 'stall.pack_from', 'stall.pack_posting', 'stall.pack_rent', 'stall.pack_run',
        'stall.pack_to', 'stall.pack_vendor', 'stall.prior_bps', 'stall.prior_tax', 'stall.snap_priced',
        'stall.vendor_id', 'stall.migration', 'stall.count_reason'
    ]) AS setting;
END $$;

-- One row stands for one change. A row written but never used is refused at
-- commit. A row is history: no edit, no delete, no un-using.
DO $$
BEGIN
    BEGIN
        INSERT INTO item_quantity_changes (item_id, vendor_id, old_quantity, new_quantity, ending, reason_id, actor_kind, actor)
        VALUES ('01900000-0000-7000-8000-00000000e201', '01900000-0000-7000-8000-00000000e101', 3, 2, 'by_hand', '01900000-0000-7000-8000-0000000000f2', 'staff', 'Ada');
        UPDATE items SET quantity = 2 WHERE id = '01900000-0000-7000-8000-00000000e201';
        INSERT INTO item_quantity_changes (item_id, vendor_id, old_quantity, new_quantity, ending, actor_kind, actor)
        VALUES ('01900000-0000-7000-8000-00000000e201', '01900000-0000-7000-8000-00000000e101', 2, 3, 'added', 'staff', 'Ada');
        UPDATE items SET quantity = 3 WHERE id = '01900000-0000-7000-8000-00000000e201';
        PERFORM pg_temp.refused('UPDATE items SET quantity = 2 WHERE id = ''01900000-0000-7000-8000-00000000e201''',
            '23514', 'an item count changes only with its recorded reason');
        PERFORM pg_temp.refused('UPDATE item_quantity_changes SET actor = ''Someone else''',
            '23514', 'an item count change is history and cannot change');
        PERFORM pg_temp.refused('UPDATE item_quantity_changes SET applied = false',
            '23514', 'an item count change is history and cannot change');
        PERFORM pg_temp.refused('DELETE FROM item_quantity_changes',
            '23514', 'an item count change is history and cannot change');
        INSERT INTO item_quantity_changes (item_id, vendor_id, old_quantity, new_quantity, ending, actor_kind, actor)
        VALUES ('01900000-0000-7000-8000-00000000e201', '01900000-0000-7000-8000-00000000e101', 3, 7, 'added', 'staff', 'Ada');
        PERFORM pg_temp.refused('SET CONSTRAINTS item_quantity_changes_used_sealed IMMEDIATE',
            '23514', 'an item count change was written but the count did not change');
        RAISE EXCEPTION 'undo' USING ERRCODE = 'P0001';
    EXCEPTION
        WHEN SQLSTATE 'P0001' THEN
            IF SQLERRM <> 'undo' THEN
                RAISE;
            END IF;
    END;
    IF pg_temp.count_of('01900000-0000-7000-8000-00000000e201') <> 3 OR pg_temp.rows_of('01900000-0000-7000-8000-00000000e201') <> 0 THEN
        RAISE EXCEPTION 'the single-use block left something behind';
    END IF;
END $$;

-- A lower count by hand names a reason; it writes one row with who and why.
DO $$
DECLARE
    change record;
BEGIN
    PERFORM pg_temp.refused('SELECT stall_change_item_quantity(''01900000-0000-7000-8000-00000000e201'', 2, NULL, ''staff'', ''Ada'', NULL)',
        '23514', 'Choose why there are fewer.');
    PERFORM pg_temp.refused('SELECT stall_change_item_quantity(''01900000-0000-7000-8000-00000000e201'', -1, NULL, ''staff'', ''Ada'', NULL)',
        '23514', 'Enter a count of 0 or more.');
    PERFORM pg_temp.refused('SELECT stall_change_item_quantity(''01900000-0000-7000-8000-00000000e201'', 2, ''01900000-0000-7000-8000-0000000000f1'', ''staff'', '' '', NULL)',
        '23514', 'a count change names who made it');
    PERFORM pg_temp.refused('SELECT stall_change_item_quantity(''01900000-0000-7000-8000-00000000e201'', 2, ''01900000-0000-7000-8000-0000000000f1'', ''system'', ''Ada'', NULL)',
        '23514', 'a count change names staff or a vendor');
    PERFORM pg_temp.refused('SELECT stall_change_item_quantity(''01900000-0000-7000-8000-00000000e201'', 5, ''01900000-0000-7000-8000-0000000000f1'', ''staff'', ''Ada'', NULL)',
        '23514', 'a higher count takes no reason');

    IF stall_change_item_quantity('01900000-0000-7000-8000-00000000e201', 2, '01900000-0000-7000-8000-0000000000f1', 'staff', 'Ada', NULL) <> 2 THEN
        RAISE EXCEPTION 'the new count was not returned';
    END IF;
    SELECT * INTO change FROM item_quantity_changes WHERE item_id = '01900000-0000-7000-8000-00000000e201';
    IF pg_temp.rows_of('01900000-0000-7000-8000-00000000e201') <> 1
       OR change.old_quantity <> 3 OR change.new_quantity <> 2 OR change.ending <> 'by_hand'
       OR change.reason_id <> '01900000-0000-7000-8000-0000000000f1' OR change.actor_kind <> 'staff' OR change.actor <> 'Ada'
       OR change.vendor_id <> '01900000-0000-7000-8000-00000000e101' OR NOT change.applied
       OR change.written_xid <> pg_current_xact_id()
       OR pg_temp.count_of('01900000-0000-7000-8000-00000000e201') <> 2 THEN
        RAISE EXCEPTION 'a hand change did not write its one row';
    END IF;
    IF (SELECT said FROM item_count_history WHERE id = change.id) NOT LIKE 'Ada: 3 → 2, Took it home, %' THEN
        RAISE EXCEPTION 'the count history line reads wrong: %', (SELECT said FROM item_count_history WHERE id = change.id);
    END IF;

    -- The same count writes nothing.
    PERFORM stall_change_item_quantity('01900000-0000-7000-8000-00000000e201', 2, NULL, 'staff', 'Ada', NULL);
    IF pg_temp.rows_of('01900000-0000-7000-8000-00000000e201') <> 1 THEN
        RAISE EXCEPTION 'an unchanged count wrote a row';
    END IF;

    -- A higher count is Added more; back from zero after a hand count-out is Found.
    PERFORM stall_change_item_quantity('01900000-0000-7000-8000-00000000e201', 4, NULL, 'staff', 'Ada', NULL);
    PERFORM stall_change_item_quantity('01900000-0000-7000-8000-00000000e201', 0, '01900000-0000-7000-8000-0000000000f2', 'staff', 'Ada', NULL);
    PERFORM stall_change_item_quantity('01900000-0000-7000-8000-00000000e201', 1, NULL, 'staff', 'Ada', NULL);
    IF ARRAY(SELECT ending FROM item_quantity_changes WHERE item_id = '01900000-0000-7000-8000-00000000e201' ORDER BY changed_at, id)
       <> ARRAY['by_hand', 'added', 'by_hand', 'found'] THEN
        RAISE EXCEPTION 'added and found were not recorded';
    END IF;
    PERFORM stall_change_item_quantity('01900000-0000-7000-8000-00000000e201', 3, NULL, 'staff', 'Ada', NULL);
END $$;

-- A vendor changes only their own items, with a reason vendors may use.
DO $$
BEGIN
    INSERT INTO quantity_reasons (id, label, kind, vendor_may, staff_may, position)
    VALUES ('01900000-0000-7000-8000-00000000e301', 'Moved to another store', 'leaving', false, true, 9);
    PERFORM pg_temp.refused('SELECT stall_change_item_quantity(''01900000-0000-7000-8000-00000000e202'', 0, ''01900000-0000-7000-8000-0000000000f1'', ''vendor'', ''Mary Smith'', ''01900000-0000-7000-8000-00000000e101'')',
        '23514', 'That item is not yours.');
    PERFORM pg_temp.refused('SELECT stall_change_item_quantity(''01900000-0000-7000-8000-00000000e201'', 2, ''01900000-0000-7000-8000-0000000000f1'', ''vendor'', ''Mary Smith'', NULL)',
        '23514', 'That item is not yours.');
    PERFORM pg_temp.refused('SELECT stall_change_item_quantity(''01900000-0000-7000-8000-00000000e201'', 2, ''01900000-0000-7000-8000-00000000e301'', ''vendor'', ''Mary Smith'', ''01900000-0000-7000-8000-00000000e101'')',
        '23514', 'Choose why there are fewer.');
    PERFORM stall_change_item_quantity('01900000-0000-7000-8000-00000000e201', 2, '01900000-0000-7000-8000-0000000000f3', 'vendor', 'Mary Smith', '01900000-0000-7000-8000-00000000e101');
    IF NOT EXISTS (
        SELECT 1 FROM item_quantity_changes
        WHERE item_id = '01900000-0000-7000-8000-00000000e201' AND actor_kind = 'vendor' AND actor = 'Mary Smith'
          AND reason_id = '01900000-0000-7000-8000-0000000000f3' AND new_quantity = 2
    ) THEN
        RAISE EXCEPTION 'the vendor change was not recorded';
    END IF;
    -- A switched-off reason is refused for everyone.
    UPDATE quantity_reasons SET active = false WHERE id = '01900000-0000-7000-8000-0000000000f3';
    PERFORM pg_temp.refused('SELECT stall_change_item_quantity(''01900000-0000-7000-8000-00000000e201'', 1, ''01900000-0000-7000-8000-0000000000f3'', ''staff'', ''Ada'', NULL)',
        '23514', 'Choose why there are fewer.');
    UPDATE quantity_reasons SET active = true WHERE id = '01900000-0000-7000-8000-0000000000f3';
    -- The app role reaches the hand change, and only through it.
    SET LOCAL ROLE stall_poster;
    PERFORM stall_change_item_quantity('01900000-0000-7000-8000-00000000e201', 3, NULL, 'staff', 'Ada', NULL);
    RESET ROLE;
END $$;

-- The reasons: at least one stays on, a used reason is never deleted, a code
-- never changes, and someone must be able to use each one.
DO $$
BEGIN
    PERFORM pg_temp.refused('DELETE FROM quantity_reasons WHERE id = ''01900000-0000-7000-8000-0000000000f1''',
        '23514', 'That reason is in the count history. Switch it off instead.');
    PERFORM pg_temp.refused('UPDATE quantity_reasons SET code = ''home'' WHERE id = ''01900000-0000-7000-8000-0000000000f1''',
        '23514', 'a reason keeps its code');
    PERFORM pg_temp.refused('UPDATE quantity_reasons SET vendor_may = false, staff_may = false WHERE id = ''01900000-0000-7000-8000-0000000000f4''',
        '23514', 'quantity_reasons_someone_chk');
    DELETE FROM quantity_reasons WHERE id = '01900000-0000-7000-8000-00000000e301';
    UPDATE quantity_reasons SET active = false WHERE id <> '01900000-0000-7000-8000-0000000000f4';
    PERFORM pg_temp.refused('UPDATE quantity_reasons SET active = false WHERE id = ''01900000-0000-7000-8000-0000000000f4''',
        '23514', 'Keep at least one reason switched on.');
    PERFORM pg_temp.refused('DELETE FROM quantity_reasons WHERE id = ''01900000-0000-7000-8000-0000000000f4''',
        '23514', 'Keep at least one reason switched on.');
    PERFORM pg_temp.refused('UPDATE quantity_reasons SET active = false',
        '23514', 'Keep at least one reason switched on.');
    UPDATE quantity_reasons SET active = true;
END $$;

-- Giving an expired piece back to its consignor counts it out with one row.
INSERT INTO item_dispositions (item_id, kind, posted_on)
VALUES ('01900000-0000-7000-8000-00000000e206', 'return', pg_temp.today());

DO $$
BEGIN
    IF pg_temp.count_of('01900000-0000-7000-8000-00000000e206') <> 0
       OR (SELECT count(*) FROM item_quantity_changes
           WHERE item_id = '01900000-0000-7000-8000-00000000e206' AND ending = 'returned_to_consignor'
             AND old_quantity = 2 AND new_quantity = 0 AND actor_kind = 'system') <> 1
       OR pg_temp.rows_of('01900000-0000-7000-8000-00000000e206') <> 1 THEN
        RAISE EXCEPTION 'a return to the consignor did not write its one row';
    END IF;
END $$;

-- A vendor who has left: no new item, no change of seller to them, no sale of
-- their piece, no higher count. Make inactive waits for every piece to be
-- counted out and off every open sale.
INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-00000000e022',
    '01900000-0000-7000-8000-000000000d10',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-00000000e401', '01900000-0000-7000-8000-00000000e022');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor, quantity)
VALUES ('01900000-0000-7000-8000-00000000e401', 1, '01900000-0000-7000-8000-00000000e203', 900, 1);

DO $$
BEGIN
    PERFORM pg_temp.refused('UPDATE vendors SET active = false WHERE id = ''01900000-0000-7000-8000-00000000e103''',
        '23514', 'They still have 2 items in the store. Close them out first.');
    PERFORM pg_temp.refused('UPDATE vendors SET active = false WHERE id = ''01900000-0000-7000-8000-00000000e103''',
        '23514', '1 of their items is on an open, parked or layaway sale.');
    PERFORM pg_temp.refused('SELECT stall_change_item_quantity(''01900000-0000-7000-8000-00000000e203'', 0, ''01900000-0000-7000-8000-0000000000f1'', ''staff'', ''Ada'', NULL)',
        '23514', '1 of these is on an open, parked or layaway sale. Finish or remove that sale first.');
    PERFORM stall_change_item_quantity('01900000-0000-7000-8000-00000000e203', 1, '01900000-0000-7000-8000-0000000000f1', 'staff', 'Ada', NULL);
    DELETE FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-00000000e401';
    PERFORM stall_change_item_quantity('01900000-0000-7000-8000-00000000e203', 0, '01900000-0000-7000-8000-0000000000f1', 'staff', 'Ada', NULL);
    UPDATE vendors SET active = false WHERE id = '01900000-0000-7000-8000-00000000e103';

    IF EXISTS (SELECT 1 FROM active_sellers WHERE id = '01900000-0000-7000-8000-00000000e103')
       OR NOT EXISTS (SELECT 1 FROM active_sellers WHERE id = '01900000-0000-7000-8000-00000000e101') THEN
        RAISE EXCEPTION 'the Active sellers view is wrong';
    END IF;
    PERFORM pg_temp.refused(
        'INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on) '
        || 'VALUES (''PAT-2'', ''01900000-0000-7000-8000-00000000e103'', ''Cup'', 100, ''BC-PAT2'', DATE ''2026-11-01'')',
        '23514', 'Pat Gone has left the store. Choose another vendor.');
    PERFORM pg_temp.refused('UPDATE items SET vendor_id = ''01900000-0000-7000-8000-00000000e103'' WHERE id = ''01900000-0000-7000-8000-00000000e202''',
        '23514', 'Pat Gone has left the store. Choose another vendor.');
    PERFORM pg_temp.refused('SELECT stall_change_item_quantity(''01900000-0000-7000-8000-00000000e203'', 1, NULL, ''staff'', ''Ada'', NULL)',
        '23514', 'Pat Gone has left the store. Choose another vendor.');
    PERFORM pg_temp.refused(
        'INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor, quantity) '
        || 'VALUES (''01900000-0000-7000-8000-00000000e401'', 2, ''01900000-0000-7000-8000-00000000e203'', 900, 1)',
        '23514', 'This piece belongs to Pat Gone, who has left the store. Please set it aside and give it to the manager.');
    -- The app's money lists do not count pieces as money left open.
    UPDATE vendors SET active = true WHERE id = '01900000-0000-7000-8000-00000000e103';
END $$;

-- A piece that turns up after its vendor left is held for them until picked up.
DO $$
BEGIN
    PERFORM pg_temp.refused(
        'INSERT INTO floor_findings (item_id, kind, noted_on, noted_by) VALUES (''01900000-0000-7000-8000-00000000e201'', ''held'', CURRENT_DATE, ''Ada'')',
        '23514', 'Only a piece from a vendor who has left is held for them.');
    UPDATE vendors SET active = false WHERE id = '01900000-0000-7000-8000-00000000e103';
    INSERT INTO floor_findings (item_id, kind, noted_on, noted_by)
    VALUES ('01900000-0000-7000-8000-00000000e203', 'held', pg_temp.today(), 'Ada');
    PERFORM pg_temp.refused(
        'INSERT INTO floor_findings (item_id, kind, noted_on, noted_by) VALUES (''01900000-0000-7000-8000-00000000e203'', ''held'', CURRENT_DATE, ''Ada'')',
        '23514', 'That piece is already held.');
    IF (SELECT count(*) FROM held_pieces WHERE vendor_id = '01900000-0000-7000-8000-00000000e103') <> 1 THEN
        RAISE EXCEPTION 'the held piece is not listed';
    END IF;
    INSERT INTO pickups (item_id, vendor_id, picked_up_on, received_by)
    VALUES ('01900000-0000-7000-8000-00000000e203', '01900000-0000-7000-8000-00000000e103', pg_temp.today(), 'Ada');
    IF EXISTS (SELECT 1 FROM held_pieces WHERE vendor_id = '01900000-0000-7000-8000-00000000e103') THEN
        RAISE EXCEPTION 'a picked-up piece is still held';
    END IF;
END $$;

-- D11: a piece left behind becomes store stock only after the owner's waiting
-- days, counted from the day the last booth ended. It keeps its count.
INSERT INTO booths (id, code, name)
VALUES
    ('01900000-0000-7000-8000-00000000e501', 'E1', 'Booth E1'),
    ('01900000-0000-7000-8000-00000000e502', 'E2', 'Booth E2'),
    ('01900000-0000-7000-8000-00000000e503', 'E3', 'Booth E3');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on, ends_on)
VALUES
    ('01900000-0000-7000-8000-00000000e601', '01900000-0000-7000-8000-00000000e501', '01900000-0000-7000-8000-00000000e104', DATE '2026-01-01', pg_temp.today() - 40),
    ('01900000-0000-7000-8000-00000000e602', '01900000-0000-7000-8000-00000000e502', '01900000-0000-7000-8000-00000000e105', DATE '2026-01-01', pg_temp.today() - 10),
    ('01900000-0000-7000-8000-00000000e603', '01900000-0000-7000-8000-00000000e503', '01900000-0000-7000-8000-00000000e102', DATE '2026-01-01', NULL);

DO $$
BEGIN
    PERFORM pg_temp.refused(
        'INSERT INTO item_dispositions (item_id, kind, posted_on) VALUES (''01900000-0000-7000-8000-00000000e204'', ''abandoned'', CURRENT_DATE)',
        '23514', 'Keeping left-behind items is off.');
    UPDATE mall SET abandon_wait_days = 30;
    PERFORM pg_temp.refused(
        'INSERT INTO item_dispositions (item_id, kind, posted_on) VALUES (''01900000-0000-7000-8000-00000000e204'', ''abandoned'', CURRENT_DATE)',
        '23514', 'Turn on store stock first.');
    UPDATE mall SET store_stock_enabled = true;
    PERFORM pg_temp.refused(
        'INSERT INTO item_dispositions (item_id, kind, posted_on) VALUES (''01900000-0000-7000-8000-00000000e205'', ''abandoned'', CURRENT_DATE)',
        '23514', 'The store can keep Kim Recent''s items from ' || to_char(pg_temp.today() + 20, 'MM-DD-YYYY') || '.');
    PERFORM pg_temp.refused(
        'INSERT INTO item_dispositions (item_id, kind, posted_on) VALUES (''01900000-0000-7000-8000-00000000e202'', ''abandoned'', CURRENT_DATE)',
        '23514', 'Joe Brown still has a booth.');
    PERFORM pg_temp.refused(
        'INSERT INTO item_dispositions (item_id, kind, posted_on) VALUES (''01900000-0000-7000-8000-00000000e201'', ''abandoned'', CURRENT_DATE)',
        '23514', 'Only items left after a booth ends can be kept.');
    PERFORM pg_temp.refused('UPDATE mall SET abandon_wait_days = 0', '23514', 'mall_abandon_wait_days_chk');

    INSERT INTO item_dispositions (item_id, kind, posted_on)
    VALUES ('01900000-0000-7000-8000-00000000e204', 'abandoned', pg_temp.today());
    IF NOT EXISTS (
        SELECT 1 FROM items
        WHERE id = '01900000-0000-7000-8000-00000000e204'
          AND placement = 'store' AND vendor_id IS NULL AND cost_minor IS NULL AND quantity = 1
    ) OR pg_temp.rows_of('01900000-0000-7000-8000-00000000e204') <> 0
      OR NOT EXISTS (
          SELECT 1 FROM item_dispositions
          WHERE item_id = '01900000-0000-7000-8000-00000000e204' AND kind = 'abandoned' AND vendor_name = 'Lee Left'
      ) THEN
        RAISE EXCEPTION 'a piece left behind did not become store stock';
    END IF;
END $$;

-- Item counts (D10): every count change above wrote exactly one row, used by
-- that change, and each item's rows run from one count to the next and end at
-- its count now.
DO $$
BEGIN
    SET CONSTRAINTS item_quantity_changes_used_sealed IMMEDIATE;
    IF EXISTS (SELECT 1 FROM item_quantity_changes WHERE NOT applied) THEN
        RAISE EXCEPTION 'a count change row was never used';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM (
            SELECT change.item_id, change.old_quantity, change.new_quantity,
                   lag(change.new_quantity) OVER (PARTITION BY change.item_id ORDER BY change.changed_at, change.id) AS before,
                   lead(change.id) OVER (PARTITION BY change.item_id ORDER BY change.changed_at, change.id) AS later
            FROM item_quantity_changes AS change
        ) AS chain
        JOIN items AS item ON item.id = chain.item_id
        WHERE (chain.before IS NOT NULL AND chain.before <> chain.old_quantity)
           OR (chain.later IS NULL AND chain.new_quantity <> item.quantity)
    ) THEN
        RAISE EXCEPTION 'an item count history does not match its counts';
    END IF;
END $$;

ROLLBACK;
