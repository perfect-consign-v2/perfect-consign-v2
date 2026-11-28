-- Session B. Another desk takes the same booth for overlapping dates at the
-- same moment. It must wait on the booth, then see the first stay and refuse.
BEGIN;
INSERT INTO booth_assignments (id, booth_id, vendor_id, starts_on)
VALUES ('01900000-0000-7000-8000-0000000009b7', '01900000-0000-7000-8000-0000000009b4', '01900000-0000-7000-8000-0000000009b1', '2030-01-15');
COMMIT;
