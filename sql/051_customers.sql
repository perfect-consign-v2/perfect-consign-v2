-- Shoppers. A customer is not a vendor. Layaway names this person.
-- Apply after 050. The provisioner rewrites stall_poster.

CREATE TABLE customers (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    name text NOT NULL,
    phone text,
    email text,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT customers_name_chk CHECK (
        name = btrim(name) AND name <> '' AND char_length(name) <= 120
    ),
    CONSTRAINT customers_phone_chk CHECK (
        phone IS NULL OR (phone = btrim(phone) AND phone <> '' AND char_length(phone) <= 40)
    ),
    CONSTRAINT customers_email_chk CHECK (
        email IS NULL OR (
            email = btrim(email)
            AND email ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'
            AND char_length(email) <= 200
        )
    )
);

GRANT SELECT, INSERT, UPDATE, DELETE ON customers TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON customers FROM stall_poster;
GRANT SELECT ON customers TO stall_backup;

CREATE TRIGGER customers_no_truncate
    BEFORE TRUNCATE ON customers
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE customers ENABLE ALWAYS TRIGGER customers_no_truncate;

ALTER TABLE tickets ADD COLUMN customer_id uuid REFERENCES customers (id);
