-- Session A. Commits a new rate while the charge is waiting.
BEGIN;
UPDATE assignment_rent
SET amount_minor = 20000
WHERE assignment_id = '01900000-0000-7000-8000-00000000d401';
SELECT pg_sleep(6);
COMMIT;
