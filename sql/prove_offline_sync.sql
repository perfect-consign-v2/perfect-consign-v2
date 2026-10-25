-- A snap posts the captured price and collected tax. Commission comes from the rule engine.
-- Duplicate, closed period, quantity, a missing item, and a closed drawer are refused one at a time.
-- The second register does not post the last unit. journal_no stays empty until the post commits.

BEGIN;

INSERT INTO vendors (id, code, first_name, last_name, commission_bps)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane', 2000);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity, commission_bps)
VALUES (
    '01900000-0000-7000-8000-0000000005a1',
    'Coat',
    '01900000-0000-7000-8000-000000000101',
    'Wool coat',
    10000,
    'BC-COAT',
    DATE '2096-03-01',
    1,
    2000
);

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES
    ('01900000-0000-7000-8000-000000000021', '01900000-0000-7000-8000-000000000001', '2096-01-01', '2096-12-31', 'open'),
    ('01900000-0000-7000-8000-000000000022', '01900000-0000-7000-8000-000000000001', '2095-01-01', '2095-12-31', 'closed');

INSERT INTO registers (id, name) VALUES ('01900000-0000-7000-8000-000000000d92', 'Snap proof');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d23',
    '01900000-0000-7000-8000-000000000d92',
    'Bea',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

UPDATE register_sessions
SET closing_counts = '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    counted_cash_minor = 0,
    counted_card_minor = 0,
    counted_checks_minor = 0,
    closed_at = clock_timestamp()
WHERE id = '01900000-0000-7000-8000-000000000d23';

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d22',
    '01900000-0000-7000-8000-000000000d92',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO register_snaps (
    posting_key, session_id, business_date, item_id, price_minor, tax_minor, commission_minor, commission_bps, tax_bps, quantity
) VALUES
    ('01900000-0000-7000-8000-00000000a101', '01900000-0000-7000-8000-000000000d22', DATE '2096-03-30', '01900000-0000-7000-8000-0000000005a1', 10000, 0, 0, 0, 0, 1),
    ('01900000-0000-7000-8000-00000000a102', '01900000-0000-7000-8000-000000000d22', DATE '2096-03-30', '01900000-0000-7000-8000-0000000005a1', 10000, 0, 0, 0, 0, 1),
    ('01900000-0000-7000-8000-00000000a103', '01900000-0000-7000-8000-000000000d22', DATE '2095-03-30', '01900000-0000-7000-8000-0000000005a1', 10000, 0, 0, 0, 0, 1),
    ('01900000-0000-7000-8000-00000000a104', '01900000-0000-7000-8000-000000000d22', DATE '2096-03-30', '01900000-0000-7000-8000-0000000005a9', 10000, 0, 0, 0, 0, 1),
    ('01900000-0000-7000-8000-00000000a105', '01900000-0000-7000-8000-000000000d23', DATE '2096-03-30', '01900000-0000-7000-8000-0000000005a1', 10000, 0, 0, 0, 0, 1);

DO $$
DECLARE
    posted bigint;
    again bigint;
BEGIN
    IF (SELECT journal_no FROM register_snaps WHERE posting_key = '01900000-0000-7000-8000-00000000a101') IS NOT NULL THEN
        RAISE EXCEPTION 'a waiting snap printed a receipt number';
    END IF;
    posted := public.stall_sync_snap('01900000-0000-7000-8000-00000000a101');
    IF posted IS NULL OR (SELECT journal_no FROM register_snaps WHERE posting_key = '01900000-0000-7000-8000-00000000a101') IS DISTINCT FROM posted THEN
        RAISE EXCEPTION 'the snap did not keep its receipt number';
    END IF;
    IF (SELECT commission_minor FROM sale_lines WHERE journal_id = (SELECT journal_id FROM register_snaps WHERE posting_key = '01900000-0000-7000-8000-00000000a101')) IS DISTINCT FROM 2000 THEN
        RAISE EXCEPTION 'the snap did not use the rule-engine commission';
    END IF;
    IF (SELECT tax_variance_minor FROM register_snaps WHERE posting_key = '01900000-0000-7000-8000-00000000a101') IS NULL THEN
        RAISE EXCEPTION 'the snap did not store a tax variance';
    END IF;
    IF (SELECT posting_date FROM journals WHERE posting_key = '01900000-0000-7000-8000-00000000a101') IS DISTINCT FROM DATE '2096-03-30' THEN
        RAISE EXCEPTION 'the snap did not post on its business date';
    END IF;
    again := public.stall_sync_snap('01900000-0000-7000-8000-00000000a101');
    IF again IS DISTINCT FROM posted OR (SELECT count(*) FROM journals WHERE posting_key = '01900000-0000-7000-8000-00000000a101') IS DISTINCT FROM 1 THEN
        RAISE EXCEPTION 'a retry created a second journal';
    END IF;
    IF public.stall_sync_snap('01900000-0000-7000-8000-00000000a102') IS NOT NULL THEN
        RAISE EXCEPTION 'the last unit posted twice';
    END IF;
    IF (SELECT refusal FROM register_snaps WHERE posting_key = '01900000-0000-7000-8000-00000000a102') IS DISTINCT FROM 'there are not enough on hand' THEN
        RAISE EXCEPTION 'the second snap was not refused';
    END IF;
    IF public.stall_sync_snap('01900000-0000-7000-8000-00000000a103') IS NOT NULL THEN
        RAISE EXCEPTION 'a closed period posted';
    END IF;
    IF (SELECT refusal FROM register_snaps WHERE posting_key = '01900000-0000-7000-8000-00000000a103') IS DISTINCT FROM 'a closed period refuses a new journal' THEN
        RAISE EXCEPTION 'the closed period was not named';
    END IF;
    IF public.stall_sync_snap('01900000-0000-7000-8000-00000000a104') IS NOT NULL THEN
        RAISE EXCEPTION 'a missing item posted';
    END IF;
    IF (SELECT refusal FROM register_snaps WHERE posting_key = '01900000-0000-7000-8000-00000000a104') IS DISTINCT FROM 'a sale line needs an item' THEN
        RAISE EXCEPTION 'the missing item was not named';
    END IF;
    IF public.stall_sync_snap('01900000-0000-7000-8000-00000000a105') IS NOT NULL THEN
        RAISE EXCEPTION 'a closed drawer posted';
    END IF;
    IF (SELECT refusal FROM register_snaps WHERE posting_key = '01900000-0000-7000-8000-00000000a105') IS DISTINCT FROM 'a register session is required' THEN
        RAISE EXCEPTION 'the closed drawer was not named';
    END IF;
END $$;

ROLLBACK;
