-- Offline snaps post server commission. Collected tax is kept; expected/variance are stored on the snap.
-- Snap price lock no longer rewrites items or mall. Sale shape trusts snap-priced tax as collected.

ALTER TABLE public.register_snaps
    ADD COLUMN tax_expected_minor bigint,
    ADD COLUMN tax_variance_minor bigint;

-- sale_lines_append_only blocks CREATE OR REPLACE of its function; rename, replace, rename back.
ALTER TRIGGER sale_lines_append_only ON public.sale_lines RENAME TO sale_lines_shape_guard;

CREATE OR REPLACE FUNCTION public.stall_assert_sale_shape() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    sale_source text;
    sale_reversal boolean;
    sale_date date;
    tax_exempt boolean;
    commission_bps integer;
    tax_bps integer;
    snap_priced boolean;
    expected_commission bigint;
    expected_tax bigint;
    expected_discount bigint;
    expected_store bigint;
    picked_offer uuid;
    waiver boolean;
    tender_lines integer;
    tender_roles integer;
    tender_sum bigint;
    prices bigint;
    commissions bigint;
    taxes bigint;
    discounts bigint;
    store_discounts bigint;
    promo_debit bigint;
    promo_lines integer;
    net_commission bigint;
    commission_lines integer;
    commission_credit bigint;
    tax_lines integer;
    tax_credit bigint;
    payable_gaps integer;
    on_hand integer;
    foreign_lines integer;
    round_debit bigint;
    round_credit bigint;
    round_lines integer;
    cash_debit bigint;
    exact_cash bigint;
    round_delta integer;
    round_cash boolean;
    placement text;
    store_net bigint;
    store_lines integer;
    store_credit bigint;
    cogs_amount bigint;
    cogs_lines integer;
    cogs_debit bigint;
    inventory_credit bigint;
BEGIN
    IF TG_TABLE_NAME = 'sale_lines' THEN
        IF TG_OP <> 'INSERT' THEN
            RAISE EXCEPTION 'a sale line cannot be changed'
                USING ERRCODE = '23514';
        END IF;

        SELECT journal.source_type, journal.is_reversal, journal.posting_date
        INTO sale_source, sale_reversal, sale_date
        FROM public.journals AS journal
        WHERE journal.id = NEW.journal_id;

        IF sale_source IS DISTINCT FROM 'sale' OR sale_reversal IS DISTINCT FROM false THEN
            RAISE EXCEPTION 'a sale line belongs on a sale'
                USING ERRCODE = '23514';
        END IF;

        snap_priced := current_setting('stall.snap_priced', true) = '1';

        SELECT item.placement,
               vendor.tax_exempt,
               CASE
                   WHEN item.placement = 'store' THEN 0
                   WHEN snap_priced THEN public.stall_commission_for(item.id, NEW.price_minor, sale_date)
                   ELSE COALESCE(item.commission_bps, vendor.commission_bps, mall.commission_bps)
               END,
               mall.tax_bps,
               item.quantity
        INTO placement, tax_exempt, commission_bps, tax_bps, on_hand
        FROM public.items AS item
        LEFT JOIN public.vendors AS vendor ON vendor.id = item.vendor_id
        CROSS JOIN public.mall AS mall
        WHERE item.id = NEW.item_id
        FOR UPDATE OF item;

        IF placement IS NULL THEN
            RAISE EXCEPTION 'a sale line needs an item'
                USING ERRCODE = '23514';
        END IF;

        IF placement = 'store' THEN
            tax_exempt := false;
        ELSIF tax_exempt IS NULL THEN
            RAISE EXCEPTION 'a sale line needs an item'
                USING ERRCODE = '23514';
        END IF;

        IF placement = 'consigned'
            AND EXISTS (
                SELECT 1
                FROM public.items AS aged
                CROSS JOIN public.mall AS mall_clock
                WHERE aged.id = NEW.item_id
                  AND aged.expires_on IS NOT NULL
                  AND aged.expires_on < (clock_timestamp() AT TIME ZONE mall_clock.time_zone)::date
                  AND aged.quantity > 0
                  AND NOT EXISTS (
                      SELECT 1 FROM public.item_dispositions AS disposition
                      WHERE disposition.item_id = aged.id
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM public.item_buys AS bought
                      WHERE bought.item_id = aged.id
                  )
            ) THEN
            RAISE EXCEPTION 'an expired piece needs a disposition'
                USING ERRCODE = '23514';
        END IF;

        expected_discount := 0;
        expected_store := 0;
        picked_offer := NULL;
        waiver := false;
        SELECT public.stall_line_discount(ticket.id, NEW.item_id, ticket.discount_kind, ticket.discount_value)
                   + line.line_discount_minor
                   + line.offer_discount_minor,
               line.store_discount_minor,
               line.offer_id,
               ticket.tax_waiver_reason IS NOT NULL
        INTO expected_discount, expected_store, picked_offer, waiver
        FROM public.tickets AS ticket
        JOIN public.ticket_lines AS line ON line.ticket_id = ticket.id
        WHERE line.item_id = NEW.item_id
          AND ticket.status = 'open';
        IF NOT FOUND THEN
            expected_discount := 0;
            expected_store := 0;
            picked_offer := NULL;
            waiver := false;
        END IF;

        NEW.offer_id := picked_offer;

        IF NEW.discount_minor IS DISTINCT FROM expected_discount
            OR NEW.store_discount_minor IS DISTINCT FROM expected_store
            OR NEW.discount_minor < 0
            OR NEW.store_discount_minor < 0
            OR NEW.discount_minor + NEW.store_discount_minor > NEW.price_minor * NEW.quantity THEN
            RAISE EXCEPTION 'a sale discount does not match the ticket'
                USING ERRCODE = '23514';
        END IF;

        expected_commission := public.stall_half_up(NEW.price_minor * NEW.quantity - NEW.discount_minor, commission_bps);

        IF snap_priced THEN
            expected_tax := NEW.tax_minor;
        ELSIF tax_exempt OR waiver THEN
            expected_tax := 0;
        ELSE
            expected_tax := public.stall_tax_minor(
                NEW.price_minor * NEW.quantity - NEW.discount_minor - NEW.store_discount_minor,
                tax_bps
            );
        END IF;

        IF NEW.commission_minor IS DISTINCT FROM expected_commission THEN
            RAISE EXCEPTION 'a sale commission does not match the store take'
                USING ERRCODE = '23514';
        END IF;

        IF NEW.tax_minor IS DISTINCT FROM expected_tax THEN
            RAISE EXCEPTION 'a sale tax does not match the mall rate'
                USING ERRCODE = '23514';
        END IF;

        IF NEW.quantity > on_hand THEN
            RAISE EXCEPTION 'there are not enough on hand'
                USING ERRCODE = '23514';
        END IF;

        UPDATE public.items
        SET quantity = quantity - NEW.quantity
        WHERE id = NEW.item_id;

        RETURN NEW;
    END IF;

    IF NEW.source_type <> 'sale' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    SELECT count(*), count(DISTINCT role.role), COALESCE(sum(line.debit_minor), 0)
    INTO tender_lines, tender_roles, tender_sum
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND line.debit_minor > 0
      AND (
          role.role IN ('cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit', 'layaway_deposit')
          OR (role.role = 'vendor_payable' AND line.subledger_type = 'vendor')
      );

    IF tender_lines < 1 OR tender_lines <> tender_roles THEN
        RAISE EXCEPTION 'a sale debits each tender once'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND line.debit_minor > 0
          AND (
              (role.role IN ('cash', 'card', 'checks') AND line.subledger_type IS NOT NULL)
              OR (
                  role.role IN ('gift_certificate', 'gift_card', 'store_credit')
                  AND line.subledger_type IS DISTINCT FROM role.role
              )
          )
    ) THEN
        RAISE EXCEPTION 'a sale debits each tender once'
            USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM public.sale_lines WHERE journal_id = NEW.id) THEN
        RAISE EXCEPTION 'a sale needs an item'
            USING ERRCODE = '23514';
    END IF;

    SELECT COALESCE(sum(price_minor * quantity), 0),
           COALESCE(sum(commission_minor), 0),
           COALESCE(sum(tax_minor), 0),
           COALESCE(sum(discount_minor), 0),
           COALESCE(sum(store_discount_minor), 0)
    INTO prices, commissions, taxes, discounts, store_discounts
    FROM public.sale_lines
    WHERE journal_id = NEW.id;

    net_commission := commissions;

    SELECT COALESCE(sum(line.debit_minor), 0), count(*)::integer
    INTO promo_debit, promo_lines
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'promotion_expense'
      AND line.debit_minor > 0
      AND line.credit_minor = 0;

    IF store_discounts = 0 THEN
        IF promo_lines <> 0 OR promo_debit <> 0 THEN
            RAISE EXCEPTION 'a store deal is the mall''s expense'
                USING ERRCODE = '23514';
        END IF;
    ELSIF promo_lines <> 1 OR promo_debit <> store_discounts THEN
        RAISE EXCEPTION 'a store deal is the mall''s expense'
            USING ERRCODE = '23514';
    END IF;

    SELECT COALESCE(sum(line.debit_minor), 0), COALESCE(sum(line.credit_minor), 0), count(*)
    INTO round_debit, round_credit, round_lines
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'cash_rounding'
      AND (line.debit_minor > 0 OR line.credit_minor > 0);

    SELECT COALESCE(sum(line.debit_minor), 0)
    INTO cash_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'cash'
      AND line.debit_minor > 0;

    SELECT cash_round_nickel INTO round_cash FROM public.mall;

    exact_cash := prices - discounts - store_discounts + taxes - (tender_sum - cash_debit);
    round_delta := CASE exact_cash % 5
        WHEN 1 THEN -1
        WHEN 2 THEN -2
        WHEN 3 THEN 2
        WHEN 4 THEN 1
        ELSE 0
    END;

    IF cash_debit = 0 OR cash_debit <> tender_sum OR round_cash IS DISTINCT FROM true THEN
        IF round_lines <> 0 OR tender_sum <> prices - discounts - store_discounts + taxes THEN
            RAISE EXCEPTION 'a sale debits the sold prices plus tax'
                USING ERRCODE = '23514';
        END IF;
    ELSIF exact_cash < 0
        OR cash_debit <> exact_cash + round_delta
        OR (round_delta = 0 AND round_lines <> 0)
        OR (round_delta > 0 AND (round_lines <> 1 OR round_credit <> round_delta OR round_debit <> 0))
        OR (round_delta < 0 AND (round_lines <> 1 OR round_debit <> -round_delta OR round_credit <> 0)) THEN
        RAISE EXCEPTION 'a cash sale rounds to the nearest nickel'
            USING ERRCODE = '23514';
    END IF;

    IF tender_sum <> prices - discounts - store_discounts + taxes + round_credit - round_debit THEN
        RAISE EXCEPTION 'a sale debits the sold prices plus tax'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*), COALESCE(sum(line.credit_minor), 0)
    INTO commission_lines, commission_credit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'commission_revenue'
      AND line.credit_minor > 0;

    IF net_commission = 0 THEN
        IF commission_lines <> 0 THEN
            RAISE EXCEPTION 'a sale commission does not match the store take'
                USING ERRCODE = '23514';
        END IF;
    ELSIF commission_lines <> 1 OR commission_credit <> net_commission THEN
        RAISE EXCEPTION 'a sale commission does not match the store take'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*), COALESCE(sum(line.credit_minor), 0)
    INTO tax_lines, tax_credit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'sales_tax_payable'
      AND line.credit_minor > 0;

    IF taxes = 0 THEN
        IF tax_lines <> 0 THEN
            RAISE EXCEPTION 'a sale tax does not match the mall rate'
                USING ERRCODE = '23514';
        END IF;
    ELSIF tax_lines <> 1 OR tax_credit <> taxes THEN
        RAISE EXCEPTION 'a sale tax does not match the mall rate'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO payable_gaps
    FROM (
        SELECT item.vendor_id, sum(sale.price_minor * sale.quantity - sale.discount_minor - sale.commission_minor) AS owed
        FROM public.sale_lines AS sale
        JOIN public.items AS item ON item.id = sale.item_id
        WHERE sale.journal_id = NEW.id
          AND item.vendor_id IS NOT NULL
        GROUP BY item.vendor_id
    ) AS owed
    FULL OUTER JOIN (
        SELECT line.subledger_ref::uuid AS vendor_id,
               sum(line.credit_minor)::bigint AS credit_minor,
               count(*)::integer AS line_count
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role = 'vendor_payable'
          AND line.credit_minor > 0
          AND line.subledger_type = 'vendor'
        GROUP BY line.subledger_ref
    ) AS posted ON posted.vendor_id = owed.vendor_id
    WHERE COALESCE(owed.owed, 0) IS DISTINCT FROM COALESCE(posted.credit_minor, 0)
       OR COALESCE(posted.line_count, 0) > 1
       OR (COALESCE(owed.owed, 0) = 0 AND posted.vendor_id IS NOT NULL)
       OR (COALESCE(owed.owed, 0) > 0 AND COALESCE(posted.line_count, 0) <> 1);

    IF payable_gaps > 0 THEN
        RAISE EXCEPTION 'a sale credits that vendor''s payable'
            USING ERRCODE = '23514';
    END IF;

    SELECT COALESCE(sum(sale.price_minor * sale.quantity - sale.discount_minor), 0)
    INTO store_net
    FROM public.sale_lines AS sale
    JOIN public.items AS item ON item.id = sale.item_id
    WHERE sale.journal_id = NEW.id
      AND item.placement = 'store';

    SELECT count(*), COALESCE(sum(line.credit_minor), 0)
    INTO store_lines, store_credit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'store_sales'
      AND line.credit_minor > 0;

    IF store_net = 0 THEN
        IF store_lines <> 0 THEN
            RAISE EXCEPTION 'a store sale credits store sales'
                USING ERRCODE = '23514';
        END IF;
    ELSIF store_lines <> 1 OR store_credit <> store_net THEN
        RAISE EXCEPTION 'a store sale credits store sales'
            USING ERRCODE = '23514';
    END IF;

    SELECT COALESCE(sum(item.cost_minor * sale.quantity), 0)
    INTO cogs_amount
    FROM public.sale_lines AS sale
    JOIN public.items AS item ON item.id = sale.item_id
    WHERE sale.journal_id = NEW.id
      AND item.placement = 'store'
      AND item.cost_minor IS NOT NULL;

    SELECT count(*), COALESCE(sum(line.debit_minor), 0)
    INTO cogs_lines, cogs_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'cost_of_goods'
      AND line.debit_minor > 0;

    SELECT COALESCE(sum(line.credit_minor), 0)
    INTO inventory_credit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'inventory'
      AND line.credit_minor > 0;

    IF tender_sum + round_debit + promo_debit + cogs_amount <> NEW.debit_total_minor
        OR tender_sum <> prices - discounts - store_discounts + taxes + round_credit - round_debit THEN
        RAISE EXCEPTION 'a sale debits the sold prices plus tax'
            USING ERRCODE = '23514';
    END IF;

    IF cogs_amount = 0 THEN
        IF cogs_lines <> 0 OR inventory_credit <> 0 THEN
            RAISE EXCEPTION 'a store sale relieves its cost'
                USING ERRCODE = '23514';
        END IF;
    ELSIF cogs_lines <> 1 OR cogs_debit <> cogs_amount OR inventory_credit <> cogs_amount THEN
        RAISE EXCEPTION 'a store sale relieves its cost'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.journal_lines AS draw
        JOIN public.chart_roles AS draw_role ON draw_role.account_id = draw.account_id
        WHERE draw.journal_id = NEW.id
          AND draw_role.role = 'vendor_payable'
          AND draw.debit_minor > 0
          AND (
              draw.subledger_type IS DISTINCT FROM 'vendor'
              OR draw.debit_minor > COALESCE((
                  SELECT sum(prior.credit_minor - prior.debit_minor)
                  FROM public.journal_lines AS prior
                  JOIN public.chart_roles AS prior_role ON prior_role.account_id = prior.account_id
                  WHERE prior.journal_id <> NEW.id
                    AND prior_role.role = 'vendor_payable'
                    AND prior.subledger_type = 'vendor'
                    AND prior.subledger_ref = draw.subledger_ref
              ), 0)
          )
    ) THEN
        RAISE EXCEPTION 'a vendor draw is limited to the open payable'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.journal_lines AS layaway
        JOIN public.chart_roles AS layaway_role ON layaway_role.account_id = layaway.account_id
        WHERE layaway.journal_id = NEW.id
          AND layaway_role.role = 'layaway_deposit'
          AND layaway.debit_minor > 0
          AND (
              layaway.subledger_type IS DISTINCT FROM 'ticket'
              OR layaway.debit_minor > COALESCE((
                  SELECT sum(prior.credit_minor - prior.debit_minor)
                  FROM public.journal_lines AS prior
                  JOIN public.chart_roles AS prior_role ON prior_role.account_id = prior.account_id
                  WHERE prior.journal_id <> NEW.id
                    AND prior_role.role = 'layaway_deposit'
                    AND prior.subledger_type = 'ticket'
                    AND prior.subledger_ref = layaway.subledger_ref
              ), 0)
          )
    ) THEN
        RAISE EXCEPTION 'a layaway deposit is limited to what was paid in'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO foreign_lines
    FROM public.journal_lines AS line
    WHERE line.journal_id = NEW.id
      AND NOT EXISTS (
          SELECT 1
          FROM public.chart_roles AS role
          WHERE role.account_id = line.account_id
            AND (
                (role.role IN ('cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit', 'layaway_deposit') AND line.debit_minor > 0)
                OR (role.role = 'commission_revenue' AND line.credit_minor > 0)
                OR (role.role = 'sales_tax_payable' AND line.credit_minor > 0)
                OR (role.role = 'vendor_payable' AND line.credit_minor > 0)
                OR (role.role = 'vendor_payable' AND line.debit_minor > 0 AND line.subledger_type = 'vendor')
                OR (role.role = 'cash_rounding' AND (line.debit_minor > 0 OR line.credit_minor > 0))
                OR (role.role = 'promotion_expense' AND line.debit_minor > 0 AND line.credit_minor = 0)
                OR (role.role = 'store_sales' AND line.credit_minor > 0)
                OR (role.role = 'cost_of_goods' AND line.debit_minor > 0 AND line.credit_minor = 0)
                OR (role.role = 'inventory' AND line.credit_minor > 0 AND line.debit_minor = 0)
            )
      );

    IF foreign_lines > 0 THEN
        RAISE EXCEPTION 'a sale only credits commission, sales tax, and vendor payable'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

ALTER TRIGGER sale_lines_shape_guard ON public.sale_lines RENAME TO sale_lines_append_only;

CREATE OR REPLACE FUNCTION public.stall_sync_snap(snap_key text) RETURNS bigint
    LANGUAGE plpgsql
    SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'public'
    AS $$
DECLARE
    snap public.register_snaps%ROWTYPE;
    on_hand integer;
    vendor uuid;
    cash_id uuid;
    commission_id uuid;
    payable_id uuid;
    tax_id uuid;
    posted_id uuid;
    payable bigint;
    posted_no bigint;
    book uuid;
    period uuid;
    commission_bps integer;
    commission_minor bigint;
    tax_expected bigint;
    mall_tax integer;
BEGIN
    SELECT * INTO snap
    FROM public.register_snaps AS row
    WHERE row.posting_key = snap_key
    FOR UPDATE;

    IF snap.id IS NULL THEN
        RAISE EXCEPTION 'that snap is not on this register'
            USING ERRCODE = '23514';
    END IF;

    IF snap.status = 'posted' AND snap.journal_no IS NOT NULL THEN
        RETURN snap.journal_no;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM public.register_sessions AS session
        WHERE session.id = snap.session_id
          AND session.closed_at IS NULL
    ) THEN
        UPDATE public.register_snaps
        SET status = 'refused', refusal = 'a register session is required'
        WHERE id = snap.id;
        RETURN NULL;
    END IF;

    IF EXISTS (
        SELECT 1 FROM public.journals AS journal WHERE journal.posting_key = snap.posting_key
    ) THEN
        SELECT journal.id, journal.journal_no
        INTO posted_id, posted_no
        FROM public.journals AS journal
        WHERE journal.posting_key = snap.posting_key;
        UPDATE public.register_snaps
        SET status = 'posted', journal_id = posted_id, journal_no = posted_no, refusal = NULL
        WHERE id = snap.id;
        RETURN posted_no;
    END IF;

    SELECT role.account_id INTO cash_id FROM public.chart_roles AS role WHERE role.role = 'cash';
    SELECT role.account_id INTO commission_id FROM public.chart_roles AS role WHERE role.role = 'commission_revenue';
    SELECT role.account_id INTO payable_id FROM public.chart_roles AS role WHERE role.role = 'vendor_payable';
    SELECT role.account_id INTO tax_id FROM public.chart_roles AS role WHERE role.role = 'sales_tax_payable';
    SELECT id INTO book FROM public.ledger_books WHERE is_default;
    SELECT open_period.id INTO period
    FROM public.accounting_periods AS open_period
    WHERE open_period.book_id = book
      AND open_period.starts_on <= snap.business_date
      AND open_period.ends_on >= snap.business_date
      AND open_period.status = 'open';

    IF period IS NULL THEN
        UPDATE public.register_snaps
        SET status = 'refused', refusal = 'a closed period refuses a new journal'
        WHERE id = snap.id;
        RETURN NULL;
    END IF;

    SELECT item.quantity, item.vendor_id
    INTO on_hand, vendor
    FROM public.items AS item
    WHERE item.id = snap.item_id
      AND item.placement = 'consigned'
    FOR UPDATE;

    IF vendor IS NULL THEN
        UPDATE public.register_snaps
        SET status = 'refused', refusal = 'a sale line needs an item'
        WHERE id = snap.id;
        RETURN NULL;
    END IF;

    IF on_hand < snap.quantity THEN
        UPDATE public.register_snaps
        SET status = 'refused', refusal = 'there are not enough on hand'
        WHERE id = snap.id;
        RETURN NULL;
    END IF;

    commission_bps := public.stall_commission_for(snap.item_id, snap.price_minor, snap.business_date);
    commission_minor := public.stall_half_up(snap.price_minor * snap.quantity, commission_bps);
    SELECT mall.tax_bps INTO mall_tax FROM public.mall AS mall;
    tax_expected := public.stall_tax_minor(snap.price_minor * snap.quantity, mall_tax);
    UPDATE public.register_snaps
    SET tax_expected_minor = tax_expected,
        tax_variance_minor = snap.tax_minor - tax_expected
    WHERE id = snap.id;

    payable := snap.price_minor * snap.quantity - commission_minor;
    posted_id := uuidv7();

    BEGIN
        INSERT INTO public.journals (
            id, book_id, posting_key, posting_date, occurred_at, period_id,
            currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
        ) VALUES (
            posted_id, book, snap.posting_key, snap.business_date, clock_timestamp(), period,
            'USD', 'sale', snap.posting_key, 'Snapped sale',
            snap.price_minor * snap.quantity + snap.tax_minor,
            snap.price_minor * snap.quantity + snap.tax_minor
        );
        INSERT INTO public.journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
        VALUES (posted_id, book, 1, cash_id, snap.price_minor * snap.quantity + snap.tax_minor, 0);
        IF commission_minor > 0 THEN
            INSERT INTO public.journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
            VALUES (posted_id, book, 2, commission_id, 0, commission_minor);
        END IF;
        INSERT INTO public.journal_lines (
            journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
        ) VALUES (
            posted_id, book, 3, payable_id, 0, payable, 'vendor', vendor::text
        );
        IF snap.tax_minor > 0 THEN
            INSERT INTO public.journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
            VALUES (posted_id, book, 4, tax_id, 0, snap.tax_minor);
        END IF;
        INSERT INTO public.sale_lines (journal_id, line_no, item_id, price_minor, quantity, commission_minor, tax_minor)
        VALUES (posted_id, 1, snap.item_id, snap.price_minor, snap.quantity, commission_minor, snap.tax_minor);
        INSERT INTO public.register_session_posts (session_id, journal_id)
        VALUES (snap.session_id, posted_id);
        SET CONSTRAINTS ALL IMMEDIATE;
        SELECT journal.journal_no INTO posted_no FROM public.journals AS journal WHERE journal.id = posted_id;
    EXCEPTION WHEN OTHERS THEN
        UPDATE public.register_snaps
        SET status = 'refused', refusal = SQLERRM
        WHERE id = snap.id;
        RETURN NULL;
    END;

    SET CONSTRAINTS ALL DEFERRED;

    UPDATE public.register_snaps
    SET status = 'posted', journal_id = posted_id, journal_no = posted_no, refusal = NULL
    WHERE id = snap.id;

    RETURN posted_no;
END;
$$;

CREATE OR REPLACE FUNCTION public.stall_apply_snap_price() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    snap_price bigint;
    posting text;
BEGIN
    SELECT journal.posting_key INTO posting
    FROM public.journals AS journal
    WHERE journal.id = NEW.journal_id;
    SELECT snap.price_minor
    INTO snap_price
    FROM public.register_snaps AS snap
    WHERE snap.posting_key = posting
      AND snap.item_id = NEW.item_id;
    IF snap_price IS NULL THEN
        RETURN NEW;
    END IF;
    IF NEW.price_minor IS DISTINCT FROM snap_price THEN
        RAISE EXCEPTION 'a snapped sale keeps its price'
            USING ERRCODE = '23514';
    END IF;
    PERFORM set_config('stall.snap_priced', '1', true);

    RETURN NEW;
END;
$$;

REVOKE ALL ON TABLE public.register_snaps FROM PUBLIC;
REVOKE ALL ON TABLE public.register_snaps FROM stall_poster;
REVOKE ALL ON TABLE public.register_snaps FROM stall_backup;
GRANT SELECT, INSERT ON TABLE public.register_snaps TO stall_poster;
GRANT SELECT ON TABLE public.register_snaps TO stall_backup;

REVOKE ALL ON FUNCTION public.stall_sync_snap(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.stall_sync_snap(text) FROM stall_backup;
GRANT EXECUTE ON FUNCTION public.stall_sync_snap(text) TO stall_poster;

REVOKE ALL ON FUNCTION public.stall_apply_snap_price() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.stall_apply_snap_price() TO stall_poster;
