-- Proof for register sessions. One transaction, then ROLLBACK.
-- Run with search_path set to the mall schema. The poster role is stall_poster.

BEGIN;

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-000000000d11', 'Back');

DO $$
BEGIN
    INSERT INTO register_sessions (register_id, cashier, opening_counts, opening_cash_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000d10',
        ' ',
        '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
        0
    );
    RAISE EXCEPTION 'blank cashier was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO register_sessions (register_id, cashier, opening_counts, opening_cash_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000d10',
        'Ada',
        '{"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
        0
    );
    RAISE EXCEPTION 'a short count was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%opening cash is the bill and coin count%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO register_sessions (register_id, cashier, opening_counts, opening_cash_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000d10',
        'Ada',
        '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0,"50":1}'::jsonb,
        0
    );
    RAISE EXCEPTION 'an extra denomination was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%opening cash is the bill and coin count%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO register_sessions (register_id, cashier, opening_counts, opening_cash_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000d10',
        'Ada',
        '{"10000":-1,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
        0
    );
    RAISE EXCEPTION 'a negative count was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%opening cash is the bill and coin count%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO register_sessions (register_id, cashier, opening_counts, opening_cash_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000d10',
        'Ada',
        '{"10000":1.5,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
        150000
    );
    RAISE EXCEPTION 'a fractional count was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%opening cash is the bill and coin count%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO register_sessions (register_id, cashier, opening_counts, opening_cash_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000d10',
        'Ada',
        '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
        1
    );
    RAISE EXCEPTION 'a typed float that disagrees with the count was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%opening cash is the bill and coin count%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO register_sessions (
        register_id, cashier, opening_counts, opening_cash_minor, closed_at, closing_counts, counted_cash_minor
    ) VALUES (
        '01900000-0000-7000-8000-000000000d10',
        'Ada',
        '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
        0,
        clock_timestamp(),
        '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
        0
    );
    RAISE EXCEPTION 'a session opened already closed';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a session opens before it closes%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d20',
    '01900000-0000-7000-8000-000000000d10',
    'Ada',
    '{"10000":0,"5000":0,"2000":1,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":1}'::jsonb,
    2001
);

DO $$
BEGIN
    INSERT INTO register_sessions (register_id, cashier, opening_counts, opening_cash_minor)
    VALUES (
        '01900000-0000-7000-8000-000000000d10',
        'Bee',
        '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
        0
    );
    RAISE EXCEPTION 'a second open session was accepted';
EXCEPTION
    WHEN SQLSTATE '23505' THEN
        NULL;
END $$;

INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d21',
    '01900000-0000-7000-8000-000000000d11',
    'Bee',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

DO $$
BEGIN
    UPDATE register_sessions
    SET opening_cash_minor = 0
    WHERE id = '01900000-0000-7000-8000-000000000d20';
    RAISE EXCEPTION 'an opening count was changed';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%an opening count cannot be changed%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    UPDATE register_sessions
    SET closing_counts = '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
        counted_cash_minor = 1,
        closed_at = clock_timestamp()
    WHERE id = '01900000-0000-7000-8000-000000000d20';
    RAISE EXCEPTION 'a close count that disagrees was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%counted cash is the bill and coin count%' THEN
            RAISE;
        END IF;
END $$;

UPDATE register_sessions
SET closing_counts = '{"10000":0,"5000":0,"2000":1,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":1}'::jsonb,
    counted_cash_minor = 2001,
    counted_card_minor = 0,
    counted_checks_minor = 0,
    closed_at = clock_timestamp()
WHERE id = '01900000-0000-7000-8000-000000000d20';

DO $$
BEGIN
    UPDATE register_sessions
    SET cashier = 'Cia'
    WHERE id = '01900000-0000-7000-8000-000000000d20';
    RAISE EXCEPTION 'a closed session was changed';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a closed session cannot be changed%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DELETE FROM register_sessions WHERE id = '01900000-0000-7000-8000-000000000d21';
    RAISE EXCEPTION 'a session delete was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a register session cannot be deleted%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DELETE FROM registers WHERE id = '01900000-0000-7000-8000-000000000d11';
    RAISE EXCEPTION 'a register with a session was deleted';
EXCEPTION
    WHEN SQLSTATE '23503' THEN
        NULL;
END $$;

DO $$
BEGIN
    TRUNCATE register_sessions CASCADE;
    RAISE EXCEPTION 'session truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%table register_sessions is append-only and cannot be truncated%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE register_sessions DISABLE TRIGGER register_sessions_no_delete;
    RAISE EXCEPTION 'disabling the session delete trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE register_sessions ENABLE REPLICA TRIGGER register_sessions_append_only;
    RAISE EXCEPTION 'replica-only session trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER register_sessions_append_only ON register_sessions;
    RAISE EXCEPTION 'dropping the session trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_reject_session_rewrite()
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
    RAISE EXCEPTION 'replacing the session function was accepted';
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
        DELETE FROM register_sessions WHERE id = '01900000-0000-7000-8000-000000000d21';
        RAISE EXCEPTION 'replica session delete was accepted';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM NOT LIKE '%a register session cannot be deleted%' THEN
                RAISE;
            END IF;
    END;
    BEGIN
        TRUNCATE register_sessions CASCADE;
        RAISE EXCEPTION 'replica session truncate was accepted';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%table register_sessions is append-only and cannot be truncated%' THEN
                RAISE;
            END IF;
    END;
    SET LOCAL session_replication_role = origin;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM register_sessions
        WHERE id = '01900000-0000-7000-8000-000000000d21'
          AND closed_at IS NULL
          AND opening_cash_minor = 0
    ) THEN
        RAISE EXCEPTION 'a replica attack changed the open session';
    END IF;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    BEGIN
        DELETE FROM register_sessions WHERE id = '01900000-0000-7000-8000-000000000d21';
        RAISE EXCEPTION 'poster deleted a session';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        TRUNCATE register_sessions CASCADE;
        RAISE EXCEPTION 'poster truncated sessions';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    RESET ROLE;
END $$;

ROLLBACK;
