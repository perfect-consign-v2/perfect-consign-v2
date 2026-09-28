-- Session A holds the piece on an open ticket, then rolls back.
BEGIN;
INSERT INTO registers (id, name)
VALUES ('01900000-0000-7000-8000-00000000d0f5', 'Race ticket A');
INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES (
    '01900000-0000-7000-8000-00000000d0f6',
    '01900000-0000-7000-8000-00000000d0f5',
    'Ada',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
    0
);
INSERT INTO tickets (id, session_id)
VALUES ('01900000-0000-7000-8000-00000000d0f7', '01900000-0000-7000-8000-00000000d0f6');
INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor)
VALUES (
    '01900000-0000-7000-8000-00000000d0f7',
    1,
    '01900000-0000-7000-8000-00000000d0f3',
    100
);
SELECT pg_sleep(6);
ROLLBACK;
