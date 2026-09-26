-- Session A. Commits an assignment end while the charge is waiting.
BEGIN;
UPDATE booth_assignments
SET ends_on = '2029-02-01'
WHERE id = '01900000-0000-7000-8000-00000000d402';
SELECT pg_sleep(6);
COMMIT;
