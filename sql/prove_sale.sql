-- Proof for a sale. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane');

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-000000000021',
    '01900000-0000-7000-8000-000000000001',
    '2026-01-01',
    '2026-12-31',
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

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000501',
    '01900000-0000-7000-8000-000000000001',
    'sale:1001',
    '2026-03-01',
    '2026-03-01 12:00:00+00',
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
) VALUES
    ('01900000-0000-7000-8000-000000000501', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000013', 0, 800, NULL, NULL),
    (
        '01900000-0000-7000-8000-000000000501',
        '01900000-0000-7000-8000-000000000001',
        4,
        '01900000-0000-7000-8000-000000000014',
        0,
        7200,
        'vendor',
        '01900000-0000-7000-8000-000000000101'
    );

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000501');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000508',
    '01900000-0000-7000-8000-000000000001',
    'sale:1001-b',
    '2026-03-01',
    '2026-03-01 13:00:00+00',
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
        '2026-03-01',
        '2026-03-01 14:00:00+00',
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
    '2026-03-02',
    '2026-03-02 12:00:00+00',
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
        '2026-03-03',
        '2026-03-03 12:00:00+00',
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
        '2026-03-03',
        '2026-03-03 12:00:00+00',
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
        '2026-03-04',
        '2026-03-04 12:00:00+00',
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
    '2026-03-05',
    '2026-03-05 12:00:00+00',
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
) VALUES
    ('01900000-0000-7000-8000-000000000506', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000013', 800, 0, NULL, NULL),
    (
        '01900000-0000-7000-8000-000000000506',
        '01900000-0000-7000-8000-000000000001',
        4,
        '01900000-0000-7000-8000-000000000014',
        7200,
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
        '2026-03-06',
        '2026-03-06 12:00:00+00',
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
