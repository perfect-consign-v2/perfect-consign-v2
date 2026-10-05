-- Proof for a rent charge. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000201', 'A1', 'Booth A1');

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane');

INSERT INTO booth_assignments (booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-000000000201',
    '01900000-0000-7000-8000-000000000101',
    '2026-01-01'
);

INSERT INTO booth_rent_rates (booth_id, month_start, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000201', '2026-01-01', 15000);

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
    'rent-charge:01900000-0000-7000-8000-000000000201:2026-03',
    '2026-03-01',
    '2026-03-01 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'rent_charge',
    'A1:2026-03',
    'March rent for booth A1',
    15000,
    15000
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000601',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000015',
    15000,
    0,
    'booth',
    '01900000-0000-7000-8000-000000000201'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000601',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000017',
    0,
    15000
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000602',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-000000000201:2026-03',
        '2026-03-02',
        '2026-03-02 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'rent_charge',
        'A1:2026-03-again',
        'Second March rent charge',
        15000,
        15000
    );
    RAISE EXCEPTION 'second rent charge for the same booth and month was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
END $$;

INSERT INTO booth_rent_rates (booth_id, month_start, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000201', '2026-04-01', 16000);

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000604',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-000000000201:2026-04',
        '2026-04-01',
        '2026-04-01 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'rent_charge',
        'A1:2026-04-wrong',
        'April rent at a later booth rate',
        16000,
        16000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000604',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000015',
        16000,
        0,
        'booth',
        '01900000-0000-7000-8000-000000000201'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000604',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000017',
        0,
        16000
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'rent charge that ignores the assignment rate was accepted';
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
        '01900000-0000-7000-8000-000000000603',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-000000000201:2026-04',
        '2026-04-01',
        '2026-04-01 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'rent_charge',
        'A1:2026-04',
        'April rent at the assignment rate',
        15000,
        15000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000603',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000015',
        15000,
        0,
        'booth',
        '01900000-0000-7000-8000-000000000201'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000603',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000017',
        0,
        15000
    );
    SET CONSTRAINTS ALL IMMEDIATE;
END $$;

DO $$
BEGIN
    UPDATE booth_rent_rates
    SET amount_minor = 1
    WHERE booth_id = '01900000-0000-7000-8000-000000000201'
      AND month_start = '2026-01-01';
    RAISE EXCEPTION 'rent rate change after the charge was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000202', 'B2', 'Booth B2');

INSERT INTO booth_rent_rates (booth_id, month_start, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000202', '2026-05-01', 15000);

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000605',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-000000000202:2026-05',
        '2026-05-01',
        '2026-05-01 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'rent_charge',
        'B2:2026-05',
        'May rent with no assignment',
        15000,
        15000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000605',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000015',
        15000,
        0,
        'booth',
        '01900000-0000-7000-8000-000000000202'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000605',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000017',
        0,
        15000
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'rent charge without an assignment was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000221', 'B3', 'Booth B3');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-000000000321',
    '01900000-0000-7000-8000-000000000221',
    '01900000-0000-7000-8000-000000000101',
    '2026-03-15'
);

INSERT INTO booth_rent_rates (booth_id, month_start, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000221', '2026-03-01', 3100);

INSERT INTO assignment_rent (assignment_id, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000321', 6200);

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000606',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-000000000221:2026-03',
        '2026-03-15',
        '2026-03-15 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'rent_charge',
        'B3:2026-03-full',
        'March rent ignored the move-in and the override',
        6200,
        6200
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000606',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000015',
        6200,
        0,
        'booth',
        '01900000-0000-7000-8000-000000000221'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000606',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000017',
        0,
        6200
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a mid-month move-in was charged the full override';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000607',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-000000000221:2026-03',
    '2026-03-15',
    '2026-03-15 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'rent_charge',
    'B3:2026-03',
    'March rent from the 15th',
    3400,
    3400
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000607',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000015',
    3400,
    0,
    'booth',
    '01900000-0000-7000-8000-000000000221'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000607',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000017',
    0,
    3400
);

SET CONSTRAINTS ALL IMMEDIATE;

DO $$
BEGIN
    UPDATE assignment_rent
    SET amount_minor = 1
    WHERE assignment_id = '01900000-0000-7000-8000-000000000321';
    RAISE EXCEPTION 'an assignment rent changed after the month was charged';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

ROLLBACK;
