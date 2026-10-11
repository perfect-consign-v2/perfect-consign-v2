-- Proof for a sale. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane');

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES
    (
        '01900000-0000-7000-8000-0000000005a1',
        'Coat',
        '01900000-0000-7000-8000-000000000101',
        'Wool coat',
        10000,
        'BC-COAT',
        DATE '2096-03-01',
        2000
    ),
    (
        '01900000-0000-7000-8000-0000000005a2',
        'Hat',
        '01900000-0000-7000-8000-000000000101',
        'Felt hat',
        1000,
        'BC-HAT',
        DATE '2096-03-01',
        0
    ),
    (
        '01900000-0000-7000-8000-0000000005a3',
        'Card',
        '01900000-0000-7000-8000-000000000101',
        'Card item',
        5000,
        'BC-CARD',
        DATE '2096-03-01',
        0
    );

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-000000000021',
    '01900000-0000-7000-8000-000000000001',
    '2096-01-01',
    '2096-12-31',
    'open'
);

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-000000000d92', 'Sale proof');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d22',
    '01900000-0000-7000-8000-000000000d92',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000501',
    '01900000-0000-7000-8000-000000000001',
    'sale:1001',
    '2096-03-01',
    '2096-03-01 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'sale',
    '1001',
    'Cash sale 1001',
    10000,
    10000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000501', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 10000, 0),
    ('01900000-0000-7000-8000-000000000501', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 2000);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000501',
    '01900000-0000-7000-8000-000000000001',
    3,
    '01900000-0000-7000-8000-000000000014',
    0,
    8000,
    'vendor',
    '01900000-0000-7000-8000-000000000101'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES (
    '01900000-0000-7000-8000-000000000501',
    1,
    '01900000-0000-7000-8000-0000000005a1',
    10000,
    2000,
    0
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000501');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES (
    '01900000-0000-7000-8000-0000000005a6',
    'Draw',
    '01900000-0000-7000-8000-000000000101',
    'Draw item',
    1000,
    'BC-DRAW',
    DATE '2096-03-01',
    0
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000050c',
    '01900000-0000-7000-8000-000000000001',
    'sale:draw',
    '2096-03-01',
    '2096-03-01 12:30:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'sale',
    'draw',
    'Sale that also draws vendor payable',
    1000,
    1000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000050c',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000010',
    500,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    (
        '01900000-0000-7000-8000-00000000050c',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000014',
        500,
        0,
        'vendor',
        '01900000-0000-7000-8000-000000000101'
    ),
    (
        '01900000-0000-7000-8000-00000000050c',
        '01900000-0000-7000-8000-000000000001',
        3,
        '01900000-0000-7000-8000-000000000014',
        0,
        1000,
        'vendor',
        '01900000-0000-7000-8000-000000000101'
    );

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES (
    '01900000-0000-7000-8000-00000000050c',
    1,
    '01900000-0000-7000-8000-0000000005a6',
    1000,
    0,
    0
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-00000000050c');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000050d',
        '01900000-0000-7000-8000-000000000001',
        'adjustment:both-cash',
        '2096-03-01',
        '2096-03-01 12:40:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'adjustment',
        'both-cash',
        'Cash on both sides',
        100,
        100
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-00000000050d', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 100, 0),
        ('01900000-0000-7000-8000-00000000050d', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000010', 0, 100);
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'cash on both sides was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM <> 'an account may appear on only one side of a journal' THEN
            RAISE;
        END IF;
END $$;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
    VALUES (
        '01900000-0000-7000-8000-0000000005a5',
        'Over',
        '01900000-0000-7000-8000-000000000101',
        'Over draw',
        8501,
        'BC-OVER',
        DATE '2096-03-01',
        0
    );
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000050e',
        '01900000-0000-7000-8000-000000000001',
        'sale:overdraw',
        '2096-03-01',
        '2096-03-01 12:45:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'sale',
        'overdraw',
        'Draw larger than the open payable',
        8501,
        8501
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES
        (
            '01900000-0000-7000-8000-00000000050e',
            '01900000-0000-7000-8000-000000000001',
            1,
            '01900000-0000-7000-8000-000000000014',
            8501,
            0,
            'vendor',
            '01900000-0000-7000-8000-000000000101'
        ),
        (
            '01900000-0000-7000-8000-00000000050e',
            '01900000-0000-7000-8000-000000000001',
            2,
            '01900000-0000-7000-8000-000000000014',
            0,
            8501,
            'vendor',
            '01900000-0000-7000-8000-000000000101'
        );
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
    VALUES (
        '01900000-0000-7000-8000-00000000050e',
        1,
        '01900000-0000-7000-8000-0000000005a5',
        8501,
        0,
        0
    );
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-00000000050e');
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a draw larger than the open payable was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM <> 'a vendor draw is limited to the open payable' THEN
            RAISE;
        END IF;
END $$;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000508',
    '01900000-0000-7000-8000-000000000001',
    'sale:1001-b',
    '2096-03-01',
    '2096-03-01 13:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'sale',
    '1001',
    'Second posting key for source 1001',
    1000,
    1000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000508',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000010',
    1000,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000508',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000014',
    0,
    1000,
    'vendor',
    '01900000-0000-7000-8000-000000000101'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES (
    '01900000-0000-7000-8000-000000000508',
    1,
    '01900000-0000-7000-8000-0000000005a2',
    1000,
    0,
    0
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000508');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000509',
        '01900000-0000-7000-8000-000000000001',
        'memo:1001',
        '2096-03-01',
        '2096-03-01 14:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'memo',
        '1001-memo',
        'Memo credit to vendor payable',
        500,
        500
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000509',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000010',
        500,
        0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000509',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000014',
        0,
        500,
        'vendor',
        '01900000-0000-7000-8000-000000000101'
    );
    RAISE EXCEPTION 'memo credit to vendor payable was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000502',
    '01900000-0000-7000-8000-000000000001',
    'sale:1002',
    '2096-03-02',
    '2096-03-02 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'sale',
    '1002',
    'Card sale 1002',
    5000,
    5000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000502',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000016',
    5000,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000502',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000014',
    0,
    5000,
    'vendor',
        '01900000-0000-7000-8000-000000000101'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES (
    '01900000-0000-7000-8000-000000000502',
    1,
    '01900000-0000-7000-8000-0000000005a3',
    5000,
    0,
    0
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000502');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000503',
        '01900000-0000-7000-8000-000000000001',
        'sale:1001',
        '2096-03-03',
        '2096-03-03 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'sale',
        '1001-again',
        'Duplicate sale 1001',
        10000,
        10000
    );
    RAISE EXCEPTION 'duplicate sale posting key was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
END $$;

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000504',
        '01900000-0000-7000-8000-000000000001',
        'sale:1003',
        '2096-03-03',
        '2096-03-03 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'sale',
        '1003',
        'Sale that debits the bank',
        1000,
        1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000504',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000011',
        1000,
        0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000504',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000014',
        0,
        1000,
        'vendor',
        '01900000-0000-7000-8000-000000000101'
    );
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000504');
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'bank debit sale was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000505',
        '01900000-0000-7000-8000-000000000001',
        'sale:1004',
        '2096-03-04',
        '2096-03-04 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'sale',
        '1004',
        'Sale with no vendor payable',
        1000,
        1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-000000000505', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 1000, 0),
        ('01900000-0000-7000-8000-000000000505', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 1000);
    INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
    VALUES (
        '01900000-0000-7000-8000-0000000005a4',
        'Owed',
        '01900000-0000-7000-8000-000000000101',
        'Owed item',
        1000,
        'BC-OWED',
        DATE '2096-03-01',
        0
    );
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000505',
        1,
        '01900000-0000-7000-8000-0000000005a4',
        1000,
        0,
        0
    );
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000505');
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'sale without vendor payable was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
    debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000506',
    '01900000-0000-7000-8000-000000000001',
    'sale:1001:void',
    '2096-03-05',
    '2096-03-05 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'sale',
    '1001-void',
    'Void of sale 1001',
    true,
    '01900000-0000-7000-8000-000000000501',
    10000,
    10000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000506', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000012', 2000, 0),
    ('01900000-0000-7000-8000-000000000506', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000010', 0, 10000);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000506',
    '01900000-0000-7000-8000-000000000001',
    3,
    '01900000-0000-7000-8000-000000000014',
    8000,
    0,
    'vendor',
    '01900000-0000-7000-8000-000000000101'
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000506');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
        debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000507',
        '01900000-0000-7000-8000-000000000001',
        'sale:1001:void-again',
        '2096-03-06',
        '2096-03-06 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'sale',
        '1001-void-again',
        'Second void of sale 1001',
        true,
        '01900000-0000-7000-8000-000000000501',
        10000,
        10000
    );
    RAISE EXCEPTION 'second sale void was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
END $$;

ROLLBACK;
