-- Effective-dated assignment rent. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000ea01',
    '01900000-0000-7000-8000-000000000001',
    '2098-01-01',
    '2098-12-31',
    'open'
);

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-00000000ea02', 'ea02', 'Rate', 'History');

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-00000000ea03', 'ea03', 'Rate booth');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-00000000ea04',
    '01900000-0000-7000-8000-00000000ea03',
    '01900000-0000-7000-8000-00000000ea02',
    '2098-01-01'
);

INSERT INTO assignment_rent (assignment_id, amount_minor)
VALUES ('01900000-0000-7000-8000-00000000ea04', 10000);

DO $$
BEGIN
    IF (
        SELECT amount_minor
        FROM assignment_rent_rates
        WHERE assignment_id = '01900000-0000-7000-8000-00000000ea04'
          AND starts_on = '2098-01-01'
    ) IS DISTINCT FROM 10000 THEN
        RAISE EXCEPTION 'seeding assignment_rent did not write the opening rate';
    END IF;
END $$;

INSERT INTO assignment_rent_rates (assignment_id, starts_on, amount_minor)
VALUES ('01900000-0000-7000-8000-00000000ea04', '2098-04-01', 12500);

DO $$
BEGIN
    INSERT INTO assignment_rent_rates (assignment_id, starts_on, amount_minor)
    VALUES ('01900000-0000-7000-8000-00000000ea04', '2098-04-01', 13000);
    RAISE EXCEPTION 'a duplicate effective date was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE assignment_rent_rates
    SET amount_minor = 1
    WHERE assignment_id = '01900000-0000-7000-8000-00000000ea04'
      AND starts_on = '2098-01-01';
    RAISE EXCEPTION 'updating an assignment rent rate was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%an assignment rent rate cannot change%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DELETE FROM assignment_rent_rates
    WHERE assignment_id = '01900000-0000-7000-8000-00000000ea04'
      AND starts_on = '2098-01-01';
    RAISE EXCEPTION 'deleting an assignment rent rate was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%an assignment rent rate cannot change%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    TRUNCATE assignment_rent_rates;
    RAISE EXCEPTION 'truncating assignment_rent_rates was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%table assignment_rent_rates is append-only and cannot be truncated%' THEN
            RAISE;
        END IF;
END $$;

-- March still charges the opening rate.
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000ea05',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-00000000ea03:2098-03',
    '2098-03-01',
    '2098-03-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000ea01',
    'USD',
    'rent_charge',
    'ea03:2098-03',
    'March rent before the raise',
    10000,
    10000
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000ea05',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000015',
    10000,
    0,
    'booth',
    '01900000-0000-7000-8000-00000000ea03'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000ea05',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000017',
    0,
    10000
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

-- April at the old rate is refused.
DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000ea06',
        '01900000-0000-7000-8000-000000000001',
        'rent-charge:01900000-0000-7000-8000-00000000ea03:2098-04',
        '2098-04-01',
        '2098-04-01 12:00:00+00',
        '01900000-0000-7000-8000-00000000ea01',
        'USD',
        'rent_charge',
        'ea03:2098-04-old',
        'April rent ignored the raise',
        10000,
        10000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000ea06',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000015',
        10000,
        0,
        'booth',
        '01900000-0000-7000-8000-00000000ea03'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-00000000ea06',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000017',
        0,
        10000
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a rent charge that ignored the effective-dated raise was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

-- April at the raised rate posts.
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000ea07',
    '01900000-0000-7000-8000-000000000001',
    'rent-charge:01900000-0000-7000-8000-00000000ea03:2098-04',
    '2098-04-01',
    '2098-04-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000ea01',
    'USD',
    'rent_charge',
    'ea03:2098-04',
    'April rent after the raise',
    12500,
    12500
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000ea07',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000015',
    12500,
    0,
    'booth',
    '01900000-0000-7000-8000-00000000ea03'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-00000000ea07',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000017',
    0,
    12500
);

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

-- Attack the append-only guard.
DO $$
BEGIN
    ALTER TABLE assignment_rent_rates DISABLE TRIGGER assignment_rent_rates_append_only;
    RAISE EXCEPTION 'disabling assignment_rent_rates_append_only was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE assignment_rent_rates ENABLE REPLICA TRIGGER assignment_rent_rates_append_only;
    RAISE EXCEPTION 'replica-only assignment_rent_rates_append_only was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER assignment_rent_rates_append_only ON assignment_rent_rates;
    RAISE EXCEPTION 'dropping assignment_rent_rates_append_only was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_reject_assignment_rent_rate_rewrite()
    RETURNS trigger
    LANGUAGE plpgsql
    SET search_path = pg_catalog
    AS $body$
    BEGIN
        RETURN NEW;
    END;
    $body$;
    RAISE EXCEPTION 'replacing stall_reject_assignment_rent_rate_rewrite was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    SET LOCAL session_replication_role = replica;
    BEGIN
        ALTER TABLE assignment_rent_rates DISABLE TRIGGER assignment_rent_rates_append_only;
        RAISE EXCEPTION 'replica disable of assignment_rent_rates_append_only was accepted';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            NULL;
    END;
    SET LOCAL session_replication_role = origin;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    BEGIN
        SET LOCAL session_replication_role = replica;
        RAISE EXCEPTION 'poster replica mode was accepted';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        SET LOCAL event_triggers = false;
        RAISE EXCEPTION 'poster turned event triggers off';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    RESET ROLE;
END $$;

DO $$
BEGIN
    SET LOCAL session_replication_role = replica;
    UPDATE assignment_rent_rates
    SET amount_minor = 1
    WHERE assignment_id = '01900000-0000-7000-8000-00000000ea04'
      AND starts_on = '2098-01-01';
    IF FOUND THEN
        RAISE EXCEPTION 'replica session changed an assignment rent rate';
    END IF;
    SET LOCAL session_replication_role = origin;
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        SET LOCAL session_replication_role = origin;
END $$;

ROLLBACK;
