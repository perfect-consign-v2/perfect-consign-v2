-- Proof for the opening journal. One transaction, then ROLLBACK.

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

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000c12',
        '01900000-0000-7000-8000-000000000001',
        'adjustment:before-opening',
        '2026-01-01',
        '2026-01-01 00:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'adjustment',
        'before-opening',
        'Journal before the first opening',
        100,
        100
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-000000000c12', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 100, 0),
        ('01900000-0000-7000-8000-000000000c12', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 100);
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000c13',
        '01900000-0000-7000-8000-000000000001',
        'opening',
        '2026-01-01',
        '2026-01-01 01:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'opening',
        'opening-late',
        'Opening after another journal',
        100,
        100
    );
    RAISE EXCEPTION 'first opening was accepted after another journal';
EXCEPTION
    WHEN unique_violation THEN
        IF SQLERRM NOT LIKE '%journals_book_key%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000c01',
    '01900000-0000-7000-8000-000000000001',
    'opening',
    '2026-01-01',
    '2026-01-01 00:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'opening',
    'opening',
    'Opening balances',
    5000,
    5000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000c01',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000010',
    5000,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000c01',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000014',
    0,
    5000,
    'vendor',
    '01900000-0000-7000-8000-000000000101'
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    payable bigint;
BEGIN
    SELECT COALESCE(sum(credit_minor - debit_minor), 0) INTO payable
    FROM vendor_statement
    WHERE vendor_id = '01900000-0000-7000-8000-000000000101'
      AND role = 'vendor_payable';
    IF payable <> 5000 THEN
        RAISE EXCEPTION 'statement did not pick up the opening payable';
    END IF;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000c02',
        '01900000-0000-7000-8000-000000000001',
        'opening',
        '2026-01-02',
        '2026-01-02 00:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'opening',
        'opening-2',
        'Second opening',
        100,
        100
    );
    RAISE EXCEPTION 'second opening was accepted';
EXCEPTION
    WHEN unique_violation THEN
        IF SQLERRM NOT LIKE '%journals_book_key%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
    debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000c03',
    '01900000-0000-7000-8000-000000000001',
    'opening:void',
    '2026-01-01',
    '2026-01-01 01:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'opening',
    'opening-void',
    'Void opening balances',
    true,
    '01900000-0000-7000-8000-000000000c01',
    5000,
    5000
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000c03',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000014',
    5000,
    0,
    'vendor',
    '01900000-0000-7000-8000-000000000101'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000c03',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000010',
    0,
    5000
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    payable bigint;
BEGIN
    SELECT COALESCE(sum(credit_minor - debit_minor), 0) INTO payable
    FROM vendor_statement
    WHERE vendor_id = '01900000-0000-7000-8000-000000000101'
      AND role = 'vendor_payable';
    IF payable <> 0 THEN
        RAISE EXCEPTION 'reversed opening still has a payable';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000c06',
        '01900000-0000-7000-8000-000000000001',
        'adjustment:block-opening',
        '2026-01-03',
        '2026-01-03 00:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'adjustment',
        'block-opening',
        'Journal before a replacement opening',
        100,
        100
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-000000000c06', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 100, 0),
        ('01900000-0000-7000-8000-000000000c06', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 100);
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000c07',
        '01900000-0000-7000-8000-000000000001',
        'opening',
        '2026-01-03',
        '2026-01-03 01:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'opening',
        'opening-blocked',
        'Opening after another journal',
        100,
        100
    );
    RAISE EXCEPTION 'replacement opening was accepted after another journal';
EXCEPTION
    WHEN unique_violation THEN
        IF SQLERRM NOT LIKE '%journals_book_key%' THEN
            RAISE;
        END IF;
END $$;

SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000c08',
        '01900000-0000-7000-8000-000000000001',
        'opening-again',
        '2026-01-02',
        '2026-01-02 00:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'opening',
        'opening-again',
        'Opening under another key',
        100,
        100
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-000000000c08', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 100, 0),
        ('01900000-0000-7000-8000-000000000c08', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 100);
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'opening key was not opening';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM <> 'an opening posting key is opening' THEN
            RAISE;
        END IF;
END $$;

SET CONSTRAINTS ALL DEFERRED;

SET LOCAL ROLE stall_poster;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000c04',
    '01900000-0000-7000-8000-000000000001',
    'opening',
    '2026-01-02',
    '2026-01-02 00:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'opening',
    'opening-corrected',
    'Corrected opening balances',
    4000,
    4000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000c04',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000010',
    4000,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000c04',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000014',
    0,
    4000,
    'vendor',
    '01900000-0000-7000-8000-000000000101'
);

RESET ROLE;

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
DECLARE
    payable bigint;
BEGIN
    SELECT COALESCE(sum(credit_minor - debit_minor), 0) INTO payable
    FROM vendor_statement
    WHERE vendor_id = '01900000-0000-7000-8000-000000000101'
      AND role = 'vendor_payable';
    IF payable <> 4000 THEN
        RAISE EXCEPTION 'corrected opening payable is %', payable;
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000c05',
        '01900000-0000-7000-8000-000000000001',
        'opening',
        '2026-01-04',
        '2026-01-04 00:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'opening',
        'opening-3',
        'Third opening',
        100,
        100
    );
    RAISE EXCEPTION 'third opening was accepted';
EXCEPTION
    WHEN unique_violation THEN
        IF SQLERRM NOT LIKE '%journals_book_key%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
        debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000c09',
        '01900000-0000-7000-8000-000000000001',
        'opening:void-corrected',
        '2026-01-04',
        '2026-01-04 01:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'opening',
        'opening-void-corrected',
        'Void corrected opening',
        true,
        '01900000-0000-7000-8000-000000000c04',
        4000,
        4000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000c09',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000014',
        4000,
        0,
        'vendor',
        '01900000-0000-7000-8000-000000000101'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000c09',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000010',
        0,
        4000
    );
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000c10',
        '01900000-0000-7000-8000-000000000001',
        'adjustment:after-corrected-opening',
        '2026-01-05',
        '2026-01-05 00:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'adjustment',
        'after-corrected-opening',
        'Journal after the corrected opening',
        100,
        100
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-000000000c10', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 100, 0),
        ('01900000-0000-7000-8000-000000000c10', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 100);
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000c11',
        '01900000-0000-7000-8000-000000000001',
        'opening',
        '2026-01-05',
        '2026-01-05 01:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'opening',
        'opening-after-activity',
        'Opening after the corrected opening was voided',
        100,
        100
    );
    RAISE EXCEPTION 'opening was accepted after a void and another journal';
EXCEPTION
    WHEN unique_violation THEN
        IF SQLERRM NOT LIKE '%journals_book_key%' THEN
            RAISE;
        END IF;
END $$;

ROLLBACK;
