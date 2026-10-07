-- Color, category, size, condition, and department. A name only. Not a subledger.
-- Apply inside each tenant schema, after 047. The provisioner rewrites stall_poster.

CREATE TABLE colors (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    name text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT colors_name_chk CHECK (name = btrim(name) AND name <> '')
);

CREATE UNIQUE INDEX colors_name_key ON colors (lower(name));

GRANT SELECT, INSERT, UPDATE, DELETE ON colors TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON colors FROM stall_poster;

CREATE TRIGGER colors_no_truncate
    BEFORE TRUNCATE ON colors
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE colors ENABLE ALWAYS TRIGGER colors_no_truncate;

CREATE TABLE categories (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    name text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT categories_name_chk CHECK (name = btrim(name) AND name <> '')
);

CREATE UNIQUE INDEX categories_name_key ON categories (lower(name));

GRANT SELECT, INSERT, UPDATE, DELETE ON categories TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON categories FROM stall_poster;

CREATE TRIGGER categories_no_truncate
    BEFORE TRUNCATE ON categories
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE categories ENABLE ALWAYS TRIGGER categories_no_truncate;

CREATE TABLE sizes (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    name text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT sizes_name_chk CHECK (name = btrim(name) AND name <> '')
);

CREATE UNIQUE INDEX sizes_name_key ON sizes (lower(name));

GRANT SELECT, INSERT, UPDATE, DELETE ON sizes TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON sizes FROM stall_poster;

CREATE TRIGGER sizes_no_truncate
    BEFORE TRUNCATE ON sizes
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE sizes ENABLE ALWAYS TRIGGER sizes_no_truncate;

CREATE TABLE conditions (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    name text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT conditions_name_chk CHECK (name = btrim(name) AND name <> '')
);

CREATE UNIQUE INDEX conditions_name_key ON conditions (lower(name));

GRANT SELECT, INSERT, UPDATE, DELETE ON conditions TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON conditions FROM stall_poster;

CREATE TRIGGER conditions_no_truncate
    BEFORE TRUNCATE ON conditions
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE conditions ENABLE ALWAYS TRIGGER conditions_no_truncate;

CREATE TABLE departments (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    name text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT departments_name_chk CHECK (name = btrim(name) AND name <> '')
);

CREATE UNIQUE INDEX departments_name_key ON departments (lower(name));

GRANT SELECT, INSERT, UPDATE, DELETE ON departments TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON departments FROM stall_poster;

CREATE TRIGGER departments_no_truncate
    BEFORE TRUNCATE ON departments
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE departments ENABLE ALWAYS TRIGGER departments_no_truncate;

ALTER TABLE items
    ADD COLUMN color_id uuid REFERENCES colors (id),
    ADD COLUMN category_id uuid REFERENCES categories (id),
    ADD COLUMN size_id uuid REFERENCES sizes (id),
    ADD COLUMN condition_id uuid REFERENCES conditions (id),
    ADD COLUMN department_id uuid REFERENCES departments (id);

ALTER TABLE mall
    ADD COLUMN use_color boolean NOT NULL DEFAULT true,
    ADD COLUMN use_category boolean NOT NULL DEFAULT true,
    ADD COLUMN use_size boolean NOT NULL DEFAULT true,
    ADD COLUMN use_condition boolean NOT NULL DEFAULT true,
    ADD COLUMN use_department boolean NOT NULL DEFAULT true;

CREATE OR REPLACE FUNCTION items_reject_disabled_attribute()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    use_color boolean;
    use_category boolean;
    use_size boolean;
    use_condition boolean;
    use_department boolean;
BEGIN
    SELECT mall.use_color, mall.use_category, mall.use_size, mall.use_condition, mall.use_department
      INTO use_color, use_category, use_size, use_condition, use_department
      FROM mall;

    IF (TG_OP = 'INSERT' OR NEW.color_id IS DISTINCT FROM OLD.color_id)
       AND NEW.color_id IS NOT NULL AND NOT use_color THEN
        RAISE EXCEPTION 'color is turned off' USING ERRCODE = '23514';
    END IF;
    IF (TG_OP = 'INSERT' OR NEW.category_id IS DISTINCT FROM OLD.category_id)
       AND NEW.category_id IS NOT NULL AND NOT use_category THEN
        RAISE EXCEPTION 'category is turned off' USING ERRCODE = '23514';
    END IF;
    IF (TG_OP = 'INSERT' OR NEW.size_id IS DISTINCT FROM OLD.size_id)
       AND NEW.size_id IS NOT NULL AND NOT use_size THEN
        RAISE EXCEPTION 'size is turned off' USING ERRCODE = '23514';
    END IF;
    IF (TG_OP = 'INSERT' OR NEW.condition_id IS DISTINCT FROM OLD.condition_id)
       AND NEW.condition_id IS NOT NULL AND NOT use_condition THEN
        RAISE EXCEPTION 'condition is turned off' USING ERRCODE = '23514';
    END IF;
    IF (TG_OP = 'INSERT' OR NEW.department_id IS DISTINCT FROM OLD.department_id)
       AND NEW.department_id IS NOT NULL AND NOT use_department THEN
        RAISE EXCEPTION 'department is turned off' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER items_disabled_attribute
    BEFORE INSERT OR UPDATE OF color_id, category_id, size_id, condition_id, department_id
    ON items
    FOR EACH ROW
    EXECUTE FUNCTION items_reject_disabled_attribute();
