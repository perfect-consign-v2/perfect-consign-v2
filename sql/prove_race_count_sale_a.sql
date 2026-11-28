-- Session A. Puts the last piece on an open sale and holds the transaction open.
BEGIN;
INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor, quantity)
VALUES ('01900000-0000-7000-8000-0000000009c6', 2, '01900000-0000-7000-8000-0000000009c3', 1500, 1);
SELECT pg_sleep(6);
COMMIT;
