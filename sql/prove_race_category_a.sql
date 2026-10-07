-- Session A holds the category name, then rolls back.
BEGIN;
INSERT INTO categories (id, name)
VALUES ('04800000-0000-7000-8000-00000000d0d1', 'Race category');
SELECT pg_sleep(6);
ROLLBACK;
