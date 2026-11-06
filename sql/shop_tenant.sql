-- Consignment store flags. Not part of provisioning. A demo wipe loads this into slug shop.
-- People, stock, and posted books come from bin/ShopDemo.php.

UPDATE public.mall
SET operating_mode = 'consignor',
    booth_rentals_enabled = false,
    consignment_enabled = true,
    store_stock_enabled = false;
