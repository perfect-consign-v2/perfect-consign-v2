-- A store credit issue that is not cash, card, or a check is refused. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000e811',
    '01900000-0000-7000-8000-000000000001',
    '2095-01-01',
    '2095-12-31',
    'open'
);

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-00000000e812',
    '01900000-0000-7000-8000-000000000d10',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO stored_values (id, kind, number)
VALUES ('01900000-0000-7000-8000-00000000e813', 'store_credit', 'SC-PROOF');

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e814',
        '01900000-0000-7000-8000-000000000001',
        'store-credit:sc-proof-bad',
        '2095-02-01',
        '2095-02-01 12:00:00+00',
        '01900000-0000-7000-8000-00000000e811',
        'USD',
        'store_credit_issue',
        'SC-PROOF',
        'Bad store credit',
        800,
        800
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        (
            '01900000-0000-7000-8000-00000000e814',
            '01900000-0000-7000-8000-000000000001',
            1,
            '01900000-0000-7000-8000-000000000010',
            500,
            0
        ),
        (
            '01900000-0000-7000-8000-00000000e814',
            '01900000-0000-7000-8000-000000000001',
            2,
            '01900000-0000-7000-8000-000000000010',
            300,
            0
        );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000e814',
        '01900000-0000-7000-8000-000000000001',
        3,
        '01900000-0000-7000-8000-00000000001e',
        0,
        800,
        'store_credit',
        '01900000-0000-7000-8000-00000000e813'
    );
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES (
        '01900000-0000-7000-8000-00000000e812',
        '01900000-0000-7000-8000-00000000e814'
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a short store credit issue was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%stored value is issued for cash, card, or a check%' THEN
            RAISE;
        END IF;
END $$;

ROLLBACK;
