-- A vendor draw is a tender. It cannot exceed the payable that existed before this sale.
-- One transaction, then ROLLBACK.

BEGIN;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-000000000e91', 'drawproof', 'Bea', 'Draw');

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES
    ('01900000-0000-7000-8000-000000000e81', 'DrawSeed', '01900000-0000-7000-8000-000000000e91', 'Opens the payable', 1000, 'BC-DRAW-SEED', DATE '2092-03-01', 0),
    ('01900000-0000-7000-8000-000000000e82', 'DrawOver', '01900000-0000-7000-8000-000000000e91', 'Draws too much', 1500, 'BC-DRAW-OVER', DATE '2092-03-01', 0),
    ('01900000-0000-7000-8000-000000000e83', 'DrawOk', '01900000-0000-7000-8000-000000000e91', 'Draws with cash', 800, 'BC-DRAW-OK', DATE '2092-03-01', 0);

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-000000000e86',
    '01900000-0000-7000-8000-000000000001',
    '2092-01-01',
    '2092-12-31',
    'open'
);

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-000000000e96', 'Draw proof');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000e2a',
    '01900000-0000-7000-8000-000000000e96',
    'Bea',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

UPDATE mall SET tax_bps = 0, cash_round_nickel = false;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000ec1',
    '01900000-0000-7000-8000-000000000001',
    'sale:draw-seed', '2092-03-01', '2092-03-01 12:00:00+00',
    '01900000-0000-7000-8000-000000000e86',
    'USD', 'sale', 'draw-seed', 'Seed the payable', 1000, 1000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000ec1',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 1000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000ec1',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 1000, 'vendor', '01900000-0000-7000-8000-000000000e91'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor, discount_minor)
VALUES ('01900000-0000-7000-8000-000000000ec1', 1, '01900000-0000-7000-8000-000000000e81', 1000, 0, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000e2a', '01900000-0000-7000-8000-000000000ec1');
SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000ec2',
        '01900000-0000-7000-8000-000000000001',
        'sale:draw-over', '2092-03-02', '2092-03-02 12:00:00+00',
        '01900000-0000-7000-8000-000000000e86',
        'USD', 'sale', 'draw-over', 'Draw above the payable', 1500, 1500
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000ec2',
        '01900000-0000-7000-8000-000000000001',
        1, '01900000-0000-7000-8000-000000000010', 300, 0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES
        (
            '01900000-0000-7000-8000-000000000ec2',
            '01900000-0000-7000-8000-000000000001',
            2, '01900000-0000-7000-8000-000000000014', 1200, 0, 'vendor', '01900000-0000-7000-8000-000000000e91'
        ),
        (
            '01900000-0000-7000-8000-000000000ec2',
            '01900000-0000-7000-8000-000000000001',
            3, '01900000-0000-7000-8000-000000000014', 0, 1500, 'vendor', '01900000-0000-7000-8000-000000000e91'
        );
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor, discount_minor)
    VALUES ('01900000-0000-7000-8000-000000000ec2', 1, '01900000-0000-7000-8000-000000000e82', 1500, 0, 0, 0);
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000e2a', '01900000-0000-7000-8000-000000000ec2');
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a draw above the open payable was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a vendor draw is limited to the open payable%' THEN
            RAISE;
        END IF;
END $$;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000ec3',
    '01900000-0000-7000-8000-000000000001',
    'sale:draw-ok', '2092-03-03', '2092-03-03 12:00:00+00',
    '01900000-0000-7000-8000-000000000e86',
    'USD', 'sale', 'draw-ok', 'Draw with cash', 800, 800
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000ec3',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 300, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    (
        '01900000-0000-7000-8000-000000000ec3',
        '01900000-0000-7000-8000-000000000001',
        2, '01900000-0000-7000-8000-000000000014', 500, 0, 'vendor', '01900000-0000-7000-8000-000000000e91'
    ),
    (
        '01900000-0000-7000-8000-000000000ec3',
        '01900000-0000-7000-8000-000000000001',
        3, '01900000-0000-7000-8000-000000000014', 0, 800, 'vendor', '01900000-0000-7000-8000-000000000e91'
    );
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor, discount_minor)
VALUES ('01900000-0000-7000-8000-000000000ec3', 1, '01900000-0000-7000-8000-000000000e83', 800, 0, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000e2a', '01900000-0000-7000-8000-000000000ec3');
SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
    debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000ec4',
    '01900000-0000-7000-8000-000000000001',
    'sale:draw-void', '2092-03-04', '2092-03-04 12:00:00+00',
    '01900000-0000-7000-8000-000000000e86',
    'USD', 'sale', 'draw-void', 'Void the draw', true,
    '01900000-0000-7000-8000-000000000ec3',
    800, 800
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000ec4',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 0, 300
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES
    (
        '01900000-0000-7000-8000-000000000ec4',
        '01900000-0000-7000-8000-000000000001',
        2, '01900000-0000-7000-8000-000000000014', 0, 500, 'vendor', '01900000-0000-7000-8000-000000000e91'
    ),
    (
        '01900000-0000-7000-8000-000000000ec4',
        '01900000-0000-7000-8000-000000000001',
        3, '01900000-0000-7000-8000-000000000014', 800, 0, 'vendor', '01900000-0000-7000-8000-000000000e91'
    );
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000e2a', '01900000-0000-7000-8000-000000000ec4');
SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

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
    CREATE OR REPLACE FUNCTION stall_assert_sale_shape()
    RETURNS trigger
    LANGUAGE plpgsql
    SET search_path = pg_catalog
    AS $body$
    BEGIN
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
        DELETE FROM sale_lines WHERE journal_id = '01900000-0000-7000-8000-000000000ec1';
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
