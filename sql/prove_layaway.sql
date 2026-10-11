-- A layaway deposit holds the item. The sale posts only when the deposits cover it.
-- One transaction, then ROLLBACK.

BEGIN;

UPDATE mall SET tax_bps = 0, commission_bps = 0, cash_round_nickel = false;

INSERT INTO vendors (id, code, first_name, last_name, commission_bps)
VALUES ('01900000-0000-7000-8000-000000000f92', 'layproof', 'Bea', 'Lay', 0);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity)
VALUES
    (
        '01900000-0000-7000-8000-000000000f82',
        'LayCoat',
        '01900000-0000-7000-8000-000000000f92',
        'Wool coat',
        1000,
        'BC-LAY-COAT',
        DATE '2094-03-01',
        1
    ),
    (
        '01900000-0000-7000-8000-000000000f83',
        'LayBag',
        '01900000-0000-7000-8000-000000000f92',
        'Leather bag',
        800,
        'BC-LAY-BAG',
        DATE '2094-03-01',
        1
    );

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-000000000f87',
    '01900000-0000-7000-8000-000000000001',
    '2094-01-01',
    '2094-12-31',
    'open'
);

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-000000000f97', 'Layaway proof');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000f2b',
    '01900000-0000-7000-8000-000000000f97',
    'Bea',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-000000000f72', '01900000-0000-7000-8000-000000000f2b');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
VALUES (
    '01900000-0000-7000-8000-000000000f72',
    1,
    '01900000-0000-7000-8000-000000000f82',
    1000
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000fc3',
    '01900000-0000-7000-8000-000000000001',
    'layaway:coat-short', '2094-03-01', '2094-03-01 12:00:00+00',
    '01900000-0000-7000-8000-000000000f87',
    'USD', 'layaway_deposit', '01900000-0000-7000-8000-000000000f72', 'Short deposit', 400, 400
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000fc3',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 400, 0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000fc3',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000021', 0, 400, 'ticket', '01900000-0000-7000-8000-000000000f72'
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000f2b', '01900000-0000-7000-8000-000000000fc3');

UPDATE tickets SET status = 'layaway' WHERE id = '01900000-0000-7000-8000-000000000f72';

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM sale_lines WHERE item_id = '01900000-0000-7000-8000-000000000f82'
    ) THEN
        RAISE EXCEPTION 'a short deposit sold the coat';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000fc4',
        '01900000-0000-7000-8000-000000000001',
        'layaway:coat-over', '2094-03-02', '2094-03-02 12:00:00+00',
        '01900000-0000-7000-8000-000000000f87',
        'USD', 'layaway_deposit', '01900000-0000-7000-8000-000000000f72', 'Deposit above the due', 700, 700
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000fc4',
        '01900000-0000-7000-8000-000000000001',
        1, '01900000-0000-7000-8000-000000000010', 700, 0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000fc4',
        '01900000-0000-7000-8000-000000000001',
        2, '01900000-0000-7000-8000-000000000021', 0, 700, 'ticket', '01900000-0000-7000-8000-000000000f72'
    );
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000f2b', '01900000-0000-7000-8000-000000000fc4');
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a deposit above the amount due was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a layaway deposit cannot pass the amount due%' THEN
            RAISE;
        END IF;
END $$;

SET CONSTRAINTS ALL DEFERRED;

UPDATE tickets SET status = 'open' WHERE id = '01900000-0000-7000-8000-000000000f72';

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000fc5',
    '01900000-0000-7000-8000-000000000001',
    'layaway:coat-rest', '2094-03-03', '2094-03-03 12:00:00+00',
    '01900000-0000-7000-8000-000000000f87',
    'USD', 'layaway_deposit', '01900000-0000-7000-8000-000000000f72', 'Rest of the deposit', 600, 600
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000fc5',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 600, 0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000fc5',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000021', 0, 600, 'ticket', '01900000-0000-7000-8000-000000000f72'
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000f2b', '01900000-0000-7000-8000-000000000fc5');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000fc6',
    '01900000-0000-7000-8000-000000000001',
    'sale:layaway-coat', '2094-03-04', '2094-03-04 12:00:00+00',
    '01900000-0000-7000-8000-000000000f87',
    'USD', 'sale', 'layaway-coat', 'Complete the layaway', 1000, 1000
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000fc6',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000021', 1000, 0, 'ticket', '01900000-0000-7000-8000-000000000f72'
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000fc6',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 1000, 'vendor', '01900000-0000-7000-8000-000000000f92'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor, discount_minor)
VALUES ('01900000-0000-7000-8000-000000000fc6', 1, '01900000-0000-7000-8000-000000000f82', 1000, 0, 0, 0);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000f2b', '01900000-0000-7000-8000-000000000fc6');

UPDATE tickets
SET status = 'posted', posted_journal_id = '01900000-0000-7000-8000-000000000fc6'
WHERE id = '01900000-0000-7000-8000-000000000f72';

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-000000000f73', '01900000-0000-7000-8000-000000000f2b');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
VALUES (
    '01900000-0000-7000-8000-000000000f73',
    1,
    '01900000-0000-7000-8000-000000000f83',
    800
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000fc7',
    '01900000-0000-7000-8000-000000000001',
    'layaway:bag', '2094-03-05', '2094-03-05 12:00:00+00',
    '01900000-0000-7000-8000-000000000f87',
    'USD', 'layaway_deposit', '01900000-0000-7000-8000-000000000f73', 'Bag deposit', 300, 300
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000fc7',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 300, 0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000fc7',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000021', 0, 300, 'ticket', '01900000-0000-7000-8000-000000000f73'
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000f2b', '01900000-0000-7000-8000-000000000fc7');

UPDATE tickets SET status = 'layaway' WHERE id = '01900000-0000-7000-8000-000000000f73';

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000fc8',
    '01900000-0000-7000-8000-000000000001',
    'layaway:bag:refund', '2094-03-06', '2094-03-06 12:00:00+00',
    '01900000-0000-7000-8000-000000000f87',
    'USD', 'layaway_refund', '01900000-0000-7000-8000-000000000f73', 'Cancel the bag', 300, 300
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000fc8',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000021', 300, 0, 'ticket', '01900000-0000-7000-8000-000000000f73'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000fc8',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000010', 0, 300
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000f2b', '01900000-0000-7000-8000-000000000fc8');

DELETE FROM tickets WHERE id = '01900000-0000-7000-8000-000000000f73';

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM ticket_lines WHERE item_id = '01900000-0000-7000-8000-000000000f83'
    ) THEN
        RAISE EXCEPTION 'cancel left the bag on a ticket';
    END IF;
    IF (
        SELECT COALESCE(sum(credit_minor - debit_minor), 0)
        FROM journal_lines
        WHERE account_id = '01900000-0000-7000-8000-000000000021'
          AND subledger_ref = '01900000-0000-7000-8000-000000000f73'
    ) <> 0 THEN
        RAISE EXCEPTION 'cancel left a layaway balance';
    END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER journals_layaway_no_delete ON journals;
    RAISE EXCEPTION 'dropping the layaway trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE journals DISABLE TRIGGER journals_layaway_no_delete;
    RAISE EXCEPTION 'disabling the layaway trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_assert_layaway()
    RETURNS trigger
    LANGUAGE plpgsql
    SET search_path = pg_catalog
    AS $body$
    BEGIN
        RETURN NULL;
    END;
    $body$;
    RAISE EXCEPTION 'replacing the layaway function was accepted';
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
        ALTER TABLE journals DISABLE TRIGGER journals_layaway_no_delete;
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
