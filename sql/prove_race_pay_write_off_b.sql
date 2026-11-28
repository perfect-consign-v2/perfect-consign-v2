-- Session B. The owner writes off the same rent at the same moment. It must
-- wait on the vendor, then see nothing left to write off and refuse.
BEGIN;
SELECT stall_write_off('01900000-0000-7000-8000-0000000009d1', 'Moved away', '01900000-0000-7000-8000-0000000009d9', 'raceowner');
COMMIT;
