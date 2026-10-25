-- A snap with wrong client commission still posts the rule-engine take.
-- Poster cannot rewrite or delete a posted snap.

BEGIN;

INSERT INTO vendors (id, code, first_name, last_name, commission_bps)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane', 2000);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity, commission_bps)
VALUES (
    '01900000-0000-7000-8000-0000000005a1',
    'Coat',
    '01900000-0000-7000-8000-000000000101',
    'Wool coat',
    5000,
    'BC-COAT',
    DATE '2096-03-01',
    1,
    2000
);

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES ('01900000-0000-7000-8000-000000000021', '01900000-0000-7000-8000-000000000001', '2096-01-01', '2096-12-31', 'open');

INSERT INTO registers (id, name) VALUES ('01900000-0000-7000-8000-000000000d92', 'Snap rules');

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
) VALUES (
    '01900000-0000-7000-8000-00000000b101',
    '01900000-0000-7000-8000-000000000d22',
    DATE '2096-03-30',
    '01900000-0000-7000-8000-0000000005a1',
    5000,
    0,
    0,
    0,
    0,
    1
);

DO $$
DECLARE
    posted bigint;
    again bigint;
    expected bigint;
BEGIN
    expected := public.stall_half_up(5000, public.stall_commission_for('01900000-0000-7000-8000-0000000005a1', 5000, DATE '2096-03-30'));
    IF expected IS DISTINCT FROM 1000 THEN
        RAISE EXCEPTION 'the rule engine expected 1000, got %', expected;
    END IF;
    posted := public.stall_sync_snap('01900000-0000-7000-8000-00000000b101');
    IF posted IS NULL THEN
        RAISE EXCEPTION 'the zero-commission snap was refused';
    END IF;
    IF (SELECT commission_minor FROM sale_lines WHERE journal_id = (SELECT journal_id FROM register_snaps WHERE posting_key = '01900000-0000-7000-8000-00000000b101')) IS DISTINCT FROM expected THEN
        RAISE EXCEPTION 'client commission zero was trusted';
    END IF;
    IF (SELECT credit_minor FROM journal_lines AS line
        JOIN chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = (SELECT journal_id FROM register_snaps WHERE posting_key = '01900000-0000-7000-8000-00000000b101')
          AND role.role = 'commission_revenue') IS DISTINCT FROM expected THEN
        RAISE EXCEPTION 'the commission journal line was wrong';
    END IF;
    again := public.stall_sync_snap('01900000-0000-7000-8000-00000000b101');
    IF again IS DISTINCT FROM posted OR (SELECT count(*) FROM journals WHERE posting_key = '01900000-0000-7000-8000-00000000b101') IS DISTINCT FROM 1 THEN
        RAISE EXCEPTION 'a retry created a second journal';
    END IF;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    BEGIN
        UPDATE register_snaps SET price_minor = 1 WHERE posting_key = '01900000-0000-7000-8000-00000000b101';
        RAISE EXCEPTION 'poster updated a posted snap';
    EXCEPTION WHEN insufficient_privilege THEN
        NULL;
    END;
    BEGIN
        DELETE FROM register_snaps WHERE posting_key = '01900000-0000-7000-8000-00000000b101';
        RAISE EXCEPTION 'poster deleted a posted snap';
    EXCEPTION WHEN insufficient_privilege THEN
        NULL;
    END;
    RESET ROLE;
END $$;

ROLLBACK;
