-- An open ticket is the cashier's cart. It is not a journal.
-- Pay posts the sale, then the ticket is frozen. Apply after 031.
-- The provisioner rewrites stall_poster.

CREATE TABLE IF NOT EXISTS tickets (
    id uuid PRIMARY KEY,
    session_id uuid NOT NULL REFERENCES register_sessions (id),
    status text NOT NULL DEFAULT 'open',
    posted_journal_id uuid REFERENCES journals (id),
    CONSTRAINT tickets_status_chk CHECK (status IN ('open', 'posted')),
    CONSTRAINT tickets_posted_chk CHECK (
        (status = 'open' AND posted_journal_id IS NULL)
        OR (status = 'posted' AND posted_journal_id IS NOT NULL)
    )
);

CREATE UNIQUE INDEX IF NOT EXISTS tickets_one_open
    ON tickets (session_id)
    WHERE status = 'open';

CREATE TABLE IF NOT EXISTS ticket_lines (
    ticket_id uuid NOT NULL REFERENCES tickets (id),
    line_no integer NOT NULL,
    item_id uuid NOT NULL REFERENCES items (id),
    price_minor bigint NOT NULL,
    CONSTRAINT ticket_lines_pkey PRIMARY KEY (ticket_id, line_no),
    CONSTRAINT ticket_lines_item_key UNIQUE (ticket_id, item_id),
    CONSTRAINT ticket_lines_no_chk CHECK (line_no > 0),
    CONSTRAINT ticket_lines_price_chk CHECK (price_minor > 0)
);

GRANT SELECT, INSERT, UPDATE, DELETE ON tickets TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON tickets FROM stall_poster;
GRANT SELECT ON tickets TO stall_backup;

GRANT SELECT, INSERT, UPDATE, DELETE ON ticket_lines TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON ticket_lines FROM stall_poster;
GRANT SELECT ON ticket_lines TO stall_backup;

CREATE OR REPLACE FUNCTION stall_assert_ticket()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    missing_lines integer;
    extra_lines integer;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status <> 'open' THEN
            RAISE EXCEPTION 'a posted ticket cannot be discarded'
                USING ERRCODE = '23514';
        END IF;
        DELETE FROM public.ticket_lines WHERE ticket_id = OLD.id;
        RETURN OLD;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM public.register_sessions AS session
        WHERE session.id = NEW.session_id
          AND session.closed_at IS NULL
    ) THEN
        RAISE EXCEPTION 'a ticket belongs on an open register'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'open' OR NEW.posted_journal_id IS NOT NULL THEN
            RAISE EXCEPTION 'a ticket opens before it is paid'
                USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
    END IF;

    IF OLD.status <> 'open'
        OR NEW.status <> 'posted'
        OR NEW.session_id IS DISTINCT FROM OLD.session_id
        OR NEW.id IS DISTINCT FROM OLD.id THEN
        RAISE EXCEPTION 'a posted ticket cannot change'
            USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM public.ticket_lines WHERE ticket_id = NEW.id
    ) THEN
        RAISE EXCEPTION 'a ticket needs a piece'
            USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM public.journals AS journal
        JOIN public.register_session_posts AS post ON post.journal_id = journal.id
        WHERE journal.id = NEW.posted_journal_id
          AND journal.source_type = 'sale'
          AND journal.is_reversal = false
          AND post.session_id = NEW.session_id
    ) THEN
        RAISE EXCEPTION 'a paid ticket must match its sale'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO missing_lines
    FROM (
        SELECT item_id, price_minor
        FROM public.ticket_lines
        WHERE ticket_id = NEW.id
        EXCEPT ALL
        SELECT item_id, price_minor
        FROM public.sale_lines
        WHERE journal_id = NEW.posted_journal_id
    ) AS missing;

    SELECT count(*) INTO extra_lines
    FROM (
        SELECT item_id, price_minor
        FROM public.sale_lines
        WHERE journal_id = NEW.posted_journal_id
        EXCEPT ALL
        SELECT item_id, price_minor
        FROM public.ticket_lines
        WHERE ticket_id = NEW.id
    ) AS extra;

    IF missing_lines > 0 OR extra_lines > 0 THEN
        RAISE EXCEPTION 'a paid ticket must match its sale'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION stall_assert_ticket_line()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    piece uuid;
    ticket_status text;
    line_item uuid;
    line_ticket uuid;
BEGIN
    IF TG_OP = 'DELETE' THEN
        line_item := OLD.item_id;
        line_ticket := OLD.ticket_id;
    ELSE
        line_item := NEW.item_id;
        line_ticket := NEW.ticket_id;
    END IF;

    SELECT ticket.status INTO ticket_status
    FROM public.tickets AS ticket
    WHERE ticket.id = line_ticket
    FOR UPDATE;

    IF ticket_status IS NULL THEN
        RAISE EXCEPTION 'a ticket line needs a ticket'
            USING ERRCODE = '23514';
    END IF;

    IF ticket_status <> 'open' AND NOT (TG_OP = 'DELETE' AND ticket_status = 'parked') THEN
        RAISE EXCEPTION 'a posted ticket cannot change'
            USING ERRCODE = '23514';
    END IF;

    SELECT item.id INTO piece
    FROM public.items AS item
    WHERE item.id = line_item
    FOR UPDATE OF item;

    IF piece IS NULL THEN
        RAISE EXCEPTION 'a ticket line needs a piece'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.ticket_lines AS line
        JOIN public.tickets AS ticket ON ticket.id = line.ticket_id
        WHERE line.item_id = NEW.item_id
          AND ticket.status IN ('open', 'parked')
          AND ticket.id <> NEW.ticket_id
    ) THEN
        RAISE EXCEPTION 'a piece is already on a ticket'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.sale_lines AS sale
        JOIN public.journals AS journal ON journal.id = sale.journal_id
        WHERE sale.item_id = NEW.item_id
          AND journal.source_type = 'sale'
          AND journal.is_reversal = false
          AND NOT EXISTS (
              SELECT 1
              FROM public.journals AS reversal
              WHERE reversal.reverses_journal_id = journal.id
          )
          AND NOT EXISTS (
              SELECT 1
              FROM public.piece_returns AS returned
              JOIN public.journals AS return_journal ON return_journal.id = returned.journal_id
              WHERE returned.sale_journal_id = journal.id
                AND returned.item_id = NEW.item_id
                AND return_journal.is_reversal = false
                AND NOT EXISTS (
                    SELECT 1
                    FROM public.journals AS reversal
                    WHERE reversal.reverses_journal_id = return_journal.id
                )
          )
    ) THEN
        RAISE EXCEPTION 'a piece is already sold'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION stall_assert_sale_not_on_ticket()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM public.ticket_lines AS line
        JOIN public.tickets AS ticket ON ticket.id = line.ticket_id
        WHERE line.item_id = NEW.item_id
          AND ticket.status IN ('open', 'parked')
    ) THEN
        RAISE EXCEPTION 'a piece is already on a ticket'
            USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

CREATE OR REPLACE FUNCTION stall_reject_close_with_open_ticket()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF TG_OP <> 'UPDATE' OR OLD.closed_at IS NOT NULL OR NEW.closed_at IS NULL THEN
        RETURN NEW;
    END IF;

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

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = 'tickets'::regclass AND tgname = 'tickets_no_delete' AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER tickets_no_delete
            BEFORE INSERT OR UPDATE OR DELETE ON tickets
            FOR EACH ROW
            EXECUTE FUNCTION stall_assert_ticket();
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = 'tickets'::regclass AND tgname = 'tickets_no_truncate' AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER tickets_no_truncate
            BEFORE TRUNCATE ON tickets
            FOR EACH STATEMENT
            EXECUTE FUNCTION ledger_reject_truncate();
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = 'ticket_lines'::regclass AND tgname = 'ticket_lines_no_delete' AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER ticket_lines_no_delete
            BEFORE INSERT OR UPDATE OR DELETE ON ticket_lines
            FOR EACH ROW
            EXECUTE FUNCTION stall_assert_ticket_line();
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = 'ticket_lines'::regclass AND tgname = 'ticket_lines_no_truncate' AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER ticket_lines_no_truncate
            BEFORE TRUNCATE ON ticket_lines
            FOR EACH STATEMENT
            EXECUTE FUNCTION ledger_reject_truncate();
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = 'sale_lines'::regclass           AND tgname = 'sale_lines_open_ticket_no_delete' AND NOT tgisinternal
    ) THEN
        CREATE CONSTRAINT TRIGGER sale_lines_open_ticket_no_delete
            AFTER INSERT ON sale_lines
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION stall_assert_sale_not_on_ticket();
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = 'register_sessions'::regclass
          AND tgname = 'register_sessions_open_ticket_no_delete'
          AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER register_sessions_open_ticket_no_delete
            BEFORE UPDATE ON register_sessions
            FOR EACH ROW
            EXECUTE FUNCTION stall_reject_close_with_open_ticket();
    END IF;
END $$;

ALTER TABLE tickets
    ENABLE ALWAYS TRIGGER tickets_no_delete,
    ENABLE ALWAYS TRIGGER tickets_no_truncate;

ALTER TABLE ticket_lines
    ENABLE ALWAYS TRIGGER ticket_lines_no_delete,
    ENABLE ALWAYS TRIGGER ticket_lines_no_truncate;

ALTER TABLE sale_lines ENABLE ALWAYS TRIGGER sale_lines_open_ticket_no_delete;

ALTER TABLE register_sessions ENABLE ALWAYS TRIGGER register_sessions_open_ticket_no_delete;
