-- Session B waits for the same department name, then rolls back.
BEGIN;
INSERT INTO departments (id, name)
VALUES ('04800000-0000-7000-8000-00000000d102', 'Race department');
ROLLBACK;
