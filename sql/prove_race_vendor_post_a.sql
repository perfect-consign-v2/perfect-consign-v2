-- Session A. Makes a vendor with nothing open inactive and holds it open.
BEGIN;
UPDATE vendors SET active = false WHERE id = '01900000-0000-7000-8000-0000000009a1';
SELECT pg_sleep(6);
COMMIT;
