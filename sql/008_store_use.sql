-- A store use buys a vendor piece for the business. It is not inventory.

CREATE OR REPLACE FUNCTION public.stall_apply_item_buy() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    expense bigint;
    payable bigint;
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM public.journals
        WHERE id = NEW.journal_id AND source_type = 'store_purchase' AND is_reversal = false
    ) THEN
        RAISE EXCEPTION 'a buy belongs on a store purchase'
            USING ERRCODE = '23514';
    END IF;
    IF EXISTS (
        SELECT 1 FROM public.store_uses
        WHERE journal_id = NEW.journal_id OR item_id = NEW.item_id
    ) THEN
        RAISE EXCEPTION 'a use is not inventory'
            USING ERRCODE = '23514';
    END IF;
    SELECT COALESCE(sum(line.debit_minor), 0)
    INTO expense
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.journal_id AND role.role = 'store_purchases' AND line.debit_minor > 0;
    SELECT COALESCE(sum(line.credit_minor), 0)
    INTO payable
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.journal_id
      AND role.role = 'vendor_payable'
      AND line.credit_minor > 0
      AND line.subledger_type = 'vendor'
      AND line.subledger_ref = NEW.vendor_id::text;
    IF expense <> NEW.amount_minor OR payable <> NEW.amount_minor THEN
        RAISE EXCEPTION 'a buy pays the consignor'
            USING ERRCODE = '23514';
    END IF;
    IF EXISTS (SELECT 1 FROM public.sale_lines WHERE item_id = NEW.item_id) THEN
        RAISE EXCEPTION 'a sold piece cannot be bought'
            USING ERRCODE = '23514';
    END IF;
    UPDATE public.items
    SET placement = 'store',
        vendor_id = NULL,
        expires_on = NULL,
        commission_bps = NULL,
        cost_minor = NEW.amount_minor
    WHERE id = NEW.item_id
      AND placement = 'consigned'
      AND vendor_id = NEW.vendor_id
      AND quantity > 0;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'a buy takes a consigned piece'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_reject_store_use_write() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'a store use cannot be changed'
        USING ERRCODE = '25006';
END;
$$;

CREATE FUNCTION public.stall_apply_store_use() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    expense bigint;
    payable bigint;
    on_hand integer;
    placement text;
    owner uuid;
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM public.journals
        WHERE id = NEW.journal_id AND source_type = 'store_purchase' AND is_reversal = false
    ) THEN
        RAISE EXCEPTION 'a use belongs on a store purchase'
            USING ERRCODE = '23514';
    END IF;
    IF EXISTS (
        SELECT 1 FROM public.item_buys
        WHERE journal_id = NEW.journal_id
           OR (NEW.item_id IS NOT NULL AND item_id = NEW.item_id)
    ) THEN
        RAISE EXCEPTION 'inventory cannot be a use'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.item_id IS NOT NULL THEN
        SELECT item.placement, item.vendor_id, item.quantity
        INTO placement, owner, on_hand
        FROM public.items AS item
        WHERE item.id = NEW.item_id
        FOR UPDATE OF item;
        IF placement IS NULL THEN
            RAISE EXCEPTION 'a store use needs an item'
                USING ERRCODE = '23514';
        END IF;
        IF placement = 'store' OR owner IS DISTINCT FROM NEW.vendor_id THEN
            RAISE EXCEPTION 'a store use stays with the vendor'
                USING ERRCODE = '23514';
        END IF;
        IF EXISTS (SELECT 1 FROM public.sale_lines WHERE item_id = NEW.item_id) THEN
            RAISE EXCEPTION 'a sold piece cannot be a store use'
                USING ERRCODE = '23514';
        END IF;
        IF on_hand < 1 THEN
            RAISE EXCEPTION 'there are not enough on hand'
                USING ERRCODE = '23514';
        END IF;
        IF EXISTS (
            SELECT 1
            FROM public.store_uses AS prior
            JOIN public.journals AS posted ON posted.id = prior.journal_id
            WHERE prior.item_id = NEW.item_id
              AND NOT EXISTS (
                  SELECT 1
                  FROM public.journals AS reversal
                  WHERE reversal.reverses_journal_id = posted.id
                    AND reversal.is_reversal = true
              )
        ) THEN
            RAISE EXCEPTION 'that piece is already used by the store'
                USING ERRCODE = '23514';
        END IF;
    END IF;

    SELECT COALESCE(sum(line.debit_minor), 0)
    INTO expense
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.journal_id
      AND role.role = 'store_purchases'
      AND line.debit_minor > 0
      AND line.credit_minor = 0
      AND line.subledger_type IS NULL;
    SELECT COALESCE(sum(line.credit_minor), 0)
    INTO payable
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.journal_id
      AND role.role = 'vendor_payable'
      AND line.credit_minor > 0
      AND line.debit_minor = 0
      AND line.subledger_type = 'vendor'
      AND line.subledger_ref = NEW.vendor_id::text;
    IF expense <> NEW.amount_minor OR payable <> NEW.amount_minor OR (
        SELECT count(*) FROM public.journal_lines WHERE journal_id = NEW.journal_id
    ) <> 2 THEN
        RAISE EXCEPTION 'a store use pays the agreed price'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.item_id IS NOT NULL THEN
        UPDATE public.items AS piece
        SET quantity = piece.quantity - 1
        WHERE piece.id = NEW.item_id
          AND piece.placement = ANY (ARRAY['consigned'::text, 'booth'::text])
          AND piece.vendor_id = NEW.vendor_id
          AND piece.cost_minor IS NULL;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'a store use stays with the vendor'
                USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_restore_store_use() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF NEW.is_reversal IS DISTINCT FROM true
        OR NEW.source_type <> 'store_purchase'
        OR NEW.reverses_journal_id IS NULL THEN
        RETURN NEW;
    END IF;
    IF EXISTS (
        SELECT 1
        FROM public.journals AS other
        WHERE other.reverses_journal_id = NEW.reverses_journal_id
          AND other.is_reversal = true
          AND other.id <> NEW.id
    ) THEN
        RAISE EXCEPTION 'a second void is refused'
            USING ERRCODE = '23514';
    END IF;
    UPDATE public.items AS item
    SET quantity = item.quantity + 1
    FROM public.store_uses AS used
    WHERE used.journal_id = NEW.reverses_journal_id
      AND used.item_id = item.id;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_assert_store_use() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF NEW.source_type <> 'store_purchase' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM public.store_uses WHERE journal_id = NEW.id) THEN
        RETURN NULL;
    END IF;
    IF left(NEW.posting_key, 15) <> 'store-purchase:' OR (
        SELECT count(*) FROM public.journal_lines WHERE journal_id = NEW.id
    ) <> 2 THEN
        RAISE EXCEPTION 'a store use names the piece'
            USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

CREATE TABLE public.store_uses (
    id uuid DEFAULT uuidv7() NOT NULL,
    journal_id uuid NOT NULL,
    vendor_id uuid NOT NULL,
    item_id uuid,
    amount_minor bigint NOT NULL,
    description text NOT NULL,
    memo text NOT NULL,
    CONSTRAINT store_uses_pkey PRIMARY KEY (id),
    CONSTRAINT store_uses_journal_key UNIQUE (journal_id),
    CONSTRAINT store_uses_amount_chk CHECK ((amount_minor > 0)),
    CONSTRAINT store_uses_description_chk CHECK (((description = btrim(description)) AND (description <> '') AND (char_length(description) <= 200))),
    CONSTRAINT store_uses_memo_chk CHECK (((memo = btrim(memo)) AND (memo <> '') AND (char_length(memo) <= 200)))
);

CREATE TRIGGER store_uses_apply_no_delete
    BEFORE INSERT ON public.store_uses
    FOR EACH ROW EXECUTE FUNCTION public.stall_apply_store_use();

CREATE TRIGGER store_uses_no_delete
    BEFORE UPDATE OR DELETE ON public.store_uses
    FOR EACH ROW EXECUTE FUNCTION public.stall_reject_store_use_write();

CREATE TRIGGER store_uses_no_truncate
    BEFORE TRUNCATE ON public.store_uses
    FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.store_uses
    ENABLE ALWAYS TRIGGER store_uses_apply_no_delete,
    ENABLE ALWAYS TRIGGER store_uses_no_delete,
    ENABLE ALWAYS TRIGGER store_uses_no_truncate;

CREATE TRIGGER journals_store_use_restore_no_delete
    AFTER INSERT ON public.journals
    FOR EACH ROW EXECUTE FUNCTION public.stall_restore_store_use();

CREATE CONSTRAINT TRIGGER journals_store_use_no_delete
    AFTER INSERT ON public.journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION public.stall_assert_store_use();

ALTER TABLE public.journals
    ENABLE ALWAYS TRIGGER journals_store_use_restore_no_delete,
    ENABLE ALWAYS TRIGGER journals_store_use_no_delete;

ALTER TABLE ONLY public.store_uses
    ADD CONSTRAINT store_uses_journal_id_fkey FOREIGN KEY (journal_id) REFERENCES public.journals(id);
ALTER TABLE ONLY public.store_uses
    ADD CONSTRAINT store_uses_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendors(id);
ALTER TABLE ONLY public.store_uses
    ADD CONSTRAINT store_uses_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.items(id);

GRANT SELECT, INSERT ON TABLE public.store_uses TO stall_poster;
