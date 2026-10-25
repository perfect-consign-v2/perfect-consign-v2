-- An owner letter stays as filed. sent_at can be set once. The script rolls back.
-- Postgres does not deliver ALTER EVENT TRIGGER or DROP EVENT TRIGGER to this guard.
-- Those triggers are ENABLE ALWAYS, so replica mode still refuses a rewrite.

BEGIN;

INSERT INTO vendors (id, code, first_name, last_name, email, active, email_bounced)
VALUES
    ('01900000-0000-7000-8000-00000000c101', 'lettera', 'Ada', 'Letter', 'ada@example.test', true, false),
    ('01900000-0000-7000-8000-00000000c102', 'letterb', 'Bea', 'Letter', 'bea@example.test', true, true),
    ('01900000-0000-7000-8000-00000000c103', 'letterc', 'Cara', 'Letter', 'cara@example.test', false, false);

INSERT INTO vendor_letters (id, subject, body)
VALUES ('01900000-0000-7000-8000-00000000c201', 'Pickup', 'Your items are ready.');

INSERT INTO vendor_letter_copies (letter_id, vendor_id, email)
VALUES ('01900000-0000-7000-8000-00000000c201', '01900000-0000-7000-8000-00000000c101', 'ada@example.test');

DO $$
BEGIN
    UPDATE vendor_letter_copies
    SET email = 'other@example.test'
    WHERE letter_id = '01900000-0000-7000-8000-00000000c201';
    RAISE EXCEPTION 'a letter copy address was rewritten';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a letter copy cannot be rewritten%' THEN
            RAISE;
        END IF;
END $$;

UPDATE vendor_letter_copies
SET sent_at = clock_timestamp()
WHERE letter_id = '01900000-0000-7000-8000-00000000c201';

DO $$
BEGIN
    IF (SELECT sent_at FROM vendor_letter_copies WHERE letter_id = '01900000-0000-7000-8000-00000000c201') IS NULL THEN
        RAISE EXCEPTION 'sent_at was not stored';
    END IF;
END $$;

DO $$
BEGIN
    UPDATE vendor_letter_copies
    SET sent_at = clock_timestamp()
    WHERE letter_id = '01900000-0000-7000-8000-00000000c201';
    RAISE EXCEPTION 'a second sent_at was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a letter copy was already sent%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DELETE FROM vendor_letter_copies WHERE letter_id = '01900000-0000-7000-8000-00000000c201';
    RAISE EXCEPTION 'a letter copy was removed';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a letter copy cannot be removed%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    UPDATE vendor_letters SET subject = 'Changed' WHERE id = '01900000-0000-7000-8000-00000000c201';
    RAISE EXCEPTION 'a letter was rewritten';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a letter cannot be changed%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DELETE FROM vendor_letters WHERE id = '01900000-0000-7000-8000-00000000c201';
    RAISE EXCEPTION 'a letter was removed';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a letter cannot be changed%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO vendor_letter_copies (letter_id, vendor_id, email)
    VALUES ('01900000-0000-7000-8000-00000000c201', '01900000-0000-7000-8000-00000000c102', 'bea@example.test');
    RAISE EXCEPTION 'a bounced address was copied';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a bounced address is left out%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO vendor_letter_copies (letter_id, vendor_id, email)
    VALUES ('01900000-0000-7000-8000-00000000c201', '01900000-0000-7000-8000-00000000c103', 'cara@example.test');
    RAISE EXCEPTION 'an inactive consignor was copied';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%an inactive consignor is left out%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO vendor_letter_copies (letter_id, vendor_id, email)
    VALUES ('01900000-0000-7000-8000-00000000c201', '01900000-0000-7000-8000-00000000c101', 'other@example.test');
    RAISE EXCEPTION 'a different address was copied';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%the letter address is not the consignor email%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO vendor_letters (subject, body) VALUES (E'Hello\nthere', 'Body');
    RAISE EXCEPTION 'a subject with a line break was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    TRUNCATE vendor_letter_copies;
    RAISE EXCEPTION 'a letter copy truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%cannot be truncated%' THEN
            RAISE;
        END IF;
END $$;

ALTER TABLE vendor_letter_copies DROP CONSTRAINT vendor_letter_copies_letter_id_fkey;

DO $$
BEGIN
    TRUNCATE vendor_letters;
    RAISE EXCEPTION 'a letter truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%cannot be truncated%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO platform.jobs (tenant, kind, payload)
VALUES ('letterproof', 'sale-notice', jsonb_build_object('date', '2099-06-01'));

DO $$
BEGIN
    INSERT INTO platform.jobs (tenant, kind, payload)
    VALUES ('letterproof', 'sale-notice', jsonb_build_object('date', '2099-06-01'));
    RAISE EXCEPTION 'a second sale notice for the day was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
END $$;

INSERT INTO platform.jobs (tenant, kind, payload)
VALUES
    ('letterproof', 'vendor-letter', jsonb_build_object('date', '2099-06-01', 'letter', '01900000-0000-7000-8000-00000000c201')),
    ('letterproof', 'vendor-letter', jsonb_build_object('date', '2099-06-01', 'letter', '01900000-0000-7000-8000-00000000c202'));

INSERT INTO platform.jobs (tenant, kind, payload)
VALUES ('letterproof', 'vendor-letter', jsonb_build_object('date', '2099-06-02', 'letter', '01900000-0000-7000-8000-00000000c201'))
ON CONFLICT (tenant, kind, (payload ->> 'letter')) WHERE ((payload ->> 'letter') IS NOT NULL) DO NOTHING;

DO $$
DECLARE
    notices integer;
    letters integer;
BEGIN
    SELECT count(*) INTO notices
    FROM platform.jobs
    WHERE tenant = 'letterproof' AND kind = 'sale-notice' AND payload ->> 'date' = '2099-06-01';
    SELECT count(*) INTO letters
    FROM platform.jobs
    WHERE tenant = 'letterproof' AND kind = 'vendor-letter';
    IF notices <> 1 OR letters <> 2 THEN
        RAISE EXCEPTION 'letter jobs did not stay one per letter';
    END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE vendor_letters DISABLE TRIGGER vendor_letters_no_delete;
    RAISE EXCEPTION 'disabling the letter trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE vendor_letters ENABLE REPLICA TRIGGER vendor_letters_no_delete;
    RAISE EXCEPTION 'replica-only letter trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE vendor_letters ENABLE TRIGGER vendor_letters_no_delete;
    RAISE EXCEPTION 'origin-only letter trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER vendor_letters_no_delete ON vendor_letters;
    RAISE EXCEPTION 'dropping the letter trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER vendor_letter_copies_no_delete ON vendor_letter_copies;
    RAISE EXCEPTION 'dropping the letter copy trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_reject_vendor_letter()
    RETURNS trigger LANGUAGE plpgsql AS $body$
    BEGIN
        RETURN NEW;
    END;
    $body$;
    RAISE EXCEPTION 'replacing the letter guard was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_guard_vendor_letter_copy()
    RETURNS trigger LANGUAGE plpgsql AS $body$
    BEGIN
        RETURN NEW;
    END;
    $body$;
    RAISE EXCEPTION 'replacing the letter copy guard was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
            RAISE;
        END IF;
END $$;

DO $$
DECLARE
    kept text;
BEGIN
    kept := (SELECT subject FROM vendor_letters WHERE id = '01900000-0000-7000-8000-00000000c201');
    SET LOCAL session_replication_role = replica;
    BEGIN
        UPDATE vendor_letters SET subject = 'Changed' WHERE id = '01900000-0000-7000-8000-00000000c201';
        RAISE EXCEPTION 'replica mode rewrote a letter';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            NULL;
    END;
    BEGIN
        ALTER TABLE vendor_letters DISABLE TRIGGER vendor_letters_no_delete;
        RAISE EXCEPTION 'replica mode disabled the letter trigger';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            NULL;
    END;
    SET LOCAL session_replication_role = origin;
    IF (SELECT subject FROM vendor_letters WHERE id = '01900000-0000-7000-8000-00000000c201') IS DISTINCT FROM kept THEN
        RAISE EXCEPTION 'a replica session changed a letter';
    END IF;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE stall_app;
    BEGIN
        SET LOCAL session_replication_role = replica;
        RAISE EXCEPTION 'stall_app replica mode was accepted';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        SET LOCAL event_triggers = false;
        RAISE EXCEPTION 'stall_app turned event triggers off';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    RESET ROLE;
END $$;

ROLLBACK;
