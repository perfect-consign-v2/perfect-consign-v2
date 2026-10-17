-- Parked sales can carry a short name. An item can keep one photo with the mall.
-- A books login can read the mall and cannot post. Apply after 062.
-- The provisioner rewrites stall_poster.

ALTER TABLE tickets
    ADD COLUMN park_label text;

ALTER TABLE tickets
    ADD CONSTRAINT tickets_park_label_chk CHECK (
        park_label IS NULL
        OR (
            park_label = btrim(park_label)
            AND char_length(park_label) BETWEEN 1 AND 40
        )
    );

ALTER TABLE items
    ADD COLUMN has_photo boolean NOT NULL DEFAULT false;

CREATE TABLE item_photos (
    item_id uuid PRIMARY KEY REFERENCES items (id) ON DELETE CASCADE,
    photo bytea NOT NULL,
    CONSTRAINT item_photos_size_chk CHECK (octet_length(photo) BETWEEN 1 AND 2000000)
);

GRANT SELECT, INSERT, UPDATE, DELETE ON item_photos TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON item_photos FROM stall_poster;
GRANT SELECT ON item_photos TO stall_backup;

CREATE TRIGGER item_photos_no_truncate
    BEFORE TRUNCATE ON item_photos
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE item_photos ENABLE ALWAYS TRIGGER item_photos_no_truncate;

ALTER TABLE staff DROP CONSTRAINT staff_role_chk;

ALTER TABLE staff
    ADD CONSTRAINT staff_role_chk CHECK (role IN ('cashier', 'vendor', 'books'));

ALTER TABLE staff DROP CONSTRAINT staff_vendor_chk;

ALTER TABLE staff
    ADD CONSTRAINT staff_vendor_chk CHECK (
        (role = 'vendor' AND vendor_id IS NOT NULL)
        OR (role IN ('cashier', 'books') AND vendor_id IS NULL)
    );
