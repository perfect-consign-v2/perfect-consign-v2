-- Proof that a sale takes quantity off the piece and a return puts units back. One transaction, then ROLLBACK.

BEGIN;

UPDATE mall SET tax_bps = 0, cash_round_nickel = false;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-0000000009a0', 'soda', 'Soda', 'Lane');

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
    'USD', 'piece_return', 'return-cola', 'Return one cola', 1000, 1000
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
INSERT INTO piece_returns (journal_id, sale_journal_id, item_id, price_minor, quantity, commission_minor, tax_minor, discount_minor)
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

ROLLBACK;
