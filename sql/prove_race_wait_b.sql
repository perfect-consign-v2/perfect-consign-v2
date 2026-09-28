-- Session B waits for the same open wait-list email, then rolls back.
BEGIN;
INSERT INTO wait_list (id, first_name, last_name, email, phone)
VALUES ('01900000-0000-7000-8000-00000000d0e2', 'Race', 'Wait', 'race-wait@example.com', '555-0198');
ROLLBACK;
