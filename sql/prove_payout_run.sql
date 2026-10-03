-- Locks: a settled window, net columns, and a unique disbursement check number.

BEGIN;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-00000000f201', 'lock', 'Cal', 'Lane');

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-00000000f301', 'L1', 'Lock booth');

INSERT INTO booth_assignments (booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-00000000f301',
    '01900000-0000-7000-8000-00000000f201',
    '2032-01-01'
);

INSERT INTO booth_rent_rates (booth_id, month_start, amount_minor)
VALUES ('01900000-0000-7000-8000-00000000f301', '2032-03-01', 1000);

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000f022',
    '01900000-0000-7000-8000-000000000001',
    '2032-01-01',
    '2032-12-31',
    'open'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f811',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-00000000f301:2032-03',
    '2032-03-01',
    '2032-03-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000f022',
    'USD', 'rent_charge', '01900000-0000-7000-8000-00000000f301', 'March rent', 1000, 1000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f811',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000015', 1000, 0, 'booth', '01900000-0000-7000-8000-00000000f301'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f811',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000017', 0, 1000
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f812',
    '01900000-0000-7000-8000-000000000001',
    'store-purchase:lock-1',
    '2032-03-10',
    '2032-03-10 12:00:00+00',
    '01900000-0000-7000-8000-00000000f022',
    'USD', 'store_purchase', '01900000-0000-7000-8000-00000000f201', 'Lock purchase', 4000, 4000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f812',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000022', 4000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f812',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 4000, 'vendor', '01900000-0000-7000-8000-00000000f201'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f813',
    '01900000-0000-7000-8000-000000000001',
    'settlement:01900000-0000-7000-8000-00000000f901:01900000-0000-7000-8000-00000000f201',
    '2032-04-01',
    '2032-04-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000f022',
    'USD', 'settlement', '01900000-0000-7000-8000-00000000f201', 'Close', 1000, 1000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-00000000f813', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000014', 1000, 0, 'vendor', '01900000-0000-7000-8000-00000000f201'),
    ('01900000-0000-7000-8000-00000000f813', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000015', 0, 1000, 'booth', '01900000-0000-7000-8000-00000000f301');

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000f814',
    '01900000-0000-7000-8000-000000000001',
    'disbursement:01900000-0000-7000-8000-00000000f901:01900000-0000-7000-8000-00000000f201',
    '2032-04-01',
    '2032-04-01 12:01:00+00',
    '01900000-0000-7000-8000-00000000f022',
    'USD', 'check_disbursement', '01900000-0000-7000-8000-00000000f201', 'Check', 3000, 3000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000f814',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 3000, 0, 'vendor', '01900000-0000-7000-8000-00000000f201'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000f814',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000011', 0, 3000
);
INSERT INTO register_checks (journal_id, check_number) VALUES ('01900000-0000-7000-8000-00000000f814', '1402');

INSERT INTO payout_runs (
    id, book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date, reviewed_checksum
) VALUES (
    '01900000-0000-7000-8000-00000000f901',
    '01900000-0000-7000-8000-000000000001',
    'batch', 'monthly', '2032-03-01', '2032-03-31', '2032-03-01', 'check', 'draft', '2032-04-01', 'lock'
);

SET CONSTRAINTS ALL IMMEDIATE;
UPDATE payout_runs SET status = 'settled', settled_at = clock_timestamp() WHERE id = '01900000-0000-7000-8000-00000000f901';

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000f815',
        '01900000-0000-7000-8000-000000000001',
        'adjustment:lock-backdated',
        '2032-03-20',
        '2032-04-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000f022',
        'USD', 'adjustment', 'backdated', 'Backdated', 100, 100
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-00000000f815', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000018', 100, 0),
        ('01900000-0000-7000-8000-00000000f815', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000010', 0, 100);
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a settled window accepted a journal';
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
        '01900000-0000-7000-8000-00000000f816',
        '01900000-0000-7000-8000-000000000001',
        'disbursement:other:01900000-0000-7000-8000-00000000f201',
        '2032-04-02',
        '2032-04-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000f022',
        'USD', 'check_disbursement', '01900000-0000-7000-8000-00000000f201', 'Second check', 100, 100
    );
    INSERT INTO register_checks (journal_id, check_number) VALUES ('01900000-0000-7000-8000-00000000f816', '1402');
    RAISE EXCEPTION 'a duplicate disbursement check number was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

ROLLBACK;
