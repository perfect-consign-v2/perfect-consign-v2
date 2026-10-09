-- A wrong-booth correction that pays cash instead of a vendor is refused. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000e821',
    '01900000-0000-7000-8000-000000000001',
    '2096-01-01',
    '2096-12-31',
    'open'
);

INSERT INTO vendors (id, code, first_name, last_name)
VALUES
    ('01900000-0000-7000-8000-00000000e822', 'e822', 'Wrong', 'Booth'),
    ('01900000-0000-7000-8000-00000000e823', 'e823', 'Right', 'Booth');

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e824',
        '01900000-0000-7000-8000-000000000001',
        'booth-correction:proof',
        '2096-03-01',
        '2096-03-01 12:00:00+00',
        '01900000-0000-7000-8000-00000000e821',
        'USD',
        'booth_correction',
        'proof',
        'Bad booth correction',
        500,
        500
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000e824',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000014',
        500,
        0,
        'vendor',
        '01900000-0000-7000-8000-00000000e822'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-00000000e824',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000010',
        0,
        500
    );
    RAISE EXCEPTION 'a booth correction that paid cash was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a chart account line must come from a listed source type%' THEN
            RAISE;
        END IF;
END $$;

ROLLBACK;
