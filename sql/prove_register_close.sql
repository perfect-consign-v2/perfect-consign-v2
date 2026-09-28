-- Proof for expected cash, drops, and over/short. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane');

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES
    (
        '01900000-0000-7000-8000-000000000db1',
        'Drawer',
        '01900000-0000-7000-8000-000000000101',
        'Drawer piece',
        5000,
        'BC-DRAWER',
        DATE '2033-02-01',
        0
    ),
    (
        '01900000-0000-7000-8000-000000000db2',
        'Later',
        '01900000-0000-7000-8000-000000000101',
        'Later piece',
        2000,
        'BC-LATER',
        DATE '2033-02-02',
        0
    );

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-000000000d42',
    '01900000-0000-7000-8000-000000000001',
    '2033-01-01',
    '2033-12-31',
    'open'
);

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-000000000d93', 'Close proof');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d26',
    '01900000-0000-7000-8000-000000000d93',
    'Ada',
    '{"10000":0,"5000":0,"2000":1,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    2000
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000d80',
    '01900000-0000-7000-8000-000000000001',
    'sale:d80',
    '2033-02-01',
    '2033-02-01 12:00:00+00',
    '01900000-0000-7000-8000-000000000d42',
    'USD', 'sale', 'd80', 'Cash sale', 5000, 5000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d80',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 5000, 0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000d80',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 5000, 'vendor', '01900000-0000-7000-8000-000000000101'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-000000000d80', 1, '01900000-0000-7000-8000-000000000db1', 5000, 0, 0);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000d81',
    '01900000-0000-7000-8000-000000000001',
    'drop:d81',
    '2033-02-01',
    '2033-02-01 15:00:00+00',
    '01900000-0000-7000-8000-000000000d42',
    'USD', 'cash_drop', 'd81', 'Drop to the safe', 1000, 1000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000d81', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-00000000001a', 1000, 0),
    ('01900000-0000-7000-8000-000000000d81', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000010', 0, 1000);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES
    ('01900000-0000-7000-8000-000000000d26', '01900000-0000-7000-8000-000000000d80'),
    ('01900000-0000-7000-8000-000000000d26', '01900000-0000-7000-8000-000000000d81');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

UPDATE register_sessions
SET closing_counts = '{"10000":0,"5000":0,"2000":3,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    counted_cash_minor = 6000,
    counted_card_minor = 0,
    counted_checks_minor = 0,
    closed_at = clock_timestamp()
WHERE id = '01900000-0000-7000-8000-000000000d26';

DO $$
BEGIN
    IF stall_register_expected_cash('01900000-0000-7000-8000-000000000d26') <> 6000 THEN
        RAISE EXCEPTION 'expected cash ignored the drop';
    END IF;
END $$;

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d27',
    '01900000-0000-7000-8000-000000000d93',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000d82',
    '01900000-0000-7000-8000-000000000001',
    'sale:d82',
    '2033-02-02',
    '2033-02-02 12:00:00+00',
    '01900000-0000-7000-8000-000000000d42',
    'USD', 'sale', 'd82', 'Later cash sale', 2000, 2000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d82',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 2000, 0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000d82',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 2000, 'vendor', '01900000-0000-7000-8000-000000000101'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-000000000d82', 1, '01900000-0000-7000-8000-000000000db2', 2000, 0, 0);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d27', '01900000-0000-7000-8000-000000000d82');

DO $$
BEGIN
    UPDATE register_sessions
    SET closing_counts = '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
        counted_cash_minor = 0,
        counted_card_minor = 0,
        counted_checks_minor = 0,
        closed_at = clock_timestamp()
    WHERE id = '01900000-0000-7000-8000-000000000d27';
    RAISE EXCEPTION 'a short count closed without confirmation';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%counted cash does not match the expected drawer%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000d83',
    '01900000-0000-7000-8000-000000000001',
    'variance:d83',
    '2033-02-02',
    '2033-02-02 18:00:00+00',
    '01900000-0000-7000-8000-000000000d42',
    'USD', 'register_variance', 'd83', 'Till over/short', 2000, 2000
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000d83', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-00000000001f', 2000, 0),
    ('01900000-0000-7000-8000-000000000d83', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000010', 0, 2000);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d27', '01900000-0000-7000-8000-000000000d83');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

UPDATE register_sessions
SET closing_counts = '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    counted_cash_minor = 0,
    counted_card_minor = 0,
    counted_checks_minor = 0,
    closed_at = clock_timestamp()
WHERE id = '01900000-0000-7000-8000-000000000d27';

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000d84',
    '01900000-0000-7000-8000-000000000001',
    'drop:d84',
    '2033-02-02',
    '2033-02-02 19:00:00+00',
    '01900000-0000-7000-8000-000000000d42',
    'USD', 'cash_drop', 'd84', 'Drop after close', 500, 500
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000d84', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-00000000001a', 500, 0),
    ('01900000-0000-7000-8000-000000000d84', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000010', 0, 500);

DO $$
BEGIN
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d27', '01900000-0000-7000-8000-000000000d84');
    RAISE EXCEPTION 'a drop was posted to a closed session';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a drawer journal needs an open register%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE register_sessions DISABLE TRIGGER register_sessions_close_no_delete;
    RAISE EXCEPTION 'disabling the close trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE register_sessions ENABLE REPLICA TRIGGER register_sessions_close_no_delete;
    RAISE EXCEPTION 'replica-only close trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE register_sessions ENABLE TRIGGER register_sessions_close_no_delete;
    RAISE EXCEPTION 'origin-only close trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER register_sessions_close_no_delete ON register_sessions;
    RAISE EXCEPTION 'dropping the close trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_assert_cash_drop()
    RETURNS trigger
    LANGUAGE plpgsql
    SET search_path = pg_catalog
    AS $body$
    BEGIN
        RETURN NULL;
    END;
    $body$;
    RAISE EXCEPTION 'replacing the cash drop function was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_assert_register_variance()
    RETURNS trigger
    LANGUAGE plpgsql
    SET search_path = pg_catalog
    AS $body$
    BEGIN
        RETURN NULL;
    END;
    $body$;
    RAISE EXCEPTION 'replacing the variance function was accepted';
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
        UPDATE register_sessions
        SET closing_counts = '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
            counted_cash_minor = 0,
            counted_card_minor = 0,
            counted_checks_minor = 0,
            closed_at = NULL
        WHERE id = '01900000-0000-7000-8000-000000000d27';
        RAISE EXCEPTION 'replica reopened a closed session';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM NOT LIKE '%a closed session cannot be changed%' THEN
                RAISE;
            END IF;
    END;
    SET LOCAL session_replication_role = origin;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_assert_register_close()
    RETURNS trigger
    LANGUAGE plpgsql
    SET search_path = pg_catalog
    AS $body$
    BEGIN
        RETURN NEW;
    END;
    $body$;
    RAISE EXCEPTION 'replacing the close function was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
            RAISE;
        END IF;
END $$;

ROLLBACK;
