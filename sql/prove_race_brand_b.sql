-- Session B waits for the same brand name, then rolls back.
BEGIN;
INSERT INTO brands (id, name)
VALUES ('01900000-0000-7000-8000-00000000d0b2', 'Race brand');
ROLLBACK;
