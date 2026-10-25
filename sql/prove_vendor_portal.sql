-- The vendor role sees only the vendor named by stall.vendor_id.
-- A missing setting and another vendor's id both return no rows.

BEGIN;

INSERT INTO vendors (id, code, first_name, last_name, email, agreement_starts_on)
VALUES
    ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane', 'ada@example.test', DATE '2026-01-01'),
    ('01900000-0000-7000-8000-000000000102', 'bea', 'Bea', 'Lane', 'bea@example.test', DATE '2026-01-01');

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, expires_on)
VALUES (
    '01900000-0000-7000-8000-0000000005a1',
    'Coat',
    '01900000-0000-7000-8000-000000000101',
    'Wool coat',
    10000,
    'BC-COAT',
    DATE '2026-01-01',
    CURRENT_DATE + 7
);

INSERT INTO vendor_entry_tokens (id, vendor_id, token_hash, expires_at)
VALUES (
    '01900000-0000-7000-8000-00000000e101',
    '01900000-0000-7000-8000-000000000101',
    'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    clock_timestamp() + interval '30 minutes'
);

DO $$
DECLARE
    seen integer;
    opened uuid;
BEGIN
    PERFORM set_config('stall.vendor_id', '', true);
    SET LOCAL ROLE stall_vendor;
    SELECT count(*) INTO seen FROM stall_vendor_items;
    IF seen <> 0 THEN
        RAISE EXCEPTION 'a missing vendor sees rows';
    END IF;
    RESET ROLE;
    PERFORM set_config('stall.vendor_id', '01900000-0000-7000-8000-000000000102', true);
    SET LOCAL ROLE stall_vendor;
    SELECT count(*) INTO seen FROM stall_vendor_items WHERE id = '01900000-0000-7000-8000-0000000005a1';
    IF seen <> 0 THEN
        RAISE EXCEPTION 'another vendor sees the coat';
    END IF;
    RESET ROLE;
    PERFORM set_config('stall.vendor_id', '01900000-0000-7000-8000-000000000101', true);
    SET LOCAL ROLE stall_vendor;
    SELECT count(*) INTO seen FROM stall_vendor_items;
    IF seen IS DISTINCT FROM 1 THEN
        RAISE EXCEPTION 'the vendor cannot see their coat';
    END IF;
    SELECT count(*) INTO seen FROM stall_vendor_expiring;
    IF seen IS DISTINCT FROM 1 THEN
        RAISE EXCEPTION 'the expiring coat is hidden';
    END IF;
    SELECT count(*) INTO seen FROM stall_vendor_agreement;
    IF seen IS DISTINCT FROM 1 THEN
        RAISE EXCEPTION 'the agreement is hidden';
    END IF;
    RESET ROLE;
    opened := public.stall_vendor_open('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
    IF opened IS DISTINCT FROM '01900000-0000-7000-8000-000000000101' THEN
        RAISE EXCEPTION 'the link did not open';
    END IF;
    IF public.stall_vendor_open('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb') IS NOT NULL THEN
        RAISE EXCEPTION 'a wrong link opened';
    END IF;
    IF public.stall_vendor_open('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa') IS NOT NULL THEN
        RAISE EXCEPTION 'a used link opened again';
    END IF;
    PERFORM set_config('stall.vendor_id', '01900000-0000-7000-8000-000000000101', true);
    PERFORM public.stall_vendor_prefer('hold');
    IF (SELECT payout_mode FROM vendors WHERE id = '01900000-0000-7000-8000-000000000101') IS DISTINCT FROM 'hold' THEN
        RAISE EXCEPTION 'the preference was not hold';
    END IF;
    BEGIN
        PERFORM public.stall_vendor_prefer('credit_only');
        RAISE EXCEPTION 'store credit was offered';
    EXCEPTION WHEN SQLSTATE '23514' THEN
        IF SQLERRM IS DISTINCT FROM 'a payout preference is check or hold' THEN
            RAISE;
        END IF;
    END;
    SET LOCAL ROLE stall_vendor;
    BEGIN
        PERFORM count(*) FROM items;
        RAISE EXCEPTION 'stall_vendor read items';
    EXCEPTION WHEN insufficient_privilege THEN
        NULL;
    END;
    RESET ROLE;
    SET LOCAL ROLE stall_poster;
    BEGIN
        PERFORM count(*) FROM stall_vendor_items;
        RAISE EXCEPTION 'poster still reads stall_vendor_items';
    EXCEPTION WHEN insufficient_privilege THEN
        NULL;
    END;
    RESET ROLE;
END $$;

ROLLBACK;
