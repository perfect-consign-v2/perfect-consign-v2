-- Proof. The whole script is one transaction and ends in ROLLBACK.
-- On an empty kernel it inserts throwaway books. Once the mall book exists,
-- that insert is refused, so the mall branch proves the same kernel rules
-- against the loaded book and leaves the chart in place.

SELECT CASE WHEN EXISTS (SELECT 1 FROM ledger_books WHERE code = 'mall') THEN 1 ELSE 0 END AS mall_loaded \gset

\if :mall_loaded
BEGIN;
INSERT INTO accounts (id, book_id, code, name, account_type, normal_balance, is_postable)
VALUES (
    '01900000-0000-7000-8000-00000000e011',
    '01900000-0000-7000-8000-000000000001',
    '9999',
    'Proof cash',
    'asset',
    'debit',
    true
);
INSERT INTO accounts (id, book_id, code, name, account_type, normal_balance, is_postable)
VALUES (
    '01900000-0000-7000-8000-00000000e012',
    '01900000-0000-7000-8000-000000000001',
    '9998',
    'Proof income',
    'revenue',
    'credit',
    true
);
INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000e021',
    '01900000-0000-7000-8000-000000000001',
    '2026-01-01',
    '2026-12-31',
    'open'
);
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000e031',
    '01900000-0000-7000-8000-000000000001',
'proof-loaded-balanced', '2026-03-01', '2026-03-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000e021',
    'USD', 'proof', 'loaded-balanced', 'Loaded balanced proof', 1000, 1000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-00000000e031', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-00000000e011', 1000, 0),
    ('01900000-0000-7000-8000-00000000e031', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-00000000e012', 0, 1000);
SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;
DO $$
DECLARE
    assigned bigint;
BEGIN
    SELECT journal_no INTO assigned
    FROM journals
    WHERE id = '01900000-0000-7000-8000-00000000e031';
    IF assigned <> 1 THEN
        RAISE EXCEPTION 'first journal number was %', assigned;
    END IF;
END $$;
DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, journal_no, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e039',
        '01900000-0000-7000-8000-000000000001',
        99, 'proof-supplied-no', '2026-03-05', '2026-03-05 12:00:00+00',
        '01900000-0000-7000-8000-00000000e021',
        'USD', 'proof', 'supplied-no', 'Supplied journal number', 1000, 1000
    );
    RAISE EXCEPTION 'supplied journal number was accepted';
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
        '01900000-0000-7000-8000-00000000e032',
        '01900000-0000-7000-8000-000000000001',
'proof-loaded-unbalanced', '2026-03-02', '2026-03-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000e021',
        'USD', 'proof', 'loaded-unbalanced', 'Loaded unbalanced proof', 1000, 1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-00000000e032', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-00000000e011', 1000, 0),
        ('01900000-0000-7000-8000-00000000e032', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-00000000e012', 0, 500);
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'unbalanced journal was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;
DO $$
BEGIN
    UPDATE accounts
    SET account_type = 'revenue', normal_balance = 'credit'
    WHERE id = '01900000-0000-7000-8000-00000000e011';
    RAISE EXCEPTION 'posted account type change was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;
DO $$
BEGIN
    TRUNCATE journal_lines, journals;
    RAISE EXCEPTION 'truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        NULL;
END $$;
DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    BEGIN
        TRUNCATE journals;
        RAISE EXCEPTION 'poster truncate was accepted';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        ALTER TABLE journals DISABLE TRIGGER journals_append_only;
        RAISE EXCEPTION 'poster disable trigger was accepted';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    RESET ROLE;
END $$;
INSERT INTO vendors (id, code, name)
VALUES ('01900000-0000-7000-8000-00000000e101', 'poster', 'Poster');
DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e033',
        '01900000-0000-7000-8000-000000000001',
'proof-poster-memo', '2026-03-03', '2026-03-03 12:00:00+00',
        '01900000-0000-7000-8000-00000000e021',
        'USD', 'proof', 'poster-memo', 'Poster non-control journal', 1000, 1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-00000000e033', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-00000000e011', 1000, 0),
        ('01900000-0000-7000-8000-00000000e033', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-00000000e012', 0, 1000);
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e034',
        '01900000-0000-7000-8000-000000000001',
'proof-poster-sale', '2026-03-04', '2026-03-04 12:00:00+00',
        '01900000-0000-7000-8000-00000000e021',
        'USD', 'sale', 'poster-sale', 'Poster vendor payable', 1000, 1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-00000000e034',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000010',
        1000,
        0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000e034',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000014',
        0,
        1000,
        'vendor',
        '01900000-0000-7000-8000-00000000e101'
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    BEGIN
        INSERT INTO chart_roles (role, account_id)
        VALUES ('poster_probe', '01900000-0000-7000-8000-000000000010');
        RAISE EXCEPTION 'poster chart role insert was accepted';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    RESET ROLE;
END $$;
DO $$
DECLARE
    second_no bigint;
BEGIN
    SELECT journal_no INTO second_no
    FROM journals
    WHERE id = '01900000-0000-7000-8000-00000000e033';
    IF second_no <> 2 THEN
        RAISE EXCEPTION 'second journal number was %', second_no;
    END IF;
END $$;
ROLLBACK;
DO $$
DECLARE
    role_count integer;
    journal_count bigint;
BEGIN
    SELECT count(*) INTO role_count FROM chart_roles;
    SELECT count(*) INTO journal_count FROM journals;
    IF role_count <> 10 OR journal_count <> 0 THEN
        RAISE EXCEPTION 'mall chart changed during the loaded kernel proof';
    END IF;
END $$;
\else
BEGIN;

INSERT INTO ledger_books (id, code, name, currency, currency_scale, is_default)
VALUES
    ('018f0000-0000-7000-8000-000000000001', 'proof', 'Proof book', 'USD', 2, true),
    ('018f0000-0000-7000-8000-000000000002', 'proof-other', 'Other proof book', 'USD', 2, false);

INSERT INTO accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type)
VALUES
    ('018f0000-0000-7000-8000-000000000011', '018f0000-0000-7000-8000-000000000001', '1000', 'Cash', 'asset', 'debit', true, false, NULL),
    ('018f0000-0000-7000-8000-000000000012', '018f0000-0000-7000-8000-000000000001', '4000', 'Income', 'revenue', 'credit', true, false, NULL),
    ('018f0000-0000-7000-8000-000000000013', '018f0000-0000-7000-8000-000000000001', '3000', 'Equity header', 'equity', 'credit', false, false, NULL),
    ('018f0000-0000-7000-8000-000000000014', '018f0000-0000-7000-8000-000000000001', '1200', 'Accounts receivable', 'asset', 'debit', true, true, 'customer'),
    ('018f0000-0000-7000-8000-000000000015', '018f0000-0000-7000-8000-000000000002', '1000', 'Other cash', 'asset', 'debit', true, false, NULL);

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES
    ('018f0000-0000-7000-8000-000000000021', '018f0000-0000-7000-8000-000000000001', '2026-01-01', '2026-12-31', 'open'),
    ('018f0000-0000-7000-8000-000000000022', '018f0000-0000-7000-8000-000000000002', '2026-01-01', '2026-12-31', 'open');

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '018f0000-0000-7000-8000-000000000031',
    '018f0000-0000-7000-8000-000000000001',
'proof-balanced', '2026-03-01', '2026-03-01 12:00:00+00',
    '018f0000-0000-7000-8000-000000000021',
    'USD', 'proof', 'balanced', 'Balanced proof journal', 1000, 1000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('018f0000-0000-7000-8000-000000000031', '018f0000-0000-7000-8000-000000000001', 1, '018f0000-0000-7000-8000-000000000011', 1000, 0),
    ('018f0000-0000-7000-8000-000000000031', '018f0000-0000-7000-8000-000000000001', 2, '018f0000-0000-7000-8000-000000000012', 0, 1000);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '018f0000-0000-7000-8000-000000000032',
        '018f0000-0000-7000-8000-000000000001',
'proof-unbalanced', '2026-03-02', '2026-03-02 12:00:00+00',
        '018f0000-0000-7000-8000-000000000021',
        'USD', 'proof', 'unbalanced', 'Unbalanced proof journal', 1000, 1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('018f0000-0000-7000-8000-000000000032', '018f0000-0000-7000-8000-000000000001', 1, '018f0000-0000-7000-8000-000000000011', 1000, 0),
        ('018f0000-0000-7000-8000-000000000032', '018f0000-0000-7000-8000-000000000001', 2, '018f0000-0000-7000-8000-000000000012', 0, 500);
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'unbalanced journal was accepted';
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
        '018f0000-0000-7000-8000-000000000033',
        '018f0000-0000-7000-8000-000000000001',
'proof-both-sides', '2026-03-03', '2026-03-03 12:00:00+00',
        '018f0000-0000-7000-8000-000000000021',
        'USD', 'proof', 'both-sides', 'Both sides proof journal', 1000, 1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('018f0000-0000-7000-8000-000000000033', '018f0000-0000-7000-8000-000000000001', 1, '018f0000-0000-7000-8000-000000000011', 1000, 0),
        ('018f0000-0000-7000-8000-000000000033', '018f0000-0000-7000-8000-000000000001', 2, '018f0000-0000-7000-8000-000000000011', 0, 1000);
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'both-sides account was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE journals SET description = 'edited' WHERE id = '018f0000-0000-7000-8000-000000000031';
    RAISE EXCEPTION 'journal edit was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        NULL;
END $$;

DO $$
BEGIN
    DELETE FROM journals WHERE id = '018f0000-0000-7000-8000-000000000031';
    RAISE EXCEPTION 'journal delete was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE accounting_periods
    SET ends_on = '2026-01-31'
    WHERE id = '018f0000-0000-7000-8000-000000000021';
    RAISE EXCEPTION 'shrunk period was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE accounts
    SET is_postable = false
    WHERE id = '018f0000-0000-7000-8000-000000000011';
    RAISE EXCEPTION 'posted account mutation was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE accounts
    SET account_type = 'revenue', normal_balance = 'credit'
    WHERE id = '018f0000-0000-7000-8000-000000000011';
    RAISE EXCEPTION 'posted account type change was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE ledger_books
    SET currency = 'EUR'
    WHERE id = '018f0000-0000-7000-8000-000000000001';
    RAISE EXCEPTION 'book currency mutation was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
    VALUES (
        '018f0000-0000-7000-8000-000000000023',
        '018f0000-0000-7000-8000-000000000001',
        '2026-06-01', '2026-12-31', 'open'
    );
    RAISE EXCEPTION 'overlapping period was accepted';
EXCEPTION
    WHEN SQLSTATE '23P01' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '018f0000-0000-7000-8000-000000000037',
        '018f0000-0000-7000-8000-000000000001',
'proof-cross-period', '2026-03-07', '2026-03-07 12:00:00+00',
        '018f0000-0000-7000-8000-000000000022',
        'USD', 'proof', 'cross-period', 'Cross-book period', 1000, 1000
    );
    RAISE EXCEPTION 'cross-book period was accepted';
EXCEPTION
    WHEN SQLSTATE '23503' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '018f0000-0000-7000-8000-000000000038',
        '018f0000-0000-7000-8000-000000000001',
'proof-cross-account', '2026-03-08', '2026-03-08 12:00:00+00',
        '018f0000-0000-7000-8000-000000000021',
        'USD', 'proof', 'cross-account', 'Cross-book account', 1000, 1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '018f0000-0000-7000-8000-000000000038',
        '018f0000-0000-7000-8000-000000000001',
        1,
        '018f0000-0000-7000-8000-000000000015',
        1000,
        0
    );
    RAISE EXCEPTION 'cross-book account was accepted';
EXCEPTION
    WHEN SQLSTATE '23503' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '018f0000-0000-7000-8000-000000000039',
        '018f0000-0000-7000-8000-000000000001',
'proof-currency', '2026-03-09', '2026-03-09 12:00:00+00',
        '018f0000-0000-7000-8000-000000000021',
        'CAD', 'proof', 'currency', 'Currency mismatch', 1000, 1000
    );
    RAISE EXCEPTION 'currency mismatch was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '018f0000-0000-7000-8000-00000000003a',
        '018f0000-0000-7000-8000-000000000001',
'proof-non-postable', '2026-03-10', '2026-03-10 12:00:00+00',
        '018f0000-0000-7000-8000-000000000021',
        'USD', 'proof', 'non-postable', 'Non-postable account', 1000, 1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('018f0000-0000-7000-8000-00000000003a', '018f0000-0000-7000-8000-000000000001', 1, '018f0000-0000-7000-8000-000000000011', 1000, 0),
        ('018f0000-0000-7000-8000-00000000003a', '018f0000-0000-7000-8000-000000000001', 2, '018f0000-0000-7000-8000-000000000013', 0, 1000);
    RAISE EXCEPTION 'non-postable account was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '018f0000-0000-7000-8000-00000000003b',
        '018f0000-0000-7000-8000-000000000001',
'proof-control', '2026-03-11', '2026-03-11 12:00:00+00',
        '018f0000-0000-7000-8000-000000000021',
        'USD', 'proof', 'control', 'Wrong control subledger', 1000, 1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
    VALUES (
        '018f0000-0000-7000-8000-00000000003b',
        '018f0000-0000-7000-8000-000000000001',
        1,
        '018f0000-0000-7000-8000-000000000014',
        1000,
        0,
        'vendor',
        'v-1'
    );
    RAISE EXCEPTION 'wrong control subledger was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
        debit_total_minor, credit_total_minor
    ) VALUES (
        '018f0000-0000-7000-8000-00000000003c',
        '018f0000-0000-7000-8000-000000000001',
'proof-bad-swap', '2026-03-12', '2026-03-12 12:00:00+00',
        '018f0000-0000-7000-8000-000000000021',
        'USD', 'proof', 'bad-swap', 'Reversal that is not a swap', true,
        '018f0000-0000-7000-8000-000000000031',
        1000, 1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('018f0000-0000-7000-8000-00000000003c', '018f0000-0000-7000-8000-000000000001', 1, '018f0000-0000-7000-8000-000000000011', 1000, 0),
        ('018f0000-0000-7000-8000-00000000003c', '018f0000-0000-7000-8000-000000000001', 2, '018f0000-0000-7000-8000-000000000012', 0, 1000);
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'reversal that is not a swap was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
        debit_total_minor, credit_total_minor
    ) VALUES (
        '018f0000-0000-7000-8000-00000000003d',
        '018f0000-0000-7000-8000-000000000001',
'proof-early-date', '2026-02-01', '2026-03-13 12:00:00+00',
        '018f0000-0000-7000-8000-000000000021',
        'USD', 'proof', 'early-date', 'Reversal with an earlier posting date', true,
        '018f0000-0000-7000-8000-000000000031',
        1000, 1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('018f0000-0000-7000-8000-00000000003d', '018f0000-0000-7000-8000-000000000001', 1, '018f0000-0000-7000-8000-000000000012', 1000, 0),
        ('018f0000-0000-7000-8000-00000000003d', '018f0000-0000-7000-8000-000000000001', 2, '018f0000-0000-7000-8000-000000000011', 0, 1000);
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'earlier reversal posting date was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
        debit_total_minor, credit_total_minor
    ) VALUES (
        '018f0000-0000-7000-8000-00000000003e',
        '018f0000-0000-7000-8000-000000000001',
'proof-early-time', '2026-03-14', '2026-03-01 12:00:00+00',
        '018f0000-0000-7000-8000-000000000021',
        'USD', 'proof', 'early-time', 'Reversal that is not later', true,
        '018f0000-0000-7000-8000-000000000031',
        1000, 1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('018f0000-0000-7000-8000-00000000003e', '018f0000-0000-7000-8000-000000000001', 1, '018f0000-0000-7000-8000-000000000012', 1000, 0),
        ('018f0000-0000-7000-8000-00000000003e', '018f0000-0000-7000-8000-000000000001', 2, '018f0000-0000-7000-8000-000000000011', 0, 1000);
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'reversal that is not later was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
    debit_total_minor, credit_total_minor
) VALUES (
    '018f0000-0000-7000-8000-000000000034',
    '018f0000-0000-7000-8000-000000000001',
'proof-reversal', '2026-03-04', '2026-03-04 12:00:00+00',
    '018f0000-0000-7000-8000-000000000021',
    'USD', 'proof', 'reversal', 'Reversal of the balanced journal', true,
    '018f0000-0000-7000-8000-000000000031',
    1000, 1000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('018f0000-0000-7000-8000-000000000034', '018f0000-0000-7000-8000-000000000001', 1, '018f0000-0000-7000-8000-000000000012', 1000, 0),
    ('018f0000-0000-7000-8000-000000000034', '018f0000-0000-7000-8000-000000000001', 2, '018f0000-0000-7000-8000-000000000011', 0, 1000);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
        debit_total_minor, credit_total_minor
    ) VALUES (
        '018f0000-0000-7000-8000-000000000035',
        '018f0000-0000-7000-8000-000000000001',
'proof-second-reversal', '2026-03-05', '2026-03-05 12:00:00+00',
        '018f0000-0000-7000-8000-000000000021',
        'USD', 'proof', 'second-reversal', 'Second reversal', true,
        '018f0000-0000-7000-8000-000000000031',
        1000, 1000
    );
    RAISE EXCEPTION 'second reversal was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
END $$;

UPDATE accounting_periods
SET status = 'closed'
WHERE id = '018f0000-0000-7000-8000-000000000021';

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '018f0000-0000-7000-8000-000000000036',
        '018f0000-0000-7000-8000-000000000001',
'proof-closed', '2026-03-06', '2026-03-06 12:00:00+00',
        '018f0000-0000-7000-8000-000000000021',
        'USD', 'proof', 'closed', 'Closed period journal', 1000, 1000
    );
    RAISE EXCEPTION 'closed-period journal was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    DELETE FROM accounting_periods WHERE id = '018f0000-0000-7000-8000-000000000021';
    RAISE EXCEPTION 'closed-period delete was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    TRUNCATE journal_lines, journals;
    RAISE EXCEPTION 'truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        NULL;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    BEGIN
        TRUNCATE journals;
        RAISE EXCEPTION 'poster truncate was accepted';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        ALTER TABLE journals DISABLE TRIGGER journals_append_only;
        RAISE EXCEPTION 'poster disable trigger was accepted';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    RESET ROLE;
END $$;

ROLLBACK;

DO $$
DECLARE
    account_count bigint;
BEGIN
    SELECT count(*) INTO account_count FROM accounts;
    IF account_count <> 0 THEN
        RAISE EXCEPTION 'accounts table was not empty after rollback';
    END IF;
END $$;
\endif
