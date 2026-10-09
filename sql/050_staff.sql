-- Cashiers and vendor sign-ins. The owner row in platform.owners stays the one owner.
-- Apply after 049. The provisioner rewrites stall_poster.

CREATE TABLE staff (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    username text NOT NULL,
    display_name text NOT NULL,
    password_hash text NOT NULL,
    role text NOT NULL,
    vendor_id uuid REFERENCES vendors (id),
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT staff_username_chk CHECK (
        username = btrim(username)
        AND username ~ '^[a-z][a-z0-9-]{0,62}$'
    ),
    CONSTRAINT staff_display_name_chk CHECK (
        display_name = btrim(display_name)
        AND display_name <> ''
        AND char_length(display_name) <= 80
    ),
    CONSTRAINT staff_password_chk CHECK (char_length(password_hash) >= 20),
    CONSTRAINT staff_role_chk CHECK (role IN ('cashier', 'vendor')),
    CONSTRAINT staff_vendor_chk CHECK (
        (role = 'vendor' AND vendor_id IS NOT NULL)
        OR (role = 'cashier' AND vendor_id IS NULL)
    )
);

CREATE UNIQUE INDEX staff_username_key ON staff (lower(username));

GRANT SELECT, INSERT, UPDATE, DELETE ON staff TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON staff FROM stall_poster;
GRANT SELECT ON staff TO stall_app, stall_backup;

CREATE TRIGGER staff_no_truncate
    BEFORE TRUNCATE ON staff
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE staff ENABLE ALWAYS TRIGGER staff_no_truncate;
