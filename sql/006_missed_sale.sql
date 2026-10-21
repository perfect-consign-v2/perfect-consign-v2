-- A missed sale credits the vendor their sale share and expenses that share.

CREATE FUNCTION public.stall_reject_missed_sale_write() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'a missed sale cannot be changed'
        USING ERRCODE = '25006';
END;
$$;

CREATE FUNCTION public.stall_apply_missed_sale() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    rate integer;
    share bigint;
    expense bigint;
    payable bigint;
    on_hand integer;
    placement text;
    owner uuid;
BEGIN
    IF NEW.item_id IS NULL THEN
        SELECT COALESCE(vendor.commission_bps, mall.commission_bps)
        INTO rate
        FROM public.vendors AS vendor
        CROSS JOIN public.mall AS mall
        WHERE vendor.id = NEW.vendor_id;
    ELSE
        SELECT item.placement, item.vendor_id, item.quantity,
               COALESCE(item.commission_bps, vendor.commission_bps, mall.commission_bps)
        INTO placement, owner, on_hand, rate
        FROM public.items AS item
        LEFT JOIN public.vendors AS vendor ON vendor.id = item.vendor_id
        CROSS JOIN public.mall AS mall
        WHERE item.id = NEW.item_id
        FOR UPDATE OF item;
        IF placement IS NULL THEN
            RAISE EXCEPTION 'a missed sale needs an item'
                USING ERRCODE = '23514';
        END IF;
        IF placement = 'store' OR owner IS DISTINCT FROM NEW.vendor_id THEN
            RAISE EXCEPTION 'a missed sale stays with the vendor'
                USING ERRCODE = '23514';
        END IF;
        IF EXISTS (SELECT 1 FROM public.sale_lines WHERE item_id = NEW.item_id) THEN
            RAISE EXCEPTION 'a sold piece cannot be a missed sale'
                USING ERRCODE = '23514';
        END IF;
        IF on_hand < 1 THEN
            RAISE EXCEPTION 'there are not enough on hand'
                USING ERRCODE = '23514';
        END IF;
        IF EXISTS (
            SELECT 1
            FROM public.missed_sales AS prior
            JOIN public.journals AS posted ON posted.id = prior.journal_id
            WHERE prior.item_id = NEW.item_id
              AND NOT EXISTS (
                  SELECT 1
                  FROM public.journals AS reversal
                  WHERE reversal.reverses_journal_id = posted.id
                    AND reversal.is_reversal = true
              )
        ) THEN
            RAISE EXCEPTION 'a missed sale is already posted for that piece'
                USING ERRCODE = '23514';
        END IF;
    END IF;

    IF rate IS NULL THEN
        RAISE EXCEPTION 'a missed sale needs a vendor'
            USING ERRCODE = '23514';
    END IF;

    share := NEW.price_minor - public.stall_half_up(NEW.price_minor, rate);
    IF share < 1 THEN
        RAISE EXCEPTION 'a missed sale owes the vendor'
            USING ERRCODE = '23514';
    END IF;

    SELECT COALESCE(sum(line.debit_minor), 0)
    INTO expense
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.journal_id
      AND role.role = 'operating_expense'
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

    IF expense <> share OR payable <> share OR (
        SELECT count(*) FROM public.journal_lines WHERE journal_id = NEW.journal_id
    ) <> 2 THEN
        RAISE EXCEPTION 'a missed sale debits the loss and credits the vendor'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.item_id IS NOT NULL THEN
        UPDATE public.items
        SET quantity = quantity - 1
        WHERE id = NEW.item_id;
    END IF;

    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_assert_missed_sale() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF NEW.source_type <> 'missed_sale' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;
    IF NEW.posting_key <> 'missed-sale:' || NEW.source_reference THEN
        RAISE EXCEPTION 'a missed sale posting key is its source reference'
            USING ERRCODE = '23514';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM public.missed_sales WHERE journal_id = NEW.id
    ) OR (
        SELECT count(*) FROM public.journal_lines WHERE journal_id = NEW.id
    ) <> 2 THEN
        RAISE EXCEPTION 'a missed sale names the piece'
            USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

CREATE FUNCTION public.stall_restore_missed_sale() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF NEW.is_reversal IS DISTINCT FROM true
        OR NEW.source_type <> 'missed_sale'
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
    FROM public.missed_sales AS missed
    WHERE missed.journal_id = NEW.reverses_journal_id
      AND missed.item_id = item.id;
    RETURN NEW;
END;
$$;

CREATE TABLE public.missed_sales (
    id uuid DEFAULT uuidv7() NOT NULL,
    journal_id uuid NOT NULL,
    vendor_id uuid NOT NULL,
    item_id uuid,
    price_minor bigint NOT NULL,
    description text NOT NULL,
    memo text NOT NULL,
    CONSTRAINT missed_sales_pkey PRIMARY KEY (id),
    CONSTRAINT missed_sales_journal_key UNIQUE (journal_id),
    CONSTRAINT missed_sales_price_chk CHECK ((price_minor > 0)),
    CONSTRAINT missed_sales_description_chk CHECK (((description = btrim(description)) AND (description <> '') AND (char_length(description) <= 200))),
    CONSTRAINT missed_sales_memo_chk CHECK (((memo = btrim(memo)) AND (memo <> '') AND (char_length(memo) <= 200)))
);

CREATE TRIGGER missed_sales_apply_no_delete
    BEFORE INSERT ON public.missed_sales
    FOR EACH ROW EXECUTE FUNCTION public.stall_apply_missed_sale();

CREATE TRIGGER missed_sales_no_delete
    BEFORE UPDATE OR DELETE ON public.missed_sales
    FOR EACH ROW EXECUTE FUNCTION public.stall_reject_missed_sale_write();

CREATE TRIGGER missed_sales_no_truncate
    BEFORE TRUNCATE ON public.missed_sales
    FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.missed_sales
    ENABLE ALWAYS TRIGGER missed_sales_apply_no_delete,
    ENABLE ALWAYS TRIGGER missed_sales_no_delete,
    ENABLE ALWAYS TRIGGER missed_sales_no_truncate;

CREATE CONSTRAINT TRIGGER journals_missed_sale_no_delete
    AFTER INSERT ON public.journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION public.stall_assert_missed_sale();

ALTER TABLE public.journals ENABLE ALWAYS TRIGGER journals_missed_sale_no_delete;

CREATE TRIGGER journals_missed_sale_restore
    AFTER INSERT ON public.journals
    FOR EACH ROW EXECUTE FUNCTION public.stall_restore_missed_sale();

ALTER TABLE ONLY public.missed_sales
    ADD CONSTRAINT missed_sales_journal_id_fkey FOREIGN KEY (journal_id) REFERENCES public.journals(id);
ALTER TABLE ONLY public.missed_sales
    ADD CONSTRAINT missed_sales_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendors(id);
ALTER TABLE ONLY public.missed_sales
    ADD CONSTRAINT missed_sales_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.items(id);

INSERT INTO public.chart_source_rules (role, source_type) VALUES ('operating_expense', 'missed_sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('vendor_payable', 'missed_sale');

GRANT SELECT, INSERT ON TABLE public.missed_sales TO stall_poster;
