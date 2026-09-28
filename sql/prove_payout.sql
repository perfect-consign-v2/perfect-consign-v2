-- Proof for a payout. One transaction, then ROLLBACK.
-- The payout amount is the open payable minus the open rent, not a recompute of the sale.

BEGIN;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane');

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000201', 'A1', 'Booth A1');

INSERT INTO booth_assignments (booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-000000000201',
    '01900000-0000-7000-8000-000000000101',
    '2026-01-01'
);

INSERT INTO booth_rent_rates (booth_id, month_start, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000201', '2026-03-01', 1500);

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
    '01900000-0000-7000-8000-000000000801',
    '01900000-0000-7000-8000-000000000001',
    'sale:1001',
    '2026-03-10',
    '2026-03-10 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'sale',
    '1001',
    'Cash sale 1001',
    7200,
    7200
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000801',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000010',
    7200,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000801',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000014',
    0,
    7200,
    'vendor',
    '01900000-0000-7000-8000-000000000101'
);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES (
    '01900000-0000-7000-8000-0000000008a1',
    'Coat',
    '01900000-0000-7000-8000-000000000101',
    'Wool coat',
    7200,
    'BC-PAYOUT',
    DATE '2026-03-10',
    0
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-000000000801', 1, '01900000-0000-7000-8000-0000000008a1', 7200, 0, 0);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000802',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-000000000201:2026-03',
    '2026-03-01',
    '2026-03-01 08:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'rent_charge',
    'A1:2026-03',
    'March rent for booth A1',
    1500,
    1500
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000802',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000015',
    1500,
    0,
    'booth',
    '01900000-0000-7000-8000-000000000201'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000802',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000017',
    0,
    1500
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000803',
    '01900000-0000-7000-8000-000000000001',
    'payout:01900000-0000-7000-8000-000000000101:2026-03',
    '2026-03-31',
    '2026-03-31 18:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'payout',
    'ada:2026-03',
    'March payout for Ada',
    7200,
    7200
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    (
        '01900000-0000-7000-8000-000000000803',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000014',
        7200,
        0,
        'vendor',
        '01900000-0000-7000-8000-000000000101'
    ),
    (
        '01900000-0000-7000-8000-000000000803',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000015',
        0,
        1500,
        'booth',
        '01900000-0000-7000-8000-000000000201'
    );

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000803',
    '01900000-0000-7000-8000-000000000001',
    3,
    '01900000-0000-7000-8000-000000000010',
    0,
    5700
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES
    ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000801'),
    ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000803');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
        debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000807',
        '01900000-0000-7000-8000-000000000001',
        'sale:1001:void-after-payout',
        '2026-04-02',
        '2026-04-02 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'sale',
        '1001-void-after-payout',
        'Void of a sale already paid out',
        true,
        '01900000-0000-7000-8000-000000000801',
        7200,
        7200
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000807',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000014',
        7200,
        0,
        'vendor',
        '01900000-0000-7000-8000-000000000101'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000807',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000010',
        0,
        7200
    );
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000807');
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'sale void after payout was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000804',
        '01900000-0000-7000-8000-000000000001',
        'payout:01900000-0000-7000-8000-000000000101:2026-03',
        '2026-03-31',
        '2026-03-31 19:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'payout',
        'ada:2026-03-again',
        'Second March payout',
        7200,
        7200
    );
    RAISE EXCEPTION 'second payout for the same vendor and month was accepted';
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
        '01900000-0000-7000-8000-000000000805',
        '01900000-0000-7000-8000-000000000001',
        'payout:01900000-0000-7000-8000-000000000101:2026-04',
        '2026-04-30',
        '2026-04-30 18:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'payout',
        'ada:2026-04-wrong',
        'Payout that pays the sale total',
        10000,
        10000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000805',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000014',
        10000,
        0,
        'vendor',
        '01900000-0000-7000-8000-000000000101'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000805',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000010',
        0,
        10000
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'payout that recomputes the sale was accepted';
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
    '01900000-0000-7000-8000-000000000806',
    '01900000-0000-7000-8000-000000000001',
    'payout:01900000-0000-7000-8000-000000000101:2026-03:void',
    '2026-03-31',
    '2026-03-31 20:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'payout',
    'ada:2026-03-void',
    'Reversal of the March payout',
    true,
    '01900000-0000-7000-8000-000000000803',
    7200,
    7200
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    (
        '01900000-0000-7000-8000-000000000806',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000014',
        0,
        7200,
        'vendor',
        '01900000-0000-7000-8000-000000000101'
    ),
    (
        '01900000-0000-7000-8000-000000000806',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000015',
        1500,
        0,
        'booth',
        '01900000-0000-7000-8000-000000000201'
    );

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000806',
    '01900000-0000-7000-8000-000000000001',
    3,
    '01900000-0000-7000-8000-000000000010',
    5700,
    0
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000806');

SET CONSTRAINTS ALL IMMEDIATE;

ROLLBACK;
