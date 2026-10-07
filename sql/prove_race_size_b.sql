-- Session B waits for the same size name, then rolls back.
BEGIN;
INSERT INTO sizes (id, name)
VALUES ('04800000-0000-7000-8000-00000000d0e2', 'Race size');
ROLLBACK;
