-- Session B waits for the same item, then rolls back.
BEGIN;
INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-00000000d0f8', 'Race ticket B');
INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-00000000d0f9',
    '01900000-0000-7000-8000-00000000d0f8',
    'Bea',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);
INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-00000000d0fa', '01900000-0000-7000-8000-00000000d0f9');
INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
VALUES (
    '01900000-0000-7000-8000-00000000d0fa',
    1,
    '01900000-0000-7000-8000-00000000d0f3',
    100
);
ROLLBACK;
