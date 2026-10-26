-- Quantity deals. A bundle prices complete groups. A store deal does not cut the vendor's check.
-- One transaction, then ROLLBACK.

BEGIN;

UPDATE mall SET tax_bps = 0, commission_bps = 4000, cash_round_minor = 0;

INSERT INTO vendors (id, code, first_name, last_name, commission_bps)
VALUES ('01900000-0000-7000-8000-000000000e10', 'bulk', 'Ada', 'Bulk', 4000);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity)
VALUES
    ('01900000-0000-7000-8000-000000000e21', 'BulkHat', '01900000-0000-7000-8000-000000000e10', 'Wool hat', 1000, 'BC-BULK-HAT', CURRENT_DATE, 40),
    ('01900000-0000-7000-8000-000000000e22', 'BulkMug', '01900000-0000-7000-8000-000000000e10', 'Mug', 1000, 'BC-BULK-MUG', CURRENT_DATE, 40),
    ('01900000-0000-7000-8000-000000000e23', 'BulkPin', '01900000-0000-7000-8000-000000000e10', 'Pin', 1000, 'BC-BULK-PIN', CURRENT_DATE, 40);

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-000000000e30',
    '01900000-0000-7000-8000-000000000001',
    DATE '2000-01-01',
    DATE '2099-12-31',
    'open'
);

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-000000000e40', 'Bulk proof');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000e41',
    '01900000-0000-7000-8000-000000000e40',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO offers (id, name, scope, scope_id, kind, threshold_qty, deal_minor, funded_by, starts_on)
VALUES (
    '01900000-0000-7000-8000-000000000e61',
    '3 for $25',
    'item',
    '01900000-0000-7000-8000-000000000e21',
    'bundle',
    3,
    2500,
    'vendor',
    (SELECT (clock_timestamp() AT TIME ZONE time_zone)::date FROM mall)
);

DO $$
BEGIN
    INSERT INTO offers (name, scope, scope_id, kind, threshold_qty, deal_minor, funded_by, starts_on)
    VALUES (
        'Not cheaper',
        'item',
        '01900000-0000-7000-8000-000000000e21',
        'bundle',
        3,
        4000,
        'vendor',
        (SELECT (clock_timestamp() AT TIME ZONE time_zone)::date FROM mall)
    );
    RAISE EXCEPTION 'a deal above the tag was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%the deal is not cheaper than the tag%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-000000000e51', '01900000-0000-7000-8000-000000000e41');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor, quantity)
VALUES ('01900000-0000-7000-8000-000000000e51', 1, '01900000-0000-7000-8000-000000000e21', 1000, 1);

DO $$
BEGIN
    IF (SELECT offer_discount_minor + store_discount_minor FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000e51') <> 0 THEN
        RAISE EXCEPTION 'one unit took the bundle';
    END IF;
    UPDATE ticket_lines SET quantity = 2 WHERE ticket_id = '01900000-0000-7000-8000-000000000e51';
    IF (SELECT offer_discount_minor + store_discount_minor FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000e51') <> 0 THEN
        RAISE EXCEPTION 'two units took the bundle';
    END IF;
    UPDATE ticket_lines SET quantity = 3 WHERE ticket_id = '01900000-0000-7000-8000-000000000e51';
    IF (SELECT offer_discount_minor FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000e51') <> 500
        OR (SELECT store_discount_minor FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000e51') <> 0 THEN
        RAISE EXCEPTION 'three units were not $5 off';
    END IF;
    UPDATE ticket_lines SET quantity = 4 WHERE ticket_id = '01900000-0000-7000-8000-000000000e51';
    IF (SELECT offer_discount_minor FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000e51') <> 500 THEN
        RAISE EXCEPTION 'four units were not one bundle plus one at the tag';
    END IF;
    UPDATE ticket_lines SET quantity = 6 WHERE ticket_id = '01900000-0000-7000-8000-000000000e51';
    IF (SELECT offer_discount_minor FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000e51') <> 1000 THEN
        RAISE EXCEPTION 'six units were not two bundles';
    END IF;
END $$;

UPDATE ticket_lines SET quantity = 3 WHERE ticket_id = '01900000-0000-7000-8000-000000000e51';

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000e71',
    '01900000-0000-7000-8000-000000000001',
    'sale:bulk-vendor', CURRENT_DATE, clock_timestamp(),
    '01900000-0000-7000-8000-000000000e30',
    'USD', 'sale', 'bulk-vendor', 'Vendor bundle', 2500, 2500
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000e71', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 2500, 0),
    ('01900000-0000-7000-8000-000000000e71', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 1000);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000e71',
    '01900000-0000-7000-8000-000000000001',
    3, '01900000-0000-7000-8000-000000000014', 0, 1500, 'vendor', '01900000-0000-7000-8000-000000000e10'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, quantity, commission_minor, tax_minor, discount_minor, store_discount_minor)
VALUES ('01900000-0000-7000-8000-000000000e71', 1, '01900000-0000-7000-8000-000000000e21', 1000, 3, 1000, 0, 500, 0);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000e41', '01900000-0000-7000-8000-000000000e71');

UPDATE tickets
SET status = 'posted', posted_journal_id = '01900000-0000-7000-8000-000000000e71'
WHERE id = '01900000-0000-7000-8000-000000000e51';

DO $$
BEGIN
    DELETE FROM offers WHERE id = '01900000-0000-7000-8000-000000000e61';
    RAISE EXCEPTION 'a rung deal was deleted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a deal that has been rung cannot be deleted%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO offers (id, name, scope, scope_id, kind, threshold_qty, deal_minor, funded_by, starts_on)
VALUES
    (
        '01900000-0000-7000-8000-000000000e62',
        'Vendor 3 for $27',
        'vendor',
        '01900000-0000-7000-8000-000000000e10',
        'bundle',
        3,
        2700,
        'vendor',
        (SELECT (clock_timestamp() AT TIME ZONE time_zone)::date FROM mall)
    ),
    (
        '01900000-0000-7000-8000-000000000e63',
        'Store 3 for $20',
        'store',
        NULL,
        'bundle',
        3,
        2000,
        'store',
        (SELECT (clock_timestamp() AT TIME ZONE time_zone)::date FROM mall)
    );

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-000000000e52', '01900000-0000-7000-8000-000000000e41');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor, quantity)
VALUES ('01900000-0000-7000-8000-000000000e52', 1, '01900000-0000-7000-8000-000000000e21', 1000, 3);

DO $$
BEGIN
    IF (SELECT offer_id FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000e52')
           IS DISTINCT FROM '01900000-0000-7000-8000-000000000e63'::uuid
        OR (SELECT store_discount_minor FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000e52') <> 1000 THEN
        RAISE EXCEPTION 'the larger store deal did not win';
    END IF;
END $$;

UPDATE offers SET deal_minor = 2500, name = 'Store 3 for $25' WHERE id = '01900000-0000-7000-8000-000000000e63';
UPDATE ticket_lines SET quantity = 3 WHERE ticket_id = '01900000-0000-7000-8000-000000000e52';

DO $$
BEGIN
    IF (SELECT offer_id FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000e52')
           IS DISTINCT FROM '01900000-0000-7000-8000-000000000e61'::uuid
        OR (SELECT offer_discount_minor FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000e52') <> 500
        OR (SELECT store_discount_minor FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000e52') <> 0 THEN
        RAISE EXCEPTION 'a tie did not keep the item deal';
    END IF;
END $$;

DO $$
BEGIN
    UPDATE ticket_lines
    SET line_discount_kind = 'amount', line_discount_value = 2501, line_discount_reason = 'promotion'
    WHERE ticket_id = '01900000-0000-7000-8000-000000000e52';
    RAISE EXCEPTION 'a markdown past the tag was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%the discount is more than the price%' THEN
            RAISE;
        END IF;
END $$;

DELETE FROM tickets WHERE id = '01900000-0000-7000-8000-000000000e52';

DELETE FROM offers WHERE id IN (
    '01900000-0000-7000-8000-000000000e62',
    '01900000-0000-7000-8000-000000000e63'
);

UPDATE offers SET funded_by = 'store' WHERE id = '01900000-0000-7000-8000-000000000e61';

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-000000000e53', '01900000-0000-7000-8000-000000000e41');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor, quantity)
VALUES ('01900000-0000-7000-8000-000000000e53', 1, '01900000-0000-7000-8000-000000000e21', 1000, 3);

DO $$
BEGIN
    IF (SELECT store_discount_minor FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000e53') <> 500
        OR (SELECT offer_discount_minor FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000e53') <> 0 THEN
        RAISE EXCEPTION 'the store did not fund the bundle';
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000e72',
    '01900000-0000-7000-8000-000000000001',
    'sale:bulk-store', CURRENT_DATE, clock_timestamp(),
    '01900000-0000-7000-8000-000000000e30',
    'USD', 'sale', 'bulk-store', 'Store bundle', 3000, 3000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000e72', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 2500, 0),
    ('01900000-0000-7000-8000-000000000e72', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000024', 500, 0),
    ('01900000-0000-7000-8000-000000000e72', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000012', 0, 1200);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000e72',
    '01900000-0000-7000-8000-000000000001',
    4, '01900000-0000-7000-8000-000000000014', 0, 1800, 'vendor', '01900000-0000-7000-8000-000000000e10'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, quantity, commission_minor, tax_minor, discount_minor, store_discount_minor)
VALUES ('01900000-0000-7000-8000-000000000e72', 1, '01900000-0000-7000-8000-000000000e21', 1000, 3, 1200, 0, 0, 500);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000e41', '01900000-0000-7000-8000-000000000e72');

UPDATE tickets
SET status = 'posted', posted_journal_id = '01900000-0000-7000-8000-000000000e72'
WHERE id = '01900000-0000-7000-8000-000000000e53';

UPDATE offers
SET starts_on = LEAST(starts_on, (SELECT (clock_timestamp() AT TIME ZONE time_zone)::date FROM mall) - 1),
    ends_on = (SELECT (clock_timestamp() AT TIME ZONE time_zone)::date FROM mall) - 1
WHERE id = '01900000-0000-7000-8000-000000000e61';

DO $$
BEGIN
    IF (SELECT discount_minor FROM sale_lines WHERE journal_id = '01900000-0000-7000-8000-000000000e71') <> 500
        OR (SELECT store_discount_minor FROM sale_lines WHERE journal_id = '01900000-0000-7000-8000-000000000e72') <> 500 THEN
        RAISE EXCEPTION 'ending the deal changed a posted sale';
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000e73',
    '01900000-0000-7000-8000-000000000001',
    'return:bulk-store', CURRENT_DATE, clock_timestamp(),
    '01900000-0000-7000-8000-000000000e30',
    'USD', 'item_return', 'bulk-store-return', 'Return one', 1000, 1000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000e73', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000012', 400, 0),
    ('01900000-0000-7000-8000-000000000e73', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000010', 0, 834),
    ('01900000-0000-7000-8000-000000000e73', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000024', 0, 166);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000e73',
    '01900000-0000-7000-8000-000000000001',
    4, '01900000-0000-7000-8000-000000000014', 600, 0, 'vendor', '01900000-0000-7000-8000-000000000e10'
);

INSERT INTO item_returns (
    journal_id, sale_journal_id, item_id, price_minor, quantity, commission_minor, tax_minor, discount_minor, store_discount_minor
) VALUES (
    '01900000-0000-7000-8000-000000000e73',
    '01900000-0000-7000-8000-000000000e72',
    '01900000-0000-7000-8000-000000000e21',
    1000, 1, 400, 0, 0, 166
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000e41', '01900000-0000-7000-8000-000000000e73');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    IF (SELECT store_discount_minor FROM sale_lines WHERE journal_id = '01900000-0000-7000-8000-000000000e72') <> 500 THEN
        RAISE EXCEPTION 'the return repriced the units kept';
    END IF;
    IF (SELECT debit_minor FROM journal_lines WHERE journal_id = '01900000-0000-7000-8000-000000000e73' AND account_id = '01900000-0000-7000-8000-000000000014') <> 600
        OR (SELECT credit_minor FROM journal_lines WHERE journal_id = '01900000-0000-7000-8000-000000000e73' AND account_id = '01900000-0000-7000-8000-000000000024') <> 166 THEN
        RAISE EXCEPTION 'the store return changed the vendor payable';
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000e74',
    '01900000-0000-7000-8000-000000000001',
    'return:bulk-store-rest', CURRENT_DATE, clock_timestamp(),
    '01900000-0000-7000-8000-000000000e30',
    'USD', 'item_return', 'bulk-store-rest', 'Return the rest', 2000, 2000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000e74', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000012', 800, 0),
    ('01900000-0000-7000-8000-000000000e74', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000010', 0, 1666),
    ('01900000-0000-7000-8000-000000000e74', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000024', 0, 334);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000e74',
    '01900000-0000-7000-8000-000000000001',
    4, '01900000-0000-7000-8000-000000000014', 1200, 0, 'vendor', '01900000-0000-7000-8000-000000000e10'
);

INSERT INTO item_returns (
    journal_id, sale_journal_id, item_id, price_minor, quantity, commission_minor, tax_minor, discount_minor, store_discount_minor
) VALUES (
    '01900000-0000-7000-8000-000000000e74',
    '01900000-0000-7000-8000-000000000e72',
    '01900000-0000-7000-8000-000000000e21',
    2000, 2, 800, 0, 0, 334
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000e41', '01900000-0000-7000-8000-000000000e74');

SET CONSTRAINTS ALL IMMEDIATE;

INSERT INTO offers (id, name, scope, scope_id, kind, threshold_qty, deal_minor, funded_by, starts_on)
VALUES (
    '01900000-0000-7000-8000-000000000e64',
    '3 or more at $8',
    'item',
    '01900000-0000-7000-8000-000000000e22',
    'unit_break',
    3,
    800,
    'vendor',
    (SELECT (clock_timestamp() AT TIME ZONE time_zone)::date FROM mall)
);

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-000000000e54', '01900000-0000-7000-8000-000000000e41');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor, quantity)
VALUES ('01900000-0000-7000-8000-000000000e54', 1, '01900000-0000-7000-8000-000000000e22', 1000, 2);

DO $$
BEGIN
    IF (SELECT offer_discount_minor FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000e54') <> 0 THEN
        RAISE EXCEPTION 'two units took the unit break';
    END IF;
    UPDATE ticket_lines SET quantity = 3 WHERE ticket_id = '01900000-0000-7000-8000-000000000e54';
    IF (SELECT offer_discount_minor FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000e54') <> 600 THEN
        RAISE EXCEPTION 'three units were not $8 each';
    END IF;
END $$;

INSERT INTO offers (id, name, scope, scope_id, kind, threshold_qty, deal_minor, funded_by, starts_on)
VALUES (
    '01900000-0000-7000-8000-000000000e65',
    'Tomorrow',
    'item',
    '01900000-0000-7000-8000-000000000e23',
    'bundle',
    3,
    2500,
    'vendor',
    (SELECT (clock_timestamp() AT TIME ZONE time_zone)::date FROM mall) + 1
);

DELETE FROM tickets WHERE id = '01900000-0000-7000-8000-000000000e54';

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-000000000e55', '01900000-0000-7000-8000-000000000e41');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor, quantity)
VALUES ('01900000-0000-7000-8000-000000000e55', 1, '01900000-0000-7000-8000-000000000e23', 1000, 3);

DO $$
BEGIN
    IF (SELECT offer_discount_minor + store_discount_minor FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000e55') <> 0 THEN
        RAISE EXCEPTION 'a future deal fired today';
    END IF;
    UPDATE ticket_lines
    SET quantity = 1,
        line_discount_kind = 'percent',
        line_discount_value = 1000,
        line_discount_reason = 'promotion'
    WHERE ticket_id = '01900000-0000-7000-8000-000000000e55';
    IF (SELECT line_discount_minor FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000e55') <> 100 THEN
        RAISE EXCEPTION 'a cashier percent on a line with no deal was not 100';
    END IF;
END $$;

DO $$
BEGIN
    IF stall_sticker_deal('01900000-0000-7000-8000-000000000e22') <> '3 or more at $8' THEN
        RAISE EXCEPTION 'the sticker did not name the item deal';
    END IF;
    IF stall_sticker_deal('01900000-0000-7000-8000-000000000e23') <> '' THEN
        RAISE EXCEPTION 'a future deal printed on the sticker';
    END IF;
    IF stall_sticker_deal('01900000-0000-7000-8000-000000000e21') <> '' THEN
        RAISE EXCEPTION 'an ended deal printed on the sticker';
    END IF;
END $$;

ROLLBACK;
