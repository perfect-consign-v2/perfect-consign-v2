-- Session B. Must block on the stay the hold has locked, then charge March at the committed rate.
BEGIN;
SELECT stall_post_rent_charge('01900000-0000-7000-8000-00000000d401', '2029-03-01', '2029-03-01');
COMMIT;
