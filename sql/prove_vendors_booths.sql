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
      AND column_name NOT IN (
          'width_ft', 'depth_ft', 'x_ft', 'z_ft', 'commission_bps',
          'keep_days'
      )
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

    IF (
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'booths'
          AND column_name = 'location'
    ) <> 'text' THEN
        RAISE EXCEPTION 'booth location is missing';
    END IF;

    IF (
        SELECT count(*)
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'persons'
          AND column_name IN ('first_name', 'last_name')
          AND data_type = 'text'
    ) <> 2 THEN
        RAISE EXCEPTION 'person name columns are missing';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'persons'
          AND column_name IN ('phone', 'email', 'address', 'city', 'state', 'postal')
    ) THEN
        RAISE EXCEPTION 'persons still stores contact';
    END IF;

    IF to_regclass('party_contacts') IS NULL OR to_regclass('party_addresses') IS NULL THEN
        RAISE EXCEPTION 'party contact tables are missing';
    END IF;

    -- Contact lives on party children. vendors.email may remain as a synced
    -- copy for letter triggers that cannot be replaced after append-only lock.
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'vendors'
          AND column_name IN ('first_name', 'last_name', 'phone', 'address', 'business_name')
    ) THEN
        RAISE EXCEPTION 'vendor still stores identity';
    END IF;

    IF (
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'vendors'
          AND column_name = 'active'
    ) <> 'boolean' THEN
        RAISE EXCEPTION 'vendor active standing is missing';
    END IF;

    IF (
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'vendors'
          AND column_name = 'commission_bps'
    ) <> 'integer' THEN
        RAISE EXCEPTION 'vendor store take is missing';
    END IF;

    -- keep_days is a retention period in days, not an amount of money.
    IF (
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'vendors'
          AND column_name = 'keep_days'
    ) <> 'integer' THEN
        RAISE EXCEPTION 'vendor keep_days is not a day count';
    END IF;

    IF (
        SELECT count(*)
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'vendors'
          AND column_name IN ('agreement_starts_on', 'agreement_ends_on')
          AND data_type = 'date'
    ) <> 2 THEN
        RAISE EXCEPTION 'vendor agreement dates are missing';
    END IF;

    IF (
        SELECT count(*)
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'vendors'
          AND column_name IN ('tax_id', 'notes', 'tax_exempt_number')
          AND data_type = 'text'
    ) <> 3 THEN
        RAISE EXCEPTION 'vendor tax id or notes are missing';
    END IF;

    IF (
        SELECT data_type
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'vendors'
          AND column_name = 'tax_exempt'
    ) <> 'boolean' THEN
        RAISE EXCEPTION 'vendor tax exempt standing is missing';
    END IF;
END $$;

SELECT stall_seed_vendor('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane');

DO $$
BEGIN
    UPDATE vendors
    SET commission_bps = 10001
    WHERE id = '01900000-0000-7000-8000-000000000101';
    RAISE EXCEPTION 'a store take over 100 percent was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE vendors
    SET agreement_starts_on = '2026-06-01', agreement_ends_on = '2026-05-01'
    WHERE id = '01900000-0000-7000-8000-000000000101';
    RAISE EXCEPTION 'an agreement that ends before it starts was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE vendors
    SET tax_exempt = true
    WHERE id = '01900000-0000-7000-8000-000000000101';
    RAISE EXCEPTION 'tax exempt without a resale number was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE vendors
    SET tax_exempt = true, tax_exempt_number = ' '
    WHERE id = '01900000-0000-7000-8000-000000000101';
    RAISE EXCEPTION 'a blank resale number was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE vendors
    SET tax_exempt = true, tax_exempt_number = repeat('n', 101)
    WHERE id = '01900000-0000-7000-8000-000000000101';
    RAISE EXCEPTION 'a resale number over 100 characters was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

UPDATE vendors
SET tax_exempt = true, tax_exempt_number = 'RESALE-1'
WHERE id = '01900000-0000-7000-8000-000000000101';

UPDATE vendors
SET tax_exempt = false, tax_exempt_number = NULL
WHERE id = '01900000-0000-7000-8000-000000000101';

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

SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000401',
    '01900000-0000-7000-8000-000000000001',
    'vendor-memo:vendor-ref',
    '2026-03-01',
    '2026-03-01 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'vendor_memo',
    'vendor-ref',
    'Vendor subledger proof',
    1000,
    1000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000401',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000028',
    1000,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000401',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000014',
    0,
    1000,
    'vendor',
    '01900000-0000-7000-8000-000000000101'
);

INSERT INTO vendor_memos (journal_id, vendor_id, direction, amount_minor, reason)
VALUES ('01900000-0000-7000-8000-000000000401', '01900000-0000-7000-8000-000000000101', 'credit', 1000, 'Vendor subledger proof');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

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

DO $$
BEGIN
    INSERT INTO booths (code, name, location) VALUES ('blank-loc', 'Blank location', '');
    RAISE EXCEPTION 'a blank booth location was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO booths (code, name, location) VALUES ('pad-loc', 'Padded location', ' north');
    RAISE EXCEPTION 'an untrimmed booth location was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO booths (code, name, location) VALUES ('line-loc', 'Line location', E'north\nwall');
    RAISE EXCEPTION 'a booth location with a line break was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO booths (code, name, location) VALUES ('long-loc', 'Long location', repeat('x', 201));
    RAISE EXCEPTION 'a booth location over 200 characters was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

INSERT INTO booths (id, code, name, location)
VALUES (
    '01900000-0000-7000-8000-00000000021a',
    'loc1',
    'Located booth',
    repeat('y', 200)
);

DO $$
BEGIN
    IF (
        SELECT location FROM booths WHERE id = '01900000-0000-7000-8000-00000000021a'
    ) IS DISTINCT FROM repeat('y', 200) THEN
        RAISE EXCEPTION 'a booth location was not stored';
    END IF;
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

INSERT INTO booth_rent_rates (booth_id, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000211', 10000);
INSERT INTO assignment_rent (assignment_id, amount_minor)
SELECT assignment.id, rate.amount_minor
FROM booth_assignments AS assignment
JOIN booth_rent_rates AS rate ON rate.booth_id = assignment.booth_id
ON CONFLICT (assignment_id) DO NOTHING;

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

INSERT INTO booth_rent_rates (booth_id, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000212', 8000);
INSERT INTO assignment_rent (assignment_id, amount_minor)
SELECT assignment.id, rate.amount_minor
FROM booth_assignments AS assignment
JOIN booth_rent_rates AS rate ON rate.booth_id = assignment.booth_id
ON CONFLICT (assignment_id) DO NOTHING;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000403',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-000000000312:2026-04',
    '2026-04-01',
    '2026-04-01 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'rent_charge',
    '01900000-0000-7000-8000-000000000312:2026-04',
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
    'assignment',
    '01900000-0000-7000-8000-000000000312'
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

SELECT stall_seed_vendor('01900000-0000-7000-8000-000000000102', 'owed', 'Owed', 'Lane');

SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000404',
    '01900000-0000-7000-8000-000000000001',
    'vendor-memo:owed-payable',
    '2026-03-03',
    '2026-03-03 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'vendor_memo',
    'owed-payable',
    'Payable still owed to the vendor',
    1000,
    1000
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-000000000404', '01900000-0000-7000-8000-000000000001', 1,
     '01900000-0000-7000-8000-000000000028', 1000, 0, NULL, NULL),
    ('01900000-0000-7000-8000-000000000404', '01900000-0000-7000-8000-000000000001', 2,
     '01900000-0000-7000-8000-000000000014', 0, 1000, 'vendor', '01900000-0000-7000-8000-000000000102');

INSERT INTO vendor_memos (journal_id, vendor_id, direction, amount_minor, reason)
VALUES ('01900000-0000-7000-8000-000000000404', '01900000-0000-7000-8000-000000000102', 'credit', 1000, 'We owe her for a repair');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

-- Inactive is refused while money is open in either direction or a booth is
-- held, with one plain sentence per reason and the reason codes in DETAIL.
CREATE TEMPORARY TABLE inactive_refusal (said text, codes text) ON COMMIT DROP;

CREATE FUNCTION pg_temp.try_inactive(target uuid) RETURNS boolean
    LANGUAGE plpgsql
    AS $fn$
DECLARE
    said text;
    codes text;
BEGIN
    DELETE FROM inactive_refusal;
    BEGIN
        UPDATE vendors SET active = false WHERE id = target;
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            GET STACKED DIAGNOSTICS said = MESSAGE_TEXT, codes = PG_EXCEPTION_DETAIL;
            INSERT INTO inactive_refusal VALUES (said, codes);
            RETURN false;
    END;
    RETURN true;
END;
$fn$;

DO $$
BEGIN
    IF pg_temp.try_inactive('01900000-0000-7000-8000-000000000102') THEN
        RAISE EXCEPTION 'a vendor the store still owes was made inactive';
    END IF;
    IF (SELECT said FROM inactive_refusal) <> 'Owed Lane can''t be made inactive yet. The store owes them $10.00.'
       OR (SELECT codes FROM inactive_refusal) <> 'payable' THEN
        RAISE EXCEPTION 'the payable refusal read: % (%)', (SELECT said FROM inactive_refusal), (SELECT codes FROM inactive_refusal);
    END IF;
END $$;

DO $$
BEGIN
    IF pg_temp.try_inactive('01900000-0000-7000-8000-000000000101') THEN
        RAISE EXCEPTION 'a vendor with open rent and a booth was made inactive';
    END IF;
    IF (SELECT said FROM inactive_refusal) <> 'Ada Lane can''t be made inactive yet. They owe $80.00 in rent. The store owes them $10.00. They still have a booth. End it first.'
       OR (SELECT codes FROM inactive_refusal) <> 'rent,payable,booth' THEN
        RAISE EXCEPTION 'the rent and booth refusal read: % (%)', (SELECT said FROM inactive_refusal), (SELECT codes FROM inactive_refusal);
    END IF;
END $$;

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d23',
    '01900000-0000-7000-8000-000000000d10',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000405',
    '01900000-0000-7000-8000-000000000001',
    'rent-payment:prove-open-rent',
    '2026-04-02',
    '2026-04-02 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'rent_payment',
    'prove-open-rent',
    'Rent payment that clears the open charge',
    8000,
    8000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000405',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000010',
    8000,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000405',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000015',
    0,
    8000,
    'assignment',
    '01900000-0000-7000-8000-000000000312'
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d23', '01900000-0000-7000-8000-000000000405');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    IF pg_temp.try_inactive('01900000-0000-7000-8000-000000000101') THEN
        RAISE EXCEPTION 'a vendor who still has a booth was made inactive';
    END IF;
    IF (SELECT codes FROM inactive_refusal) <> 'payable,booth' THEN
        RAISE EXCEPTION 'paid rent still blocked inactive: % (%)', (SELECT said FROM inactive_refusal), (SELECT codes FROM inactive_refusal);
    END IF;
END $$;

-- A vendor who owes on their payout balance, then from a return (clawback).
SELECT stall_seed_vendor('01900000-0000-7000-8000-000000000103', 'claw', 'Claw', 'Lane');

SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000406',
    '01900000-0000-7000-8000-000000000001',
    'vendor-memo:claw-owed',
    '2026-03-04',
    '2026-03-04 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'vendor_memo',
    'claw-owed',
    'A vendor who owes on the payout balance',
    500,
    500
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-000000000406', '01900000-0000-7000-8000-000000000001', 1,
     '01900000-0000-7000-8000-000000000014', 500, 0, 'vendor', '01900000-0000-7000-8000-000000000103'),
    ('01900000-0000-7000-8000-000000000406', '01900000-0000-7000-8000-000000000001', 2,
     '01900000-0000-7000-8000-000000000029', 0, 500, NULL, NULL);

INSERT INTO vendor_memos (journal_id, vendor_id, direction, amount_minor, reason)
VALUES ('01900000-0000-7000-8000-000000000406', '01900000-0000-7000-8000-000000000103', 'debit', 500, 'A sign we made for her');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    IF pg_temp.try_inactive('01900000-0000-7000-8000-000000000103') THEN
        RAISE EXCEPTION 'a vendor who owes on the payout balance was made inactive';
    END IF;
    IF (SELECT said FROM inactive_refusal) <> 'Claw Lane can''t be made inactive yet. They owe $5.00 on their payout balance.'
       OR (SELECT codes FROM inactive_refusal) <> 'payable' THEN
        RAISE EXCEPTION 'the negative payable refusal read: % (%)', (SELECT said FROM inactive_refusal), (SELECT codes FROM inactive_refusal);
    END IF;
END $$;

SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000407',
    '01900000-0000-7000-8000-000000000001',
    'clawback_reclass:prove-claw',
    '2026-03-05',
    '2026-03-05 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'clawback_reclass',
    'prove-claw',
    'Move what the vendor owes to their receivable',
    500,
    500
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    ('01900000-0000-7000-8000-000000000407', '01900000-0000-7000-8000-000000000001', 1,
     '01900000-0000-7000-8000-00000000002e', 500, 0, 'vendor', '01900000-0000-7000-8000-000000000103'),
    ('01900000-0000-7000-8000-000000000407', '01900000-0000-7000-8000-000000000001', 2,
     '01900000-0000-7000-8000-000000000014', 0, 500, 'vendor', '01900000-0000-7000-8000-000000000103');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    IF pg_temp.try_inactive('01900000-0000-7000-8000-000000000103') THEN
        RAISE EXCEPTION 'a vendor who owes from a return was made inactive';
    END IF;
    IF (SELECT said FROM inactive_refusal) <> 'Claw Lane can''t be made inactive yet. They owe $5.00 from returns.'
       OR (SELECT codes FROM inactive_refusal) <> 'clawback' THEN
        RAISE EXCEPTION 'the clawback refusal read: % (%)', (SELECT said FROM inactive_refusal), (SELECT codes FROM inactive_refusal);
    END IF;
    IF (SELECT active FROM vendors WHERE id = '01900000-0000-7000-8000-000000000103') IS NOT TRUE THEN
        RAISE EXCEPTION 'a refused inactive left the vendor inactive';
    END IF;
END $$;

-- Mary: her stay ended Jan 31. January is charged Feb 5 and paid Feb 10, after
-- she left, and both land on her stay. With nothing open she can be made inactive.
SELECT stall_seed_vendor('01900000-0000-7000-8000-000000000105', 'mary', 'Mary', 'Smith');

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-000000000216', 'M1', 'Mary booth');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on, ends_on)
VALUES (
    '01900000-0000-7000-8000-000000000316',
    '01900000-0000-7000-8000-000000000216',
    '01900000-0000-7000-8000-000000000105',
    '2026-01-01',
    '2026-01-31'
);

INSERT INTO assignment_rent (assignment_id, amount_minor)
VALUES ('01900000-0000-7000-8000-000000000316', 6000);

DO $$
DECLARE
    receipt uuid;
BEGIN
    PERFORM stall_post_rent_charge('01900000-0000-7000-8000-000000000316', '2026-01-01', '2026-02-05');
    SET CONSTRAINTS ALL IMMEDIATE;
    IF pg_temp.try_inactive('01900000-0000-7000-8000-000000000105') THEN
        RAISE EXCEPTION 'a departed vendor with open rent was made inactive';
    END IF;
    IF (SELECT said FROM inactive_refusal) <> 'Mary Smith can''t be made inactive yet. They owe $60.00 in rent.'
       OR (SELECT codes FROM inactive_refusal) <> 'rent' THEN
        RAISE EXCEPTION 'the departed vendor refusal read: % (%)', (SELECT said FROM inactive_refusal), (SELECT codes FROM inactive_refusal);
    END IF;
    receipt := stall_post_rent_payment('01900000-0000-7000-8000-000000000105', 0, 6000, 0, '2026-02-10');
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d23', receipt);
    SET CONSTRAINTS ALL IMMEDIATE;
    IF NOT pg_temp.try_inactive('01900000-0000-7000-8000-000000000105') THEN
        RAISE EXCEPTION 'a vendor with nothing open was refused: % (%)', (SELECT said FROM inactive_refusal), (SELECT codes FROM inactive_refusal);
    END IF;
    IF (SELECT active FROM vendors WHERE id = '01900000-0000-7000-8000-000000000105') THEN
        RAISE EXCEPTION 'Mary was left active';
    END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE items DROP CONSTRAINT items_vendor_id_fkey;
    ALTER TABLE tickets DROP CONSTRAINT tickets_buyer_id_fkey;
    ALTER TABLE booth_assignments DROP CONSTRAINT booth_assignments_vendor_id_fkey;
    ALTER TABLE payout_run_lines DROP CONSTRAINT payout_run_lines_vendor_id_fkey;
    ALTER TABLE payout_run_sales DROP CONSTRAINT payout_run_sales_vendor_id_fkey;
    ALTER TABLE staff DROP CONSTRAINT staff_vendor_id_fkey;
    ALTER TABLE item_buys DROP CONSTRAINT item_buys_vendor_id_fkey;
    ALTER TABLE missed_sales DROP CONSTRAINT missed_sales_vendor_id_fkey;
    ALTER TABLE store_uses DROP CONSTRAINT store_uses_vendor_id_fkey;
    ALTER TABLE vendor_memos DROP CONSTRAINT vendor_memos_vendor_id_fkey;
    ALTER TABLE pickups DROP CONSTRAINT pickups_vendor_id_fkey;
    ALTER TABLE item_quantity_changes DROP CONSTRAINT item_quantity_changes_vendor_id_fkey;
    ALTER TABLE bad_debt_events DROP CONSTRAINT bad_debt_events_vendor_id_fkey;
    ALTER TABLE vendor_letter_copies DROP CONSTRAINT vendor_letter_copies_vendor_id_fkey;
    IF EXISTS (
        SELECT 1
        FROM pg_constraint AS constraint_row
        JOIN pg_class AS relation ON relation.oid = constraint_row.conrelid
        JOIN pg_namespace AS namespace ON namespace.oid = relation.relnamespace
        WHERE constraint_row.conname = 'commission_rules_vendor_id_fkey'
          AND namespace.nspname = current_schema()
    ) THEN
        ALTER TABLE commission_rules DROP CONSTRAINT commission_rules_vendor_id_fkey;
    END IF;
    IF EXISTS (
        SELECT 1
        FROM pg_constraint AS constraint_row
        JOIN pg_class AS relation ON relation.oid = constraint_row.conrelid
        JOIN pg_namespace AS namespace ON namespace.oid = relation.relnamespace
        WHERE constraint_row.conname = 'vendor_entry_tokens_vendor_id_fkey'
          AND namespace.nspname = current_schema()
    ) THEN
        ALTER TABLE vendor_entry_tokens DROP CONSTRAINT vendor_entry_tokens_vendor_id_fkey;
    END IF;
    TRUNCATE assignment_rent_rates, assignment_rent, booth_rent_rates, booth_assignments, booths, vendors;
    RAISE EXCEPTION 'vendor truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        NULL;
END $$;

ROLLBACK;
