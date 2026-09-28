-- Proof for items. One transaction, then ROLLBACK.
-- Run with search_path set to the mall schema. The poster role is stall_poster.

BEGIN;

DO $$
DECLARE
    extra_money integer;
    price_column integer;
    extra_facts integer;
BEGIN
    SELECT count(*) INTO extra_money
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'items'
      AND column_name <> 'price_minor'
      AND column_name <> 'commission_bps'
      AND (
          data_type IN ('numeric', 'money', 'bigint', 'integer', 'smallint')
          OR column_name ~* '(amount|balance|minor|rate|price|total)'
      );

    IF extra_money <> 0 THEN
        RAISE EXCEPTION 'item table carries an extra money column';
    END IF;

    SELECT count(*) INTO price_column
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'items'
      AND column_name = 'price_minor'
      AND data_type = 'bigint';

    IF price_column <> 1 THEN
        RAISE EXCEPTION 'asking price is missing';
    END IF;

    SELECT count(*) INTO extra_facts
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'items'
      AND column_name IN ('quantity', 'status', 'booth_id');

    IF extra_facts <> 0 THEN
        RAISE EXCEPTION 'item table carries quantity, status, or a booth';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'items'
          AND column_name = 'barcode'
          AND data_type = 'text'
          AND is_nullable = 'NO'
    ) THEN
        RAISE EXCEPTION 'item barcode is missing';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'items'
          AND column_name = 'commission_bps'
          AND data_type = 'integer'
          AND is_nullable = 'YES'
    ) THEN
        RAISE EXCEPTION 'item store take override is missing';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'items'
          AND column_name = 'intake_on'
          AND data_type = 'date'
          AND is_nullable = 'NO'
    ) THEN
        RAISE EXCEPTION 'item intake date is missing';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'items'
          AND column_name = 'expires_on'
          AND data_type = 'date'
          AND is_nullable = 'YES'
    ) THEN
        RAISE EXCEPTION 'item expire date is missing';
    END IF;
END $$;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-000000000c10', 'item-ada', 'Ada', 'Item');

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-000000000c11', 'item-bee', 'Bee', 'Item');

INSERT INTO brands (id, name)
VALUES ('01900000-0000-7000-8000-000000000c20', 'Item brand');

INSERT INTO brands (id, name)
VALUES ('01900000-0000-7000-8000-000000000c21', 'Other brand');

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on)
    VALUES ('', '01900000-0000-7000-8000-000000000c10', 'Vase', 100, 'BC-TRY', DATE '2026-09-27');
    RAISE EXCEPTION 'blank tag was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on)
    VALUES ('  padded', '01900000-0000-7000-8000-000000000c10', 'Vase', 100, 'BC-TRY', DATE '2026-09-27');
    RAISE EXCEPTION 'padded tag was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on)
    VALUES (repeat('t', 41), '01900000-0000-7000-8000-000000000c10', 'Vase', 100, 'BC-TRY', DATE '2026-09-27');
    RAISE EXCEPTION 'long tag was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on)
    VALUES ('Tag', '01900000-0000-7000-8000-000000000c10', '', 100, 'BC-TRY', DATE '2026-09-27');
    RAISE EXCEPTION 'blank description was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on)
    VALUES ('Tag', '01900000-0000-7000-8000-000000000c10', '  padded', 100, 'BC-TRY', DATE '2026-09-27');
    RAISE EXCEPTION 'padded description was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on)
    VALUES ('Tag', '01900000-0000-7000-8000-000000000c10', repeat('d', 201), 100, 'BC-TRY', DATE '2026-09-27');
    RAISE EXCEPTION 'long description was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on)
    VALUES ('Tag', '01900000-0000-7000-8000-000000000c10', 'Vase', 0, 'BC-TRY', DATE '2026-09-27');
    RAISE EXCEPTION 'zero price was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on)
    VALUES ('Tag', '01900000-0000-7000-8000-000000000c10', 'Vase', -1, 'BC-TRY', DATE '2026-09-27');
    RAISE EXCEPTION 'negative price was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on)
    VALUES ('Tag', NULL, 'Vase', 100, 'BC-TRY', DATE '2026-09-27');
    RAISE EXCEPTION 'missing vendor was accepted';
EXCEPTION
    WHEN SQLSTATE '23502' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on)
    VALUES ('Tag', '01900000-0000-7000-8000-000000000c98', 'Vase', 100, 'BC-TRY', DATE '2026-09-27');
    RAISE EXCEPTION 'unknown vendor was accepted';
EXCEPTION
    WHEN SQLSTATE '23503' THEN
        IF SQLERRM NOT LIKE '%items_vendor_id_fkey%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, brand_id, description, price_minor, barcode, intake_on)
    VALUES (
        'Tag',
        '01900000-0000-7000-8000-000000000c10',
        '01900000-0000-7000-8000-000000000c99',
        'Vase',
        100,
        'BC-TRY',
        DATE '2026-09-27'
    );
    RAISE EXCEPTION 'unknown brand was accepted';
EXCEPTION
    WHEN SQLSTATE '23503' THEN
        IF SQLERRM NOT LIKE '%items_brand_id_fkey%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on)
VALUES (
    '01900000-0000-7000-8000-000000000c01',
    'Ada',
    '01900000-0000-7000-8000-000000000c10',
    'Blue vase',
    1250,
    'BC-ADA',
    DATE '2026-09-27'
);

INSERT INTO items (id, code, vendor_id, brand_id, description, price_minor, barcode, intake_on)
VALUES (
    '01900000-0000-7000-8000-000000000c02',
    'Bee',
    '01900000-0000-7000-8000-000000000c10',
    '01900000-0000-7000-8000-000000000c20',
    'Green vase',
    1500,
    'BC-BEE',
    DATE '2026-09-01'
);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM items
        WHERE id = '01900000-0000-7000-8000-000000000c01'
          AND brand_id IS NULL
    ) THEN
        RAISE EXCEPTION 'a piece without a brand was not stored';
    END IF;
    IF NOT EXISTS (
        SELECT 1
        FROM items
        WHERE id = '01900000-0000-7000-8000-000000000c01'
          AND barcode = 'BC-ADA'
          AND commission_bps IS NULL
          AND intake_on = DATE '2026-09-27'
          AND expires_on IS NULL
    ) THEN
        RAISE EXCEPTION 'a piece did not keep an empty expire date and the consignor split';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on)
    VALUES ('Bare', '01900000-0000-7000-8000-000000000c10', 'Vase', 100, NULL, DATE '2026-09-27');
    RAISE EXCEPTION 'a null barcode was accepted';
EXCEPTION
    WHEN SQLSTATE '23502' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on)
    VALUES ('Bare', '01900000-0000-7000-8000-000000000c10', 'Vase', 100, '', DATE '2026-09-27');
    RAISE EXCEPTION 'a blank barcode was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%items_barcode_chk%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on)
    VALUES ('Bare', '01900000-0000-7000-8000-000000000c10', 'Vase', 100, '  padded', DATE '2026-09-27');
    RAISE EXCEPTION 'a padded barcode was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%items_barcode_chk%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on)
    VALUES ('Bare', '01900000-0000-7000-8000-000000000c10', 'Vase', 100, repeat('b', 101), DATE '2026-09-27');
    RAISE EXCEPTION 'a long barcode was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%items_barcode_chk%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
    VALUES ('Bare', '01900000-0000-7000-8000-000000000c10', 'Vase', 100, 'BC-RANGE', DATE '2026-09-27', -1);
    RAISE EXCEPTION 'a negative store take was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%items_commission_chk%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
    VALUES ('Bare', '01900000-0000-7000-8000-000000000c10', 'Vase', 100, 'BC-RANGE', DATE '2026-09-27', 10001);
    RAISE EXCEPTION 'a store take over 100 percent was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%items_commission_chk%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on, expires_on)
    VALUES (
        'Bare',
        '01900000-0000-7000-8000-000000000c10',
        'Vase',
        100,
        'BC-EARLY',
        DATE '2026-09-27',
        DATE '2026-09-01'
    );
    RAISE EXCEPTION 'an expire date before intake was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%items_dates_chk%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on)
    VALUES ('Bare', '01900000-0000-7000-8000-000000000c10', 'Vase', 100, 'bc-ada', DATE '2026-09-27');
    RAISE EXCEPTION 'barcode case collision was accepted';
EXCEPTION
    WHEN SQLSTATE '23505' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on)
    VALUES ('Ada', '01900000-0000-7000-8000-000000000c10', 'Copy', 100, 'BC-TRY', DATE '2026-09-27');
    RAISE EXCEPTION 'duplicate tag was accepted';
EXCEPTION
    WHEN SQLSTATE '23505' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on)
    VALUES ('ada', '01900000-0000-7000-8000-000000000c11', 'Copy', 100, 'BC-TRY', DATE '2026-09-27');
    RAISE EXCEPTION 'tag case collision was accepted';
EXCEPTION
    WHEN SQLSTATE '23505' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE items
    SET code = 'Ada'
    WHERE id = '01900000-0000-7000-8000-000000000c02';
    RAISE EXCEPTION 'tag collision was accepted';
EXCEPTION
    WHEN SQLSTATE '23505' THEN
        NULL;
END $$;

UPDATE items
SET code = 'Cee',
    barcode = 'BC-CEE',
    vendor_id = '01900000-0000-7000-8000-000000000c11',
    brand_id = '01900000-0000-7000-8000-000000000c21',
    description = 'Moved vase',
    price_minor = 2500,
    commission_bps = 2500,
    intake_on = DATE '2026-09-01',
    expires_on = DATE '2026-10-01'
WHERE id = '01900000-0000-7000-8000-000000000c02';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM items
        WHERE id = '01900000-0000-7000-8000-000000000c02'
          AND code = 'Cee'
          AND vendor_id = '01900000-0000-7000-8000-000000000c11'
          AND brand_id = '01900000-0000-7000-8000-000000000c21'
          AND description = 'Moved vase'
          AND price_minor = 2500
          AND barcode = 'BC-CEE'
          AND commission_bps = 2500
          AND intake_on = DATE '2026-09-01'
          AND expires_on = DATE '2026-10-01'
    ) THEN
        RAISE EXCEPTION 'piece fields were not saved';
    END IF;
END $$;

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on)
VALUES (
    '01900000-0000-7000-8000-000000000c03',
    'Gone',
    '01900000-0000-7000-8000-000000000c10',
    'Sold vase',
    100,
    'BC-GONE',
    DATE '2026-09-27'
);

DELETE FROM items WHERE id = '01900000-0000-7000-8000-000000000c03';

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM items WHERE id = '01900000-0000-7000-8000-000000000c03'
    ) THEN
        RAISE EXCEPTION 'unreferenced piece was not deleted';
    END IF;
END $$;

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on)
VALUES (
    '01900000-0000-7000-8000-000000000c04',
    'Held',
    '01900000-0000-7000-8000-000000000c10',
    'Held vase',
    100,
    'BC-HELD',
    DATE '2026-09-27'
);

CREATE TABLE item_hold (
    item_id uuid NOT NULL REFERENCES items (id)
);

INSERT INTO item_hold (item_id)
VALUES ('01900000-0000-7000-8000-000000000c04');

DO $$
BEGIN
    DELETE FROM items WHERE id = '01900000-0000-7000-8000-000000000c04';
    RAISE EXCEPTION 'referenced piece delete was accepted';
EXCEPTION
    WHEN SQLSTATE '23503' THEN
        IF SQLERRM NOT LIKE '%item_hold_item_id_fkey%' THEN
            RAISE;
        END IF;
END $$;

DROP TABLE item_hold;

DO $$
BEGIN
    DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-000000000c10';
    RAISE EXCEPTION 'vendor delete with inventory was accepted';
EXCEPTION
    WHEN SQLSTATE '23503' THEN
        IF SQLERRM NOT LIKE '%items_vendor_id_fkey%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DELETE FROM brands WHERE id = '01900000-0000-7000-8000-000000000c21';
    RAISE EXCEPTION 'brand delete with inventory was accepted';
EXCEPTION
    WHEN SQLSTATE '23503' THEN
        IF SQLERRM NOT LIKE '%items_brand_id_fkey%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    TRUNCATE items;
    RAISE EXCEPTION 'item truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        NULL;
END $$;

DO $$
BEGIN
    ALTER TABLE items DISABLE TRIGGER items_no_truncate;
    RAISE EXCEPTION 'disabling the item trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on)
VALUES (
    '01900000-0000-7000-8000-000000000c05',
    'Poster',
    '01900000-0000-7000-8000-000000000c10',
    'Poster vase',
    100,
    'BC-POSTER',
    DATE '2026-09-27'
);

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    DELETE FROM items WHERE id = '01900000-0000-7000-8000-000000000c05';
    IF EXISTS (
        SELECT 1 FROM items WHERE id = '01900000-0000-7000-8000-000000000c05'
    ) THEN
        RAISE EXCEPTION 'poster did not delete the piece';
    END IF;
    BEGIN
        TRUNCATE items;
        RAISE EXCEPTION 'poster truncate was accepted';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    RESET ROLE;
END $$;

ROLLBACK;
