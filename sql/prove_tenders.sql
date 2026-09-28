-- Proof for checks, safe, over/short, and stored value. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-000000000d40',
    '01900000-0000-7000-8000-000000000001',
    '2031-01-01',
    '2031-12-31',
    'open'
);

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d22',
    '01900000-0000-7000-8000-000000000d10',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

DO $$
BEGIN
    INSERT INTO stored_values (kind, number) VALUES ('gift_certificate', '');
    RAISE EXCEPTION 'blank stored value number was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO stored_values (kind, number) VALUES ('gift_certificate', '  GC');
    RAISE EXCEPTION 'padded stored value number was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO stored_values (kind, number) VALUES ('other', 'GC-1');
    RAISE EXCEPTION 'unknown stored value kind was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

INSERT INTO stored_values (id, kind, number)
VALUES
    ('01900000-0000-7000-8000-000000000d60', 'gift_certificate', 'GC-1'),
    ('01900000-0000-7000-8000-000000000d61', 'gift_card', 'GC-1'),
    ('01900000-0000-7000-8000-000000000d62', 'store_credit', 'SC-1');

DO $$
BEGIN
    INSERT INTO stored_values (kind, number) VALUES ('gift_certificate', 'gc-1');
    RAISE EXCEPTION 'stored value number case collision was accepted';
EXCEPTION
    WHEN SQLSTATE '23505' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000d50',
        '01900000-0000-7000-8000-000000000001',
        'opening',
        '2031-01-02',
        '2031-01-02 12:00:00+00',
        '01900000-0000-7000-8000-000000000d40',
        'USD',
        'opening',
        'opening',
        'Debit a certificate before it is issued',
        1000,
        1000
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000d50',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-00000000001c',
        1000,
        0,
        'gift_certificate',
        '01900000-0000-7000-8000-000000000d60'
    );
    INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000d50',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000010',
        0,
        1000
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a gift certificate went below zero';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a gift certificate cannot go below zero%' THEN
            RAISE;
        END IF;
END $$;

SET CONSTRAINTS ALL DEFERRED;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000d51',
    '01900000-0000-7000-8000-000000000001',
    'gift-cert:d51',
    '2031-01-03',
    '2031-01-03 12:00:00+00',
    '01900000-0000-7000-8000-000000000d40',
    'USD',
    'gift_certificate_issue',
    'GC-1',
    'Issue gift certificate GC-1',
    2500,
    2500
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d51',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000010',
    2500,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000d51',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-00000000001c',
    0,
    2500,
    'gift_certificate',
    '01900000-0000-7000-8000-000000000d60'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000d52',
    '01900000-0000-7000-8000-000000000001',
    'gift-card:d52',
    '2031-01-03',
    '2031-01-03 12:10:00+00',
    '01900000-0000-7000-8000-000000000d40',
    'USD',
    'gift_card_issue',
    'GC-1',
    'Issue gift card GC-1',
    1500,
    1500
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d52',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000016',
    1500,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000d52',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-00000000001d',
    0,
    1500,
    'gift_card',
    '01900000-0000-7000-8000-000000000d61'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000d53',
    '01900000-0000-7000-8000-000000000001',
    'store-credit:d53',
    '2031-01-03',
    '2031-01-03 12:20:00+00',
    '01900000-0000-7000-8000-000000000d40',
    'USD',
    'store_credit_issue',
    'SC-1',
    'Issue store credit SC-1',
    800,
    800
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d53',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-00000000001b',
    800,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-000000000d53',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-00000000001e',
    0,
    800,
    'store_credit',
    '01900000-0000-7000-8000-000000000d62'
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES
    ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000d51'),
    ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000d52'),
    ('01900000-0000-7000-8000-000000000d22', '01900000-0000-7000-8000-000000000d53');

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

DO $$
BEGIN
    UPDATE stored_values SET number = 'GC-2' WHERE id = '01900000-0000-7000-8000-000000000d60';
    RAISE EXCEPTION 'a stored value number was changed';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a stored value number cannot be changed%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DELETE FROM stored_values WHERE id = '01900000-0000-7000-8000-000000000d60';
    RAISE EXCEPTION 'a referenced stored value was deleted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a stored value referenced by a journal line cannot be deleted%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-000000000d51',
        '01900000-0000-7000-8000-000000000001',
        3,
        '01900000-0000-7000-8000-00000000001c',
        0,
        1,
        'gift_certificate',
        '01900000-0000-7000-8000-000000000d61'
    );
    RAISE EXCEPTION 'a gift card was used as a certificate';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%stored value subledger ref must be that kind of number%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    TRUNCATE stored_values;
    RAISE EXCEPTION 'stored value truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%table stored_values is append-only and cannot be truncated%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE stored_values DISABLE TRIGGER stored_values_no_delete;
    RAISE EXCEPTION 'disabling the stored value trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER stored_values_no_delete ON stored_values;
    RAISE EXCEPTION 'dropping the stored value trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_reject_stored_value_change()
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
    RAISE EXCEPTION 'replacing the stored value function was accepted';
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
        DELETE FROM stored_values WHERE id = '01900000-0000-7000-8000-000000000d60';
        RAISE EXCEPTION 'replica stored value delete was accepted';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM NOT LIKE '%a stored value referenced by a journal line cannot be deleted%' THEN
                RAISE;
            END IF;
    END;
    SET LOCAL session_replication_role = origin;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM stored_values WHERE id = '01900000-0000-7000-8000-000000000d60' AND number = 'GC-1'
    ) THEN
        RAISE EXCEPTION 'a replica attack changed the certificate';
    END IF;
END $$;

ROLLBACK;
