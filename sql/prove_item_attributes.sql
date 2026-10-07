-- Proof for color, category, size, condition, and department. One transaction, then ROLLBACK.
-- Run with search_path set to the mall schema. The poster role is stall_poster.

BEGIN;

DO $$
DECLARE
    catalog text;
    money_columns integer;
    name_id uuid;
    held_id uuid;
    vendor_id uuid := '04800000-0000-7000-8000-0000000000a1';
    item_id uuid := '04800000-0000-7000-8000-0000000000a2';
    other_id uuid;
    column_name text;
    flag_name text;
BEGIN
    INSERT INTO vendors (id, code, first_name, last_name)
    VALUES (vendor_id, 'attr-proof', 'Ada', 'Proof');

    FOREACH catalog IN ARRAY ARRAY['colors', 'categories', 'sizes', 'conditions', 'departments']
    LOOP
        SELECT count(*) INTO money_columns
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = catalog
          AND (
              data_type IN ('numeric', 'money', 'bigint', 'integer', 'smallint')
              OR column_name ~* '(amount|balance|minor|rate|price|total)'
          );
        IF money_columns <> 0 THEN
            RAISE EXCEPTION '% table carries money', catalog;
        END IF;

        BEGIN
            EXECUTE format('INSERT INTO %I (name) VALUES ('''')', catalog);
            RAISE EXCEPTION 'blank % name was accepted', catalog;
        EXCEPTION
            WHEN SQLSTATE '23514' THEN
                NULL;
        END;

        BEGIN
            EXECUTE format('INSERT INTO %I (name) VALUES (''  padded'')', catalog);
            RAISE EXCEPTION 'padded % name was accepted', catalog;
        EXCEPTION
            WHEN SQLSTATE '23514' THEN
                NULL;
        END;

        name_id := '04800000-0000-7000-8000-0000000000b1';
        EXECUTE format('INSERT INTO %I (id, name) VALUES ($1, ''Ada'')', catalog) USING name_id;
        BEGIN
            EXECUTE format('INSERT INTO %I (name) VALUES (''ada'')', catalog);
            RAISE EXCEPTION '% name case collision was accepted', catalog;
        EXCEPTION
            WHEN SQLSTATE '23505' THEN
                NULL;
        END;

        held_id := '04800000-0000-7000-8000-0000000000b2';
        EXECUTE format('INSERT INTO %I (id, name) VALUES ($1, ''Gone'')', catalog) USING held_id;
        EXECUTE format('DELETE FROM %I WHERE id = $1', catalog) USING held_id;

        held_id := '04800000-0000-7000-8000-0000000000b3';
        EXECUTE format('INSERT INTO %I (id, name) VALUES ($1, ''Held'')', catalog) USING held_id;
        column_name := left(catalog, length(catalog) - 1) || '_id';
        IF catalog = 'categories' THEN
            column_name := 'category_id';
        ELSIF catalog = 'sizes' THEN
            column_name := 'size_id';
        END IF;

        EXECUTE format(
            'INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, %I) VALUES ($1, $2, $3, ''Held piece'', 100, $2, DATE ''2026-10-06'', $4)',
            column_name
        ) USING item_id, catalog || '-held', vendor_id, held_id;

        BEGIN
            EXECUTE format('DELETE FROM %I WHERE id = $1', catalog) USING held_id;
            RAISE EXCEPTION 'referenced % delete was accepted', catalog;
        EXCEPTION
            WHEN SQLSTATE '23503' THEN
                NULL;
        END;

        DELETE FROM items WHERE id = item_id;

        BEGIN
            EXECUTE format('TRUNCATE %I', catalog);
            RAISE EXCEPTION '% truncate was accepted', catalog;
        EXCEPTION
            WHEN SQLSTATE '25006' THEN
                IF SQLERRM NOT LIKE '%' || catalog || ' is append-only and cannot be truncated%' THEN
                    RAISE;
                END IF;
        END;

        BEGIN
            EXECUTE format('ALTER TABLE %I DISABLE TRIGGER %I', catalog, catalog || '_no_truncate');
            RAISE EXCEPTION 'disabling the % trigger was accepted', catalog;
        EXCEPTION
            WHEN SQLSTATE '25006' THEN
                IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
                    RAISE;
                END IF;
        END;

        flag_name := 'use_' || left(catalog, length(catalog) - 1);
        IF catalog = 'categories' THEN
            flag_name := 'use_category';
        ELSIF catalog = 'sizes' THEN
            flag_name := 'use_size';
        END IF;

        EXECUTE format('UPDATE mall SET %I = false', flag_name);
        BEGIN
            EXECUTE format(
                'INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on, %I) VALUES ($1, $2, ''Off'', 100, $1, DATE ''2026-10-06'', $3)',
                column_name
            ) USING catalog || '-off', vendor_id, held_id;
            RAISE EXCEPTION '% was accepted while turned off', catalog;
        EXCEPTION
            WHEN SQLSTATE '23514' THEN
                NULL;
        END;

        other_id := '04800000-0000-7000-8000-0000000000b4';
        EXECUTE format('UPDATE mall SET %I = true', flag_name);
        EXECUTE format(
            'INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, %I) VALUES ($1, $2, $3, ''On'', 100, $2, DATE ''2026-10-06'', $4)',
            column_name
        ) USING item_id, catalog || '-on', vendor_id, held_id;

        EXECUTE format('INSERT INTO %I (id, name) VALUES ($1, ''Other'')', catalog) USING other_id;
        EXECUTE format('UPDATE mall SET %I = false', flag_name);
        BEGIN
            EXECUTE format('UPDATE items SET %I = $1 WHERE id = $2', column_name) USING other_id, item_id;
            RAISE EXCEPTION '% change was accepted while turned off', catalog;
        EXCEPTION
            WHEN SQLSTATE '23514' THEN
                NULL;
        END;

        UPDATE items SET description = 'Still held' WHERE id = item_id;
        IF NOT EXISTS (
            SELECT 1 FROM items WHERE id = item_id AND description = 'Still held'
        ) THEN
            RAISE EXCEPTION '% row was not updated while the field was off', catalog;
        END IF;

        DELETE FROM items WHERE id = item_id;
        EXECUTE format('UPDATE mall SET %I = true', flag_name);
        EXECUTE format('DELETE FROM %I WHERE id = $1', catalog) USING other_id;
        EXECUTE format('DELETE FROM %I WHERE name = ''Ada''', catalog);
        EXECUTE format('DELETE FROM %I WHERE id = $1', catalog) USING held_id;
    END LOOP;

    DELETE FROM vendors WHERE id = vendor_id;
END $$;

ROLLBACK;
