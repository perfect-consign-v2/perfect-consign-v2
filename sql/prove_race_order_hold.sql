-- Session A. Holds the earlier period row, then rolls back.
BEGIN;
SELECT id
FROM accounting_periods
WHERE id = '01900000-0000-7000-8000-00000000d035'
FOR UPDATE;
SELECT pg_sleep(6);
ROLLBACK;
