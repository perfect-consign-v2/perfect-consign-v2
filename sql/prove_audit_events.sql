-- An audit row names the signed-in account. A row cannot be updated.
-- A one-cent report drift is refused. The script rolls back.

BEGIN;

DO $$
BEGIN
    INSERT INTO audit_events (actor, role, action, entity_type, entity_id, request_id)
    VALUES ('owner-1', 'owner', 'rent.late_fees', 'mall', 'mall', 'request-1');
    RAISE EXCEPTION 'an audit row without an account was stored';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%an audit row needs the signed-in account%' THEN
            RAISE;
        END IF;
END $$;

INSERT INTO staff (id, username, display_name, password_hash, role)
VALUES (
    '01900000-0000-7000-8000-00000000a011',
    'ada',
    'Ada',
    'hash-hash-hash-hash-hash',
    'cashier'
);

INSERT INTO audit_events (
    actor, role, action, entity_type, entity_id, request_id,
    account_id, account_kind, username, client_address
) VALUES (
    'Ada', 'cashier', 'rent.late_fees', 'mall', 'mall', 'request-1',
    '01900000-0000-7000-8000-00000000a011', 'staff', 'ada', '127.0.0.1'
);

DO $$
BEGIN
    INSERT INTO audit_events (
        actor, role, action, entity_type, entity_id, request_id,
        account_id, account_kind, username, client_address
    ) VALUES (
        'Ada', 'cashier', 'rent.late_fees', 'mall', 'mall', 'request-2',
        '01900000-0000-7000-8000-00000000a099', 'staff', 'ada', ''
    );
    RAISE EXCEPTION 'an unknown account was stored';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%an audit row needs the signed-in account%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO audit_events (
        actor, role, action, entity_type, entity_id, request_id,
        account_id, account_kind, username, client_address,
        authority_account_id, authority_username
    ) VALUES (
        'Ada', 'cashier', 'sale.void', 'journal', 'journal', 'request-3',
        '01900000-0000-7000-8000-00000000a011', 'staff', 'ada', '',
        '01900000-0000-7000-8000-00000000a011', 'ada'
    );
    RAISE EXCEPTION 'the same person signed twice';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a second signer is a different person%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO audit_events (
        actor, role, action, entity_type, entity_id, request_id,
        account_id, account_kind, username, client_address,
        entered_by_admin_id
    ) VALUES (
        'Ada', 'cashier', 'platform.enter', 'session', 'session', 'request-4',
        '01900000-0000-7000-8000-00000000a011', 'staff', 'ada', '',
        '01900000-0000-7000-8000-00000000a011'
    );
    RAISE EXCEPTION 'an enter-as row without an email was stored';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%an audit row needs the operator%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    INSERT INTO audit_events (
        actor, role, action, entity_type, entity_id, request_id,
        account_id, account_kind, username, client_address
    ) VALUES (
        'owner', 'owner', 'settings.edit', 'company', 'company', 'request-5',
        '01900000-0000-7000-8000-00000000a011', 'owner', 'owner', ''
    );
    RAISE EXCEPTION 'a staff id was stored as an owner';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%an audit row needs the signed-in account%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DELETE FROM audit_events;
    RAISE EXCEPTION 'an audit delete stuck';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%audit row cannot be changed%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    TRUNCATE audit_events;
    RAISE EXCEPTION 'an audit truncate stuck';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%audit row cannot be changed%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE audit_events ENABLE REPLICA TRIGGER audit_events_subject_append_only;
    RAISE EXCEPTION 'replica-only audit trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    UPDATE audit_events SET action = 'changed';
    RAISE EXCEPTION 'an audit update stuck';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%audit row cannot be changed%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    SET LOCAL session_replication_role = replica;
    UPDATE audit_events SET action = 'changed';
    RAISE EXCEPTION 'a replica session changed an audit row';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%audit row cannot be changed%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    DROP TRIGGER audit_events_subject_append_only ON audit_events;
    RAISE EXCEPTION 'dropping the audit subject trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    ALTER TABLE audit_events DISABLE TRIGGER audit_events_subject_append_only;
    RAISE EXCEPTION 'disabling the audit subject trigger was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
            RAISE;
        END IF;
END $$;

DO $$
BEGIN
    CREATE OR REPLACE FUNCTION stall_require_audit_subject()
    RETURNS trigger LANGUAGE plpgsql AS $body$
    BEGIN
        RETURN NEW;
    END;
    $body$;
    RAISE EXCEPTION 'replacing the audit subject guard was accepted';
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
        ALTER TABLE audit_events DISABLE TRIGGER audit_events_subject_append_only;
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

DO $$
BEGIN
    PERFORM stall_refuse_report_drift(1, 2);
    RAISE EXCEPTION 'a one-cent drift was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%disagrees with the books%' THEN
            RAISE;
        END IF;
END $$;

SELECT stall_refuse_report_drift(5, 5);

ROLLBACK;
