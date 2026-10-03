-- One opening journal per book. It may post to any chart account.
-- A reversed opening can be posted again only while every other journal on the
-- book is that opening or a reversal of it. Any other journal refuses an opening.
-- Safe to run again.

CREATE OR REPLACE FUNCTION stall_assert_opening()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    line_count integer;
    bad_lines integer;
BEGIN
    IF NEW.source_type <> 'opening' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    IF NEW.posting_key <> 'opening' THEN
        RAISE EXCEPTION 'an opening posting key is opening'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO line_count
    FROM public.journal_lines
    WHERE journal_id = NEW.id;

    SELECT count(*) INTO bad_lines
    FROM public.journal_lines AS line
    WHERE line.journal_id = NEW.id
      AND NOT EXISTS (
          SELECT 1
          FROM public.chart_roles AS role
          WHERE role.account_id = line.account_id
      );

    IF line_count < 2 OR bad_lines > 0 THEN
        RAISE EXCEPTION 'an opening posts only to chart accounts'
            USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS journals_opening ON journals;
CREATE CONSTRAINT TRIGGER journals_opening
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_opening();

CREATE INDEX journals_book_key_lookup
    ON journals (book_id, posting_key);

CREATE OR REPLACE FUNCTION stall_reject_live_posting_key()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog
AS $$
BEGIN
    IF NEW.posting_key = 'opening' THEN
        RETURN NEW;
    END IF;

    PERFORM pg_advisory_xact_lock(hashtextextended(NEW.book_id::text || chr(31) || NEW.posting_key, 0));

    IF EXISTS (
        SELECT 1
        FROM public.journals AS prior
        WHERE prior.book_id = NEW.book_id
          AND prior.posting_key = NEW.posting_key
          AND NOT EXISTS (
              SELECT 1 FROM public.journals AS reversal
              WHERE reversal.reverses_journal_id = prior.id
          )
    ) THEN
        RAISE EXCEPTION 'duplicate key value violates unique constraint "journals_book_key"'
            USING ERRCODE = '23505';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS journals_live_key ON journals;
CREATE TRIGGER journals_live_key
    BEFORE INSERT ON journals
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_live_posting_key();

ALTER TABLE journals ENABLE ALWAYS TRIGGER journals_live_key;

CREATE OR REPLACE FUNCTION stall_reject_duplicate_opening()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog
AS $$
DECLARE
    live_opening integer;
    other_journal integer;
BEGIN
    IF NEW.posting_key <> 'opening' THEN
        RETURN NEW;
    END IF;

    PERFORM pg_advisory_xact_lock(hashtextextended(NEW.book_id::text || chr(31) || NEW.posting_key, 0));

    SELECT count(*) INTO live_opening
    FROM public.journals AS prior
    WHERE prior.book_id = NEW.book_id
      AND prior.posting_key = 'opening'
      AND NOT EXISTS (
          SELECT 1
          FROM public.journals AS reversal
          WHERE reversal.reverses_journal_id = prior.id
      );

    IF live_opening > 0 THEN
        RAISE EXCEPTION 'duplicate key value violates unique constraint "journals_book_key"'
            USING ERRCODE = '23505';
    END IF;

    SELECT count(*) INTO other_journal
    FROM public.journals AS other
    WHERE other.book_id = NEW.book_id
      AND other.posting_key <> 'opening'
      AND NOT EXISTS (
          SELECT 1
          FROM public.journals AS opened
          WHERE opened.id = other.reverses_journal_id
            AND opened.book_id = NEW.book_id
            AND opened.posting_key = 'opening'
      );

    IF other_journal > 0 THEN
        RAISE EXCEPTION 'duplicate key value violates unique constraint "journals_book_key"'
            USING ERRCODE = '23505';
    END IF;

    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS journals_opening_key ON journals;
CREATE TRIGGER journals_opening_key
    BEFORE INSERT ON journals
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_duplicate_opening();

ALTER TABLE journals ENABLE ALWAYS TRIGGER journals_opening_key;
