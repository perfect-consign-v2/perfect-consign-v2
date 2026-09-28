-- Proof for the export query. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d22',
    '01900000-0000-7000-8000-000000000d10',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane');

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000201', 'A1', 'Booth A1');

INSERT INTO booth_assignments (booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-000000000201',
    '01900000-0000-7000-8000-000000000101',
    '2026-01-01'
);

INSERT INTO booth_rent_rates (booth_id, month_start, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000201', '2026-03-01', 10000);

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

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000d02',
    '01900000-0000-7000-8000-000000000001',
    'sale:1001',
    '2026-03-02',
    '2026-03-02 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'sale', '1001', 'March sale', 1000, 1000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000d02', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 1000, 0),
    ('01900000-0000-7000-8000-000000000d02', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 200),
    ('01900000-0000-7000-8000-000000000d02', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000013', 0, 80);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000d02',
    '01900000-0000-7000-8000-000000000001',
    4, '01900000-0000-7000-8000-000000000014', 0, 720, 'vendor', '01900000-0000-7000-8000-000000000101'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000d03',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-000000000201:2026-03',
    '2026-03-01',
    '2026-03-01 08:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'rent_charge', 'A1:2026-03', 'March rent', 10000, 10000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000d03',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000015', 10000, 0, 'booth', '01900000-0000-7000-8000-000000000201'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d03',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000017', 0, 10000
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000d04',
    '01900000-0000-7000-8000-000000000001',
    'tax-payment:t1',
    '2026-03-03',
    '2026-03-03 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'tax_payment', 't1', 'Pay sales tax', 80, 80
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000d04', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000013', 80, 0),
    ('01900000-0000-7000-8000-000000000d04', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000010', 0, 80);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000d05',
    '01900000-0000-7000-8000-000000000001',
    'adjustment:bank-fee',
    '2026-03-04',
    '2026-03-04 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'adjustment', 'bank-fee', 'Bank fee', 100, 100
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000d05', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000018', 100, 0),
    ('01900000-0000-7000-8000-000000000d05', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 100);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000d06',
    '01900000-0000-7000-8000-000000000001',
    'payout:01900000-0000-7000-8000-000000000101:2026-03',
    '2026-03-31',
    '2026-03-31 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'payout', '2026-03', 'March payout', 20720, 20720
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000d06',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 20720, 0, 'vendor', '01900000-0000-7000-8000-000000000101'
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000d06',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000015', 0, 10000, 'booth', '01900000-0000-7000-8000-000000000201'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d06',
    '01900000-0000-7000-8000-000000000001',
    3, '01900000-0000-7000-8000-000000000010', 0, 10720
);

INSERT INTO register_session_posts (session_id, journal_id)
SELECT '01900000-0000-7000-8000-000000000d22', journal.id
FROM journals AS journal
WHERE journal.source_type IN (
    'sale', 'rent_payment', 'payout', 'gift_certificate_issue', 'gift_card_issue',
    'store_credit_issue', 'cash_drop', 'register_variance'
)
  AND NOT EXISTS (
      SELECT 1 FROM register_session_posts AS post WHERE post.journal_id = journal.id
  );
SET CONSTRAINTS ALL IMMEDIATE;

DO $$
DECLARE
    ordered_keys text[];
    map_count integer;
BEGIN
    SELECT array_agg(posting_key ORDER BY posting_date, journal_no, line_no)
    INTO ordered_keys
    FROM export_lines;

    IF ordered_keys <> ARRAY[
        'opening',
        'opening',
        'rent-charge:01900000-0000-7000-8000-000000000201:2026-03',
        'rent-charge:01900000-0000-7000-8000-000000000201:2026-03',
        'sale:1001',
        'sale:1001',
        'sale:1001',
        'sale:1001',
        'tax-payment:t1',
        'tax-payment:t1',
        'adjustment:bank-fee',
        'adjustment:bank-fee',
        'payout:01900000-0000-7000-8000-000000000101:2026-03',
        'payout:01900000-0000-7000-8000-000000000101:2026-03',
        'payout:01900000-0000-7000-8000-000000000101:2026-03'
    ] THEN
        RAISE EXCEPTION 'export lines are not the posted journals';
    END IF;

    IF (
        SELECT debit_minor FROM export_lines
        WHERE posting_key = 'tax-payment:t1' AND account_code = '2100'
    ) <> 80 THEN
        RAISE EXCEPTION 'export tax payment amount is wrong';
    END IF;

    SELECT count(*) INTO map_count FROM export_accounts;
    IF map_count <> (SELECT count(*) FROM chart_roles) * 2 THEN
        RAISE EXCEPTION 'export account map does not cover both systems';
    END IF;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    BEGIN
        INSERT INTO export_accounts (system, role, external_code)
        VALUES ('quickbooks', 'cash', '1000');
        RAISE EXCEPTION 'poster insert into export accounts was accepted';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    RESET ROLE;
END $$;

ROLLBACK;
