-- Session A. Holds an account type change, then rolls back.
BEGIN;
UPDATE accounts
SET account_type = 'expense', normal_balance = 'debit'
WHERE id = '01900000-0000-7000-8000-00000000d011';
SELECT pg_sleep(6);
ROLLBACK;
