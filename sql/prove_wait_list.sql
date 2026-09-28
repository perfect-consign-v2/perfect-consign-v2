-- Proof for the wait list. One transaction, then ROLLBACK.
-- Run with search_path set to the mall schema. The poster role is stall_poster.

BEGIN;

DO $$
DECLARE
    money_columns integer;
    size_columns integer;
BEGIN
    SELECT count(*) INTO money_columns
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'wait_list'
      AND (
          data_type IN ('numeric', 'money')
          OR column_name ~* '(amount|balance|minor|rate|price|total)'
      );

    IF money_columns <> 0 THEN
        RAISE EXCEPTION 'wait list carries money';
    END IF;

    SELECT count(*) INTO size_columns
    FROM information_schema.columns
    WHERE table_schema = current_schema()
      AND table_name = 'wait_list'
      AND data_type IN ('integer', 'bigint', 'smallint')
      AND column_name NOT IN ('width_ft', 'depth_ft');

    IF size_columns <> 0 THEN
        RAISE EXCEPTION 'wait list has an unexpected integer column';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO wait_list (first_name, last_name, email, phone) VALUES ('', 'Lane', 'ada@example.com', '555-0100');
    RAISE EXCEPTION 'blank first name was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO wait_list (first_name, last_name, email, phone) VALUES ('Ada', '', 'ada@example.com', '555-0100');
    RAISE EXCEPTION 'blank last name was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO wait_list (first_name, last_name, email, phone) VALUES ('  Ada', 'Lane', 'ada@example.com', '555-0100');
    RAISE EXCEPTION 'padded first name was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO wait_list (first_name, last_name, email, phone) VALUES ('Ada', 'Lane  ', 'ada@example.com', '555-0100');
    RAISE EXCEPTION 'padded last name was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO wait_list (first_name, last_name, email, phone) VALUES ('Ada', 'Lane', 'not-an-email', '555-0100');
    RAISE EXCEPTION 'bad email was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO wait_list (first_name, last_name, email, phone) VALUES ('Ada', 'Lane', 'ada@example.com', '');
    RAISE EXCEPTION 'empty phone was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO wait_list (first_name, last_name, email, phone) VALUES ('Ada', 'Lane', 'ada@example.com', '  555');
    RAISE EXCEPTION 'padded phone was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO wait_list (first_name, last_name, email, phone, notes)
    VALUES ('Ada', 'Lane', 'ada@example.com', '555-0100', '  ');
    RAISE EXCEPTION 'blank notes were accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO wait_list (first_name, last_name, email, phone, width_ft)
    VALUES ('Ada', 'Lane', 'ada@example.com', '555-0100', 10);
    RAISE EXCEPTION 'one-sided size was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO wait_list (first_name, last_name, email, phone, width_ft, depth_ft)
    VALUES ('Ada', 'Lane', 'ada@example.com', '555-0100', 0, 10);
    RAISE EXCEPTION 'zero size was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO wait_list (first_name, last_name, email, phone, status)
    VALUES ('Ada', 'Lane', 'ada@example.com', '555-0100', 'queued');
    RAISE EXCEPTION 'unknown status was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

INSERT INTO wait_list (id, first_name, last_name, email, phone, width_ft, depth_ft)
VALUES (
    '01900000-0000-7000-8000-000000000e01',
    'Ada',
    'Lane',
    'Ada@Example.com',
    '555-0100',
    10,
    8
);

DO $$
DECLARE
    row_status text;
BEGIN
    SELECT status INTO row_status
    FROM wait_list
    WHERE id = '01900000-0000-7000-8000-000000000e01';
    IF row_status <> 'waiting' THEN
        RAISE EXCEPTION 'a new row did not start waiting';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO wait_list (first_name, last_name, email, phone)
    VALUES ('Bea', 'Lane', 'ada@example.com', '555-0101');
    RAISE EXCEPTION 'a second open email was accepted';
EXCEPTION
    WHEN SQLSTATE '23505' THEN
        NULL;
END $$;

INSERT INTO wait_list (id, first_name, last_name, email, phone)
VALUES (
    '01900000-0000-7000-8000-000000000e02',
    'Bea',
    'Cole',
    'bea@example.com',
    '555-0101'
);

DO $$
BEGIN
    UPDATE wait_list
    SET email = 'ada@example.com'
    WHERE id = '01900000-0000-7000-8000-000000000e02';
    RAISE EXCEPTION 'an open email collision was accepted';
EXCEPTION
    WHEN SQLSTATE '23505' THEN
        NULL;
END $$;

UPDATE wait_list
SET status = 'contacted'
WHERE id = '01900000-0000-7000-8000-000000000e01';

DO $$
BEGIN
    INSERT INTO wait_list (first_name, last_name, email, phone)
    VALUES ('Cia', 'Lane', 'ada@example.com', '555-0102');
    RAISE EXCEPTION 'a contacted email was accepted again';
EXCEPTION
    WHEN SQLSTATE '23505' THEN
        NULL;
END $$;

UPDATE wait_list
SET status = 'placed'
WHERE id = '01900000-0000-7000-8000-000000000e01';

INSERT INTO wait_list (id, first_name, last_name, email, phone)
VALUES (
    '01900000-0000-7000-8000-000000000e03',
    'Cia',
    'Lane',
    'ada@example.com',
    '555-0102'
);

UPDATE wait_list
SET status = 'declined'
WHERE id = '01900000-0000-7000-8000-000000000e03';

INSERT INTO wait_list (id, first_name, last_name, email, phone)
VALUES (
    '01900000-0000-7000-8000-000000000e04',
    'Dia',
    'Lane',
    'ada@example.com',
    '555-0103'
);

DO $$
BEGIN
    TRUNCATE wait_list;
    RAISE EXCEPTION 'wait list truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%table wait_list is append-only and cannot be truncated%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE wait_list DISABLE TRIGGER wait_list_no_truncate;
    RAISE EXCEPTION 'disabling the wait list trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE wait_list ENABLE REPLICA TRIGGER wait_list_no_truncate;
    RAISE EXCEPTION 'replica-only wait list trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER wait_list_no_truncate ON wait_list;
    RAISE EXCEPTION 'dropping the wait list trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    SET LOCAL session_replication_role = replica;
    TRUNCATE wait_list;
    RAISE EXCEPTION 'replica wait list truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%table wait_list is append-only and cannot be truncated%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    DELETE FROM wait_list WHERE id = '01900000-0000-7000-8000-000000000e04';
    IF EXISTS (
        SELECT 1 FROM wait_list WHERE id = '01900000-0000-7000-8000-000000000e04'
    ) THEN
        RAISE EXCEPTION 'poster did not delete the wait list row';
    END IF;
    BEGIN
        TRUNCATE wait_list;
        RAISE EXCEPTION 'poster truncate was accepted';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    RESET ROLE;
END $$;

ROLLBACK;
