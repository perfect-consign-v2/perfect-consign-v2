-- The mall poster may add a vendor and a period, may delete a vendor no posted
-- line names, and cannot delete a period. The script rolls back.

BEGIN;

DO $$
DECLARE
    vendor_id uuid := '01900000-0000-7000-8000-00000000c101';
    period_id uuid := '01900000-0000-7000-8000-00000000c201';
BEGIN
    SET LOCAL ROLE stall_poster;
    PERFORM stall_seed_vendor(vendor_id, 'poster-vendor', 'Poster', 'vendor');
    INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
    VALUES (period_id, '01900000-0000-7000-8000-000000000001', '2099-01-01', '2099-12-31', 'open');

    DELETE FROM vendors WHERE id = vendor_id;
    IF (SELECT count(*) FROM vendors WHERE id = vendor_id) <> 0 THEN
        RAISE EXCEPTION 'the poster could not delete an unreferenced vendor';
    END IF;

    BEGIN
        DELETE FROM accounting_periods WHERE id = period_id;
        RAISE EXCEPTION 'the poster deleted a period';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;

    IF (SELECT count(*) FROM accounting_periods WHERE id = period_id) <> 1 THEN
        RAISE EXCEPTION 'the poster period was not kept';
    END IF;
END $$;

ROLLBACK;
