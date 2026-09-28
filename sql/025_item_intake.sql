-- Piece intake. A barcode, an optional store-take override, an intake date, and an optional expire date.
-- Days on the floor live on the mall. Null means pieces do not expire.
-- Apply inside each tenant schema, after 024. The provisioner rewrites stall_poster.

ALTER TABLE items ADD COLUMN IF NOT EXISTS barcode text;
ALTER TABLE items ADD COLUMN IF NOT EXISTS commission_bps integer;
ALTER TABLE items ADD COLUMN IF NOT EXISTS intake_on date;
ALTER TABLE items ADD COLUMN IF NOT EXISTS expires_on date;

UPDATE items AS item
SET barcode = stamped.barcode
FROM (
    SELECT id,
           'BC' || to_char(created_at AT TIME ZONE 'UTC', 'YYYYMMDD')
               || lpad(row_number() OVER (
                    PARTITION BY to_char(created_at AT TIME ZONE 'UTC', 'YYYYMMDD')
                    ORDER BY id
                  )::text, 4, '0') AS barcode
    FROM items
    WHERE barcode IS NULL
) AS stamped
WHERE item.id = stamped.id
  AND item.barcode IS NULL;

UPDATE items
SET intake_on = (created_at AT TIME ZONE 'UTC')::date
WHERE intake_on IS NULL;

ALTER TABLE items ALTER COLUMN barcode SET NOT NULL;
ALTER TABLE items ALTER COLUMN intake_on SET NOT NULL;

DO $$
BEGIN
    ALTER TABLE items ADD CONSTRAINT items_barcode_chk CHECK (
        barcode = btrim(barcode) AND barcode <> '' AND char_length(barcode) <= 100
    );
EXCEPTION
    WHEN duplicate_object THEN
        NULL;
END $$;

DO $$
BEGIN
    ALTER TABLE items ADD CONSTRAINT items_commission_chk CHECK (
        commission_bps IS NULL OR (commission_bps >= 0 AND commission_bps <= 10000)
    );
EXCEPTION
    WHEN duplicate_object THEN
        NULL;
END $$;

DO $$
BEGIN
    ALTER TABLE items ADD CONSTRAINT items_dates_chk CHECK (
        expires_on IS NULL OR intake_on <= expires_on
    );
EXCEPTION
    WHEN duplicate_object THEN
        NULL;
END $$;

CREATE UNIQUE INDEX IF NOT EXISTS items_barcode_key ON items (lower(barcode));

ALTER TABLE mall ADD COLUMN IF NOT EXISTS floor_days integer;

DO $$
BEGIN
    ALTER TABLE mall ADD CONSTRAINT mall_floor_days_chk CHECK (
        floor_days IS NULL OR (floor_days >= 1 AND floor_days <= 365)
    );
EXCEPTION
    WHEN duplicate_object THEN
        NULL;
END $$;
