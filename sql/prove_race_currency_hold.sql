-- Session A. Holds a currency change, then rolls back.
BEGIN;
UPDATE ledger_books
SET currency = 'EUR'
WHERE id = '01900000-0000-7000-8000-000000000001';
SELECT pg_sleep(6);
ROLLBACK;
