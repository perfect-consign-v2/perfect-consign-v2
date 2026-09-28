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

ROLLBACK;
