-- Session A. Counts the last piece out by hand and holds the transaction open.
BEGIN;
SELECT stall_change_item_quantity('01900000-0000-7000-8000-0000000009c3', 0, '01900000-0000-7000-8000-0000000000f1', 'staff', 'Race desk', NULL);
SELECT pg_sleep(6);
COMMIT;
