-- Session A holds the size name, then rolls back.
BEGIN;
INSERT INTO sizes (id, name)
VALUES ('04800000-0000-7000-8000-00000000d0e1', 'Race size');
SELECT pg_sleep(6);
ROLLBACK;
