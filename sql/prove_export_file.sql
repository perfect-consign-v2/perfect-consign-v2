-- Proof for the QuickBooks and Xero journal files. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO vendors (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada, LLC');

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
    '01900000-0000-7000-8000-000000000f01',
    '01900000-0000-7000-8000-000000000001',
    'opening',
    '2026-06-15',
    '2026-06-15 00:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'opening',
    'opening',
    'Opening, balances',
    2500,
    2500
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000f01',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000010',
    2500,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000f01',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000014',
    0,
    2500,
    'vendor',
    '01900000-0000-7000-8000-000000000101'
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    journal_number text;
    quickbooks_lines text[];
    xero_lines text[];
BEGIN
    SELECT journal_no::text INTO journal_number
    FROM journals
    WHERE id = '01900000-0000-7000-8000-000000000f01';

    SELECT array_agg(csv_line ORDER BY ordinality)
    INTO quickbooks_lines
    FROM stall_export_journal_csv('quickbooks', '2026-06-01', '2026-06-30', 'Tax Exempt')
        WITH ORDINALITY AS export(csv_line, ordinality);

    IF quickbooks_lines <> ARRAY[
        'Journal No.,Journal Date,Account Name,Debits,Credits,Description,Name',
        journal_number || ',06/15/2026,1000,25.00,,"opening Opening, balances",',
        journal_number || ',06/15/2026,2000,,25.00,"opening Opening, balances","Ada, LLC"'
    ] THEN
        RAISE EXCEPTION 'quickbooks export was %', quickbooks_lines;
    END IF;

    SELECT array_agg(csv_line ORDER BY ordinality)
    INTO xero_lines
    FROM stall_export_journal_csv('xero', '2026-06-01', '2026-06-30', 'Tax Exempt')
        WITH ORDINALITY AS export(csv_line, ordinality);

    IF xero_lines <> ARRAY[
        '*Narration,*Date,Description,*AccountCode,*TaxRate,*Amount,TrackingName1,TrackingOption1,TrackingName2,TrackingOption2',
        'opening,2026-06-15,"Opening, balances",1000,Tax Exempt,25.00,,,,',
        'opening,2026-06-15,"Opening, balances",2000,Tax Exempt,-25.00,,,,'
    ] THEN
        RAISE EXCEPTION 'xero export was %', xero_lines;
    END IF;
END $$;

DO $$
DECLARE
    line_count integer;
BEGIN
    SET LOCAL ROLE stall_poster;
    SELECT count(*) INTO line_count
    FROM stall_export_journal_csv('quickbooks', '2026-06-01', '2026-06-30', 'Tax Exempt');
    RESET ROLE;
    IF line_count <> 3 THEN
        RAISE EXCEPTION 'poster export returned % lines', line_count;
    END IF;
END $$;

DO $$
BEGIN
    DELETE FROM export_accounts
    WHERE system = 'quickbooks'
      AND role = 'cash';
    PERFORM stall_export_journal_csv('quickbooks', '2026-06-01', '2026-06-30', 'Tax Exempt');
    RAISE EXCEPTION 'export with a missing account map was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM <> 'a posted line has no export account' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    PERFORM stall_export_journal_csv('quickbooks', '1990-01-01', '1990-01-31', 'Tax Exempt');
    RAISE EXCEPTION 'empty export was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM <> 'no posted lines in that range' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    PERFORM stall_export_journal_csv('xero', '2026-06-30', '2026-06-01', 'Tax Exempt');
    RAISE EXCEPTION 'inverted export range was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM <> 'the export range ends before it starts' THEN
            RAISE;
        END IF;
END $$;

ROLLBACK;
