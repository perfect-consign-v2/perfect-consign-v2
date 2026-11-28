-- Session B. Puts the same piece on a sale at the same moment. It must wait on
-- the item, then see none left and refuse.
BEGIN;
INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor, quantity)
VALUES ('01900000-0000-7000-8000-0000000009c6', 3, '01900000-0000-7000-8000-0000000009c3', 1500, 1);
COMMIT;
