-- An open ticket with no lines is an empty cart, not a sale.
-- Closing the register drops it. A ticket that still has an item still blocks the close.
-- Apply after 061. The provisioner rewrites stall_poster.

SET LOCAL event_triggers = false;

CREATE OR REPLACE FUNCTION stall_reject_close_with_open_ticket()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF TG_OP <> 'UPDATE' OR OLD.closed_at IS NOT NULL OR NEW.closed_at IS NULL THEN
        RETURN NEW;
    END IF;

    DELETE FROM public.tickets AS ticket
    WHERE ticket.session_id = NEW.id
      AND ticket.status = 'open'
      AND NOT EXISTS (
          SELECT 1
          FROM public.ticket_lines AS line
          WHERE line.ticket_id = ticket.id
      );

    IF EXISTS (
        SELECT 1
        FROM public.tickets AS ticket
        WHERE ticket.session_id = NEW.id
          AND ticket.status = 'open'
    ) THEN
        RAISE EXCEPTION 'close is refused while a ticket is open'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

SET LOCAL event_triggers = true;
