-- Proof that one piece can be returned and sold again. One transaction, then ROLLBACK.

BEGIN;

UPDATE mall SET tax_bps = 0, cash_round_nickel = false;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane');

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES
    ('01900000-0000-7000-8000-0000000008a1', 'Coat', '01900000-0000-7000-8000-000000000101', 'Wool coat', 1000, 'BC-RETURN-COAT', DATE '2093-03-01', 0),
    ('01900000-0000-7000-8000-0000000008a2', 'Lamp', '01900000-0000-7000-8000-000000000101', 'Brass lamp', 4000, 'BC-RETURN-LAMP', DATE '2093-03-01', 0);

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-0000000008a3',
    '01900000-0000-7000-8000-000000000001',
    '2093-01-01', '2093-12-31', 'open'
);

INSERT INTO registers (id, name) VALUES ('01900000-0000-7000-8000-000000000d97', 'Return proof');
INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d2b',
    '01900000-0000-7000-8000-000000000d97',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000008c1',
    '01900000-0000-7000-8000-000000000001',
    'sale:return-ticket', '2093-03-01', '2093-03-01 12:00:00+00',
    '01900000-0000-7000-8000-0000000008a3',
    'USD', 'sale', 'return-ticket', 'Two pieces', 5000, 5000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-0000000008c1',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 5000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000008c1',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 5000, 'vendor', '01900000-0000-7000-8000-000000000101'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES
    ('01900000-0000-7000-8000-0000000008c1', 1, '01900000-0000-7000-8000-0000000008a1', 1000, 0, 0),
    ('01900000-0000-7000-8000-0000000008c1', 2, '01900000-0000-7000-8000-0000000008a2', 4000, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d2b', '01900000-0000-7000-8000-0000000008c1');

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000008c2',
    '01900000-0000-7000-8000-000000000001',
    'return:coat', '2093-03-02', '2093-03-02 12:00:00+00',
    '01900000-0000-7000-8000-0000000008a3',
    'USD', 'piece_return', 'return-coat', 'Return the coat', 1000, 1000
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000008c2',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 1000, 0, 'vendor', '01900000-0000-7000-8000-000000000101'
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-0000000008c2',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000010', 0, 1000
);
INSERT INTO piece_returns (journal_id, sale_journal_id, item_id, price_minor, commission_minor, tax_minor)
VALUES (
    '01900000-0000-7000-8000-0000000008c2',
    '01900000-0000-7000-8000-0000000008c1',
    '01900000-0000-7000-8000-0000000008a1',
    1000, 0, 0
);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d2b', '01900000-0000-7000-8000-0000000008c2');

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000008c3',
    '01900000-0000-7000-8000-000000000001',
    'sale:coat-again', '2093-03-03', '2093-03-03 12:00:00+00',
    '01900000-0000-7000-8000-0000000008a3',
    'USD', 'sale', 'coat-again', 'Sell the coat again', 1000, 1000
);
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-0000000008c3',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 1000, 0
);
INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000008c3',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 1000, 'vendor', '01900000-0000-7000-8000-000000000101'
);
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-0000000008c3', 1, '01900000-0000-7000-8000-0000000008a1', 1000, 0, 0);
INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d2b', '01900000-0000-7000-8000-0000000008c3');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-0000000008c4',
        '01900000-0000-7000-8000-000000000001',
        'sale:lamp-again', '2093-03-04', '2093-03-04 12:00:00+00',
        '01900000-0000-7000-8000-0000000008a3',
        'USD', 'sale', 'lamp-again', 'The lamp is still sold', 4000, 4000
    );
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
    VALUES ('01900000-0000-7000-8000-0000000008c4', 1, '01900000-0000-7000-8000-0000000008a2', 4000, 0, 0);
    RAISE EXCEPTION 'the rest of the ticket was sold again';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a piece is already sold%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO piece_returns (journal_id, sale_journal_id, item_id, price_minor, commission_minor, tax_minor)
    VALUES (
        '01900000-0000-7000-8000-0000000008c2',
        '01900000-0000-7000-8000-0000000008c1',
        '01900000-0000-7000-8000-0000000008a1',
        1000, 0, 0
    );
    RAISE EXCEPTION 'a second return of the piece was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-0000000008c5',
        '01900000-0000-7000-8000-000000000001',
        'return:wrong', '2093-03-05', '2093-03-05 12:00:00+00',
        '01900000-0000-7000-8000-0000000008a3',
        'USD', 'piece_return', 'return-wrong', 'Wrong amount', 4000, 4000
    );
    INSERT INTO piece_returns (journal_id, sale_journal_id, item_id, price_minor, commission_minor, tax_minor)
    VALUES (
        '01900000-0000-7000-8000-0000000008c5',
        '01900000-0000-7000-8000-0000000008c1',
        '01900000-0000-7000-8000-0000000008a2',
        1, 0, 0
    );
    RAISE EXCEPTION 'a wrong return amount was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a return reverses that piece%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    UPDATE piece_returns SET price_minor = 1 WHERE journal_id = '01900000-0000-7000-8000-0000000008c2';
    RAISE EXCEPTION 'changing a return was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a return cannot be changed%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    TRUNCATE piece_returns;
    RAISE EXCEPTION 'return truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%table piece_returns is append-only and cannot be truncated%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE piece_returns DISABLE TRIGGER piece_returns_no_delete;
    RAISE EXCEPTION 'disabling the return trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE piece_returns ENABLE REPLICA TRIGGER piece_returns_no_delete;
    RAISE EXCEPTION 'replica-only return trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER piece_returns_no_delete ON piece_returns;
    RAISE EXCEPTION 'dropping the return trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_assert_piece_return()
    RETURNS trigger
    LANGUAGE plpgsql
    SET search_path = pg_catalog
    AS $body$
    BEGIN
        RETURN NULL;
    END;
    $body$;
    RAISE EXCEPTION 'replacing the return function was accepted';
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
        DELETE FROM piece_returns WHERE journal_id = '01900000-0000-7000-8000-0000000008c2';
        RAISE EXCEPTION 'replica return delete was accepted';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM NOT LIKE '%a return cannot be changed%' THEN
                RAISE;
            END IF;
    END;
    SET LOCAL session_replication_role = origin;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM piece_returns WHERE journal_id = '01900000-0000-7000-8000-0000000008c2'
    ) THEN
        RAISE EXCEPTION 'a replica attack removed a return';
    END IF;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    BEGIN
        TRUNCATE piece_returns;
        RAISE EXCEPTION 'poster truncate was accepted';
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
