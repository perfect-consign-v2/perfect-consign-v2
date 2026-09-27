-- Session A shrinks the floor and holds the mall row, then commits.
BEGIN;
SELECT floor_width_ft FROM mall FOR UPDATE;
UPDATE mall SET floor_width_ft = 100;
SELECT pg_sleep(6);
COMMIT;
