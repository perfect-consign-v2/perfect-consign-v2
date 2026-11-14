-- A store purchase buys only a consigned piece whose expire date is already past
-- in the mall time zone. The typed amount stays the cost. A piece that expires
-- today is still on the floor. Return and donate already use the same day test.

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
    IF NOT EXISTS (
        SELECT 1 FROM public.items
        WHERE id = NEW.item_id
          AND placement = 'consigned'
          AND quantity > 0
          AND expires_on IS NOT NULL
          AND expires_on < (SELECT (clock_timestamp() AT TIME ZONE mall.time_zone)::date FROM public.mall)
    ) THEN
        RAISE EXCEPTION 'only an expired consigned piece can be bought'
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
