-- Brands. A name only. Not a subledger. No money columns.
-- Apply inside each tenant schema, after 017. The provisioner rewrites stall_poster.

CREATE TABLE brands (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    name text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT brands_name_chk CHECK (name = btrim(name) AND name <> '')
);

CREATE UNIQUE INDEX brands_name_key ON brands (lower(name));

GRANT SELECT, INSERT, UPDATE, DELETE ON brands TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON brands FROM stall_poster;

CREATE TRIGGER brands_no_truncate
    BEFORE TRUNCATE ON brands
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();
