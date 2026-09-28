-- Inventory. One piece: a tag, a vendor, an optional brand, a description, and an asking price.
-- The asking price is not a journal. Apply inside each tenant schema, after 022.
-- The provisioner rewrites stall_poster.

CREATE TABLE items (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    code text NOT NULL,
    vendor_id uuid NOT NULL REFERENCES vendors (id),
    brand_id uuid REFERENCES brands (id),
    description text NOT NULL,
    price_minor bigint NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT items_code_chk CHECK (
        code = btrim(code) AND code <> '' AND char_length(code) <= 40
    ),
    CONSTRAINT items_description_chk CHECK (
        description = btrim(description) AND description <> '' AND char_length(description) <= 200
    ),
    CONSTRAINT items_price_chk CHECK (price_minor > 0)
);

CREATE UNIQUE INDEX items_code_key ON items (lower(code));

GRANT SELECT, INSERT, UPDATE, DELETE ON items TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON items FROM stall_poster;

CREATE TRIGGER items_no_truncate
    BEFORE TRUNCATE ON items
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE items ENABLE ALWAYS TRIGGER items_no_truncate;
