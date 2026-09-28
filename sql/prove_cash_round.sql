-- Proof that cash rounds to the nearest nickel only when the mall asks for it.
-- One transaction, then ROLLBACK.

BEGIN;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane');

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES
    ('01900000-0000-7000-8000-0000000007a1', 'Down', '01900000-0000-7000-8000-000000000101', 'Rounds down', 1002, 'BC-ROUND-DOWN', DATE '2094-03-01', 0),
    ('01900000-0000-7000-8000-0000000007a2', 'Up', '01900000-0000-7000-8000-000000000101', 'Rounds up', 1003, 'BC-ROUND-UP', DATE '2094-03-01', 0),
    ('01900000-0000-7000-8000-0000000007a3', 'Even', '01900000-0000-7000-8000-000000000101', 'Already a nickel', 1000, 'BC-ROUND-EVEN', DATE '2094-03-01', 0),
    ('01900000-0000-7000-8000-0000000007a4', 'Card', '01900000-0000-7000-8000-000000000101', 'Card piece', 1002, 'BC-ROUND-CARD', DATE '2094-03-01', 0),
    ('01900000-0000-7000-8000-0000000007a5', 'Split', '01900000-0000-7000-8000-000000000101', 'Split piece', 1002, 'BC-ROUND-SPLIT', DATE '2094-03-01', 0);

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-0000000007a6',
    '01900000-0000-7000-8000-000000000001',
    '2094-01-01',
    '2094-12-31',
    'open'
);

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-000000000d96', 'Round proof');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d2a',
    '01900000-0000-7000-8000-000000000d96',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

UPDATE mall SET tax_bps = 0, cash_round_nickel = false;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-0000000007c1',
        '01900000-0000-7000-8000-000000000001',
        'sale:round-off', '2094-03-01', '2094-03-01 12:00:00+00',
        '01900000-0000-7000-8000-0000000007a6',
        'USD', 'sale', 'round-off', 'Exact cash while rounding is off', 1002, 1002
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-0000000007c1',
        '01900000-0000-7000-8000-000000000001',
        1, '01900000-0000-7000-8000-000000000010', 1000, 0
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-0000000007c1',
        '01900000-0000-7000-8000-000000000001',
        2, '01900000-0000-7000-8000-000000000020', 2, 0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-0000000007c1',
        '01900000-0000-7000-8000-000000000001',
        3, '01900000-0000-7000-8000-000000000014', 0, 1002, 'vendor', '01900000-0000-7000-8000-000000000101'
    );
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
    VALUES ('01900000-0000-7000-8000-0000000007c1', 1, '01900000-0000-7000-8000-0000000007a1', 1002, 0, 0);
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d2a', '01900000-0000-7000-8000-0000000007c1');
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'rounding was accepted while the setting is off';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a sale debits the sold prices plus tax%' THEN
            RAISE;
        END IF;
END $$;

UPDATE mall SET cash_round_nickel = true;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000007c2',
    '01900000-0000-7000-8000-000000000001',
    'sale:round-down', '2094-03-02', '2094-03-02 12:00:00+00',
    '01900000-0000-7000-8000-0000000007a6',
    'USD', 'sale', 'round-down', 'Cash rounds down', 1002, 1002
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-0000000007c2', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 1000, 0),
    ('01900000-0000-7000-8000-0000000007c2', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000020', 2, 0);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000007c2',
    '01900000-0000-7000-8000-000000000001',
    3, '01900000-0000-7000-8000-000000000014', 0, 1002, 'vendor', '01900000-0000-7000-8000-000000000101'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-0000000007c2', 1, '01900000-0000-7000-8000-0000000007a1', 1002, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d2a', '01900000-0000-7000-8000-0000000007c2');

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000007c3',
    '01900000-0000-7000-8000-000000000001',
    'sale:round-up', '2094-03-03', '2094-03-03 12:00:00+00',
    '01900000-0000-7000-8000-0000000007a6',
    'USD', 'sale', 'round-up', 'Cash rounds up', 1005, 1005
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-0000000007c3', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 1005, 0),
    ('01900000-0000-7000-8000-0000000007c3', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000020', 0, 2);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000007c3',
    '01900000-0000-7000-8000-000000000001',
    3, '01900000-0000-7000-8000-000000000014', 0, 1003, 'vendor', '01900000-0000-7000-8000-000000000101'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-0000000007c3', 1, '01900000-0000-7000-8000-0000000007a2', 1003, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d2a', '01900000-0000-7000-8000-0000000007c3');

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000007c4',
    '01900000-0000-7000-8000-000000000001',
    'sale:round-card', '2094-03-04', '2094-03-04 12:00:00+00',
    '01900000-0000-7000-8000-0000000007a6',
    'USD', 'sale', 'round-card', 'Card stays on the cent', 1002, 1002
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-0000000007c4',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000016', 1002, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000007c4',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 1002, 'vendor', '01900000-0000-7000-8000-000000000101'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-0000000007c4', 1, '01900000-0000-7000-8000-0000000007a4', 1002, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d2a', '01900000-0000-7000-8000-0000000007c4');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-0000000007c5',
        '01900000-0000-7000-8000-000000000001',
        'sale:unrounded', '2094-03-05', '2094-03-05 12:00:00+00',
        '01900000-0000-7000-8000-0000000007a6',
        'USD', 'sale', 'unrounded', 'Cash left on the cent', 1000, 1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-0000000007c5',
        '01900000-0000-7000-8000-000000000001',
        1, '01900000-0000-7000-8000-000000000010', 1000, 0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-0000000007c5',
        '01900000-0000-7000-8000-000000000001',
        2, '01900000-0000-7000-8000-000000000014', 0, 1000, 'vendor', '01900000-0000-7000-8000-000000000101'
    );
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
    VALUES ('01900000-0000-7000-8000-0000000007c5', 1, '01900000-0000-7000-8000-0000000007a3', 1002, 0, 0);
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d2a', '01900000-0000-7000-8000-0000000007c5');
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'an unrounded cash sale was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a cash sale rounds to the nearest nickel%' THEN
            RAISE;
        END IF;
END $$;

ROLLBACK;
