-- Proof for vendors and booths. One transaction, then ROLLBACK.

BEGIN;

DO $$
DECLARE
    money_columns integer;
BEGIN
    SELECT count(*) INTO money_columns
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name IN ('vendors', 'booths', 'booth_assignments')
      AND column_name NOT IN ('width_ft', 'depth_ft', 'x_ft', 'z_ft')
      AND (
          data_type IN ('numeric', 'money', 'bigint', 'integer', 'smallint')
          OR column_name ~* '(amount|balance|minor|rate|price|total)'
      );

    IF money_columns <> 0 THEN
        RAISE EXCEPTION 'vendor or booth tables carry money';
    END IF;

    IF (
        SELECT count(*)
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'booths'
          AND column_name IN ('width_ft', 'depth_ft', 'x_ft', 'z_ft')
    ) <> 4 THEN
        RAISE EXCEPTION 'booth size or place columns are missing';
    END IF;
END $$;

INSERT INTO vendors (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada');

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000201', 'A1', 'Booth A1');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-000000000301',
    '01900000-0000-7000-8000-000000000201',
    '01900000-0000-7000-8000-000000000101',
    '2026-01-01'
);

DO $$
BEGIN
    INSERT INTO booth_assignments (booth_id, vendor_id, starts_on)
    VALUES (
        '01900000-0000-7000-8000-000000000201',
        '01900000-0000-7000-8000-000000000101',
        '2026-06-01'
    );
    RAISE EXCEPTION 'overlapping booth assignment was accepted';
EXCEPTION
    WHEN SQLSTATE '23P01' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE booth_assignments
    SET starts_on = '2026-02-01'
    WHERE id = '01900000-0000-7000-8000-000000000301';
    RAISE EXCEPTION 'assignment identity rewrite was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000209', 'B9', 'Booth B9');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-000000000309',
    '01900000-0000-7000-8000-000000000209',
    '01900000-0000-7000-8000-000000000101',
    '2026-01-01'
);

DELETE FROM booth_assignments WHERE id = '01900000-0000-7000-8000-000000000309';

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM booth_assignments WHERE id = '01900000-0000-7000-8000-000000000309'
    ) THEN
        RAISE EXCEPTION 'assignment with no posted rent was not deleted';
    END IF;
END $$;

UPDATE booth_assignments
SET ends_on = '2026-12-31'
WHERE id = '01900000-0000-7000-8000-000000000301';

DO $$
BEGIN
    UPDATE booth_assignments
    SET ends_on = '2026-06-30'
    WHERE id = '01900000-0000-7000-8000-000000000301';
    RAISE EXCEPTION 'assignment end rewrite was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

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
    '01900000-0000-7000-8000-000000000401',
    '01900000-0000-7000-8000-000000000001',
    'prove-vendor-ref',
    '2026-03-01',
    '2026-03-01 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'sale',
    'vendor-ref',
    'Vendor subledger proof',
    1000,
    1000
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000401',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000014',
    0,
    1000,
    'vendor',
    '01900000-0000-7000-8000-000000000101'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000401',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000010',
    1000,
    0
);

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000402',
        '01900000-0000-7000-8000-000000000001',
        'prove-missing-vendor',
        '2026-03-02',
        '2026-03-02 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD',
        'sale',
        'missing-vendor',
        'Missing vendor subledger',
        1000,
        1000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000402',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000014',
        0,
        1000,
        'vendor',
        '01900000-0000-7000-8000-000000000199'
    );
    RAISE EXCEPTION 'unknown vendor subledger was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-000000000101';
    RAISE EXCEPTION 'referenced vendor delete was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO booths (code, name, width_ft) VALUES ('wide', 'Wide only', 10);
    RAISE EXCEPTION 'a booth width without a depth was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO booths (code, name, width_ft, depth_ft) VALUES ('zero', 'Zero', 0, 10);
    RAISE EXCEPTION 'a zero booth size was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

INSERT INTO booths (id, code, name, width_ft, depth_ft)
VALUES ('01900000-0000-7000-8000-000000000210', 'C1', 'Open booth', 10, 20);

DELETE FROM booths WHERE id = '01900000-0000-7000-8000-000000000210';

DO $$
BEGIN
    DELETE FROM booths WHERE id = '01900000-0000-7000-8000-000000000201';
    RAISE EXCEPTION 'a booth with an assignment was deleted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000211', 'C2', 'Rated booth');

INSERT INTO booth_rent_rates (booth_id, month_start, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000211', '2026-03-01', 10000);

DO $$
BEGIN
    DELETE FROM booths WHERE id = '01900000-0000-7000-8000-000000000211';
    RAISE EXCEPTION 'a booth with a rent rate was deleted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000212', 'C3', 'Charged booth');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-000000000312',
    '01900000-0000-7000-8000-000000000212',
    '01900000-0000-7000-8000-000000000101',
    '2026-01-01'
);

INSERT INTO booth_rent_rates (booth_id, month_start, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000212', '2026-04-01', 8000);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000403',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-000000000212:2026-04',
    '2026-04-01',
    '2026-04-01 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'rent_charge',
    'C3:2026-04',
    'April rent for booth C3',
    8000,
    8000
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000403',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000015',
    8000,
    0,
    'booth',
    '01900000-0000-7000-8000-000000000212'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000403',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000017',
    0,
    8000
);

DO $$
BEGIN
    DELETE FROM booths WHERE id = '01900000-0000-7000-8000-000000000212';
    RAISE EXCEPTION 'a booth with posted rent was deleted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000213', 'C4', 'Poster booth');

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    DELETE FROM booths WHERE id = '01900000-0000-7000-8000-000000000213';
    IF EXISTS (
        SELECT 1 FROM booths WHERE id = '01900000-0000-7000-8000-000000000213'
    ) THEN
        RAISE EXCEPTION 'poster did not delete the booth';
    END IF;
    RESET ROLE;
END $$;

DO $$
BEGIN
    INSERT INTO booths (code, name, x_ft) VALUES ('half', 'Half placed', 0);
    RAISE EXCEPTION 'a booth x without a z was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO booths (code, name, x_ft, z_ft) VALUES ('neg', 'Negative', -1, 0);
    RAISE EXCEPTION 'a negative booth position was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

INSERT INTO booths (id, code, name, width_ft, depth_ft, x_ft, z_ft)
VALUES ('01900000-0000-7000-8000-000000000214', 'P1', 'Placed', 10, 10, 0, 0);

INSERT INTO booths (id, code, name, x_ft, z_ft)
VALUES ('01900000-0000-7000-8000-000000000215', 'P2', 'Beside', 10, 0);

INSERT INTO booths (code, name) VALUES ('loose', 'Not on the floor');

DO $$
BEGIN
    INSERT INTO booths (code, name, width_ft, depth_ft, x_ft, z_ft)
    VALUES ('past', 'Past the floor', 10, 10, 115, 0);
    RAISE EXCEPTION 'a booth past the floor was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO booths (code, name, x_ft, z_ft) VALUES ('past-default', 'Past on default size', 115, 0);
    RAISE EXCEPTION 'a default-size booth past the floor was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO booths (code, name, width_ft, depth_ft, x_ft, z_ft)
    VALUES ('over', 'Overlapping', 10, 10, 5, 0);
    RAISE EXCEPTION 'an overlapping booth was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE mall SET floor_width_ft = 15;
    RAISE EXCEPTION 'a floor shrink that cuts a placed booth was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    UPDATE booths
    SET x_ft = 20, z_ft = 0
    WHERE id = '01900000-0000-7000-8000-000000000215';
    IF (
        SELECT x_ft FROM booths WHERE id = '01900000-0000-7000-8000-000000000215'
    ) <> 20 THEN
        RAISE EXCEPTION 'poster did not place the booth';
    END IF;
    RESET ROLE;
END $$;

DO $$
BEGIN
    TRUNCATE booth_rent_rates, booth_assignments, booths, vendors;
    RAISE EXCEPTION 'vendor truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        NULL;
END $$;

ROLLBACK;
