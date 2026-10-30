-- Floor-lead staff role: manager (alongside cashier, books, vendor).

ALTER TABLE staff DROP CONSTRAINT IF EXISTS staff_role_chk;
ALTER TABLE staff
    ADD CONSTRAINT staff_role_chk CHECK (role = ANY (ARRAY[
        'cashier'::text,
        'vendor'::text,
        'books'::text,
        'manager'::text
    ]));

ALTER TABLE staff DROP CONSTRAINT IF EXISTS staff_vendor_chk;
ALTER TABLE staff
    ADD CONSTRAINT staff_vendor_chk CHECK (
        ((role = 'vendor'::text) AND (vendor_id IS NOT NULL))
        OR ((role = ANY (ARRAY['cashier'::text, 'books'::text, 'manager'::text])) AND (vendor_id IS NULL))
    );

ALTER TABLE audit_events DROP CONSTRAINT IF EXISTS audit_events_role_chk;
ALTER TABLE audit_events
    ADD CONSTRAINT audit_events_role_chk CHECK (role = ANY (ARRAY[
        'owner'::text,
        'cashier'::text,
        'vendor'::text,
        'books'::text,
        'manager'::text
    ]));
