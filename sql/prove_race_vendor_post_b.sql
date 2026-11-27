-- Session B. A charge that starts during Make inactive must wait for it, then post.
BEGIN;
SELECT stall_post_rent_charge('01900000-0000-7000-8000-0000000009a7', '2026-01-01', '2026-02-05');
COMMIT;
