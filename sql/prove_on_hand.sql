-- Proof that a sale takes quantity off the item and a return puts units back. One transaction, then ROLLBACK.

BEGIN;

UPDATE mall SET tax_bps = 0, cash_round_minor = 0;

SELECT stall_seed_vendor('01900000-0000-7000-8000-0000000009a0', 'soda', 'Soda', 'Lane');

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps, quantity)
VALUES (
    '01900000-0000-7000-8000-0000000009a1',
    'Cola',
    '01900000-0000-7000-8000-0000000009a0',
    'Cola bottle',
    1000,
    'BC-COLA',
    DATE '2094-04-01',
    0,
    5
);

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-0000000009a2',
    '01900000-0000-7000-8000-000000000001',
    '2094-01-01', '2094-12-31', 'open'
);

INSERT INTO registers (id, name) VALUES ('01900000-0000-7000-8000-000000000d98', 'On hand proof');
INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d2c',
    '01900000-0000-7000-8000-000000000d98',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000009c1',
    '01900000-0000-7000-8000-000000000001',
    'sale:cola', '2094-04-02', '2094-04-02 12:00:00+00',
    '01900000-0000-7000-8000-0000000009a2',
    'USD', 'sale', 'cola', 'Two colas', 2000, 2000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-0000000009c1',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 2000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000009c1',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 2000, 'vendor', '01900000-0000-7000-8000-0000000009a0'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, quantity, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-0000000009c1', 1, '01900000-0000-7000-8000-0000000009a1', 1000, 2, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d2c', '01900000-0000-7000-8000-0000000009c1');

DO $$
BEGIN
    IF (SELECT quantity FROM items WHERE id = '01900000-0000-7000-8000-0000000009a1') <> 3 THEN
        RAISE EXCEPTION 'a sale of two did not leave three';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-0000000009c4',
        '01900000-0000-7000-8000-000000000001',
        'sale:cola-more', '2094-04-03', '2094-04-03 12:00:00+00',
        '01900000-0000-7000-8000-0000000009a2',
        'USD', 'sale', 'cola-more', 'Too many colas', 4000, 4000
    );
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, quantity, commission_minor, tax_minor)
    VALUES ('01900000-0000-7000-8000-0000000009c4', 1, '01900000-0000-7000-8000-0000000009a1', 1000, 4, 0, 0);
    RAISE EXCEPTION 'a sale past on hand was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%there are not enough on hand%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000009c2',
    '01900000-0000-7000-8000-000000000001',
    'return:cola', '2094-04-04', '2094-04-04 12:00:00+00',
    '01900000-0000-7000-8000-0000000009a2',
    'USD', 'item_return', 'return-cola', 'Return one cola', 1000, 1000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000009c2',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 1000, 0, 'vendor', '01900000-0000-7000-8000-0000000009a0'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-0000000009c2',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000010', 0, 1000
);
INSERT INTO item_returns (journal_id, sale_journal_id, item_id, price_minor, quantity, commission_minor, tax_minor, discount_minor)
VALUES (
    '01900000-0000-7000-8000-0000000009c2',
    '01900000-0000-7000-8000-0000000009c1',
    '01900000-0000-7000-8000-0000000009a1',
    1000, 1, 0, 0, 0
);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d2c', '01900000-0000-7000-8000-0000000009c2');

DO $$
BEGIN
    IF (SELECT quantity FROM items WHERE id = '01900000-0000-7000-8000-0000000009a1') <> 4 THEN
        RAISE EXCEPTION 'a return of one did not put one back';
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
    debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000009c3',
    '01900000-0000-7000-8000-000000000001',
    'sale:cola:void', '2094-04-05', '2094-04-05 12:00:00+00',
    '01900000-0000-7000-8000-0000000009a2',
    'USD', 'sale', 'cola-void', 'Void the colas', true,
    '01900000-0000-7000-8000-0000000009c1',
    2000, 2000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000009c3',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 2000, 0, 'vendor', '01900000-0000-7000-8000-0000000009a0'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-0000000009c3',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000010', 0, 2000
);

DO $$
BEGIN
    IF (SELECT quantity FROM items WHERE id = '01900000-0000-7000-8000-0000000009a1') <> 5 THEN
        RAISE EXCEPTION 'a void did not put the unsold units back';
    END IF;
END $$;

-- Item counts (D10): every count change above wrote exactly one row, used by
-- that change; each item's rows run from one count to the next and end at its
-- count now; and each ending wrote one row per event.
DO $$
BEGIN
    SET CONSTRAINTS item_quantity_changes_used_sealed IMMEDIATE;
    IF EXISTS (SELECT 1 FROM item_quantity_changes WHERE NOT applied) THEN
        RAISE EXCEPTION 'a count change row was never used';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM (
            SELECT change.item_id, change.old_quantity, change.new_quantity,
                   lag(change.new_quantity) OVER (PARTITION BY change.item_id ORDER BY change.changed_at, change.id) AS before,
                   lead(change.id) OVER (PARTITION BY change.item_id ORDER BY change.changed_at, change.id) AS later
            FROM item_quantity_changes AS change
        ) AS chain
        JOIN items AS item ON item.id = chain.item_id
        WHERE (chain.before IS NOT NULL AND chain.before <> chain.old_quantity)
           OR (chain.later IS NULL AND chain.new_quantity <> item.quantity)
    ) THEN
        RAISE EXCEPTION 'an item count history does not match its counts';
    END IF;
    IF (SELECT count(*) FROM item_quantity_changes WHERE ending = 'sold') <> (SELECT count(*) FROM sale_lines)
       OR (SELECT count(*) FROM item_quantity_changes WHERE ending = 'customer_return') <> (SELECT count(*) FROM item_returns)
       OR (SELECT count(*) FROM item_quantity_changes WHERE ending = 'missed_sale') <> (SELECT count(*) FROM missed_sales WHERE item_id IS NOT NULL)
       OR (SELECT count(*) FROM item_quantity_changes WHERE ending = 'store_use') <> (SELECT count(*) FROM store_uses WHERE item_id IS NOT NULL)
       OR (SELECT count(*) FROM item_quantity_changes WHERE ending = 'missed_sale_undone') <> (
           SELECT count(*) FROM missed_sales AS missed
           JOIN journals AS reversal ON reversal.reverses_journal_id = missed.journal_id AND reversal.is_reversal
           WHERE missed.item_id IS NOT NULL)
       OR (SELECT count(*) FROM item_quantity_changes WHERE ending = 'store_use_undone') <> (
           SELECT count(*) FROM store_uses AS used
           JOIN journals AS reversal ON reversal.reverses_journal_id = used.journal_id AND reversal.is_reversal
           WHERE used.item_id IS NOT NULL)
       OR (SELECT count(*) FROM item_quantity_changes WHERE ending = 'customer_return_undone') <> (
           SELECT count(*) FROM item_returns AS returned
           JOIN journals AS reversal ON reversal.reverses_journal_id = returned.journal_id AND reversal.is_reversal) THEN
        RAISE EXCEPTION 'an ending did not write exactly one count row per event';
    END IF;
END $$;

ROLLBACK;
