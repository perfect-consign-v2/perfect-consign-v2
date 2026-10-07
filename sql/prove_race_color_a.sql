-- Session A holds the color name, then rolls back.
BEGIN;
INSERT INTO colors (id, name)
VALUES ('04800000-0000-7000-8000-00000000d0c1', 'Race color');
SELECT pg_sleep(6);
ROLLBACK;
