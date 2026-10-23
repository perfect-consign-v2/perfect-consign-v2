-- Consignment store rows. Not part of provisioning. A demo wipe loads this into slug shop.

UPDATE public.mall
SET operating_mode = 'consignor',
    booth_rentals_enabled = false,
    consignment_enabled = true,
    store_stock_enabled = false;

INSERT INTO public.vendors (id, code, first_name, last_name, email, phone, commission_bps)
VALUES
    (
        '01900000-0000-7000-8000-000000000f01',
        'C001',
        'Ada',
        'Cole',
        'ada.cole@example.com',
        '555-0140',
        2500
    ),
    (
        '01900000-0000-7000-8000-000000000f02',
        'C002',
        'Ben',
        'Ruiz',
        'ben.ruiz@example.com',
        '555-0141',
        3000
    );

INSERT INTO public.items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity, placement)
VALUES
    (
        '01900000-0000-7000-8000-000000000f11',
        'LAMP-1',
        '01900000-0000-7000-8000-000000000f01',
        'Brass lamp',
        2500,
        'SC000000000001',
        DATE '2026-01-02',
        1,
        'consigned'
    ),
    (
        '01900000-0000-7000-8000-000000000f12',
        'CHAIR-1',
        '01900000-0000-7000-8000-000000000f01',
        'Oak chair',
        4000,
        'SC000000000002',
        DATE '2026-01-02',
        1,
        'consigned'
    ),
    (
        '01900000-0000-7000-8000-000000000f13',
        'VASE-1',
        '01900000-0000-7000-8000-000000000f02',
        'Blue vase',
        1800,
        'SC000000000003',
        DATE '2026-01-03',
        1,
        'consigned'
    );
