-- Dev mall rows. Not part of provisioning. php bin/seed-dev-tenant.php loads this into slug dev.
-- The mailbox is planted from STALL_GMAIL_ADDRESS and STALL_GMAIL_APP_PASSWORD on each reset.

SELECT stall_seed_vendor(
    '01900000-0000-7000-8000-000000000e01'::uuid,
    'V001',
    'Ada',
    'Lane',
    2000,
    false,
    NULL,
    'ada@example.test',
    true,
    false,
    'pay',
    '555-0100'
);

INSERT INTO public.booths (id, code, name, width_ft, depth_ft, x_ft, z_ft, floor_id)
SELECT
    '01900000-0000-7000-8000-000000000e02',
    'A1',
    'Booth A1',
    10,
    10,
    0,
    0,
    floor.id
FROM public.map_floors AS floor
WHERE floor.name = 'Main Floor';

INSERT INTO public.booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-000000000e03',
    '01900000-0000-7000-8000-000000000e02',
    '01900000-0000-7000-8000-000000000e01',
    DATE '2026-01-01'
);

INSERT INTO public.items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity)
VALUES
    (
        '01900000-0000-7000-8000-000000000e11',
        'LAMP-1',
        '01900000-0000-7000-8000-000000000e01',
        'Brass lamp',
        2500,
        'BC000000000001',
        DATE '2026-01-02',
        1
    ),
    (
        '01900000-0000-7000-8000-000000000e12',
        'CHAIR-1',
        '01900000-0000-7000-8000-000000000e01',
        'Oak chair',
        4000,
        'BC000000000002',
        DATE '2026-01-02',
        2
    ),
    (
        '01900000-0000-7000-8000-000000000e13',
        'VASE-1',
        '01900000-0000-7000-8000-000000000e01',
        'Blue vase',
        1800,
        'BC000000000003',
        DATE '2026-01-03',
        1
    );
