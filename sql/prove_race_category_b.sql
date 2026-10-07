-- Session B waits for the same category name, then rolls back.
BEGIN;
INSERT INTO categories (id, name)
VALUES ('04800000-0000-7000-8000-00000000d0d2', 'Race category');
ROLLBACK;
