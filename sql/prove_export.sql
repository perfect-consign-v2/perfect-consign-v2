-- Proof for the export query. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-000000000d23', 'Export proof');

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

UPDATE mall SET tax_bps = 8000;

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000201', 'A1', 'Booth A1');

INSERT INTO booth_assignments (booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-000000000201',
    '01900000-0000-7000-8000-000000000101',
    '2026-01-01'
);

INSERT INTO booth_rent_rates (booth_id, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000201', 10000);
INSERT INTO assignment_rent (assignment_id, amount_minor)
SELECT assignment.id, rate.amount_minor
FROM booth_assignments AS assignment
JOIN booth_rent_rates AS rate ON rate.booth_id = assignment.booth_id
ON CONFLICT (assignment_id) DO NOTHING;

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
    'USD', 'sale', '1001', 'March sale', 1080, 1080
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000d02', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 1080, 0),
    ('01900000-0000-7000-8000-000000000d02', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 200),
    ('01900000-0000-7000-8000-000000000d02', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000013', 0, 80);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000d02',
    '01900000-0000-7000-8000-000000000001',
    4, '01900000-0000-7000-8000-000000000014', 0, 800, 'vendor', '01900000-0000-7000-8000-000000000101'
);
INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES (
    '01900000-0000-7000-8000-000000000da1',
    'Coat',
    '01900000-0000-7000-8000-000000000101',
    'Wool coat',
    1000,
    'BC-EXPORT',
    DATE '2026-03-02',
    2000
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-000000000d02', 1, '01900000-0000-7000-8000-000000000da1', 1000, 200, 80);

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
    ('01900000-0000-7000-8000-000000000d04', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000011', 0, 80);

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
    'settlement:01900000-0000-7000-8000-000000000d90:01900000-0000-7000-8000-000000000101',
    '2026-03-31',
    '2026-03-31 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'settlement', '01900000-0000-7000-8000-000000000101', 'March settlement', 10000, 10000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000d06',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 10000, 0, 'vendor', '01900000-0000-7000-8000-000000000101'
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000d06',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000015', 0, 10000, 'booth', '01900000-0000-7000-8000-000000000201'
);
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000d91',
    '01900000-0000-7000-8000-000000000001',
    'disbursement:01900000-0000-7000-8000-000000000d90:01900000-0000-7000-8000-000000000101',
    '2026-03-31',
    '2026-03-31 12:01:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'check_disbursement', '01900000-0000-7000-8000-000000000101', 'March check', 10800, 10800
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000d91',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 10800, 0, 'vendor', '01900000-0000-7000-8000-000000000101'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d91',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000011', 0, 10800
);

INSERT INTO register_session_posts (session_id, journal_id)
SELECT '01900000-0000-7000-8000-000000000d22', journal.id
FROM journals AS journal
WHERE journal.source_type IN (
    'sale', 'rent_payment', 'cash_disbursement', 'gift_certificate_issue', 'gift_card_issue',
    'store_credit_issue', 'cash_drop', 'register_variance'
)
  AND NOT EXISTS (
      SELECT 1 FROM register_session_posts AS post WHERE post.journal_id = journal.id
  );
SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

UPDATE mall SET tax_bps = 0, cash_round_minor = 5;

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES (
    '01900000-0000-7000-8000-000000000da2',
    'Round',
    '01900000-0000-7000-8000-000000000101',
    'Rounds down',
    1002,
    'BC-EXPORT-ROUND',
    DATE '2026-03-05',
    0
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000d07',
    '01900000-0000-7000-8000-000000000001',
    'sale:export-round',
    '2026-03-05',
    '2026-03-05 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'sale', 'export-round', 'Cash rounds down', 1002, 1002
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000d07', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 1000, 0),
    ('01900000-0000-7000-8000-000000000d07', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000020', 2, 0);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000d07',
    '01900000-0000-7000-8000-000000000001',
    3, '01900000-0000-7000-8000-000000000014', 0, 1002, 'vendor', '01900000-0000-7000-8000-000000000101'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-000000000d07', 1, '01900000-0000-7000-8000-000000000da2', 1002, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000d07');

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity)
VALUES (
    '01900000-0000-7000-8000-000000000da3',
    'Lay',
    '01900000-0000-7000-8000-000000000101',
    'Held coat',
    1000,
    'BC-EXPORT-LAY',
    DATE '2026-03-06',
    1
);

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-000000000d72', '01900000-0000-7000-8000-000000000d22');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d72',
    1,
    '01900000-0000-7000-8000-000000000da3',
    1000
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000d08',
    '01900000-0000-7000-8000-000000000001',
    'layaway:export-deposit',
    '2026-03-06',
    '2026-03-06 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'layaway_deposit', '01900000-0000-7000-8000-000000000d72', 'Export deposit', 400, 400
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d08',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 400, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000d08',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000021', 0, 400, 'ticket', '01900000-0000-7000-8000-000000000d72'
);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000d08');
UPDATE tickets SET status = 'layaway' WHERE id = '01900000-0000-7000-8000-000000000d72';
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
        'sale:export-round',
        'sale:export-round',
        'sale:export-round',
        'layaway:export-deposit',
        'layaway:export-deposit',
        'settlement:01900000-0000-7000-8000-000000000d90:01900000-0000-7000-8000-000000000101',
        'settlement:01900000-0000-7000-8000-000000000d90:01900000-0000-7000-8000-000000000101',
        'disbursement:01900000-0000-7000-8000-000000000d90:01900000-0000-7000-8000-000000000101',
        'disbursement:01900000-0000-7000-8000-000000000d90:01900000-0000-7000-8000-000000000101'
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

    IF NOT EXISTS (
        SELECT 1
        FROM stall_export_journal_csv('quickbooks', DATE '2026-03-01', DATE '2026-03-31') AS line
        WHERE line LIKE '%6160%'
    ) THEN
        RAISE EXCEPTION 'a cash-rounded sale did not export';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM stall_export_journal_csv('xero', DATE '2026-03-01', DATE '2026-03-31') AS line
        WHERE line LIKE '%2230%'
    ) THEN
        RAISE EXCEPTION 'a layaway deposit did not export';
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
