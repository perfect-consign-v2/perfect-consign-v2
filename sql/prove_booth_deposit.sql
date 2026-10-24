-- A release cannot exceed the deposit held for that booth. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000e941',
    '01900000-0000-7000-8000-000000000001',
    '2098-01-01',
    '2098-12-31',
    'open'
);

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-00000000e942', 'e942', 'Deposit booth');

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000e943',
    '01900000-0000-7000-8000-000000000001',
    'booth-deposit:e943',
    '2098-04-01',
    '2098-04-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000e941',
    'USD',
    'booth_deposit',
    'held',
    'Booth deposit',
    5000,
    5000
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor
) VALUES (
    '01900000-0000-7000-8000-00000000e943',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000010',
    5000,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000e943',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-00000000002d',
    0,
    5000,
    'booth',
    '01900000-0000-7000-8000-00000000e942'
);

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e944',
        '01900000-0000-7000-8000-000000000001',
        'booth-deposit-release:e944',
        '2098-04-02',
        '2098-04-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000e941',
        'USD',
        'booth_deposit',
        'over',
        'Booth deposit returned',
        5001,
        5001
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000e944',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-00000000002d',
        5001,
        0,
        'booth',
        '01900000-0000-7000-8000-00000000e942'
    );
    RAISE EXCEPTION 'a release larger than the deposit was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a release is larger than the deposit held%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000e945',
    '01900000-0000-7000-8000-000000000001',
    'booth-deposit-release:e945',
    '2098-04-03',
    '2098-04-03 12:00:00+00',
    '01900000-0000-7000-8000-00000000e941',
    'USD',
    'booth_deposit',
    'exact',
    'Booth deposit returned',
    5000,
    5000
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000e945',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-00000000002d',
    5000,
    0,
    'booth',
    '01900000-0000-7000-8000-00000000e942'
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor
) VALUES (
    '01900000-0000-7000-8000-00000000e945',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000010',
    0,
    5000
);

DO $$
DECLARE
    held bigint;
BEGIN
    SELECT COALESCE(sum(credit_minor - debit_minor), 0) INTO held
    FROM journal_lines
    WHERE account_id = '01900000-0000-7000-8000-00000000002d'
      AND subledger_ref = '01900000-0000-7000-8000-00000000e942';
    IF held <> 0 THEN
        RAISE EXCEPTION 'the booth deposit did not return to zero';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e946',
        '01900000-0000-7000-8000-000000000001',
        'booth-deposit-release:e946',
        '2098-04-04',
        '2098-04-04 12:00:00+00',
        '01900000-0000-7000-8000-00000000e941',
        'USD',
        'booth_deposit',
        'replica',
        'Booth deposit returned',
        1,
        1
    );
    SET LOCAL session_replication_role = replica;
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000e946',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-00000000002d',
        1,
        0,
        'booth',
        '01900000-0000-7000-8000-00000000e942'
    );
    RAISE EXCEPTION 'a replica session released more than the booth held';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a release is larger than the deposit held%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE journal_lines DISABLE TRIGGER journal_lines_booth_deposit_append_only;
    RAISE EXCEPTION 'disabling the booth deposit trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER journal_lines_booth_deposit_append_only ON journal_lines;
    RAISE EXCEPTION 'dropping the booth deposit trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_refuse_booth_deposit_overdraw()
    RETURNS trigger
    LANGUAGE plpgsql
    SET search_path = pg_catalog
    AS $body$
    BEGIN
        RETURN NEW;
    END;
    $body$;
    RAISE EXCEPTION 'replacing the booth deposit function was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
            RAISE;
        END IF;
END $$;

ROLLBACK;
