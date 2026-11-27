-- Booth deposits are a store switch, kept by booth assignment. A release cannot
-- exceed the deposit held for that stay. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000e941',
    '01900000-0000-7000-8000-000000000001',
    '2098-01-01',
    '2098-12-31',
    'open'
);

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-00000000e942', 'e942', 'Deposit booth');

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000e960', 'dee', 'Dee', 'Deposit');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES (
    '01900000-0000-7000-8000-00000000e9a2',
    '01900000-0000-7000-8000-00000000e942',
    '01900000-0000-7000-8000-00000000e960',
    '2098-01-01'
);

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-00000000e950',
    '01900000-0000-7000-8000-000000000d10',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

DO $$
BEGIN
    IF (SELECT booth_deposits_enabled FROM mall) THEN
        RAISE EXCEPTION 'booth deposits were on for a new store';
    END IF;
    PERFORM stall_post_booth_deposit('01900000-0000-7000-8000-00000000e9a2', 5000, 'off', '2098-04-01', false);
    RAISE EXCEPTION 'a booth deposit was taken with deposits turned off';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%Booth deposits are turned off for this store.%' THEN
            RAISE;
        END IF;
END $$;

UPDATE mall SET booth_deposits_enabled = true;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000e943',
    '01900000-0000-7000-8000-000000000001',
    'booth-deposit:e943',
    '2098-04-01',
    '2098-04-01 12:00:00+00',
    '01900000-0000-7000-8000-00000000e941',
    'USD',
    'booth_deposit',
    'held',
    'Booth deposit',
    5000,
    5000
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor
) VALUES (
    '01900000-0000-7000-8000-00000000e943',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-000000000010',
    5000,
    0
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000e943',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-00000000002d',
    0,
    5000,
    'assignment',
    '01900000-0000-7000-8000-00000000e9a2'
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-00000000e950', '01900000-0000-7000-8000-00000000e943');

DO $$
BEGIN
    UPDATE mall SET booth_deposits_enabled = false;
    RAISE EXCEPTION 'booth deposits were turned off while a deposit was held';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM <> 'A deposit is still being held for Booth e942. Return or apply it first.' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e944',
        '01900000-0000-7000-8000-000000000001',
        'booth-deposit-release:e944',
        '2098-04-02',
        '2098-04-02 12:00:00+00',
        '01900000-0000-7000-8000-00000000e941',
        'USD',
        'booth_deposit',
        'over',
        'Booth deposit returned',
        5001,
        5001
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000e944',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-00000000002d',
        5001,
        0,
        'assignment',
        '01900000-0000-7000-8000-00000000e9a2'
    );
    RAISE EXCEPTION 'a release larger than the deposit was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a release is larger than the deposit held%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-00000000e945',
    '01900000-0000-7000-8000-000000000001',
    'booth-deposit-release:e945',
    '2098-04-03',
    '2098-04-03 12:00:00+00',
    '01900000-0000-7000-8000-00000000e941',
    'USD',
    'booth_deposit',
    'exact',
    'Booth deposit returned',
    5000,
    5000
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
) VALUES (
    '01900000-0000-7000-8000-00000000e945',
    '01900000-0000-7000-8000-000000000001',
    1,
    '01900000-0000-7000-8000-00000000002d',
    5000,
    0,
    'assignment',
    '01900000-0000-7000-8000-00000000e9a2'
);

INSERT INTO journal_lines (
    journal_id, book_id, line_no, account_id, debit_minor, credit_minor
) VALUES (
    '01900000-0000-7000-8000-00000000e945',
    '01900000-0000-7000-8000-000000000001',
    2,
    '01900000-0000-7000-8000-000000000010',
    0,
    5000
);

INSERT INTO register_session_posts (session_id, journal_id)
VALUES ('01900000-0000-7000-8000-00000000e950', '01900000-0000-7000-8000-00000000e945');

DO $$
DECLARE
    held bigint;
BEGIN
    SELECT COALESCE(sum(credit_minor - debit_minor), 0) INTO held
    FROM journal_lines
    WHERE account_id = '01900000-0000-7000-8000-00000000002d'
      AND subledger_type = 'assignment'
      AND subledger_ref = '01900000-0000-7000-8000-00000000e9a2';
    IF held <> 0 THEN
        RAISE EXCEPTION 'the booth deposit did not return to zero';
    END IF;
    IF stall_vendor_deposit_held('01900000-0000-7000-8000-00000000e960') <> 0 THEN
        RAISE EXCEPTION 'the vendor deposit balance does not match the lines';
    END IF;
END $$;

-- Nothing held: the switch turns off, and back on for the rest of the proof.
SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;
UPDATE mall SET booth_deposits_enabled = false;
UPDATE mall SET booth_deposits_enabled = true;

-- Through the posting function: the deposit lands on the stay, and a release
-- larger than what is held is refused.
DO $$
DECLARE
    taken uuid;
    returned uuid;
BEGIN
    taken := stall_post_booth_deposit('01900000-0000-7000-8000-00000000e9a2', 2500, 'fn-take', '2098-05-01', false);
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-00000000e950', taken);
    SET CONSTRAINTS ALL IMMEDIATE;
    IF stall_vendor_deposit_held('01900000-0000-7000-8000-00000000e960') <> 2500 THEN
        RAISE EXCEPTION 'the function deposit did not land on the stay';
    END IF;
    BEGIN
        PERFORM stall_post_booth_deposit('01900000-0000-7000-8000-00000000e9a2', 2501, 'fn-over', '2098-05-02', true);
        RAISE EXCEPTION 'the function released more than the stay held';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM NOT LIKE '%a release is larger than the deposit held%' THEN
                RAISE;
            END IF;
    END;
    SET CONSTRAINTS ALL DEFERRED;
    returned := stall_post_booth_deposit('01900000-0000-7000-8000-00000000e9a2', 2500, 'fn-return', '2098-05-03', true);
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-00000000e950', returned);
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    IF stall_vendor_deposit_held('01900000-0000-7000-8000-00000000e960') <> 0 THEN
        RAISE EXCEPTION 'the function release did not clear the stay';
    END IF;
    IF (SELECT string_agg(role.role || ':' || line.debit_minor || '/' || line.credit_minor, ' ' ORDER BY line.line_no)
        FROM journal_lines AS line JOIN chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = taken) <> 'cash:2500/0 booth_deposit_held:0/2500'
       OR (SELECT string_agg(role.role || ':' || line.debit_minor || '/' || line.credit_minor, ' ' ORDER BY line.line_no)
        FROM journal_lines AS line JOIN chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = returned) <> 'booth_deposit_held:2500/0 cash:0/2500' THEN
        RAISE EXCEPTION 'a deposit is not cash in and a release cash out';
    END IF;
END $$;

-- A held deposit keeps a vendor who has left from being made inactive.
INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000e9b1',
    '01900000-0000-7000-8000-000000000001',
    '2026-01-01',
    '2026-12-31',
    'open'
);

INSERT INTO booths (id, code, name)
VALUES ('01900000-0000-7000-8000-00000000e9b3', 'e9b3', 'Left booth');

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000e961', 'lee', 'Lee', 'Left');

INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on, ends_on)
VALUES (
    '01900000-0000-7000-8000-00000000e9a3',
    '01900000-0000-7000-8000-00000000e9b3',
    '01900000-0000-7000-8000-00000000e961',
    '2026-01-01',
    '2026-01-31'
);

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-00000000e9b2', 'Deposit proof');

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-00000000e9b4',
    '01900000-0000-7000-8000-00000000e9b2',
    'Lee',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

DO $$
DECLARE
    taken uuid;
    said text;
    codes text;
BEGIN
    taken := stall_post_booth_deposit('01900000-0000-7000-8000-00000000e9a3', 1500, 'left-take', '2026-01-02', false);
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-00000000e9b4', taken);
    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    BEGIN
        UPDATE vendors SET active = false WHERE id = '01900000-0000-7000-8000-00000000e961';
        RAISE EXCEPTION 'a vendor whose deposit is still held was made inactive';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            GET STACKED DIAGNOSTICS said = MESSAGE_TEXT, codes = PG_EXCEPTION_DETAIL;
    END;
    IF said <> 'Lee Left can''t be made inactive yet. The store still holds a $15.00 booth deposit for them.'
       OR codes <> 'deposit' THEN
        RAISE EXCEPTION 'the deposit refusal read: % (%)', said, codes;
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e947',
        '01900000-0000-7000-8000-000000000001',
        'booth-deposit:e947',
        '2098-04-05',
        '2098-04-05 12:00:00+00',
        '01900000-0000-7000-8000-00000000e941',
        'USD',
        'booth_deposit',
        'nosession',
        'Booth deposit',
        100,
        100
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e947',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000010',
        100,
        0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000e947',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-00000000002d',
        0,
        100,
        'assignment',
        '01900000-0000-7000-8000-00000000e9a2'
    );
    SET CONSTRAINTS ALL IMMEDIATE;
    RAISE EXCEPTION 'a booth deposit without a shift was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a drawer journal needs an open register%' THEN
            RAISE;
        END IF;
END $$;

UPDATE register_sessions
SET closing_counts = '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    counted_cash_minor = 0,
    counted_card_minor = 0,
    counted_checks_minor = 0,
    closed_at = clock_timestamp()
WHERE id = '01900000-0000-7000-8000-00000000e950';

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e948',
        '01900000-0000-7000-8000-000000000001',
        'booth-deposit:e948',
        '2098-04-06',
        '2098-04-06 12:00:00+00',
        '01900000-0000-7000-8000-00000000e941',
        'USD',
        'booth_deposit',
        'closed',
        'Booth deposit',
        100,
        100
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e948',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000010',
        100,
        0
    );
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000e948',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-00000000002d',
        0,
        100,
        'assignment',
        '01900000-0000-7000-8000-00000000e9a2'
    );
    INSERT INTO register_session_posts (session_id, journal_id)
    VALUES ('01900000-0000-7000-8000-00000000e950', '01900000-0000-7000-8000-00000000e948');
    RAISE EXCEPTION 'a closed shift accepted a booth deposit';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a drawer journal needs an open register%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000e946',
        '01900000-0000-7000-8000-000000000001',
        'booth-deposit-release:e946',
        '2098-04-04',
        '2098-04-04 12:00:00+00',
        '01900000-0000-7000-8000-00000000e941',
        'USD',
        'booth_deposit',
        'replica',
        'Booth deposit returned',
        1,
        1
    );
    SET LOCAL session_replication_role = replica;
    INSERT INTO journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000e946',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-00000000002d',
        1,
        0,
        'assignment',
        '01900000-0000-7000-8000-00000000e9a2'
    );
    RAISE EXCEPTION 'a replica session released more than the booth held';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a release is larger than the deposit held%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE journal_lines DISABLE TRIGGER journal_lines_booth_deposit_append_only;
    RAISE EXCEPTION 'disabling the booth deposit trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER journal_lines_booth_deposit_append_only ON journal_lines;
    RAISE EXCEPTION 'dropping the booth deposit trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_refuse_booth_deposit_overdraw()
    RETURNS trigger
    LANGUAGE plpgsql
    SET search_path = pg_catalog
    AS $body$
    BEGIN
        RETURN NEW;
    END;
    $body$;
    RAISE EXCEPTION 'replacing the booth deposit function was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
            RAISE;
        END IF;
END $$;

ROLLBACK;
