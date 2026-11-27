-- Instant Add now requires the seller, description, price, and quantity, so it
-- always puts a finished line on the ticket. Nothing parks an incomplete
-- (MISSING) line anymore, and the table that held those lines goes away.
-- The rules a finished line must meet stay on items and ticket_lines:
-- items_description_chk, items_price_chk, items_store_owner_chk, and
-- ticket_lines_quantity_chk.

DROP TABLE public.ticket_draft_lines;

-- The app trims a description of spaces, tabs, line breaks, and vertical tabs
-- (PHP trim). The database now refuses the same characters at either end, so a
-- description of only blank space cannot be saved by any path. Rows saved
-- before this rule are trimmed first; a row that would end up empty makes this
-- migration fail loudly rather than keep a blank description.

UPDATE public.items
SET description = btrim(description, E' \t\n\r\x0B')
WHERE description <> btrim(description, E' \t\n\r\x0B');

ALTER TABLE public.items DROP CONSTRAINT items_description_chk;

ALTER TABLE public.items ADD CONSTRAINT items_description_chk CHECK (
    description = btrim(description, E' \t\n\r\x0B')
    AND description <> ''
    AND char_length(description) <= 200
);
