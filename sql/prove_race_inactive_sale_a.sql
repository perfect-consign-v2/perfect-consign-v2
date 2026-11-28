-- Session A. Puts one of the vendor's pieces on an open sale and holds the
-- transaction open.
BEGIN;
INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor, quantity)
VALUES ('01900000-0000-7000-8000-0000000009c6', 1, '01900000-0000-7000-8000-0000000009c1', 1500, 1);
SELECT pg_sleep(6);
COMMIT;
