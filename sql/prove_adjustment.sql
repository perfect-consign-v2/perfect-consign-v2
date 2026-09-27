-- Proof for adjustments. One transaction, then ROLLBACK.

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

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000a01',
    '01900000-0000-7000-8000-000000000001',
    'adjustment:bank-fee',
    '2026-03-01',
    '2026-03-01 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'adjustment',
    'bank-fee',
    'March bank fee',
    2500,
    2500
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000a01', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000018', 2500, 0),
    ('01900000-0000-7000-8000-000000000a01', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 2500);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000a02',
    '01900000-0000-7000-8000-000000000001',
    'adjustment:bank-fee-b',
    '2026-03-02',
    '2026-03-02 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'adjustment',
    'bank-fee-b',
    'Second adjustment key',
    100,
    100
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000a02', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000018', 100, 0),
    ('01900000-0000-7000-8000-000000000a02', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 100);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000a03',
        '01900000-0000-7000-8000-000000000001',
        'adjustment:bank-fee',
        '2026-03-03',
        '2026-03-03 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'adjustment',
        'bank-fee',
        'Duplicate adjustment key',
        100,
        100
    );
    RAISE EXCEPTION 'duplicate adjustment key was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000a04',
        '01900000-0000-7000-8000-000000000001',
        'memo:commission',
        '2026-03-04',
        '2026-03-04 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'memo',
        'commission',
        'Memo credit to commission',
        500,
        500
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000a04',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000010',
        500,
        0
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000a04',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000012',
        0,
        500
    );
    RAISE EXCEPTION 'memo credit to commission was accepted';
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
        '01900000-0000-7000-8000-000000000a05',
        '01900000-0000-7000-8000-000000000001',
        'adjustment:payable',
        '2026-03-05',
        '2026-03-05 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'adjustment',
        'payable',
        'Adjustment to vendor payable',
        100,
        100
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000a05',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000010',
        100,
        0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000a05',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000014',
        0,
        100,
        'vendor',
        '01900000-0000-7000-8000-000000000101'
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'adjustment to vendor payable was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
DECLARE
    payable bigint;
BEGIN
    SELECT COALESCE(sum(credit_minor - debit_minor), 0) INTO payable
    FROM journal_lines
    WHERE account_id = '01900000-0000-7000-8000-000000000014';
    IF payable <> 0 THEN
        RAISE EXCEPTION 'adjustment changed vendor payable';
    END IF;
END $$;

ROLLBACK;
