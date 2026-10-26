-- Session A holds the assignment rent rate unique key, then rolls back.
BEGIN;
INSERT INTO assignment_rent_rates (id, assignment_id, starts_on, amount_minor)
VALUES (
    '01900000-0000-7000-8000-00000000d411',
    '01900000-0000-7000-8000-00000000d401',
    '2029-06-01',
    18000
);
SELECT pg_sleep(6);
ROLLBACK;
