-- Session B waits for the same color name, then rolls back.
BEGIN;
INSERT INTO colors (id, name)
VALUES ('04800000-0000-7000-8000-00000000d0c2', 'Race color');
ROLLBACK;
