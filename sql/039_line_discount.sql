-- A line discount lowers that line's sold price. The ticket discount applies after it.
-- The posted sale discount is the line discount plus that ticket share.
-- Apply after 038. The provisioner rewrites stall_poster.

ALTER TABLE ticket_lines ADD COLUMN IF NOT EXISTS line_discount_kind text;
ALTER TABLE ticket_lines ADD COLUMN IF NOT EXISTS line_discount_value bigint;
ALTER TABLE ticket_lines ADD COLUMN IF NOT EXISTS line_discount_reason text;
ALTER TABLE ticket_lines ADD COLUMN IF NOT EXISTS line_discount_minor bigint NOT NULL DEFAULT 0;

ALTER TABLE ticket_lines DROP CONSTRAINT IF EXISTS ticket_lines_line_discount_chk;
ALTER TABLE ticket_lines ADD CONSTRAINT ticket_lines_line_discount_chk CHECK (
    line_discount_minor >= 0
    AND (
        (
            line_discount_kind IS NULL
            AND line_discount_value IS NULL
            AND line_discount_reason IS NULL
            AND line_discount_minor = 0
        )
        OR (
            line_discount_kind = 'percent'
            AND line_discount_value > 0
            AND line_discount_value <= 10000
            AND line_discount_reason IN ('manager approval', 'damaged', 'loyalty', 'promotion', 'other')
            AND line_discount_minor > 0
        )
        OR (
            line_discount_kind = 'amount'
            AND line_discount_value > 0
            AND line_discount_reason IN ('manager approval', 'damaged', 'loyalty', 'promotion', 'other')
            AND line_discount_minor = line_discount_value
        )
    )
);

ALTER TABLE tickets ADD COLUMN IF NOT EXISTS buyer_id uuid REFERENCES vendors (id);

SET event_triggers = false;

CREATE OR REPLACE FUNCTION stall_line_discount(ticket uuid, item uuid, kind text, value bigint)
RETURNS bigint
LANGUAGE plpgsql
STABLE
SET search_path = pg_catalog
AS $$
DECLARE
    total bigint;
    share bigint;
    running bigint := 0;
    counted integer := 0;
    index integer := 0;
    line_item uuid;
    line_price bigint;
BEGIN
    IF kind IS NULL OR value IS NULL THEN
        RETURN 0;
    END IF;
    IF kind = 'percent' THEN
        SELECT public.stall_half_up(line.price_minor * line.quantity - line.line_discount_minor, value)
        INTO share
        FROM public.ticket_lines AS line
        WHERE line.ticket_id = ticket
          AND line.item_id = item;
        RETURN COALESCE(share, 0);
    END IF;
    IF kind IS DISTINCT FROM 'amount' THEN
        RETURN 0;
    END IF;
    SELECT COALESCE(sum(line.price_minor * line.quantity - line.line_discount_minor), 0), count(*)::integer
    INTO total, counted
    FROM public.ticket_lines AS line
    WHERE line.ticket_id = ticket;
    IF total = 0 OR counted = 0 THEN
        RETURN 0;
    END IF;
    FOR line_item, line_price IN
        SELECT line.item_id, line.price_minor * line.quantity - line.line_discount_minor
        FROM public.ticket_lines AS line
        WHERE line.ticket_id = ticket
        ORDER BY line.line_no
    LOOP
        index := index + 1;
        IF index = counted THEN
            share := value - running;
        ELSE
            share := (line_price * value + total / 2) / total;
            running := running + share;
        END IF;
        IF line_item = item THEN
            RETURN share;
        END IF;
    END LOOP;
    RETURN 0;
END;
$$;

CREATE OR REPLACE FUNCTION stall_refuse_ticket_discount(ticket uuid, kind text, value bigint)
RETURNS void
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    line_price bigint;
    line_item uuid;
    line_off bigint;
    share bigint;
    pieces integer;
BEGIN
    IF kind IS NULL THEN
        RETURN;
    END IF;
    SELECT count(*)::integer INTO pieces FROM public.ticket_lines WHERE ticket_id = ticket;
    IF pieces = 0 THEN
        RAISE EXCEPTION 'a ticket needs a piece'
            USING ERRCODE = '23514';
    END IF;
    FOR line_item, line_price, line_off IN
        SELECT line.item_id, line.price_minor * line.quantity, line.line_discount_minor
        FROM public.ticket_lines AS line
        WHERE line.ticket_id = ticket
        ORDER BY line.line_no
    LOOP
        share := public.stall_line_discount(ticket, line_item, kind, value);
        IF share < 0 OR line_off + share > line_price THEN
            RAISE EXCEPTION 'the discount is more than the price'
                USING ERRCODE = '23514';
        END IF;
    END LOOP;
END;
$$;

CREATE OR REPLACE FUNCTION stall_apply_line_discount()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    extended bigint;
    minor bigint;
BEGIN
    IF NEW.line_discount_kind IS NULL THEN
        NEW.line_discount_minor := 0;
        NEW.line_discount_value := NULL;
        NEW.line_discount_reason := NULL;
        RETURN NEW;
    END IF;
    extended := NEW.price_minor * NEW.quantity;
    IF NEW.line_discount_kind = 'percent' THEN
        minor := public.stall_half_up(extended, NEW.line_discount_value);
    ELSE
        minor := NEW.line_discount_value;
    END IF;
    IF minor < 1 OR minor > extended THEN
        RAISE EXCEPTION 'the discount is more than the price'
            USING ERRCODE = '23514';
    END IF;
    NEW.line_discount_minor := minor;
    RETURN NEW;
END;
$$;

SET event_triggers = true;

DROP TRIGGER IF EXISTS ticket_lines_line_discount ON ticket_lines;
CREATE TRIGGER ticket_lines_line_discount
    BEFORE INSERT OR UPDATE OF price_minor, quantity, line_discount_kind, line_discount_value, line_discount_reason
    ON ticket_lines
    FOR EACH ROW
    EXECUTE FUNCTION stall_apply_line_discount();
