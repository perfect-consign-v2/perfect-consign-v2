-- Offline snap mint keeps ticket discounts; waiting snap refuses a zero-tax poster sale.

BEGIN;

SELECT stall_seed_vendor(
    '01900000-0000-7000-8000-00000000a501',
    'snapd',
    'Snap',
    'Discount',
    tax_exempt => true,
    tax_exempt_number => 'TX-SNAPD'
);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity, commission_bps)
VALUES (
    '01900000-0000-7000-8000-00000000a511',
    'SnapCoat',
    '01900000-0000-7000-8000-00000000a501',
    'Snap coat',
    10000,
    'BC-SNAPCOAT',
    DATE '2096-03-01',
    2,
    5000
);

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000a520',
    '01900000-0000-7000-8000-000000000001',
    '2096-01-01',
    '2096-12-31',
    'open'
);

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-00000000a521', 'Snap discount register');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-00000000a522',
    '01900000-0000-7000-8000-00000000a521',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO tickets (id, session_id, discount_kind, discount_value)
VALUES (
    '01900000-0000-7000-8000-00000000a531',
    '01900000-0000-7000-8000-00000000a522',
    'amount',
    1000
);

INSERT INTO ticket_lines (
    ticket_id, line_no, item_id, price_minor, quantity,
    line_discount_kind, line_discount_value, line_discount_reason
) VALUES (
    '01900000-0000-7000-8000-00000000a531',
    1,
    '01900000-0000-7000-8000-00000000a511',
    10000,
    1,
    'amount',
    500,
    'promotion'
);

DO $$
DECLARE
    snap_key text;
    journal_no bigint;
    sale_discount bigint;
    sale_tax bigint;
    book uuid;
    period uuid;
    cash uuid;
    commission uuid;
    payable uuid;
    evil uuid := '01900000-0000-7000-8000-00000000a599';
BEGIN
    SELECT stall_mint_register_snaps(
        '01900000-0000-7000-8000-00000000a522'::uuid,
        '01900000-0000-7000-8000-00000000a531'::uuid
    ) INTO snap_key;

    IF snap_key IS NULL OR snap_key = '' THEN
        RAISE EXCEPTION 'mint returned no snap';
    END IF;

    SELECT discount_minor, tax_minor
    INTO sale_discount, sale_tax
    FROM register_snaps
    WHERE posting_key = snap_key;

    IF sale_discount <> 1500 THEN
        RAISE EXCEPTION 'snap discount missing ticket allocation: %', sale_discount;
    END IF;

    IF sale_tax <> 0 THEN
        RAISE EXCEPTION 'tax-exempt snap should be zero tax: %', sale_tax;
    END IF;

    UPDATE register_snaps SET business_date = DATE '2096-03-30' WHERE posting_key = snap_key;

    SELECT id INTO book FROM ledger_books WHERE is_default;
    SELECT open_period.id INTO period
    FROM accounting_periods AS open_period
    JOIN register_snaps AS snap ON snap.posting_key = snap_key
    WHERE open_period.book_id = book
      AND open_period.status = 'open'
      AND open_period.starts_on <= snap.business_date
      AND open_period.ends_on >= snap.business_date
    LIMIT 1;
    SELECT account_id INTO cash FROM chart_roles WHERE role = 'cash';
    SELECT account_id INTO commission FROM chart_roles WHERE role = 'commission_revenue';
    SELECT account_id INTO payable FROM chart_roles WHERE role = 'vendor_payable';

    BEGIN
        INSERT INTO journals (
            id, book_id, posting_key, posting_date, occurred_at, period_id,
            currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
        )
        SELECT
            evil, book, snap_key, snap.business_date, clock_timestamp(), period,
            'USD', 'sale', 'evil', 'Evil mismatched discounts', 8500, 8500
        FROM register_snaps AS snap
        WHERE snap.posting_key = snap_key;
        INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
        VALUES (evil, book, 1, cash, 8500, 0);
        INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
        VALUES (evil, book, 2, commission, 0, 4250);
        INSERT INTO journal_lines (
            journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
        ) VALUES (
            evil, book, 3, payable, 0, 4250, 'vendor', '01900000-0000-7000-8000-00000000a501'
        );
        INSERT INTO sale_lines (
            journal_id, line_no, item_id, price_minor, quantity, commission_minor, tax_minor, discount_minor
        ) VALUES (
            evil, 1, '01900000-0000-7000-8000-00000000a511', 10000, 1, 4250, 0, 0
        );
        SET CONSTRAINTS ALL IMMEDIATE;
        RAISE EXCEPTION 'poster sale that dropped snap discounts was accepted';
    EXCEPTION
        WHEN check_violation THEN
            NULL;
        WHEN foreign_key_violation THEN
            NULL;
        WHEN OTHERS THEN
            IF SQLERRM LIKE '%discount%'
                OR SQLERRM LIKE '%tax%'
                OR SQLERRM LIKE '%price%'
                OR SQLERRM LIKE '%period%' THEN
                NULL;
            ELSE
                RAISE;
            END IF;
    END;

    journal_no := stall_sync_snap(snap_key);
    IF journal_no IS NULL THEN
        RAISE EXCEPTION 'sync refused: %', (SELECT refusal FROM register_snaps WHERE posting_key = snap_key);
    END IF;

    SELECT sale.discount_minor, sale.tax_minor
    INTO sale_discount, sale_tax
    FROM sale_lines AS sale
    JOIN journals AS journal ON journal.id = sale.journal_id
    WHERE journal.posting_key = snap_key;

    IF sale_discount <> 1500 THEN
        RAISE EXCEPTION 'posted sale dropped discounts: %', sale_discount;
    END IF;
END;
$$;

ROLLBACK;
