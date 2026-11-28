-- Session B. Make inactive for the same vendor at the same moment. It must wait
-- on the vendor, then see the piece on the open sale and refuse.
BEGIN;
UPDATE vendors SET active = false WHERE id = '01900000-0000-7000-8000-0000000009c0';
COMMIT;
