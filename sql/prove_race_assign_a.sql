-- Session A. Takes a booth for 2030 and holds the transaction open.
BEGIN;
INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES ('01900000-0000-7000-8000-0000000009b6', '01900000-0000-7000-8000-0000000009b4', '01900000-0000-7000-8000-0000000009b0', '2030-01-01');
SELECT pg_sleep(6);
COMMIT;
