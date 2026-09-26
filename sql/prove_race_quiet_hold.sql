-- Session A. Closes an empty period and holds that lock, then rolls back.
BEGIN;
SELECT stall_close_quiet_period('01900000-0000-7000-8000-00000000d030');
SELECT pg_sleep(6);
ROLLBACK;
