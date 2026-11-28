-- Session A. The vendor pays the rent at the desk and holds the transaction open.
BEGIN;
INSERT INTO register_session_posts (session_id, journal_id)
SELECT session.id, stall_post_rent_payment('01900000-0000-7000-8000-0000000009d1', 0, 8000, 0, (clock_timestamp() AT TIME ZONE mall.time_zone)::date)
FROM register_sessions AS session CROSS JOIN mall
WHERE session.register_id = '01900000-0000-7000-8000-000000000d10' AND session.closed_at IS NULL;
SELECT pg_sleep(6);
COMMIT;
