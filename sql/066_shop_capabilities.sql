-- A tenant sells from booths, from consignment, from store stock, or from more than one.
-- Floor days belong to consignment. Apply after 065. The provisioner rewrites public. and stall_poster.

ALTER TABLE mall ADD COLUMN consignment_enabled boolean NOT NULL DEFAULT true;

ALTER TABLE mall ADD COLUMN store_stock_enabled boolean NOT NULL DEFAULT false;

UPDATE mall
SET consignment_enabled = true
WHERE booth_rentals_enabled = false
  AND consignment_enabled = false
  AND store_stock_enabled = false;

ALTER TABLE mall ADD CONSTRAINT mall_one_way_to_sell_chk CHECK (
    booth_rentals_enabled OR consignment_enabled OR store_stock_enabled
);

CREATE OR REPLACE FUNCTION stall_guard_shop_capabilities()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF NEW.floor_days IS NOT NULL AND NEW.consignment_enabled IS DISTINCT FROM true THEN
        RAISE EXCEPTION 'floor days need consignment'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS mall_shop_capabilities ON mall;
CREATE TRIGGER mall_shop_capabilities
    BEFORE INSERT OR UPDATE OF floor_days, consignment_enabled, booth_rentals_enabled, store_stock_enabled ON mall
    FOR EACH ROW
    EXECUTE FUNCTION stall_guard_shop_capabilities();
