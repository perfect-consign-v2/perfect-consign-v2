-- A gift card issue that is not cash, card, or a check is refused. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000e801',
    '01900000-0000-7000-8000-000000000001',
    '2094-01-01',
    '2094-12-31',
    'open'
);

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-00000000e802',
    '01900000-0000-7000-8000-000000000d10',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO stored_values (id, kind, number)
VALUES ('01900000-0000-7000-8000-00000000e803', 'gift_card', 'GD-PROOF');

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e804',
        '01900000-0000-7000-8000-000000000001',
        'gift-card:gd-proof-bad',
        '2094-02-01',
        '2094-02-01 12:00:00+00',
        '01900000-0000-7000-8000-00000000e801',
        'USD',
        'gift_card_issue',
        'GD-PROOF',
        'Bad gift card',
        1000,
        1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        (
            '01900000-0000-7000-8000-00000000e804',
            '01900000-0000-7000-8000-000000000001',
            1,
            '01900000-0000-7000-8000-000000000010',
            600,
            0
        ),
        (
            '01900000-0000-7000-8000-00000000e804',
            '01900000-0000-7000-8000-000000000001',
            2,
            '01900000-0000-7000-8000-000000000010',
            400,
            0
        );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000e804',
        '01900000-0000-7000-8000-000000000001',
        3,
        '01900000-0000-7000-8000-00000000001d',
        0,
        1000,
        'gift_card',
        '01900000-0000-7000-8000-00000000e803'
    );
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES (
        '01900000-0000-7000-8000-00000000e802',
        '01900000-0000-7000-8000-00000000e804'
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a short gift card issue was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%stored value is issued for cash, card, or a check%' THEN
            RAISE;
        END IF;
END $$;

ROLLBACK;
