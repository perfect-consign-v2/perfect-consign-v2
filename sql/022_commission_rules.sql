-- Commission tiers. Historical sale lines keep the commission they posted.
-- Two rules that are equally specific, equally prioritized, and differently rated are refused.

CREATE TABLE public.commission_rules (
    id uuid DEFAULT uuidv7() NOT NULL,
    vendor_id uuid,
    item_id uuid,
    priority integer DEFAULT 0 NOT NULL,
    min_minor bigint NOT NULL,
    max_minor bigint,
    bps integer NOT NULL,
    starts_on date NOT NULL,
    ends_on date,
    CONSTRAINT commission_rules_pkey PRIMARY KEY (id),
    CONSTRAINT commission_rules_bps_chk CHECK (((bps >= 0) AND (bps <= 10000))),
    CONSTRAINT commission_rules_dates_chk CHECK (((ends_on IS NULL) OR (starts_on <= ends_on))),
    CONSTRAINT commission_rules_price_chk CHECK (((min_minor >= 0) AND ((max_minor IS NULL) OR (min_minor <= max_minor)))),
    CONSTRAINT commission_rules_target_chk CHECK ((NOT ((item_id IS NOT NULL) AND (vendor_id IS NOT NULL))))
);

CREATE FUNCTION public.stall_guard_commission_rule() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM public.commission_rules AS other
        WHERE other.id IS DISTINCT FROM NEW.id
          AND other.priority = NEW.priority
          AND other.bps IS DISTINCT FROM NEW.bps
          AND other.item_id IS NOT DISTINCT FROM NEW.item_id
          AND other.vendor_id IS NOT DISTINCT FROM NEW.vendor_id
          AND daterange(other.starts_on, COALESCE(other.ends_on, 'infinity'::date), '[]')
              && daterange(NEW.starts_on, COALESCE(NEW.ends_on, 'infinity'::date), '[]')
          AND int8range(other.min_minor, COALESCE(other.max_minor + 1, 9223372036854775807), '[)')
              && int8range(NEW.min_minor, COALESCE(NEW.max_minor + 1, 9223372036854775807), '[)')
    ) THEN
        RAISE EXCEPTION 'two commission rules are equally specific'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER commission_rules_guard
    BEFORE INSERT OR UPDATE ON public.commission_rules
    FOR EACH ROW EXECUTE FUNCTION public.stall_guard_commission_rule();

CREATE FUNCTION public.stall_commission_for(item uuid, unit_price bigint, on_date date) RETURNS integer
    LANGUAGE plpgsql STABLE
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    rank integer;
    top integer;
    rates integer;
    chosen integer;
BEGIN
    IF item IS NULL OR unit_price IS NULL OR unit_price < 0 OR on_date IS NULL THEN
        RAISE EXCEPTION 'a commission price is required'
            USING ERRCODE = '23514';
    END IF;

    SELECT max(CASE
            WHEN rule.item_id = item THEN 3
            WHEN rule.vendor_id IS NOT NULL THEN 2
            ELSE 1
        END)
    INTO rank
    FROM public.commission_rules AS rule
    JOIN public.items AS piece ON piece.id = item
    WHERE unit_price >= rule.min_minor
      AND (rule.max_minor IS NULL OR unit_price <= rule.max_minor)
      AND rule.starts_on <= on_date
      AND (rule.ends_on IS NULL OR rule.ends_on >= on_date)
      AND (
          rule.item_id = item
          OR (rule.item_id IS NULL AND rule.vendor_id IS NOT DISTINCT FROM piece.vendor_id AND rule.vendor_id IS NOT NULL)
          OR (rule.item_id IS NULL AND rule.vendor_id IS NULL)
      );

    IF rank IS NULL THEN
        SELECT CASE
                WHEN piece.placement = 'store' THEN 0
                ELSE COALESCE(piece.commission_bps, vendor.commission_bps, mall.commission_bps)
            END
        INTO chosen
        FROM public.items AS piece
        LEFT JOIN public.vendors AS vendor ON vendor.id = piece.vendor_id
        CROSS JOIN public.mall AS mall
        WHERE piece.id = item;

        RETURN COALESCE(chosen, 0);
    END IF;

    SELECT max(rule.priority)
    INTO top
    FROM public.commission_rules AS rule
    JOIN public.items AS piece ON piece.id = item
    WHERE unit_price >= rule.min_minor
      AND (rule.max_minor IS NULL OR unit_price <= rule.max_minor)
      AND rule.starts_on <= on_date
      AND (rule.ends_on IS NULL OR rule.ends_on >= on_date)
      AND (
          (rank = 3 AND rule.item_id = item)
          OR (rank = 2 AND rule.item_id IS NULL AND rule.vendor_id IS NOT DISTINCT FROM piece.vendor_id)
          OR (rank = 1 AND rule.item_id IS NULL AND rule.vendor_id IS NULL)
      );

    SELECT count(DISTINCT rule.bps), min(rule.bps)
    INTO rates, chosen
    FROM public.commission_rules AS rule
    JOIN public.items AS piece ON piece.id = item
    WHERE rule.priority = top
      AND unit_price >= rule.min_minor
      AND (rule.max_minor IS NULL OR unit_price <= rule.max_minor)
      AND rule.starts_on <= on_date
      AND (rule.ends_on IS NULL OR rule.ends_on >= on_date)
      AND (
          (rank = 3 AND rule.item_id = item)
          OR (rank = 2 AND rule.item_id IS NULL AND rule.vendor_id IS NOT DISTINCT FROM piece.vendor_id)
          OR (rank = 1 AND rule.item_id IS NULL AND rule.vendor_id IS NULL)
      );

    IF rates IS DISTINCT FROM 1 THEN
        RAISE EXCEPTION 'two commission rules are equally specific'
            USING ERRCODE = '23514';
    END IF;

    RETURN chosen;
END;
$$;

GRANT ALL ON TABLE public.commission_rules TO stall_poster;
GRANT ALL ON TABLE public.commission_rules TO stall_backup;
GRANT ALL ON FUNCTION public.stall_guard_commission_rule() TO stall_poster;
GRANT ALL ON FUNCTION public.stall_guard_commission_rule() TO stall_backup;
GRANT ALL ON FUNCTION public.stall_commission_for(item uuid, unit_price bigint, on_date date) TO stall_poster;
GRANT ALL ON FUNCTION public.stall_commission_for(item uuid, unit_price bigint, on_date date) TO stall_backup;

CREATE FUNCTION public.stall_apply_commission_rate() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    prior integer;
    chosen integer;
    sale_date date;
BEGIN
    IF current_setting('stall.snap_priced', true) = '1' THEN
        RETURN NEW;
    END IF;
    SELECT journal.posting_date INTO sale_date
    FROM public.journals AS journal
    WHERE journal.id = NEW.journal_id;
    SELECT item.commission_bps INTO prior
    FROM public.items AS item
    WHERE item.id = NEW.item_id;
    chosen := public.stall_commission_for(NEW.item_id, NEW.price_minor, sale_date);
    PERFORM set_config('stall.prior_bps', COALESCE(prior::text, ''), true);
    UPDATE public.items
    SET commission_bps = chosen
    WHERE id = NEW.item_id
      AND placement <> 'store';

    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_restore_commission_rate() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    prior_tax text;
BEGIN
    IF current_setting('stall.prior_bps', true) IS NOT NULL THEN
        UPDATE public.items
        SET commission_bps = NULLIF(current_setting('stall.prior_bps', true), '')::integer
        WHERE id = NEW.item_id
          AND placement <> 'store';
    END IF;
    prior_tax := current_setting('stall.prior_tax', true);
    IF prior_tax IS NOT NULL AND prior_tax <> '' THEN
        UPDATE public.mall
        SET tax_bps = prior_tax::integer;
    END IF;
    PERFORM set_config('stall.snap_priced', '', true);
    PERFORM set_config('stall.prior_bps', '', true);
    PERFORM set_config('stall.prior_tax', '', true);

    RETURN NULL;
END;
$$;

CREATE TRIGGER sale_lines_00_rate
    BEFORE INSERT ON public.sale_lines
    FOR EACH ROW EXECUTE FUNCTION public.stall_apply_commission_rate();

CREATE TRIGGER sale_lines_zz_rate
    AFTER INSERT ON public.sale_lines
    FOR EACH ROW EXECUTE FUNCTION public.stall_restore_commission_rate();

GRANT ALL ON FUNCTION public.stall_apply_commission_rate() TO stall_poster;
GRANT ALL ON FUNCTION public.stall_apply_commission_rate() TO stall_backup;
GRANT ALL ON FUNCTION public.stall_restore_commission_rate() TO stall_poster;
GRANT ALL ON FUNCTION public.stall_restore_commission_rate() TO stall_backup;
