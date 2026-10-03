-- A store purchase credits the vendor and debits store purchases. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-00000000f101', 'buy', 'Bea', 'Lane');

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000f021',
    '01900000-0000-7000-8000-000000000001',
    '2031-01-01',
    '2031-12-31',
    'open'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f801',
    '01900000-0000-7000-8000-000000000001',
    'store-purchase:invoice-1',
    '2031-03-01',
    '2031-03-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000f021',
    'USD',
    'store_purchase',
    '01900000-0000-7000-8000-00000000f101',
    'Store purchase',
    2500,
    2500
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f801',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000022',
    2500,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f801',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000014',
    0,
    2500,
    'vendor',
    '01900000-0000-7000-8000-00000000f101'
);

SET CONSTRAINTS ALL IMMEDIATE;

DO $$
DECLARE
    owed bigint;
BEGIN
    SELECT COALESCE(sum(credit_minor - debit_minor), 0) INTO owed
    FROM journal_lines
    WHERE subledger_ref = '01900000-0000-7000-8000-00000000f101';
    IF owed <> 2500 THEN
        RAISE EXCEPTION 'store purchase did not raise the payable';
    END IF;
END $$;

ROLLBACK;
