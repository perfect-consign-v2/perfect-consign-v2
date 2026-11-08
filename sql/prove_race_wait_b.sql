-- Session B waits for the same open wait-list email, then rolls back.
BEGIN;
SELECT stall_seed_wait_person(
    'Race',
    'Wait',
    'race-wait@example.com',
    '555-0198',
    '01900000-0000-7000-8000-00000000d0e2'::uuid
);
ROLLBACK;
