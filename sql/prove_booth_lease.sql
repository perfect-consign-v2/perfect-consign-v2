-- A lease supplies the rent. The posting key stays one booth and one month.
-- Overlap is refused. A mid-month start and an early end prorate. The deposit stays on 2151.

BEGIN;

INSERT INTO booths (id, code, name)
VALUES
    ('01900000-0000-7000-8000-000000000201', 'A1', 'Booth A1'),
    ('01900000-0000-7000-8000-000000000202', 'A2', 'Booth A2');

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES
    ('01900000-0000-7000-8000-000000000301', '01900000-0000-7000-8000-000000000201', '01900000-0000-7000-8000-000000000101', '2026-01-01'),
    ('01900000-0000-7000-8000-000000000302', '01900000-0000-7000-8000-000000000202', '01900000-0000-7000-8000-000000000101', '2026-01-01');

INSERT INTO assignment_rent (assignment_id, amount_minor)
VALUES
    ('01900000-0000-7000-8000-000000000301', 15000),
    ('01900000-0000-7000-8000-000000000302', 15000);

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
    '01900000-0000-7000-8000-000000000601',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-000000000201:' || public.stall_rent_charge_suffix(DATE '2026-01-01'),
    '2026-01-01', '2026-01-01 12:00:00+00', '01900000-0000-7000-8000-000000000021',
    'USD', 'rent_charge', 'A1:2026-01', 'January rent', 15000, 15000
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000601',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000015', 15000, 0, 'booth', '01900000-0000-7000-8000-000000000201'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000601',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000017', 0, 15000
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO booth_leases (id, booth_id, starts_on, rent_minor, deposit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000401',
    '01900000-0000-7000-8000-000000000201',
    '2026-03-01',
    20000,
    5000
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000602',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-000000000201:' || public.stall_rent_charge_suffix(DATE '2026-03-01'),
    '2026-03-01', '2026-03-01 12:00:00+00', '01900000-0000-7000-8000-000000000021',
    'USD', 'rent_charge', 'A1:2026-03', 'March lease', 20000, 20000
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000602',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000015', 20000, 0, 'booth', '01900000-0000-7000-8000-000000000201'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000602',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000017', 0, 20000
);

SET CONSTRAINTS ALL IMMEDIATE;

DO $$
DECLARE
    days integer;
    charged integer;
    expected bigint;
BEGIN
    IF (SELECT count(*) FROM journals WHERE posting_key = 'rent-charge:01900000-0000-7000-8000-000000000201:' || public.stall_rent_charge_suffix(DATE '2026-03-01')) IS DISTINCT FROM 1 THEN
        RAISE EXCEPTION 'the lease created a second charge';
    END IF;
    IF (SELECT code FROM accounts WHERE id = (SELECT account_id FROM chart_roles WHERE role = 'booth_deposit_held')) IS DISTINCT FROM '2151' THEN
        RAISE EXCEPTION 'the deposit left 2151';
    END IF;
    IF (SELECT deposit_minor FROM booth_leases WHERE id = '01900000-0000-7000-8000-000000000401') IS DISTINCT FROM 5000 THEN
        RAISE EXCEPTION 'the deposit was not stored on the lease';
    END IF;
    BEGIN
        INSERT INTO booth_leases (booth_id, starts_on, rent_minor)
        VALUES ('01900000-0000-7000-8000-000000000201', '2026-03-15', 18000);
        RAISE EXCEPTION 'an overlapping lease was stored';
    EXCEPTION WHEN exclusion_violation THEN
        NULL;
    END;
    days := (DATE '2026-05-31' - DATE '2026-05-01') + 1;
    charged := (DATE '2026-05-31' - DATE '2026-05-16') + 1;
    expected := (charged * 20000) / days;
    INSERT INTO booth_leases (id, booth_id, starts_on, rent_minor)
    VALUES ('01900000-0000-7000-8000-000000000402', '01900000-0000-7000-8000-000000000202', '2026-05-16', 20000);
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000603',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-000000000202:' || public.stall_rent_charge_suffix(DATE '2026-05-01'),
        '2026-05-16', '2026-05-16 12:00:00+00', '01900000-0000-7000-8000-000000000021',
        'USD', 'rent_charge', 'A2:2026-05', 'May lease', expected, expected
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000603',
        '01900000-0000-7000-8000-000000000001',
        1, '01900000-0000-7000-8000-000000000015', expected, 0, 'booth', '01900000-0000-7000-8000-000000000202'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000603',
        '01900000-0000-7000-8000-000000000001',
        2, '01900000-0000-7000-8000-000000000017', 0, expected
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    PERFORM public.stall_end_lease('01900000-0000-7000-8000-000000000401', DATE '2026-03-31');
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000604',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-000000000201:' || public.stall_rent_charge_suffix(DATE '2026-04-01'),
        '2026-04-01', '2026-04-01 12:00:00+00', '01900000-0000-7000-8000-000000000021',
        'USD', 'rent_charge', 'A1:2026-04', 'April rent', 15000, 15000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000604',
        '01900000-0000-7000-8000-000000000001',
        1, '01900000-0000-7000-8000-000000000015', 15000, 0, 'booth', '01900000-0000-7000-8000-000000000201'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000604',
        '01900000-0000-7000-8000-000000000001',
        2, '01900000-0000-7000-8000-000000000017', 0, 15000
    );
    SET CONSTRAINTS ALL IMMEDIATE;
END $$;

SET CONSTRAINTS ALL IMMEDIATE;

ROLLBACK;
