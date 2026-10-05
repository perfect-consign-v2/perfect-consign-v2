-- Proof for the vendor statement. One transaction, then ROLLBACK.
-- The statement lines, excluding the payout, must equal the payout journal.
-- If they disagree, the schema is wrong.

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
VALUES ('01900000-0000-7000-8000-000000000201', '2026-01-01', 1500);

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
    '01900000-0000-7000-8000-000000000901',
    '01900000-0000-7000-8000-000000000001',
    'sale:1001',
    '2026-03-10',
    '2026-03-10 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'sale',
    '1001',
    'Cash sale 1001',
    7200,
    7200
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000901',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000010',
    7200,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000901',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000014',
    0,
    7200,
    'vendor',
    '01900000-0000-7000-8000-000000000101'
);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES (
    '01900000-0000-7000-8000-0000000009a1',
    'Coat',
    '01900000-0000-7000-8000-000000000101',
    'Wool coat',
    7200,
    'BC-STATEMENT',
    DATE '2026-03-10',
    0
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-000000000901', 1, '01900000-0000-7000-8000-0000000009a1', 7200, 0, 0);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000902',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-000000000201:2026-03',
    '2026-03-01',
    '2026-03-01 08:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'rent_charge',
    'A1:2026-03',
    'March rent for booth A1',
    1500,
    1500
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000902',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000015',
    1500,
    0,
    'booth',
    '01900000-0000-7000-8000-000000000201'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000902',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000017',
    0,
    1500
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000903',
    '01900000-0000-7000-8000-000000000001',
    'settlement:01900000-0000-7000-8000-0000000009a0:01900000-0000-7000-8000-000000000101',
    '2026-03-31',
    '2026-03-31 18:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'settlement',
    '01900000-0000-7000-8000-000000000101',
    'March settlement for Ada',
    1500,
    1500
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    (
        '01900000-0000-7000-8000-000000000903',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000014',
        1500,
        0,
        'vendor',
        '01900000-0000-7000-8000-000000000101'
    ),
    (
        '01900000-0000-7000-8000-000000000903',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000015',
        0,
        1500,
        'booth',
        '01900000-0000-7000-8000-000000000201'
    );

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000904',
    '01900000-0000-7000-8000-000000000001',
    'disbursement:01900000-0000-7000-8000-0000000009a0:01900000-0000-7000-8000-000000000101',
    '2026-03-31',
    '2026-03-31 18:01:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'check_disbursement',
    '01900000-0000-7000-8000-000000000101',
    'March check for Ada',
    5700,
    5700
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000904',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000014',
    5700,
    0,
    'vendor',
    '01900000-0000-7000-8000-000000000101'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000904',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000011',
    0,
    5700
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

DO $$
DECLARE
    statement_payable bigint;
    statement_rent bigint;
    payout_payable bigint;
    payout_rent bigint;
    statement_rows integer;
    open_payable bigint;
    open_rent bigint;
BEGIN
    SELECT count(*) INTO statement_rows
    FROM vendor_statement
    WHERE vendor_id = '01900000-0000-7000-8000-000000000101';

    IF statement_rows <> 5 THEN
        RAISE EXCEPTION 'statement row count was %', statement_rows;
    END IF;

    IF (
        SELECT array_agg(posting_key ORDER BY posting_date, occurred_at, journal_no, line_no)
        FROM vendor_statement
        WHERE vendor_id = '01900000-0000-7000-8000-000000000101'
    ) <> ARRAY[
        'rent-charge:01900000-0000-7000-8000-000000000201:2026-03',
        'sale:1001',
        'settlement:01900000-0000-7000-8000-0000000009a0:01900000-0000-7000-8000-000000000101',
        'settlement:01900000-0000-7000-8000-0000000009a0:01900000-0000-7000-8000-000000000101',
        'disbursement:01900000-0000-7000-8000-0000000009a0:01900000-0000-7000-8000-000000000101'
    ] THEN
        RAISE EXCEPTION 'statement lines are out of order';
    END IF;

    SELECT
        COALESCE(sum(credit_minor - debit_minor) FILTER (WHERE role = 'vendor_payable' AND source_type NOT IN ('settlement', 'check_disbursement', 'cash_disbursement', 'safe_disbursement')), 0),
        COALESCE(sum(debit_minor - credit_minor) FILTER (WHERE role = 'booth_rent_receivable' AND source_type NOT IN ('settlement', 'check_disbursement', 'cash_disbursement', 'safe_disbursement')), 0),
        COALESCE(sum(credit_minor - debit_minor) FILTER (WHERE role = 'vendor_payable'), 0),
        COALESCE(sum(debit_minor - credit_minor) FILTER (WHERE role = 'booth_rent_receivable'), 0)
    INTO statement_payable, statement_rent, open_payable, open_rent
    FROM vendor_statement
    WHERE vendor_id = '01900000-0000-7000-8000-000000000101';

    SELECT debit_minor INTO payout_payable
    FROM vendor_statement
    WHERE journal_id = '01900000-0000-7000-8000-000000000903'
      AND role = 'vendor_payable';

    SELECT credit_minor INTO payout_rent
    FROM vendor_statement
    WHERE journal_id = '01900000-0000-7000-8000-000000000903'
      AND role = 'booth_rent_receivable';

    IF payout_payable <> 1500 OR payout_rent <> 1500 OR statement_payable <> 7200 OR statement_rent <> 1500 THEN
        RAISE EXCEPTION 'statement disagrees with the payout journal';
    END IF;

    IF open_payable <> 0 OR open_rent <> 0 THEN
        RAISE EXCEPTION 'statement still shows an open balance after the payout';
    END IF;

    IF statement_payable - statement_rent <> 5700 THEN
        RAISE EXCEPTION 'statement net does not equal the payout cash';
    END IF;

    BEGIN
        UPDATE booth_assignments
        SET ends_on = '2026-02-01'
        WHERE booth_id = '01900000-0000-7000-8000-000000000201'
          AND vendor_id = '01900000-0000-7000-8000-000000000101';
        RAISE EXCEPTION 'assignment end uncovered posted rent';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            NULL;
    END;

    BEGIN
        DELETE FROM booth_assignments
        WHERE booth_id = '01900000-0000-7000-8000-000000000201'
          AND vendor_id = '01900000-0000-7000-8000-000000000101';
        RAISE EXCEPTION 'assignment delete uncovered posted rent';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            NULL;
    END;

    IF (
        SELECT COALESCE(sum(debit_minor - credit_minor), 0)
        FROM vendor_statement
        WHERE vendor_id = '01900000-0000-7000-8000-000000000102'
          AND role = 'booth_rent_receivable'
    ) <> 0 THEN
        RAISE EXCEPTION 'the next vendor inherited posted rent';
    END IF;
END $$;

ROLLBACK;
