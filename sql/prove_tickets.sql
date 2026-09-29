-- Proof that an open ticket is not a journal. One transaction, then ROLLBACK.

BEGIN;

UPDATE mall SET tax_bps = 0;

INSERT INTO vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-000000000101', 'ada', 'Ada', 'Lane');

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, commission_bps)
VALUES
    (
        '01900000-0000-7000-8000-0000000006a1',
        'Coat',
        '01900000-0000-7000-8000-000000000101',
        'Wool coat',
        2000,
        'BC-TICKET-COAT',
        DATE '2095-03-01',
        0
    ),
    (
        '01900000-0000-7000-8000-0000000006a2',
        'Lamp',
        '01900000-0000-7000-8000-000000000101',
        'Brass lamp',
        4000,
        'BC-TICKET-LAMP',
        DATE '2095-03-01',
        0
    ),
    (
        '01900000-0000-7000-8000-0000000006a3',
        'Hat',
        '01900000-0000-7000-8000-000000000101',
        'Felt hat',
        800,
        'BC-TICKET-HAT',
        DATE '2095-03-01',
        0
    );

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-0000000006a4',
    '01900000-0000-7000-8000-000000000001',
    '2095-01-01',
    '2095-12-31',
    'open'
);

INSERT INTO registers (id, name)
VALUES
    ('01900000-0000-7000-8000-000000000d94', 'Cart proof'),
    ('01900000-0000-7000-8000-000000000d95', 'Second cart');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d28',
    '01900000-0000-7000-8000-000000000d94',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-0000000006b1', '01900000-0000-7000-8000-000000000d28');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
VALUES
    ('01900000-0000-7000-8000-0000000006b1', 1, '01900000-0000-7000-8000-0000000006a1', 1000),
    ('01900000-0000-7000-8000-0000000006b1', 2, '01900000-0000-7000-8000-0000000006a2', 4000);

UPDATE ticket_lines
SET price_minor = 1500
WHERE ticket_id = '01900000-0000-7000-8000-0000000006b1'
  AND item_id = '01900000-0000-7000-8000-0000000006a1';

DO $$
BEGIN
    IF (
        SELECT price_minor FROM ticket_lines
        WHERE ticket_id = '01900000-0000-7000-8000-0000000006b1'
          AND item_id = '01900000-0000-7000-8000-0000000006a1'
    ) <> 1500 THEN
        RAISE EXCEPTION 'the sold price did not change';
    END IF;
    IF (SELECT price_minor FROM items WHERE id = '01900000-0000-7000-8000-0000000006a1') <> 2000 THEN
        RAISE EXCEPTION 'the asking price changed on the ticket';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
    VALUES ('01900000-0000-7000-8000-0000000006b1', 3, '01900000-0000-7000-8000-0000000006a1', 1000);
    RAISE EXCEPTION 'a second scan of the same piece was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO tickets (id, session_id)
    VALUES ('01900000-0000-7000-8000-0000000006b2', '01900000-0000-7000-8000-000000000d28');
    RAISE EXCEPTION 'a second open ticket was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000d29',
        '01900000-0000-7000-8000-000000000d95',
        'Bea',
        '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
        0
    );
    INSERT INTO tickets (id, session_id)
    VALUES ('01900000-0000-7000-8000-0000000006b3', '01900000-0000-7000-8000-000000000d29');
    INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
    VALUES ('01900000-0000-7000-8000-0000000006b3', 1, '01900000-0000-7000-8000-0000000006a1', 1500);
    RAISE EXCEPTION 'one piece was on two open tickets';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a piece is already on a ticket%' THEN
            RAISE;
        END IF;
END $$;

DELETE FROM ticket_lines
WHERE ticket_id = '01900000-0000-7000-8000-0000000006b1'
  AND item_id = '01900000-0000-7000-8000-0000000006a2';

DO $$
BEGIN
    INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
    VALUES ('01900000-0000-7000-8000-0000000006b1', 3, '01900000-0000-7000-8000-0000000006a2', 0);
    RAISE EXCEPTION 'a zero sold price was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-0000000006c1',
        '01900000-0000-7000-8000-000000000001',
        'sale:open-ticket',
        '2095-03-01',
        '2095-03-01 12:00:00+00',
        '01900000-0000-7000-8000-0000000006a4',
        'USD', 'sale', 'open-ticket', 'Sale while the ticket is open', 1500, 1500
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-0000000006c1',
        '01900000-0000-7000-8000-000000000001',
        1, '01900000-0000-7000-8000-000000000010', 1500, 0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-0000000006c1',
        '01900000-0000-7000-8000-000000000001',
        2, '01900000-0000-7000-8000-000000000014', 0, 1500, 'vendor', '01900000-0000-7000-8000-000000000101'
    );
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
    VALUES ('01900000-0000-7000-8000-0000000006c1', 1, '01900000-0000-7000-8000-0000000006a1', 1500, 0, 0);
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d28', '01900000-0000-7000-8000-0000000006c1');
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a sale left the piece on an open ticket';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a piece is already on a ticket%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-0000000006c4',
        '01900000-0000-7000-8000-000000000001',
        'sale:wrong-price',
        '2095-03-01',
        '2095-03-01 12:30:00+00',
        '01900000-0000-7000-8000-0000000006a4',
        'USD', 'sale', 'wrong-price', 'Price does not match the ticket', 1000, 1000
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-0000000006c4',
        '01900000-0000-7000-8000-000000000001',
        1, '01900000-0000-7000-8000-000000000010', 1000, 0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-0000000006c4',
        '01900000-0000-7000-8000-000000000001',
        2, '01900000-0000-7000-8000-000000000014', 0, 1000, 'vendor', '01900000-0000-7000-8000-000000000101'
    );
    INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
    VALUES ('01900000-0000-7000-8000-0000000006c4', 1, '01900000-0000-7000-8000-0000000006a1', 1000, 0, 0);
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-000000000d28', '01900000-0000-7000-8000-0000000006c4');
    UPDATE tickets
    SET status = 'posted', posted_journal_id = '01900000-0000-7000-8000-0000000006c4'
    WHERE id = '01900000-0000-7000-8000-0000000006b1';
    RAISE EXCEPTION 'a ticket posted at the wrong price';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a paid ticket must match its sale%' AND SQLERRM NOT LIKE '%a piece is already on a ticket%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000006c2',
    '01900000-0000-7000-8000-000000000001',
    'sale:ticket',
    '2095-03-01',
    '2095-03-01 13:00:00+00',
    '01900000-0000-7000-8000-0000000006a4',
    'USD', 'sale', 'ticket', 'Paid ticket', 1500, 1500
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-0000000006c2',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000010', 1500, 0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000006c2',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000014', 0, 1500, 'vendor', '01900000-0000-7000-8000-000000000101'
);

INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, commission_minor, tax_minor)
VALUES ('01900000-0000-7000-8000-0000000006c2', 1, '01900000-0000-7000-8000-0000000006a1', 1500, 0, 0);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d28', '01900000-0000-7000-8000-0000000006c2');

UPDATE tickets
SET status = 'posted', posted_journal_id = '01900000-0000-7000-8000-0000000006c2'
WHERE id = '01900000-0000-7000-8000-0000000006b1';

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    UPDATE ticket_lines
    SET price_minor = 1600
    WHERE ticket_id = '01900000-0000-7000-8000-0000000006b1';
    RAISE EXCEPTION 'a posted ticket price change was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a posted ticket cannot change%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DELETE FROM tickets WHERE id = '01900000-0000-7000-8000-0000000006b1';
    RAISE EXCEPTION 'a posted ticket was discarded';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a posted ticket cannot be discarded%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO tickets (id, session_id)
    VALUES ('01900000-0000-7000-8000-0000000006b4', '01900000-0000-7000-8000-000000000d28');
    INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
    VALUES ('01900000-0000-7000-8000-0000000006b4', 1, '01900000-0000-7000-8000-0000000006a1', 1500);
    RAISE EXCEPTION 'a sold piece was put on a new ticket';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%there are not enough on hand%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
    debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-0000000006c3',
    '01900000-0000-7000-8000-000000000001',
    'sale:ticket:void',
    '2095-03-02',
    '2095-03-02 12:00:00+00',
    '01900000-0000-7000-8000-0000000006a4',
    'USD', 'sale', 'ticket-void', 'Void the paid ticket', true,
    '01900000-0000-7000-8000-0000000006c2',
    1500, 1500
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-0000000006c3',
    '01900000-0000-7000-8000-000000000001',
    1, '01900000-0000-7000-8000-000000000014', 1500, 0, 'vendor', '01900000-0000-7000-8000-000000000101'
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-0000000006c3',
    '01900000-0000-7000-8000-000000000001',
    2, '01900000-0000-7000-8000-000000000010', 0, 1500
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-000000000d28', '01900000-0000-7000-8000-0000000006c3');

INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-0000000006b5', '01900000-0000-7000-8000-000000000d28');

INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
VALUES ('01900000-0000-7000-8000-0000000006b5', 1, '01900000-0000-7000-8000-0000000006a1', 1500);

DO $$
BEGIN
    UPDATE register_sessions
    SET closed_at = clock_timestamp(),
        closing_counts = '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
        counted_cash_minor = 0,
        counted_card_minor = 0,
        counted_checks_minor = 0
    WHERE id = '01900000-0000-7000-8000-000000000d28';
    RAISE EXCEPTION 'close with an open ticket was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%close is refused while a ticket is open%' THEN
            RAISE;
        END IF;
END $$;

DELETE FROM tickets WHERE id = '01900000-0000-7000-8000-0000000006b5';

DO $$
BEGIN
    IF EXISTS (SELECT 1 FROM ticket_lines WHERE ticket_id = '01900000-0000-7000-8000-0000000006b5') THEN
        RAISE EXCEPTION 'discard left a line behind';
    END IF;
END $$;

UPDATE register_sessions
SET closed_at = clock_timestamp(),
    closing_counts = '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    counted_cash_minor = 0,
    counted_card_minor = 0,
    counted_checks_minor = 0
WHERE id = '01900000-0000-7000-8000-000000000d28';

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    TRUNCATE ticket_lines;
    RAISE EXCEPTION 'ticket line truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%table ticket_lines is append-only and cannot be truncated%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    TRUNCATE tickets, ticket_lines, ticket_tenders;
    RAISE EXCEPTION 'ticket truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only and cannot be truncated%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE tickets DISABLE TRIGGER tickets_no_delete;
    RAISE EXCEPTION 'disabling the ticket trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE ticket_lines ENABLE REPLICA TRIGGER ticket_lines_no_delete;
    RAISE EXCEPTION 'replica-only ticket line trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER tickets_no_delete ON tickets;
    RAISE EXCEPTION 'dropping the ticket trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_assert_ticket()
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
    RAISE EXCEPTION 'replacing the ticket function was accepted';
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
        DELETE FROM tickets WHERE id = '01900000-0000-7000-8000-0000000006b1';
        RAISE EXCEPTION 'replica ticket delete was accepted';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM NOT LIKE '%a posted ticket cannot be discarded%' THEN
                RAISE;
            END IF;
    END;
    SET LOCAL session_replication_role = origin;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM tickets WHERE id = '01900000-0000-7000-8000-0000000006b1' AND status = 'posted'
    ) THEN
        RAISE EXCEPTION 'a replica attack removed a posted ticket';
    END IF;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    BEGIN
        TRUNCATE tickets, ticket_tenders;
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
