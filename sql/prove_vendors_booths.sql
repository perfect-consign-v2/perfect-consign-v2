-- Proof for vendors and booths. One transaction, then ROLLBACK.

BEGIN;

DO $$
DECLARE
    money_columns integer;
BEGIN
    SELECT count(*) INTO money_columns
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND table_name IN ('vendors', 'booths', 'booth_assignments')
      AND (
          data_type IN ('numeric', 'money', 'bigint', 'integer', 'smallint')
          OR column_name ~* '(amount|balance|minor|rate|price|total)'
      );

    IF money_columns <> 0 THEN
        RAISE EXCEPTION 'vendor or booth tables carry money';
    END IF;
END $$;

INSERT INTO vendors (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada');

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000201', 'A1', 'Booth A1');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-000000000301',
    '01900000-0000-7000-8000-000000000201',
    '01900000-0000-7000-8000-000000000101',
    '2026-01-01'
);

DO $$
BEGIN
    INSERT INTO booth_assignments (booth_id, vendor_id, starts_on)
    VALUES (
        '01900000-0000-7000-8000-000000000201',
        '01900000-0000-7000-8000-000000000101',
        '2026-06-01'
    );
    RAISE EXCEPTION 'overlapping booth assignment was accepted';
EXCEPTION
    WHEN SQLSTATE '23P01' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE booth_assignments
    SET starts_on = '2026-02-01'
    WHERE id = '01900000-0000-7000-8000-000000000301';
    RAISE EXCEPTION 'assignment identity rewrite was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000209', 'B9', 'Booth B9');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-000000000309',
    '01900000-0000-7000-8000-000000000209',
    '01900000-0000-7000-8000-000000000101',
    '2026-01-01'
);

DELETE FROM booth_assignments WHERE id = '01900000-0000-7000-8000-000000000309';

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM booth_assignments WHERE id = '01900000-0000-7000-8000-000000000309'
    ) THEN
        RAISE EXCEPTION 'assignment with no posted rent was not deleted';
    END IF;
END $$;

UPDATE booth_assignments
SET ends_on = '2026-12-31'
WHERE id = '01900000-0000-7000-8000-000000000301';

DO $$
BEGIN
    UPDATE booth_assignments
    SET ends_on = '2026-06-30'
    WHERE id = '01900000-0000-7000-8000-000000000301';
    RAISE EXCEPTION 'assignment end rewrite was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

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
    '01900000-0000-7000-8000-000000000401',
    '01900000-0000-7000-8000-000000000001',
    'prove-vendor-ref',
    '2026-03-01',
    '2026-03-01 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'sale',
    'vendor-ref',
    'Vendor subledger proof',
    1000,
    1000
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000401',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000014',
    0,
    1000,
    'vendor',
    '01900000-0000-7000-8000-000000000101'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000401',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000010',
    1000,
    0
);

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000402',
        '01900000-0000-7000-8000-000000000001',
        'prove-missing-vendor',
        '2026-03-02',
        '2026-03-02 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'sale',
        'missing-vendor',
        'Missing vendor subledger',
        1000,
        1000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000402',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000014',
        0,
        1000,
        'vendor',
        '01900000-0000-7000-8000-000000000199'
    );
    RAISE EXCEPTION 'unknown vendor subledger was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-000000000101';
    RAISE EXCEPTION 'referenced vendor delete was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    TRUNCATE booth_rent_rates, booth_assignments, booths, vendors;
    RAISE EXCEPTION 'vendor truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        NULL;
END $$;

ROLLBACK;
