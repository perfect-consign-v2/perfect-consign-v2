-- Session B. Must wait for the charge in flight, then be refused: the vendor now owes rent.
BEGIN;
UPDATE vendors SET active = false WHERE id = '01900000-0000-7000-8000-0000000009a0';
COMMIT;
