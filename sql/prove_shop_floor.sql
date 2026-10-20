-- Shop, mall, and both. Placement, a buy, a markdown, and a business name. Rolls back.

BEGIN;

DO $$
BEGIN
    UPDATE mall
    SET booth_rentals_enabled = false, consignment_enabled = false, store_stock_enabled = false;
    RAISE EXCEPTION 'a mall with no way to sell was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%mall_one_way_to_sell_chk%' THEN
            RAISE;
        END IF;
END $$;

UPDATE mall SET consignment_enabled = false, floor_days = NULL, booth_rentals_enabled = true;

DO $$
BEGIN
    UPDATE mall SET floor_days = 30;
    RAISE EXCEPTION 'floor days without consignment were accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%floor days need consignment%' THEN
            RAISE;
        END IF;
END $$;

UPDATE mall SET consignment_enabled = true, store_stock_enabled = true, floor_days = 30;

INSERT INTO vendors (id, code, first_name, last_name, business_name)
VALUES ('01900000-0000-7000-8000-00000000e901', 'shop', 'Ada', 'Lane', 'Lane Goods');

DO $$
BEGIN
    IF (SELECT name FROM vendors WHERE id = '01900000-0000-7000-8000-00000000e901') <> 'Lane Goods' THEN
        RAISE EXCEPTION 'the business name is not the display name';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO items (code, vendor_id, description, price_minor, barcode, intake_on, placement)
    VALUES ('booth-1', '01900000-0000-7000-8000-00000000e901', 'Booth vase', 1000, 'booth-1', CURRENT_DATE, 'booth');
    RAISE EXCEPTION 'a booth piece without an assignment was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a booth piece needs an open assignment%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, placement, expires_on)
VALUES (
    '01900000-0000-7000-8000-00000000e911',
    'dress',
    '01900000-0000-7000-8000-00000000e901',
    'Blue dress',
    4000,
    'dress-1',
    CURRENT_DATE - 40,
    'consigned',
    CURRENT_DATE - 1
);

DO $$
BEGIN
    INSERT INTO items (code, description, price_minor, barcode, intake_on, placement, vendor_id)
    VALUES ('mug', 'Mug', 800, 'mug-1', CURRENT_DATE, 'store', '01900000-0000-7000-8000-00000000e901');
    RAISE EXCEPTION 'a store piece with a vendor was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%items_store_owner_chk%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO items (id, code, description, price_minor, barcode, intake_on, placement)
VALUES (
    '01900000-0000-7000-8000-00000000e912',
    'mug',
    'Mug',
    800,
    'mug-1',
    CURRENT_DATE,
    'store'
);

INSERT INTO markdown_steps (id, days_on_floor, percent)
VALUES ('01900000-0000-7000-8000-00000000e921', 30, 20);

DO $$
BEGIN
    INSERT INTO markdown_steps (days_on_floor, percent) VALUES (60, 10);
    RAISE EXCEPTION 'a markdown that lowers the percent was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%markdown percents increase with days on the floor%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO item_price_events (item_id, step_id, prior_minor, price_minor, applied_on)
    VALUES (
        '01900000-0000-7000-8000-00000000e911',
        '01900000-0000-7000-8000-00000000e921',
        4000,
        3200,
        CURRENT_DATE
    );
    IF (SELECT price_minor FROM items WHERE id = '01900000-0000-7000-8000-00000000e911') <> 3200 THEN
        RAISE EXCEPTION 'the markdown did not change the asking price';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO item_price_events (item_id, step_id, prior_minor, price_minor, applied_on)
    VALUES (
        '01900000-0000-7000-8000-00000000e911',
        '01900000-0000-7000-8000-00000000e921',
        3200,
        2560,
        CURRENT_DATE
    );
    RAISE EXCEPTION 'the same markdown step was accepted twice';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a markdown follows the schedule%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000e931',
    '01900000-0000-7000-8000-000000000001',
    date_trunc('year', CURRENT_DATE)::date,
    (date_trunc('year', CURRENT_DATE) + interval '1 year' - interval '1 day')::date,
    'open'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000e941',
    '01900000-0000-7000-8000-000000000001',
    'store-purchase:dress',
    CURRENT_DATE,
    clock_timestamp(),
    '01900000-0000-7000-8000-00000000e931',
    'USD',
    'store_purchase',
    '01900000-0000-7000-8000-00000000e901',
    'Buy the dress',
    1500,
    1500
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES
    ('01900000-0000-7000-8000-00000000e941', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000022', 1500, 0, NULL, NULL),
    ('01900000-0000-7000-8000-00000000e941', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000014', 0, 1500, 'vendor', '01900000-0000-7000-8000-00000000e901');

INSERT INTO item_buys (journal_id, item_id, vendor_id, amount_minor)
VALUES (
    '01900000-0000-7000-8000-00000000e941',
    '01900000-0000-7000-8000-00000000e911',
    '01900000-0000-7000-8000-00000000e901',
    1500
);

DO $$
BEGIN
    IF (SELECT placement FROM items WHERE id = '01900000-0000-7000-8000-00000000e911') <> 'store'
        OR (SELECT vendor_id FROM items WHERE id = '01900000-0000-7000-8000-00000000e911') IS NOT NULL
        OR (SELECT cost_minor FROM items WHERE id = '01900000-0000-7000-8000-00000000e911') <> 1500 THEN
        RAISE EXCEPTION 'the buy did not convert the piece';
    END IF;
END $$;

DO $$
BEGIN
    TRUNCATE item_buys;
    RAISE EXCEPTION 'truncate of buys was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only%' THEN
            RAISE;
        END IF;
END $$;

ROLLBACK;
