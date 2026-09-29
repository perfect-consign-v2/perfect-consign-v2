-- A line discount lowers that line's sold price. Store take is the percent of the final price.
-- One transaction, then ROLLBACK.

BEGIN;

UPDATE mall SET tax_bps = 0, commission_bps = 0, cash_round_nickel = false;

INSERT INTO vendors (id, code, first_name, last_name, commission_bps)
VALUES ('01900000-0000-7000-8000-000000000f91', 'lineproof', 'Ada', 'Line', 5000);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on)
VALUES (
    '01900000-0000-7000-8000-000000000f81',
    'LineHat',
    '01900000-0000-7000-8000-000000000f91',
    'Wool hat',
    1000,
    'BC-LINE-HAT',
    DATE '2093-03-01'
);

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-000000000f86',
    '01900000-0000-7000-8000-000000000001',
    '2093-01-01',
    '2093-12-31',
    'open'
);

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-000000000f96', 'Line proof');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000f2a',
    '01900000-0000-7000-8000-000000000f96',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-000000000f71', '01900000-0000-7000-8000-000000000f2a');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
VALUES (
    '01900000-0000-7000-8000-000000000f71',
    1,
    '01900000-0000-7000-8000-000000000f81',
    1000
);

DO $$
BEGIN
    UPDATE ticket_lines
    SET line_discount_kind = 'amount', line_discount_value = 1100, line_discount_reason = 'damaged'
    WHERE ticket_id = '01900000-0000-7000-8000-000000000f71';
    RAISE EXCEPTION 'a line discount above the price was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%the discount is more than the price%' THEN
            RAISE;
        END IF;
END $$;

UPDATE ticket_lines
SET line_discount_kind = 'amount', line_discount_value = 600, line_discount_reason = 'damaged'
WHERE ticket_id = '01900000-0000-7000-8000-000000000f71';

DO $$
BEGIN
    IF (SELECT line_discount_minor FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000f71') <> 600 THEN
        RAISE EXCEPTION 'a discount above the old store take was refused';
    END IF;
END $$;

UPDATE ticket_lines
SET line_discount_kind = 'amount', line_discount_value = 200, line_discount_reason = 'damaged'
WHERE ticket_id = '01900000-0000-7000-8000-000000000f71';

DO $$
BEGIN
    IF (SELECT line_discount_minor FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-000000000f71') <> 200 THEN
        RAISE EXCEPTION 'the line discount was not 200';
    END IF;
END $$;

DO $$
BEGIN
    UPDATE tickets
    SET discount_kind = 'amount', discount_value = 900, discount_reason = 'promotion'
    WHERE id = '01900000-0000-7000-8000-000000000f71';
    RAISE EXCEPTION 'a ticket discount above the price was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%the discount is more than the price%' THEN
            RAISE;
        END IF;
END $$;

UPDATE tickets
SET discount_kind = 'percent', discount_value = 2000, discount_reason = 'promotion'
WHERE id = '01900000-0000-7000-8000-000000000f71';

DO $$
BEGIN
    IF stall_line_discount(
        '01900000-0000-7000-8000-000000000f71',
        '01900000-0000-7000-8000-000000000f81',
        'percent',
        2000
    ) <> 160 THEN
        RAISE EXCEPTION 'the ticket share after the line discount was not 160';
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000fc1',
    '01900000-0000-7000-8000-000000000001',
    'sale:line-discount', '2093-03-01', '2093-03-01 12:00:00+00',
    '01900000-0000-7000-8000-000000000f86',
    'USD', 'sale', 'line-discount', 'Line discount', 640, 640
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000fc1', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 640, 0),
    ('01900000-0000-7000-8000-000000000fc1', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000012', 0, 320);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000fc1',
    '01900000-0000-7000-8000-000000000001',
    3, '01900000-0000-7000-8000-000000000014', 0, 320, 'vendor', '01900000-0000-7000-8000-000000000f91'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor, discount_minor)
VALUES ('01900000-0000-7000-8000-000000000fc1', 1, '01900000-0000-7000-8000-000000000f81', 1000, 320, 0, 360);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000f2a', '01900000-0000-7000-8000-000000000fc1');

UPDATE tickets
SET status = 'posted', posted_journal_id = '01900000-0000-7000-8000-000000000fc1'
WHERE id = '01900000-0000-7000-8000-000000000f71';

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    IF (
        SELECT credit_minor
        FROM journal_lines
        WHERE journal_id = '01900000-0000-7000-8000-000000000fc1'
          AND account_id = '01900000-0000-7000-8000-000000000014'
    ) <> 320 THEN
        RAISE EXCEPTION 'the vendor was not owed half of the final price';
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
    debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000fc2',
    '01900000-0000-7000-8000-000000000001',
    'sale:line-discount:void', '2093-03-02', '2093-03-02 12:00:00+00',
    '01900000-0000-7000-8000-000000000f86',
    'USD', 'sale', 'line-discount-void', 'Void the line discount', true,
    '01900000-0000-7000-8000-000000000fc1',
    640, 640
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000fc2', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000012', 320, 0),
    ('01900000-0000-7000-8000-000000000fc2', '01900000-0000-7000-8000-000000000001', 3, '01900000-0000-7000-8000-000000000010', 0, 640);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000fc2',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 320, 0, 'vendor', '01900000-0000-7000-8000-000000000f91'
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000f2a', '01900000-0000-7000-8000-000000000fc2');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    ALTER TABLE ticket_lines DISABLE TRIGGER ticket_lines_no_delete;
    RAISE EXCEPTION 'disabling the line trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER ticket_lines_no_delete ON ticket_lines;
    RAISE EXCEPTION 'dropping the line trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_assert_ticket_line()
    RETURNS trigger
    LANGUAGE plpgsql
    SET search_path = pg_catalog
    AS $body$
    BEGIN
        RETURN NEW;
    END;
    $body$;
    RAISE EXCEPTION 'replacing the line function was accepted';
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
        UPDATE ticket_lines
        SET line_discount_kind = 'amount', line_discount_value = 600, line_discount_reason = 'other'
        WHERE ticket_id = '01900000-0000-7000-8000-000000000f71';
        RAISE EXCEPTION 'replica line discount was accepted';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM NOT LIKE '%a posted ticket cannot change%' THEN
                RAISE;
            END IF;
    END;
    BEGIN
        ALTER TABLE ticket_lines DISABLE TRIGGER ticket_lines_no_delete;
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
        TRUNCATE ticket_lines;
        RAISE EXCEPTION 'poster truncate was accepted';
    EXCEPTION
        WHEN insufficient_privilege OR SQLSTATE '25006' THEN
            NULL;
    END;
    BEGIN
        CREATE OR REPLACE FUNCTION stall_assert_ticket_line()
        RETURNS trigger
        LANGUAGE plpgsql
        AS $body$
        BEGIN
            RETURN NEW;
        END;
        $body$;
        RAISE EXCEPTION 'poster replaced the line function';
    EXCEPTION
        WHEN insufficient_privilege OR SQLSTATE '25006' THEN
            NULL;
    END;
    BEGIN
        SET LOCAL session_replication_role = replica;
        RAISE EXCEPTION 'poster replica mode was accepted';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    RESET ROLE;
END $$;

ROLLBACK;
