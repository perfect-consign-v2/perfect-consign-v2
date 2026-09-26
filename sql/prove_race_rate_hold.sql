-- Session A. Commits a new rate while the charge is waiting.
BEGIN;
UPDATE booth_rent_rates
SET amount_minor = 20000
WHERE booth_id = '01900000-0000-7000-8000-00000000d301'
  AND month_start = '2029-03-01';
SELECT pg_sleep(6);
COMMIT;
