-- A markdown waits in the queue. The price changes when the re-tag is confirmed.
-- A parked ticket keeps the price it captured. A floor needs an audit actor.

BEGIN;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane');

INSERT INTO markdown_steps (id, days_on_floor, percent)
VALUES ('01900000-0000-7000-8000-000000000c11', 30, 10);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity)
VALUES (
    '01900000-0000-7000-8000-0000000005a1',
    'Coat',
    '01900000-0000-7000-8000-000000000101',
    'Wool coat',
    10000,
    'BC-COAT',
    DATE '2020-01-01',
    2
);

INSERT INTO item_floors (item_id, floor_minor)
VALUES ('01900000-0000-7000-8000-0000000005a1', 9500);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity, held)
VALUES (
    '01900000-0000-7000-8000-0000000005a2',
    'Held',
    '01900000-0000-7000-8000-000000000101',
    'Held coat',
    10000,
    'BC-HELD',
    DATE '2020-01-01',
    1,
    true
);

DO $$
DECLARE
    filed integer;
    proposal uuid;
BEGIN
    filed := public.stall_propose_markdowns(CURRENT_DATE);
    IF filed IS DISTINCT FROM 1 THEN
        RAISE EXCEPTION 'a held piece was proposed';
    END IF;
    IF (SELECT proposed_minor FROM markdown_proposals WHERE item_id = '01900000-0000-7000-8000-0000000005a1') IS DISTINCT FROM 9000 THEN
        RAISE EXCEPTION 'the proposal is not the schedule price';
    END IF;
    SELECT id INTO proposal FROM markdown_proposals WHERE item_id = '01900000-0000-7000-8000-0000000005a1';
    BEGIN
        PERFORM public.stall_confirm_markdown(proposal, '', false);
        RAISE EXCEPTION 'the floor was ignored';
    EXCEPTION WHEN SQLSTATE '23514' THEN
        IF SQLERRM IS DISTINCT FROM 'a markdown stays at or above the floor' THEN
            RAISE;
        END IF;
    END;
    PERFORM public.stall_confirm_markdown(proposal, 'Ada', true);
END $$;

DO $$
BEGIN
    IF (SELECT price_minor FROM items WHERE id = '01900000-0000-7000-8000-0000000005a1') IS DISTINCT FROM 9000 THEN
        RAISE EXCEPTION 'confirmation did not change the tag price';
    END IF;
    IF (SELECT count(*) FROM audit_events WHERE action = 'markdown-floor' AND actor = 'Ada') IS DISTINCT FROM 1 THEN
        RAISE EXCEPTION 'the floor override was not audited';
    END IF;
END $$;

INSERT INTO registers (id, name) VALUES ('01900000-0000-7000-8000-000000000d92', 'Markdown proof');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d22',
    '01900000-0000-7000-8000-000000000d92',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-000000000701', '01900000-0000-7000-8000-000000000d22');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor, quantity)
VALUES ('01900000-0000-7000-8000-000000000701', 1, '01900000-0000-7000-8000-0000000005a1', 10000, 1);

DO $$
BEGIN
    IF (SELECT price_minor FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000701') IS DISTINCT FROM 10000 THEN
        RAISE EXCEPTION 'the parked price changed';
    END IF;
    IF (SELECT price_minor FROM items WHERE id = '01900000-0000-7000-8000-0000000005a1') IS DISTINCT FROM 9000 THEN
        RAISE EXCEPTION 'the tag price was not the confirmed price';
    END IF;
END $$;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-000000000021',
    '01900000-0000-7000-8000-000000000001',
    '2026-01-01',
    '2026-12-31',
    'open'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000501',
    '01900000-0000-7000-8000-000000000001',
    'sale:01900000-0000-7000-8000-000000000801',
    DATE '2026-10-24',
    '2026-10-24 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'sale', 'parked', 'Parked coat', 10000, 10000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000501',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 10000, 0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000501',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 10000, 'vendor', '01900000-0000-7000-8000-000000000101'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-000000000501', 1, '01900000-0000-7000-8000-0000000005a1', 10000, 0, 0);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000501');

SET CONSTRAINTS ALL IMMEDIATE;

DO $$
BEGIN
    IF (SELECT price_minor FROM sale_lines WHERE journal_id = '01900000-0000-7000-8000-000000000501') IS DISTINCT FROM 10000 THEN
        RAISE EXCEPTION 'the parked ticket posted the new tag price';
    END IF;
    IF (SELECT price_minor FROM items WHERE id = '01900000-0000-7000-8000-0000000005a1') IS DISTINCT FROM 9000 THEN
        RAISE EXCEPTION 'the tag price was not the confirmed price';
    END IF;
END $$;

ROLLBACK;
