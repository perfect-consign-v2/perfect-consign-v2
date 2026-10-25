-- Session A holds the last unit, posts the snap, and commits.
BEGIN;
SELECT quantity FROM items WHERE id = '01900000-0000-7000-8000-0000000009f3' FOR UPDATE;
SELECT pg_sleep(6);
SELECT stall_sync_snap('01900000-0000-7000-8000-0000000009f1');
COMMIT;
