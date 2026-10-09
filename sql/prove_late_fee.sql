-- A late fee that reduces what the store owes a vendor is refused. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000e831',
    '01900000-0000-7000-8000-000000000001',
    '2097-01-01',
    '2097-12-31',
    'open'
);

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-00000000e832', 'e832', 'Late', 'Fee');

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-00000000e833', 'e833', 'Late booth');

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e834',
        '01900000-0000-7000-8000-000000000001',
        'late-fee:proof',
        '2097-04-01',
        '2097-04-01 12:00:00+00',
        '01900000-0000-7000-8000-00000000e831',
        'USD',
        'late_fee',
        '01900000-0000-7000-8000-00000000e833',
        'Bad late fee',
        250,
        250
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000e834',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000014',
        250,
        0,
        'vendor',
        '01900000-0000-7000-8000-00000000e832'
    );
    RAISE EXCEPTION 'a late fee reduced vendor payable';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a control account line must come from a sale, rent charge, rent payment, or payout%' THEN
            RAISE;
        END IF;
END $$;

ROLLBACK;
