-- Session B. Must block on the earlier period, then be refused because it is still open.
BEGIN;
SELECT stall_close_quiet_period('01900000-0000-7000-8000-00000000d036');
ROLLBACK;
