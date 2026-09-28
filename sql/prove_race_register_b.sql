-- Session B opens the same register while A holds that open session.
BEGIN;
INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-00000000d902',
    '01900000-0000-7000-8000-000000000d10',
    'Race',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);
ROLLBACK;
