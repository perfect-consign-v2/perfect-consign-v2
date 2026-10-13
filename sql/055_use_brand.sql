-- Brand follows the other item fields. Turned off, a new brand cannot be stored.
-- A brand already on an item stays until the field is turned back on.
-- Apply inside each tenant schema, after 054. The provisioner rewrites stall_poster.

ALTER TABLE mall
    ADD COLUMN use_brand boolean NOT NULL DEFAULT true;

CREATE FUNCTION items_reject_disabled_brand()
RETURNS trigger
LANGUAGE plpgsql
AS $$
DECLARE
    use_brand boolean;
BEGIN
    SELECT mall.use_brand INTO use_brand FROM mall;

    IF (TG_OP = 'INSERT' OR NEW.brand_id IS DISTINCT FROM OLD.brand_id)
       AND NEW.brand_id IS NOT NULL AND NOT use_brand THEN
        RAISE EXCEPTION 'brand is turned off' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER items_disabled_brand
    BEFORE INSERT OR UPDATE OF brand_id
    ON items
    FOR EACH ROW
    EXECUTE FUNCTION items_reject_disabled_brand();
