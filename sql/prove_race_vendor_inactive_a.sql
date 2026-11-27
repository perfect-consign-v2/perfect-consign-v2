-- Session A. Charges the departed vendor's last month and holds it open.
BEGIN;
SELECT stall_post_rent_charge('01900000-0000-7000-8000-0000000009a6', '2026-01-01', '2026-02-05');
SELECT pg_sleep(6);
COMMIT;
