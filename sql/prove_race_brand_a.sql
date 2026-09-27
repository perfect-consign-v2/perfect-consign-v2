-- Session A holds the brand name, then rolls back.
BEGIN;
INSERT INTO brands (id, name)
VALUES ('01900000-0000-7000-8000-00000000d0b1', 'Race brand');
SELECT pg_sleep(6);
ROLLBACK;
