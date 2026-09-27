-- Proof for the year-end close. Years close from earliest to latest.
-- One transaction, then ROLLBACK.

BEGIN;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane');

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES
    (
        '01900000-0000-7000-8000-000000000033',
        '01900000-0000-7000-8000-000000000001',
        '2023-01-01',
        '2023-12-31',
        'open'
    ),
    (
        '01900000-0000-7000-8000-000000000031',
        '01900000-0000-7000-8000-000000000001',
        '2024-01-01',
        '2024-12-31',
        'open'
    ),
    (
        '01900000-0000-7000-8000-000000000032',
        '01900000-0000-7000-8000-000000000001',
        '2025-01-01',
        '2025-12-31',
        'open'
    ),
    (
        '01900000-0000-7000-8000-000000000021',
        '01900000-0000-7000-8000-000000000001',
        '2026-01-01',
        '2026-12-31',
        'open'
    ),
    (
        '01900000-0000-7000-8000-000000000022',
        '01900000-0000-7000-8000-000000000001',
        '2027-01-01',
        '2027-12-31',
        'open'
    );

DO $$
BEGIN
    PERFORM stall_close_quiet_period('01900000-0000-7000-8000-000000000021');
    RAISE EXCEPTION 'close of 2026 while 2023 is open was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM <> 'an earlier period is still open' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000e20',
        '01900000-0000-7000-8000-000000000001',
        'period-close:01900000-0000-7000-8000-000000000021',
        '2026-12-31',
        '2026-12-31 21:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD', 'period_close', '2026-early', 'Close 2026 too soon', 100, 100
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-000000000e20', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000012', 100, 0),
        ('01900000-0000-7000-8000-000000000e20', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000019', 0, 100);
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'journal close of 2026 while 2023 is open was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM <> 'an earlier period is still open' THEN
            RAISE;
        END IF;
END $$;

SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES
    (
        '01900000-0000-7000-8000-000000000e13',
        '01900000-0000-7000-8000-000000000001',
        'adjustment:even-income',
        '2023-03-01',
        '2023-03-01 12:00:00+00',
        '01900000-0000-7000-8000-000000000033',
        'USD', 'adjustment', 'even-income', 'Even income', 1000, 1000
    ),
    (
        '01900000-0000-7000-8000-000000000e14',
        '01900000-0000-7000-8000-000000000001',
        'adjustment:even-fee',
        '2023-03-02',
        '2023-03-02 12:00:00+00',
        '01900000-0000-7000-8000-000000000033',
        'USD', 'adjustment', 'even-fee', 'Even expense', 1000, 1000
    );
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000e13', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 1000, 0),
    ('01900000-0000-7000-8000-000000000e13', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 1000),
    ('01900000-0000-7000-8000-000000000e14', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000018', 1000, 0),
    ('01900000-0000-7000-8000-000000000e14', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 1000);

DO $$
BEGIN
    PERFORM stall_close_quiet_period('01900000-0000-7000-8000-000000000033');
    RAISE EXCEPTION 'quiet close of a break-even year was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM <> 'a period with income or expense closes by a journal' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000e17',
    '01900000-0000-7000-8000-000000000001',
    'period-close:01900000-0000-7000-8000-000000000033',
    '2023-12-31',
    '2023-12-31 23:00:00+00',
    '01900000-0000-7000-8000-000000000033',
    'USD', 'period_close', '2023', 'Close 2023', 1000, 1000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000e17', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000012', 1000, 0),
    ('01900000-0000-7000-8000-000000000e17', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000018', 0, 1000);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    period_status text;
BEGIN
    SELECT status INTO period_status
    FROM accounting_periods
    WHERE id = '01900000-0000-7000-8000-000000000033';
    IF period_status <> 'closed' THEN
        RAISE EXCEPTION 'break-even close left 2023 open';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
    VALUES (
        '01900000-0000-7000-8000-000000000034',
        '01900000-0000-7000-8000-000000000001',
        '2022-01-01',
        '2022-12-31',
        'open'
    );
    RAISE EXCEPTION 'a period before a closed year was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM <> 'a period cannot start before a closed period' THEN
            RAISE;
        END IF;
END $$;

DO $$
DECLARE
    period_status text;
BEGIN
    SET LOCAL ROLE stall_poster;
    PERFORM stall_close_quiet_period('01900000-0000-7000-8000-000000000031');
    RESET ROLE;
    SELECT status INTO period_status
    FROM accounting_periods
    WHERE id = '01900000-0000-7000-8000-000000000031';
    IF period_status <> 'closed' THEN
        RAISE EXCEPTION 'quiet close left an empty year open';
    END IF;
END $$;

DO $$
BEGIN
    PERFORM stall_close_quiet_period('01900000-0000-7000-8000-000000000031');
    RAISE EXCEPTION 'second quiet close was accepted';
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
        '01900000-0000-7000-8000-000000000e15',
        '01900000-0000-7000-8000-000000000001',
        'adjustment:after-quiet',
        '2024-06-01',
        '2024-06-01 12:00:00+00',
        '01900000-0000-7000-8000-000000000031',
        'USD', 'adjustment', 'after-quiet', 'Journal in a quiet closed year', 100, 100
    );
    RAISE EXCEPTION 'journal in a quiet closed year was accepted';
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
        '01900000-0000-7000-8000-000000000e18',
        '01900000-0000-7000-8000-000000000001',
        'sale:wiped',
        '2025-02-01',
        '2025-02-01 12:00:00+00',
        '01900000-0000-7000-8000-000000000032',
        'USD', 'sale', 'wiped', 'Sale wiped by an adjustment', 1000, 1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000e18',
        '01900000-0000-7000-8000-000000000001',
        1, '01900000-0000-7000-8000-000000000010', 1000, 0
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000e18',
        '01900000-0000-7000-8000-000000000001',
        2, '01900000-0000-7000-8000-000000000012', 0, 800
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000e18',
        '01900000-0000-7000-8000-000000000001',
        3, '01900000-0000-7000-8000-000000000014', 0, 200, 'vendor', '01900000-0000-7000-8000-000000000101'
    );
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000e19',
        '01900000-0000-7000-8000-000000000001',
        'adjustment:wipe-commission',
        '2025-02-02',
        '2025-02-02 12:00:00+00',
        '01900000-0000-7000-8000-000000000032',
        'USD', 'adjustment', 'wipe-commission', 'Wipe commission', 800, 800
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-000000000e19', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000012', 800, 0),
        ('01900000-0000-7000-8000-000000000e19', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 800);
    PERFORM stall_close_quiet_period('01900000-0000-7000-8000-000000000032');
    RAISE EXCEPTION 'quiet close after an adjustment wiped commission was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM <> 'a period with income or expense closes by a journal' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000e11',
    '01900000-0000-7000-8000-000000000001',
    'sale:voided-year',
    '2025-04-01',
    '2025-04-01 12:00:00+00',
    '01900000-0000-7000-8000-000000000032',
    'USD', 'sale', 'voided-year', 'Sale later voided', 1000, 1000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000e11',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 1000, 0
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000e11',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000012', 0, 800
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000e11',
    '01900000-0000-7000-8000-000000000001',
    3, '01900000-0000-7000-8000-000000000014', 0, 200, 'vendor', '01900000-0000-7000-8000-000000000101'
);
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
    debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000e12',
    '01900000-0000-7000-8000-000000000001',
    'sale:voided-year:void',
    '2025-04-02',
    '2025-04-02 12:00:00+00',
    '01900000-0000-7000-8000-000000000032',
    'USD', 'sale', 'voided-year-void', 'Void the sale', true,
    '01900000-0000-7000-8000-000000000e11',
    1000, 1000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000e12', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 0, 1000),
    ('01900000-0000-7000-8000-000000000e12', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 800, 0);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000e12',
    '01900000-0000-7000-8000-000000000001',
    3, '01900000-0000-7000-8000-000000000014', 200, 0, 'vendor', '01900000-0000-7000-8000-000000000101'
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

SELECT stall_close_quiet_period('01900000-0000-7000-8000-000000000032');

DO $$
DECLARE
    period_status text;
BEGIN
    SELECT status INTO period_status
    FROM accounting_periods
    WHERE id = '01900000-0000-7000-8000-000000000032';
    IF period_status <> 'closed' THEN
        RAISE EXCEPTION 'quiet close left a voided year open';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000e16',
        '01900000-0000-7000-8000-000000000001',
        'adjustment:after-void-close',
        '2025-06-01',
        '2025-06-01 12:00:00+00',
        '01900000-0000-7000-8000-000000000032',
        'USD', 'adjustment', 'after-void-close', 'Journal in a voided closed year', 100, 100
    );
    RAISE EXCEPTION 'journal in a voided closed year was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000e01',
    '01900000-0000-7000-8000-000000000001',
    'sale:close-1',
    '2026-03-01',
    '2026-03-01 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'sale', 'close-1', 'Sale for the close', 1000, 1000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000e01',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 1000, 0
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000e01',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000012', 0, 800
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000e01',
    '01900000-0000-7000-8000-000000000001',
    3, '01900000-0000-7000-8000-000000000014', 0, 200, 'vendor', '01900000-0000-7000-8000-000000000101'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000e02',
    '01900000-0000-7000-8000-000000000001',
    'adjustment:close-fee',
    '2026-03-02',
    '2026-03-02 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'adjustment', 'close-fee', 'Expense before the close', 300, 300
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000e02', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000018', 300, 0),
    ('01900000-0000-7000-8000-000000000e02', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 300);

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000e07',
        '01900000-0000-7000-8000-000000000001',
        'period-close:01900000-0000-7000-8000-000000000021',
        '2026-12-31',
        '2026-12-31 22:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD', 'period_close', '2026-short', 'Short close', 800, 800
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-000000000e07', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000012', 800, 0),
        ('01900000-0000-7000-8000-000000000e07', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000019', 0, 800);
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a short period close was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000e08',
        '01900000-0000-7000-8000-000000000001',
        'period-close:other',
        '2026-12-31',
        '2026-12-31 22:10:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD', 'period_close', '2026-other', 'Wrong close key', 800, 800
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-000000000e08', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000012', 800, 0),
        ('01900000-0000-7000-8000-000000000e08', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000018', 0, 300),
        ('01900000-0000-7000-8000-000000000e08', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000019', 0, 500);
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a period close with another posting key was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000e09',
        '01900000-0000-7000-8000-000000000001',
        'adjustment:retained',
        '2026-03-03',
        '2026-03-03 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD', 'adjustment', 'retained', 'Adjustment into retained earnings', 100, 100
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-000000000e09', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000011', 100, 0),
        ('01900000-0000-7000-8000-000000000e09', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000019', 0, 100);
    RAISE EXCEPTION 'an adjustment to retained earnings was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    PERFORM stall_close_quiet_period('01900000-0000-7000-8000-000000000021');
    RAISE EXCEPTION 'quiet close of a year with income was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM <> 'a period with income or expense closes by a journal' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000e03',
    '01900000-0000-7000-8000-000000000001',
    'period-close:01900000-0000-7000-8000-000000000021',
    '2026-12-31',
    '2026-12-31 23:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'period_close', '2026', 'Close 2026', 800, 800
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000e03', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000012', 800, 0),
    ('01900000-0000-7000-8000-000000000e03', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000018', 0, 300),
    ('01900000-0000-7000-8000-000000000e03', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000019', 0, 500);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    period_status text;
    retained_credit bigint;
BEGIN
    SELECT status INTO period_status
    FROM accounting_periods
    WHERE id = '01900000-0000-7000-8000-000000000021';
    IF period_status <> 'closed' THEN
        RAISE EXCEPTION 'period close left the period open';
    END IF;

    SELECT credit_minor INTO retained_credit
    FROM export_lines
    WHERE posting_key = 'period-close:01900000-0000-7000-8000-000000000021'
      AND account_code = '3000';
    IF retained_credit <> 500 THEN
        RAISE EXCEPTION 'export did not show the closing retained earnings';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000e04',
        '01900000-0000-7000-8000-000000000001',
        'period-close:01900000-0000-7000-8000-000000000021',
        '2026-12-31',
        '2026-12-31 23:30:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD', 'period_close', '2026-again', 'Second close', 100, 100
    );
    RAISE EXCEPTION 'second period close was accepted';
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
        '01900000-0000-7000-8000-000000000e05',
        '01900000-0000-7000-8000-000000000001',
        'sale:after-close',
        '2026-06-01',
        '2026-06-01 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD', 'sale', 'after-close', 'Sale in a closed period', 100, 100
    );
    RAISE EXCEPTION 'journal in a closed period was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
        debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000e06',
        '01900000-0000-7000-8000-000000000001',
        'period-close:01900000-0000-7000-8000-000000000021:void',
        '2027-01-02',
        '2027-01-02 12:00:00+00',
        '01900000-0000-7000-8000-000000000022',
        'USD',
        'period_close',
        '2026-void',
        'Reverse the 2026 close',
        true,
        '01900000-0000-7000-8000-000000000e03',
        800,
        800
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-000000000e06', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000012', 0, 800),
        ('01900000-0000-7000-8000-000000000e06', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000018', 300, 0),
        ('01900000-0000-7000-8000-000000000e06', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000019', 500, 0);
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'period close reversal was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM <> 'a period close is not reversible' THEN
            RAISE;
        END IF;
END $$;

SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
        debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000e07',
        '01900000-0000-7000-8000-000000000001',
        'adjustment:undo-close',
        '2027-01-03',
        '2027-01-03 12:00:00+00',
        '01900000-0000-7000-8000-000000000022',
        'USD',
        'adjustment',
        'undo-close',
        'Adjustment reversing the 2026 close',
        true,
        '01900000-0000-7000-8000-000000000e03',
        800,
        800
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-000000000e07', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000018', 800, 0),
        ('01900000-0000-7000-8000-000000000e07', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 800);
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'adjustment reversal of a period close was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM <> 'a period close is not reversible' THEN
            RAISE;
        END IF;
END $$;

SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    period_status text;
    retained_balance bigint;
BEGIN
    SELECT status INTO period_status
    FROM accounting_periods
    WHERE id = '01900000-0000-7000-8000-000000000021';
    IF period_status <> 'closed' THEN
        RAISE EXCEPTION 'reversal reopened the closed period';
    END IF;

    SELECT balance_minor INTO retained_balance
    FROM account_balances
    WHERE account_id = '01900000-0000-7000-8000-000000000019';
    IF retained_balance IS DISTINCT FROM 500 THEN
        RAISE EXCEPTION 'retained earnings after a refused close reversal is %', retained_balance;
    END IF;
END $$;

ROLLBACK;
