-- Session A. Holds the period closed, then rolls back.
BEGIN;
UPDATE accounting_periods
SET status = 'closed'
WHERE id = '01900000-0000-7000-8000-00000000d024';
SELECT pg_sleep(6);
ROLLBACK;
