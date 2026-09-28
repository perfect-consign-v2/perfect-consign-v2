-- Proof that a sale is pieces, tax is on top, and a piece sells once. One transaction, then ROLLBACK.

BEGIN;

UPDATE mall SET tax_bps = 800, commission_bps = 0;

INSERT INTO vendors (id, code, first_name, last_name, commission_bps, tax_exempt, tax_exempt_number)
VALUES
    ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane', 2500, false, NULL),
    ('01900000-0000-7000-8000-000000000102', 'bea', 'Bea', 'Lane', NULL, true, 'EX-1');

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-000000000021',
    '01900000-0000-7000-8000-000000000001',
    '2099-01-01',
    '2099-12-31',
    'open'
);

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-000000000d91', 'Proof till');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d22',
    '01900000-0000-7000-8000-000000000d91',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on)
VALUES
    (
        '01900000-0000-7000-8000-0000000005b1',
        'Coat',
        '01900000-0000-7000-8000-000000000101',
        'Wool coat',
        12000,
        'BC-COAT',
        DATE '2099-03-01'
    ),
    (
        '01900000-0000-7000-8000-0000000005b2',
        'Lamp',
        '01900000-0000-7000-8000-000000000102',
        'Brass lamp',
        4000,
        'BC-LAMP',
        DATE '2099-03-01'
    );

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000005c1',
    '01900000-0000-7000-8000-000000000001',
    'sale:pieces',
    '2099-03-03',
    '2099-03-03 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD',
    'sale',
    'pieces',
    'Two vendors',
    14800,
    14800
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-0000000005c1', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 10000, 0),
    ('01900000-0000-7000-8000-0000000005c1', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000016', 4800, 0),
    ('01900000-0000-7000-8000-0000000005c1', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000012', 0, 2500),
    ('01900000-0000-7000-8000-0000000005c1', '01900000-0000-7000-8000-000000000001', 4, '01900000-0000-7000-8000-000000000013', 0, 800);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    (
        '01900000-0000-7000-8000-0000000005c1',
        '01900000-0000-7000-8000-000000000001',
        5,
        '01900000-0000-7000-8000-000000000014',
        0,
        7500,
        'vendor',
        '01900000-0000-7000-8000-000000000101'
    ),
    (
        '01900000-0000-7000-8000-0000000005c1',
        '01900000-0000-7000-8000-000000000001',
        6,
        '01900000-0000-7000-8000-000000000014',
        0,
        4000,
        'vendor',
        '01900000-0000-7000-8000-000000000102'
    );

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES
    ('01900000-0000-7000-8000-0000000005c1', 1, '01900000-0000-7000-8000-0000000005b1', 10000, 2500, 800),
    ('01900000-0000-7000-8000-0000000005c1', 2, '01900000-0000-7000-8000-0000000005b2', 4000, 0, 0);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-0000000005c1');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    IF (SELECT price_minor FROM items WHERE id = '01900000-0000-7000-8000-0000000005b1') <> 12000 THEN
        RAISE EXCEPTION 'the asking price changed when the piece sold';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-0000000005c2',
        '01900000-0000-7000-8000-000000000001',
        'sale:again',
        '2099-03-02',
        '2099-03-02 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD', 'sale', 'again', 'Sell the coat again', 10800, 10800
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES
        ('01900000-0000-7000-8000-0000000005c2', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 10800, 0),
        ('01900000-0000-7000-8000-0000000005c2', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 2500),
        ('01900000-0000-7000-8000-0000000005c2', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000013', 0, 800);
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-0000000005c2',
        '01900000-0000-7000-8000-000000000001',
        4, '01900000-0000-7000-8000-000000000014', 0, 7500, 'vendor', '01900000-0000-7000-8000-000000000101'
    );
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
    VALUES ('01900000-0000-7000-8000-0000000005c2', 1, '01900000-0000-7000-8000-0000000005b1', 10000, 2500, 800);
    RAISE EXCEPTION 'a sold piece was accepted again';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a piece is already sold%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
    VALUES ('01900000-0000-7000-8000-0000000005c1', 3, '01900000-0000-7000-8000-0000000005b1', 10000, 2500, 800);
    RAISE EXCEPTION 'a second line for the same piece was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
    VALUES ('01900000-0000-7000-8000-0000000005c1', 3, '01900000-0000-7000-8000-0000000005b2', 4000, 1, 0);
    RAISE EXCEPTION 'a wrong commission was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a sale commission does not match the store take%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
    VALUES ('01900000-0000-7000-8000-0000000005c1', 3, '01900000-0000-7000-8000-0000000005b2', 4000, 0, 320);
    RAISE EXCEPTION 'tax on an exempt vendor was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a sale tax does not match the mall rate%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    UPDATE mall SET tax_bps = 10001;
    RAISE EXCEPTION 'a tax rate over 100 percent was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES (
    '01900000-0000-7000-8000-0000000005b3',
    'Take',
    '01900000-0000-7000-8000-000000000101',
    'Full take',
    2000,
    'BC-TAKE',
    DATE '2099-03-01',
    10000
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000005c3',
    '01900000-0000-7000-8000-000000000001',
    'sale:full-take',
    '2099-03-03',
    '2099-03-03 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'sale', 'full-take', 'Store keeps the price', 2160, 2160
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-0000000005c3', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 2160, 0),
    ('01900000-0000-7000-8000-0000000005c3', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 2000),
    ('01900000-0000-7000-8000-0000000005c3', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000013', 0, 160);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-0000000005c3', 1, '01900000-0000-7000-8000-0000000005b3', 2000, 2000, 160);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-0000000005c3');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    SET CONSTRAINTS ALL DEFERRED;
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-0000000005c4',
        '01900000-0000-7000-8000-000000000001',
        'sale:no-piece',
        '2099-03-04',
        '2099-03-04 12:00:00+00',
        '01900000-0000-7000-8000-000000000021',
        'USD', 'sale', 'no-piece', 'Sale with no piece', 1000, 1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-0000000005c4',
        '01900000-0000-7000-8000-000000000001',
        1, '01900000-0000-7000-8000-000000000010', 1000, 0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-0000000005c4',
        '01900000-0000-7000-8000-000000000001',
        2, '01900000-0000-7000-8000-000000000014', 0, 1000, 'vendor', '01900000-0000-7000-8000-000000000101'
    );
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-0000000005c4');
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a sale with no piece was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a sale needs a piece%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
    debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000005c5',
    '01900000-0000-7000-8000-000000000001',
    'sale:pieces:void',
    '2099-03-05',
    '2099-03-05 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'sale', 'pieces-void', 'Void the ticket', true,
    '01900000-0000-7000-8000-0000000005c1',
    14800, 14800
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-0000000005c5', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000012', 2500, 0),
    ('01900000-0000-7000-8000-0000000005c5', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000013', 800, 0),
    ('01900000-0000-7000-8000-0000000005c5', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000010', 0, 10000),
    ('01900000-0000-7000-8000-0000000005c5', '01900000-0000-7000-8000-000000000001', 4, '01900000-0000-7000-8000-000000000016', 0, 4800);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    (
        '01900000-0000-7000-8000-0000000005c5',
        '01900000-0000-7000-8000-000000000001',
        5, '01900000-0000-7000-8000-000000000014', 7500, 0, 'vendor', '01900000-0000-7000-8000-000000000101'
    ),
    (
        '01900000-0000-7000-8000-0000000005c5',
        '01900000-0000-7000-8000-000000000001',
        6, '01900000-0000-7000-8000-000000000014', 4000, 0, 'vendor', '01900000-0000-7000-8000-000000000102'
    );

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-0000000005c5');

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000005c6',
    '01900000-0000-7000-8000-000000000001',
    'sale:coat-again',
    '2099-03-06',
    '2099-03-06 12:00:00+00',
    '01900000-0000-7000-8000-000000000021',
    'USD', 'sale', 'coat-again', 'Coat after the void', 10800, 10800
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-0000000005c6', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 10800, 0),
    ('01900000-0000-7000-8000-0000000005c6', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 2500),
    ('01900000-0000-7000-8000-0000000005c6', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000013', 0, 800);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000005c6',
    '01900000-0000-7000-8000-000000000001',
    4, '01900000-0000-7000-8000-000000000014', 0, 7500, 'vendor', '01900000-0000-7000-8000-000000000101'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-0000000005c6', 1, '01900000-0000-7000-8000-0000000005b1', 10000, 2500, 800);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-0000000005c6');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    UPDATE sale_lines SET price_minor = 1 WHERE journal_id = '01900000-0000-7000-8000-0000000005c6';
    RAISE EXCEPTION 'a sale line update was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a sale line cannot be changed%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DELETE FROM sale_lines WHERE journal_id = '01900000-0000-7000-8000-0000000005c6';
    RAISE EXCEPTION 'a sale line delete was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a sale line cannot be changed%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    TRUNCATE sale_lines;
    RAISE EXCEPTION 'sale line truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%table sale_lines is append-only and cannot be truncated%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE sale_lines DISABLE TRIGGER sale_lines_append_only;
    RAISE EXCEPTION 'disabling the sale line trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE sale_lines ENABLE REPLICA TRIGGER sale_lines_append_only;
    RAISE EXCEPTION 'replica-only sale line trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER sale_lines_append_only ON sale_lines;
    RAISE EXCEPTION 'dropping the sale line trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER journals_sale_shape ON journals;
    RAISE EXCEPTION 'dropping the sale shape trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_assert_sale_shape()
    RETURNS trigger
    LANGUAGE plpgsql
    SET search_path = pg_catalog
    AS $body$
    BEGIN
        IF TG_OP = 'DELETE' THEN
            RETURN OLD;
        END IF;
        RETURN NEW;
    END;
    $body$;
    RAISE EXCEPTION 'replacing the sale shape function was accepted';
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
        DELETE FROM sale_lines WHERE journal_id = '01900000-0000-7000-8000-0000000005c6';
        RAISE EXCEPTION 'replica sale line delete was accepted';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM NOT LIKE '%a sale line cannot be changed%' THEN
                RAISE;
            END IF;
    END;
    BEGIN
        ALTER TABLE sale_lines DISABLE TRIGGER sale_lines_append_only;
        RAISE EXCEPTION 'replica disable was accepted';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
                RAISE;
            END IF;
    END;
    SET LOCAL session_replication_role = origin;
END $$;

DO $$
BEGIN
    IF (SELECT count(*) FROM sale_lines WHERE journal_id = '01900000-0000-7000-8000-0000000005c6') <> 1 THEN
        RAISE EXCEPTION 'a replica attack removed a sale line';
    END IF;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    BEGIN
        TRUNCATE sale_lines;
        RAISE EXCEPTION 'poster truncate was accepted';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        CREATE OR REPLACE FUNCTION stall_assert_sale_shape()
        RETURNS trigger
        LANGUAGE plpgsql
        AS $body$
        BEGIN
            RETURN NEW;
        END;
        $body$;
        RAISE EXCEPTION 'poster replaced the sale shape function';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
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

ROLLBACK;
