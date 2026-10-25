-- The export file ties to the books. A missing map refuses the close.
-- A second export of an unchanged period is the same file. A closed period stays that way.

BEGIN;

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-000000000d23', 'Export tie');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d22',
    '01900000-0000-7000-8000-000000000d23',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

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
    '01900000-0000-7000-8000-000000000d01',
    '01900000-0000-7000-8000-000000000001',
    'opening',
    '2026-01-01',
    '2026-01-01 00:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'opening', 'opening', 'Opening balances', 20000, 20000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d01',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 20000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000d01',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 20000, 'vendor', '01900000-0000-7000-8000-000000000101'
);

DO $$
DECLARE
    quickbooks_delta bigint;
    xero_delta bigint;
    cash_debit bigint;
    first_hash text;
    second_hash text;
BEGIN
    quickbooks_delta := stall_export_tie_delta('quickbooks', DATE '2026-01-01', DATE '2026-12-31');
    xero_delta := stall_export_tie_delta('xero', DATE '2026-01-01', DATE '2026-12-31');
    IF quickbooks_delta <> 0 OR xero_delta <> 0 THEN
        RAISE EXCEPTION 'the export did not tie to the books';
    END IF;

    SELECT debit_minor INTO cash_debit
    FROM account_balances
    WHERE account_id = '01900000-0000-7000-8000-000000000010';
    IF cash_debit <> 20000 THEN
        RAISE EXCEPTION 'cash left the opening debit';
    END IF;

    first_hash := stall_export_file_hash('quickbooks', DATE '2026-01-01', DATE '2026-12-31');
    second_hash := stall_export_file_hash('quickbooks', DATE '2026-01-01', DATE '2026-12-31');
    IF first_hash IS DISTINCT FROM second_hash OR first_hash IS NULL OR char_length(first_hash) <> 32 THEN
        RAISE EXCEPTION 'a second export changed the file';
    END IF;
    IF stall_export_file_hash('xero', DATE '2026-01-01', DATE '2026-12-31')
        IS DISTINCT FROM stall_export_file_hash('xero', DATE '2026-01-01', DATE '2026-12-31') THEN
        RAISE EXCEPTION 'a second xero export changed the file';
    END IF;
END $$;

DO $$
BEGIN
    DELETE FROM export_accounts WHERE system = 'quickbooks' AND role = 'cash';
    PERFORM stall_close_quiet_period('01900000-0000-7000-8000-000000000021'::uuid);
    RAISE EXCEPTION 'an unmapped line closed';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM <> 'a posted line has no export account' THEN
            RAISE;
        END IF;
END $$;

DO $$
DECLARE
    first_hash text;
    closed_hash text;
BEGIN
    first_hash := stall_export_file_hash('quickbooks', DATE '2026-01-01', DATE '2026-12-31');
    PERFORM stall_close_quiet_period('01900000-0000-7000-8000-000000000021'::uuid);
    closed_hash := stall_export_file_hash('quickbooks', DATE '2026-01-01', DATE '2026-12-31');
    IF closed_hash IS DISTINCT FROM first_hash THEN
        RAISE EXCEPTION 'closing the period changed the export';
    END IF;
END $$;

DO $$
DECLARE
    before_hash text;
    after_hash text;
BEGIN
    before_hash := stall_export_file_hash('quickbooks', DATE '2026-01-01', DATE '2026-12-31');
    BEGIN
        INSERT INTO journals (
            id, book_id, posting_key, posting_date, occurred_at, period_id,
            currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
        ) VALUES (
            '01900000-0000-7000-8000-000000000d99',
            '01900000-0000-7000-8000-000000000001',
            'opening-after-close',
            '2026-02-01',
            '2026-02-01 00:00:00+00',
            '01900000-0000-7000-8000-000000000021',
            'USD', 'opening', 'after', 'After close', 100, 100
        );
        RAISE EXCEPTION 'a closed period accepted a journal';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM <> 'a closed period refuses a new journal' THEN
                RAISE;
            END IF;
    END;
    after_hash := stall_export_file_hash('quickbooks', DATE '2026-01-01', DATE '2026-12-31');
    IF after_hash IS DISTINCT FROM before_hash THEN
        RAISE EXCEPTION 'a refused journal changed the export';
    END IF;
END $$;

ROLLBACK;
