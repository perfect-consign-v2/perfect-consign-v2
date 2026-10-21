-- An audit row cannot be updated. A one-cent report drift is refused. The script rolls back.

BEGIN;

INSERT INTO audit_events (actor, role, action, entity_type, entity_id, request_id)
VALUES ('owner-1', 'owner', 'rent.late_fees', 'mall', 'mall', 'request-1');

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
