-- Proof that drawer journals name an open session. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane');

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES
    (
        '01900000-0000-7000-8000-000000000dc1',
        'Open',
        '01900000-0000-7000-8000-000000000101',
        'Open piece',
        1000,
        'BC-OPEN',
        DATE '2032-01-03',
        0
    ),
    (
        '01900000-0000-7000-8000-000000000dc2',
        'Split',
        '01900000-0000-7000-8000-000000000101',
        'Split piece',
        3000,
        'BC-SPLIT',
        DATE '2032-01-04',
        0
    ),
    (
        '01900000-0000-7000-8000-000000000dc3',
        'Over',
        '01900000-0000-7000-8000-000000000101',
        'Over piece',
        5000,
        'BC-OVER',
        DATE '2032-01-05',
        0
    ),
    (
        '01900000-0000-7000-8000-000000000dc4',
        'Check',
        '01900000-0000-7000-8000-000000000101',
        'Check piece',
        1000,
        'BC-CHECK',
        DATE '2032-01-06',
        0
    );

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-000000000d41',
    '01900000-0000-7000-8000-000000000001',
    '2032-01-01',
    '2032-12-31',
    'open'
);

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d22',
    '01900000-0000-7000-8000-000000000d10',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO stored_values (id, kind, number)
VALUES ('01900000-0000-7000-8000-000000000d63', 'gift_certificate', 'GC-SALE');

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000d70',
    '01900000-0000-7000-8000-000000000001',
    'gift-cert:d70',
    '2032-01-02',
    '2032-01-02 12:00:00+00',
    '01900000-0000-7000-8000-000000000d41',
    'USD',
    'gift_certificate_issue',
    'GC-SALE',
    'Issue GC-SALE',
    3000,
    3000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d70',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000010',
    3000,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000d70',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-00000000001c',
    0,
    3000,
    'gift_certificate',
    '01900000-0000-7000-8000-000000000d63'
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000d70');

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000d71',
        '01900000-0000-7000-8000-000000000001',
        'sale:d71',
        '2032-01-03',
        '2032-01-03 12:00:00+00',
        '01900000-0000-7000-8000-000000000d41',
        'USD',
        'sale',
        'd71',
        'Sale with no register',
        1000,
        1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000d71',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000010',
        1000,
        0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000d71',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000014',
        0,
        1000,
        'vendor',
        '01900000-0000-7000-8000-000000000101'
    );
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
    VALUES ('01900000-0000-7000-8000-000000000d71', 1, '01900000-0000-7000-8000-000000000dc1', 1000, 0, 0);
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a sale without a register was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a drawer journal needs an open register%' THEN
            RAISE;
        END IF;
END $$;

SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000d72',
    '01900000-0000-7000-8000-000000000001',
    'sale:d72',
    '2032-01-04',
    '2032-01-04 12:00:00+00',
    '01900000-0000-7000-8000-000000000d41',
    'USD',
    'sale',
    'd72',
    'Split tender sale',
    3000,
    3000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000d72', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 1000, 0),
    ('01900000-0000-7000-8000-000000000d72', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000016', 1000, 0);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    (
        '01900000-0000-7000-8000-000000000d72',
        '01900000-0000-7000-8000-000000000001',
        3,
        '01900000-0000-7000-8000-00000000001c',
        1000,
        0,
        'gift_certificate',
        '01900000-0000-7000-8000-000000000d63'
    ),
    (
        '01900000-0000-7000-8000-000000000d72',
        '01900000-0000-7000-8000-000000000001',
        4,
        '01900000-0000-7000-8000-000000000014',
        0,
        3000,
        'vendor',
        '01900000-0000-7000-8000-000000000101'
    );

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-000000000d72', 1, '01900000-0000-7000-8000-000000000dc2', 3000, 0, 0);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000d72');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000d73',
        '01900000-0000-7000-8000-000000000001',
        'sale:d73',
        '2032-01-05',
        '2032-01-05 12:00:00+00',
        '01900000-0000-7000-8000-000000000d41',
        'USD',
        'sale',
        'd73',
        'Certificate over the open credit',
        5000,
        5000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000d73',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-00000000001c',
        5000,
        0,
        'gift_certificate',
        '01900000-0000-7000-8000-000000000d63'
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000d73',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000014',
        0,
        5000,
        'vendor',
        '01900000-0000-7000-8000-000000000101'
    );
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
    VALUES ('01900000-0000-7000-8000-000000000d73', 1, '01900000-0000-7000-8000-000000000dc3', 5000, 0, 0);
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000d73');
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a certificate went below zero on a sale';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a gift certificate cannot go below zero%' THEN
            RAISE;
        END IF;
END $$;

SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000d74',
        '01900000-0000-7000-8000-000000000001',
        'sale:d74',
        '2032-01-06',
        '2032-01-06 12:00:00+00',
        '01900000-0000-7000-8000-000000000d41',
        'USD',
        'sale',
        'd74',
        'Check without a number',
        1000,
        1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000d74',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-00000000001b',
        1000,
        0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000d74',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000014',
        0,
        1000,
        'vendor',
        '01900000-0000-7000-8000-000000000101'
    );
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
    VALUES ('01900000-0000-7000-8000-000000000d74', 1, '01900000-0000-7000-8000-000000000dc4', 1000, 0, 0);
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000d74');
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a check sale without a number was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a check sale needs a check number%' THEN
            RAISE;
        END IF;
END $$;

UPDATE register_sessions
SET closing_counts = '{"10000":0,"5000":0,"2000":2,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    counted_cash_minor = 4000,
    counted_card_minor = 1000,
    counted_checks_minor = 0,
    closed_at = clock_timestamp()
WHERE id = '01900000-0000-7000-8000-000000000d22';

DO $$
BEGIN
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000d70');
    RAISE EXCEPTION 'a closed session accepted another post';
EXCEPTION
    WHEN unique_violation OR SQLSTATE '23514' THEN
        IF SQLSTATE = '23514' AND SQLERRM NOT LIKE '%a drawer journal needs an open register%' THEN
            RAISE;
        END IF;
END $$;

ROLLBACK;
