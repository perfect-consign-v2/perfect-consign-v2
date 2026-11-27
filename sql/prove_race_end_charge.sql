-- Session B. Must block on the stay, then fail because the committed end date leaves March uncovered.
BEGIN;
SELECT stall_post_rent_charge('01900000-0000-7000-8000-00000000d402', '2029-03-01', '2029-03-01');
COMMIT;
