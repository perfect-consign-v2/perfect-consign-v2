-- Mint from ticket; wrong client money cannot be inserted by poster.
-- Tax mismatch on a planted row is refused. Waiver mint posts tax 0.
-- Poster cannot rewrite or delete a posted snap. Replay still one journal.

BEGIN;

SELECT stall_seed_vendor('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane', 2000);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity, commission_bps)
VALUES (
    '01900000-0000-7000-8000-0000000005a1',
    'Coat',
    '01900000-0000-7000-8000-000000000101',
    'Wool coat',
    5000,
    'BC-COAT',
    DATE '2096-03-01',
    2,
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

UPDATE mall SET tax_bps = 0;

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-00000000c201', '01900000-0000-7000-8000-000000000d22');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor, quantity)
VALUES ('01900000-0000-7000-8000-00000000c201', 1, '01900000-0000-7000-8000-0000000005a1', 5000, 1);

DO $$
DECLARE
    minted text;
    posted bigint;
    again bigint;
    expected bigint;
BEGIN
    expected := public.stall_half_up(5000, public.stall_commission_for('01900000-0000-7000-8000-0000000005a1', 5000, DATE '2096-03-30'));
    IF expected IS DISTINCT FROM 1000 THEN
        RAISE EXCEPTION 'the rule engine expected 1000, got %', expected;
    END IF;
    SELECT public.stall_mint_register_snaps(
        '01900000-0000-7000-8000-000000000d22',
        '01900000-0000-7000-8000-00000000c201'
    ) INTO minted;
    UPDATE register_snaps SET business_date = DATE '2096-03-30' WHERE posting_key = minted;
    posted := public.stall_sync_snap(minted);
    IF posted IS NULL THEN
        RAISE EXCEPTION 'the minted snap was refused';
    END IF;
    IF (SELECT commission_minor FROM sale_lines WHERE journal_id = (SELECT journal_id FROM register_snaps WHERE posting_key = minted)) IS DISTINCT FROM expected THEN
        RAISE EXCEPTION 'client commission zero was trusted';
    END IF;
    IF (SELECT credit_minor FROM journal_lines AS line
        JOIN chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = (SELECT journal_id FROM register_snaps WHERE posting_key = minted)
          AND role.role = 'commission_revenue') IS DISTINCT FROM expected THEN
        RAISE EXCEPTION 'the commission journal line was wrong';
    END IF;
    again := public.stall_sync_snap(minted);
    IF again IS DISTINCT FROM posted OR (SELECT count(*) FROM journals WHERE posting_key = minted) IS DISTINCT FROM 1 THEN
        RAISE EXCEPTION 'a retry created a second journal';
    END IF;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    BEGIN
        UPDATE register_snaps SET price_minor = 1 WHERE status = 'posted';
        RAISE EXCEPTION 'poster updated a posted snap';
    EXCEPTION WHEN insufficient_privilege THEN
        NULL;
    END;
    BEGIN
        DELETE FROM register_snaps WHERE status = 'posted';
        RAISE EXCEPTION 'poster deleted a posted snap';
    EXCEPTION WHEN insufficient_privilege THEN
        NULL;
    END;
    BEGIN
        INSERT INTO register_snaps (
            posting_key, session_id, business_date, item_id, price_minor, tax_minor, commission_minor, quantity
        ) VALUES (
            '01900000-0000-7000-8000-00000000b199',
            '01900000-0000-7000-8000-000000000d22',
            DATE '2096-03-30',
            '01900000-0000-7000-8000-0000000005a1',
            1,
            0,
            0,
            1
        );
        RAISE EXCEPTION 'poster inserted a free-form snap';
    EXCEPTION WHEN insufficient_privilege THEN
        NULL;
    END;
    RESET ROLE;
END $$;

INSERT INTO register_snaps (
    posting_key, session_id, business_date, item_id, price_minor, tax_minor, commission_minor, commission_bps, tax_bps, quantity
) VALUES (
    '01900000-0000-7000-8000-00000000b301',
    '01900000-0000-7000-8000-000000000d22',
    DATE '2096-03-30',
    '01900000-0000-7000-8000-0000000005a1',
    5000,
    99,
    0,
    0,
    0,
    1
);

DO $$
BEGIN
    IF public.stall_sync_snap('01900000-0000-7000-8000-00000000b301') IS NOT NULL THEN
        RAISE EXCEPTION 'a tax mismatch posted';
    END IF;
    IF (SELECT refusal FROM register_snaps WHERE posting_key = '01900000-0000-7000-8000-00000000b301')
        IS DISTINCT FROM 'a sale tax does not match the mall rate' THEN
        RAISE EXCEPTION 'the tax mismatch was not named';
    END IF;
END $$;

INSERT INTO tickets (id, session_id, tax_waiver_reason)
VALUES ('01900000-0000-7000-8000-00000000c202', '01900000-0000-7000-8000-000000000d22', 'charity');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor, quantity)
VALUES ('01900000-0000-7000-8000-00000000c202', 1, '01900000-0000-7000-8000-0000000005a1', 5000, 1);

UPDATE mall SET tax_bps = 8750;

DO $$
DECLARE
    minted text;
    posted bigint;
BEGIN
    SELECT public.stall_mint_register_snaps(
        '01900000-0000-7000-8000-000000000d22',
        '01900000-0000-7000-8000-00000000c202'
    ) INTO minted;
    UPDATE register_snaps SET business_date = DATE '2096-03-30' WHERE posting_key = minted;
    IF (SELECT tax_minor FROM register_snaps WHERE posting_key = minted) IS DISTINCT FROM 0 THEN
        RAISE EXCEPTION 'waiver mint did not zero tax';
    END IF;
    IF (SELECT tax_waiver_reason FROM register_snaps WHERE posting_key = minted) IS DISTINCT FROM 'charity' THEN
        RAISE EXCEPTION 'waiver was not copied onto the snap';
    END IF;
    posted := public.stall_sync_snap(minted);
    IF posted IS NULL THEN
        RAISE EXCEPTION 'a waived snap was refused';
    END IF;
    IF (SELECT tax_minor FROM sale_lines WHERE journal_id = (SELECT journal_id FROM register_snaps WHERE posting_key = minted)) IS DISTINCT FROM 0 THEN
        RAISE EXCEPTION 'a waived snap posted tax';
    END IF;
END $$;

ROLLBACK;
