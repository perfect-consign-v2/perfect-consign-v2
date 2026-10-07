-- Session B waits for the same condition name, then rolls back.
BEGIN;
INSERT INTO conditions (id, name)
VALUES ('04800000-0000-7000-8000-00000000d0f2', 'Race condition');
ROLLBACK;
