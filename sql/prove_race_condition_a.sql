-- Session A holds the condition name, then rolls back.
BEGIN;
INSERT INTO conditions (id, name)
VALUES ('04800000-0000-7000-8000-00000000d0f1', 'Race condition');
SELECT pg_sleep(6);
ROLLBACK;
