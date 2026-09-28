-- Session A holds the open register session, then rolls back.
BEGIN;
INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-00000000d901',
    '01900000-0000-7000-8000-000000000d10',
    'Race',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);
SELECT pg_sleep(6);
ROLLBACK;
