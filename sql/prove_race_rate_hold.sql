-- Session A. Holds the stay the rent-charge assert locks, then rolls back.
BEGIN;
SELECT id
FROM booth_assignments
WHERE id = '01900000-0000-7000-8000-00000000d401'
FOR UPDATE;
SELECT pg_sleep(6);
ROLLBACK;
