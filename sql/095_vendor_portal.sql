-- Vendor portal and vendor inventory are store switches.
-- Portal on keeps the check, sales, and deals a vendor already sees.
-- Inventory starts off. The owner turns it on before a vendor can add,
-- edit, delete, or print labels.

ALTER TABLE public.mall
    ADD COLUMN vendor_portal_enabled boolean DEFAULT true NOT NULL,
    ADD COLUMN vendor_inventory_enabled boolean DEFAULT false NOT NULL;
