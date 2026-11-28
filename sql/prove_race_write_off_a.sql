-- Session A. The owner writes off the vendor's rent and holds the transaction open.
BEGIN;
SELECT stall_write_off('01900000-0000-7000-8000-0000000009d0', 'Moved away', '01900000-0000-7000-8000-0000000009d9', 'raceowner');
SELECT pg_sleep(6);
COMMIT;
