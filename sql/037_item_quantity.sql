-- On-hand quantity. Pay takes units off the piece. A return and a void put them back.
-- The provisioner rewrites stall_poster.

CREATE OR REPLACE FUNCTION stall_restore_on_hand()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    units integer;
    piece uuid;
BEGIN
    IF NEW.is_reversal IS DISTINCT FROM true OR NEW.reverses_journal_id IS NULL THEN
        RETURN NEW;
    END IF;

    IF NEW.source_type = 'sale' THEN
        FOR piece, units IN
            SELECT sale.item_id,
                   sale.quantity - COALESCE((
                       SELECT sum(returned.quantity)::integer
                       FROM public.piece_returns AS returned
                       JOIN public.journals AS return_journal ON return_journal.id = returned.journal_id
                       WHERE returned.sale_journal_id = NEW.reverses_journal_id
                         AND returned.item_id = sale.item_id
                         AND return_journal.is_reversal = false
                         AND NOT EXISTS (
                             SELECT 1
                             FROM public.journals AS reversal
                             WHERE reversal.reverses_journal_id = return_journal.id
                               AND reversal.is_reversal = true
                         )
                   ), 0)
            FROM public.sale_lines AS sale
            WHERE sale.journal_id = NEW.reverses_journal_id
        LOOP
            IF units > 0 THEN
                UPDATE public.items
                SET quantity = quantity + units
                WHERE id = piece;
            END IF;
        END LOOP;
    ELSIF NEW.source_type = 'piece_return' THEN
        UPDATE public.items AS item
        SET quantity = item.quantity - returned.quantity
        FROM public.piece_returns AS returned
        WHERE returned.journal_id = NEW.reverses_journal_id
          AND item.id = returned.item_id;
    END IF;

    RETURN NEW;
END;
$$;









DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = 'journals'::regclass AND tgname = 'journals_restore_on_hand' AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER journals_restore_on_hand
            AFTER INSERT ON journals
            FOR EACH ROW
            EXECUTE FUNCTION stall_restore_on_hand();
    END IF;
END $$;

ALTER TABLE journals ENABLE ALWAYS TRIGGER journals_restore_on_hand;
