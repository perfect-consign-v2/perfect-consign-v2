-- Park takes the open ticket off the shift. Recall puts it on the active shift.
-- A parked ticket is not drawer cash. Apply after 034. The provisioner rewrites stall_poster.

ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_status_chk;
ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_posted_chk;

ALTER TABLE tickets ADD CONSTRAINT tickets_status_chk CHECK (status IN ('open', 'posted', 'parked'));
ALTER TABLE tickets ADD CONSTRAINT tickets_posted_chk CHECK (
    (status IN ('open', 'parked') AND posted_journal_id IS NULL)
    OR (status = 'posted' AND posted_journal_id IS NOT NULL)
);

SET event_triggers = false;

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
        IF OLD.status = 'posted' THEN
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

    IF NEW.id IS DISTINCT FROM OLD.id OR OLD.status = 'posted' THEN
        RAISE EXCEPTION 'a posted ticket cannot change'
            USING ERRCODE = '23514';
    END IF;

    IF OLD.status = 'open' AND NEW.status = 'parked' AND NEW.posted_journal_id IS NULL
        AND NEW.session_id IS NOT DISTINCT FROM OLD.session_id THEN
        RETURN NEW;
    END IF;

    IF OLD.status = 'parked' AND NEW.status = 'open' AND NEW.posted_journal_id IS NULL THEN
        RETURN NEW;
    END IF;

    IF OLD.status <> 'open' OR NEW.status <> 'posted' OR NEW.session_id IS DISTINCT FROM OLD.session_id THEN
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

SET event_triggers = true;
