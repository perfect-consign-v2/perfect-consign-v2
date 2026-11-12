-- Booth-mall flags. Not part of provisioning. A demo wipe loads this into slug mall.
-- Booths, rent, and posted books come from bin/MallDemo.php.

UPDATE public.mall
SET operating_mode = 'booth_mall',
    booth_rentals_enabled = true,
    consignment_enabled = false,
    store_stock_enabled = false,
    floor_days = NULL;
