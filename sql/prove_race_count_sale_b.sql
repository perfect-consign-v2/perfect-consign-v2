-- Session B. Counts the same piece out by hand at the same moment. It must wait
-- on the item, then see it on the open sale and refuse.
BEGIN;
SELECT stall_change_item_quantity('01900000-0000-7000-8000-0000000009c3', 0, '01900000-0000-7000-8000-0000000000f1', 'staff', 'Race desk', NULL);
COMMIT;
