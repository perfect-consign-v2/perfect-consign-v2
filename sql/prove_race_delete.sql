-- Session A. Deletes the disposable vendor, then commits.
BEGIN;
DELETE FROM vendors WHERE id = '01900000-0000-7000-8000-00000000d101';
SELECT pg_sleep(6);
COMMIT;
