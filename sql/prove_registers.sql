-- Proof for registers. One transaction, then ROLLBACK.
-- Run with search_path set to the mall schema. The poster role is stall_poster.
-- Postgres does not deliver ALTER EVENT TRIGGER or DROP EVENT TRIGGER to this guard.
-- A superuser can set event_triggers=false and then replace a guard function. That is the way the guard comes off.

BEGIN;

DO $$
DECLARE
    seeded integer;
BEGIN
    SELECT count(*) INTO seeded
    FROM registers
    WHERE id = '01900000-0000-7000-8000-000000000d10'
      AND name = 'Register';
    IF seeded <> 1 THEN
        RAISE EXCEPTION 'the mall does not start with Register';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO registers (name) VALUES ('');
    RAISE EXCEPTION 'blank register name was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO registers (name) VALUES ('  padded');
    RAISE EXCEPTION 'padded register name was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO registers (name) VALUES (repeat('r', 41));
    RAISE EXCEPTION 'a long register name was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO registers (name) VALUES ('register');
    RAISE EXCEPTION 'register name case collision was accepted';
EXCEPTION
    WHEN SQLSTATE '23505' THEN
        NULL;
END $$;

UPDATE registers
SET name = 'Front'
WHERE id = '01900000-0000-7000-8000-000000000d10';

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM registers
        WHERE id = '01900000-0000-7000-8000-000000000d10'
          AND name = 'Front'
    ) THEN
        RAISE EXCEPTION 'register rename did not stick';
    END IF;
END $$;

UPDATE registers
SET name = 'Register'
WHERE id = '01900000-0000-7000-8000-000000000d10';

DO $$
BEGIN
    DELETE FROM registers WHERE id = '01900000-0000-7000-8000-000000000d10';
    RAISE EXCEPTION 'the last register was deleted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%the last register cannot be deleted%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-000000000d11', 'Back');

DELETE FROM registers WHERE id = '01900000-0000-7000-8000-000000000d11';

DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM registers WHERE id = '01900000-0000-7000-8000-000000000d11'
    ) THEN
        RAISE EXCEPTION 'a second register was not deleted';
    END IF;
END $$;

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-000000000d11', 'Back');

DELETE FROM registers WHERE id = '01900000-0000-7000-8000-000000000d10';

DO $$
BEGIN
    DELETE FROM registers WHERE id = '01900000-0000-7000-8000-000000000d11';
    RAISE EXCEPTION 'the remaining register was deleted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%the last register cannot be deleted%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-000000000d12', 'Side');

DO $$
BEGIN
    DELETE FROM registers
    WHERE id IN (
        '01900000-0000-7000-8000-000000000d11',
        '01900000-0000-7000-8000-000000000d12',
        '01900000-0000-7000-8000-000000000d10'
    );
    RAISE EXCEPTION 'deleting every register was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%the last register cannot be deleted%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    IF (SELECT count(*) FROM registers) < 1 THEN
        RAISE EXCEPTION 'a refused delete removed the last register';
    END IF;
END $$;

DO $$
BEGIN
    TRUNCATE registers CASCADE;
    RAISE EXCEPTION 'register truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%table registers is append-only and cannot be truncated%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE registers DISABLE TRIGGER registers_last_no_delete;
    RAISE EXCEPTION 'disabling the last-register trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE registers ENABLE REPLICA TRIGGER registers_no_truncate;
    RAISE EXCEPTION 'replica-only register trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE registers ENABLE TRIGGER registers_last_no_delete;
    RAISE EXCEPTION 'origin-only last-register trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER registers_last_no_delete ON registers;
    RAISE EXCEPTION 'dropping the last-register trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER registers_no_truncate ON registers;
    RAISE EXCEPTION 'dropping the register truncate trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_reject_last_register()
    RETURNS trigger
    LANGUAGE plpgsql
    SET search_path = pg_catalog
    AS $body$
    BEGIN
        RETURN OLD;
    END;
    $body$;
    RAISE EXCEPTION 'replacing the last-register function was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
            RAISE;
        END IF;
END $$;

DELETE FROM registers WHERE id = '01900000-0000-7000-8000-000000000d12';

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION platform.reject_guard_function_replace()
    RETURNS event_trigger
    LANGUAGE plpgsql
    SET search_path = pg_catalog
    AS $body$
    BEGIN
        RETURN;
    END;
    $body$;
    RAISE EXCEPTION 'replacing the guard event function was accepted';
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
        DELETE FROM registers;
        RAISE EXCEPTION 'replica delete of the last register was accepted';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM NOT LIKE '%the last register cannot be deleted%' THEN
                RAISE;
            END IF;
    END;
    BEGIN
        ALTER TABLE registers DISABLE TRIGGER registers_last_no_delete;
        RAISE EXCEPTION 'replica disable was accepted';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
                RAISE;
            END IF;
    END;
    BEGIN
        TRUNCATE registers CASCADE;
        RAISE EXCEPTION 'replica register truncate was accepted';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%table registers is append-only and cannot be truncated%' THEN
                RAISE;
            END IF;
    END;
    SET LOCAL session_replication_role = origin;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM registers WHERE id = '01900000-0000-7000-8000-000000000d11'
    ) THEN
        RAISE EXCEPTION 'a replica attack removed the last register';
    END IF;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    INSERT INTO registers (id, name)
    VALUES ('01900000-0000-7000-8000-000000000d13', 'Poster');
    DELETE FROM registers WHERE id = '01900000-0000-7000-8000-000000000d13';
    BEGIN
        DELETE FROM registers WHERE id = '01900000-0000-7000-8000-000000000d11';
        RAISE EXCEPTION 'poster deleted the last register';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM NOT LIKE '%the last register cannot be deleted%' THEN
                RAISE;
            END IF;
    END;
    BEGIN
        TRUNCATE registers CASCADE;
        RAISE EXCEPTION 'poster truncate was accepted';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        CREATE OR REPLACE FUNCTION stall_reject_last_register()
        RETURNS trigger
        LANGUAGE plpgsql
        AS $body$
        BEGIN
            RETURN OLD;
        END;
        $body$;
        RAISE EXCEPTION 'poster replaced the last-register function';
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

INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-000000000d14', 'Held');

INSERT INTO register_sessions (register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-000000000d14',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);

DO $$
BEGIN
    DELETE FROM registers WHERE id = '01900000-0000-7000-8000-000000000d14';
    RAISE EXCEPTION 'a register with a session was deleted';
EXCEPTION
    WHEN SQLSTATE '23503' THEN
        NULL;
END $$;

ROLLBACK;
