-- Proof for brands. One transaction, then ROLLBACK.
-- Run with search_path set to the mall schema. The poster role is stall_poster.

BEGIN;

DO $$
DECLARE
    money_columns integer;
    code_column integer;
BEGIN
    SELECT count(*) INTO money_columns
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'brands'
      AND (
          data_type IN ('numeric', 'money', 'bigint', 'integer', 'smallint')
          OR column_name ~* '(amount|balance|minor|rate|price|total)'
      );

    IF money_columns <> 0 THEN
        RAISE EXCEPTION 'brand table carries money';
    END IF;

    SELECT count(*) INTO code_column
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'brands'
      AND column_name = 'code';

    IF code_column <> 0 THEN
        RAISE EXCEPTION 'brand table still has a code';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO brands (name) VALUES ('');
    RAISE EXCEPTION 'blank brand name was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO brands (name) VALUES ('  padded');
    RAISE EXCEPTION 'padded brand name was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

INSERT INTO brands (id, name)
VALUES ('01900000-0000-7000-8000-000000000b01', 'Ada');

INSERT INTO brands (id, name)
VALUES ('01900000-0000-7000-8000-000000000b02', 'Bee');

DO $$
BEGIN
    INSERT INTO brands (name) VALUES ('Ada');
    RAISE EXCEPTION 'duplicate brand name was accepted';
EXCEPTION
    WHEN SQLSTATE '23505' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO brands (name) VALUES ('ada');
    RAISE EXCEPTION 'brand name case collision was accepted';
EXCEPTION
    WHEN SQLSTATE '23505' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE brands
    SET name = 'Ada'
    WHERE id = '01900000-0000-7000-8000-000000000b02';
    RAISE EXCEPTION 'brand name collision was accepted';
EXCEPTION
    WHEN SQLSTATE '23505' THEN
        NULL;
END $$;

INSERT INTO brands (id, name)
VALUES ('01900000-0000-7000-8000-000000000b03', 'Gone');

DELETE FROM brands WHERE id = '01900000-0000-7000-8000-000000000b03';

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM brands WHERE id = '01900000-0000-7000-8000-000000000b03'
    ) THEN
        RAISE EXCEPTION 'unreferenced brand was not deleted';
    END IF;
END $$;

INSERT INTO brands (id, name)
VALUES ('01900000-0000-7000-8000-000000000b04', 'Held');

CREATE TABLE brand_hold (
    brand_id uuid NOT NULL REFERENCES brands (id)
);

INSERT INTO brand_hold (brand_id)
VALUES ('01900000-0000-7000-8000-000000000b04');

DO $$
BEGIN
    DELETE FROM brands WHERE id = '01900000-0000-7000-8000-000000000b04';
    RAISE EXCEPTION 'referenced brand delete was accepted';
EXCEPTION
    WHEN SQLSTATE '23503' THEN
        NULL;
END $$;

DROP TABLE brand_hold;

DO $$
BEGIN
    ALTER TABLE items DROP CONSTRAINT items_brand_id_fkey;
    ALTER TABLE ticket_draft_lines DROP CONSTRAINT ticket_draft_lines_brand_fkey;
    TRUNCATE brands;
    RAISE EXCEPTION 'brand truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%table brands is append-only and cannot be truncated%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE brands DISABLE TRIGGER brands_no_truncate;
    RAISE EXCEPTION 'disabling the brand trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO brands (id, name)
VALUES ('01900000-0000-7000-8000-000000000b05', 'Poster brand');

DO $$
BEGIN
    ALTER TABLE items DROP CONSTRAINT items_brand_id_fkey;
    ALTER TABLE ticket_draft_lines DROP CONSTRAINT ticket_draft_lines_brand_fkey;
    SET LOCAL ROLE stall_poster;
    DELETE FROM brands WHERE id = '01900000-0000-7000-8000-000000000b05';
    IF EXISTS (
        SELECT 1 FROM brands WHERE id = '01900000-0000-7000-8000-000000000b05'
    ) THEN
        RAISE EXCEPTION 'poster did not delete the brand';
    END IF;
    BEGIN
        TRUNCATE brands;
        RAISE EXCEPTION 'poster truncate was accepted';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    RESET ROLE;
END $$;

DO $$
DECLARE
    vendor_id uuid := '01900000-0000-7000-8000-0000000000c1';
    item_id uuid := '01900000-0000-7000-8000-0000000000c2';
    kept_id uuid := '01900000-0000-7000-8000-0000000000c3';
    other_id uuid := '01900000-0000-7000-8000-0000000000c4';
BEGIN
    INSERT INTO vendors (id, code, first_name, last_name)
    VALUES (vendor_id, 'brand-off', 'Ada', 'Off');
    INSERT INTO brands (id, name) VALUES (kept_id, 'Kept');
    INSERT INTO brands (id, name) VALUES (other_id, 'Other brand');
    UPDATE mall SET use_brand = false;
    BEGIN
        INSERT INTO items (code, vendor_id, brand_id, description, price_minor, barcode, intake_on)
        VALUES ('brand-off', vendor_id, kept_id, 'Off', 100, 'brand-off', DATE '2026-10-06');
        RAISE EXCEPTION 'brand was accepted while turned off';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            NULL;
    END;
    UPDATE mall SET use_brand = true;
    INSERT INTO items (id, code, vendor_id, brand_id, description, price_minor, barcode, intake_on)
    VALUES (item_id, 'brand-on', vendor_id, kept_id, 'On', 100, 'brand-on', DATE '2026-10-06');
    UPDATE mall SET use_brand = false;
    BEGIN
        UPDATE items SET brand_id = other_id WHERE id = item_id;
        RAISE EXCEPTION 'brand change was accepted while turned off';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            NULL;
    END;
    UPDATE items SET description = 'Still held' WHERE id = item_id;
    IF NOT EXISTS (
        SELECT 1 FROM items
        WHERE id = item_id AND description = 'Still held' AND brand_id = kept_id
    ) THEN
        RAISE EXCEPTION 'brand row was not kept while the field was off';
    END IF;
    DELETE FROM items WHERE id = item_id;
    UPDATE mall SET use_brand = true;
    DELETE FROM brands WHERE id = other_id;
    DELETE FROM brands WHERE id = kept_id;
    DELETE FROM vendors WHERE id = vendor_id;
END $$;

ROLLBACK;
