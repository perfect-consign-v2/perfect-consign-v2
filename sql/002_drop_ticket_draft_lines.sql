-- Instant Add now requires the seller, description, price, and quantity, so it
-- always puts a finished line on the ticket. Nothing parks an incomplete
-- (MISSING) line anymore, and the table that held those lines goes away.
-- The rules a finished line must meet stay on items and ticket_lines:
-- items_description_chk, items_price_chk, items_store_owner_chk, and
-- ticket_lines_quantity_chk.

DROP TABLE public.ticket_draft_lines;
