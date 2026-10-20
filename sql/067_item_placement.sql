-- Item placement. A booth piece, a consigned piece, and a store piece post differently.
-- Apply after 066. The provisioner rewrites public. and stall_poster.

ALTER TABLE items ADD COLUMN placement text NOT NULL DEFAULT 'consigned';

ALTER TABLE items ADD CONSTRAINT items_placement_chk CHECK (placement IN ('booth', 'consigned', 'store'));

ALTER TABLE items ALTER COLUMN vendor_id DROP NOT NULL;

ALTER TABLE items ADD COLUMN cost_minor bigint;

ALTER TABLE items ADD CONSTRAINT items_cost_chk CHECK (cost_minor IS NULL OR cost_minor >= 0);

ALTER TABLE items ADD CONSTRAINT items_store_owner_chk CHECK (
    (placement = 'store' AND vendor_id IS NULL AND commission_bps IS NULL AND expires_on IS NULL)
    OR (placement IN ('booth', 'consigned') AND vendor_id IS NOT NULL AND cost_minor IS NULL)
);

UPDATE items
SET placement = CASE
        WHEN (SELECT booth_rentals_enabled FROM mall) THEN 'booth'
        ELSE 'consigned'
    END,
    expires_on = CASE
        WHEN (SELECT booth_rentals_enabled FROM mall) THEN NULL
        ELSE expires_on
    END
WHERE placement = 'consigned';

INSERT INTO accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type)
SELECT seed.id, seed.book_id, seed.code, seed.name, seed.account_type, seed.normal_balance, seed.is_postable, seed.is_control, seed.subledger_type
FROM (
    VALUES
        ('01900000-0000-7000-8000-000000000025'::uuid, '01900000-0000-7000-8000-000000000001'::uuid, '4120', 'Store sales', 'revenue', 'credit', true, false, NULL::text),
        ('01900000-0000-7000-8000-000000000026'::uuid, '01900000-0000-7000-8000-000000000001'::uuid, '1300', 'Inventory', 'asset', 'debit', true, false, NULL::text),
        ('01900000-0000-7000-8000-000000000027'::uuid, '01900000-0000-7000-8000-000000000001'::uuid, '5000', 'Cost of goods', 'expense', 'debit', true, false, NULL::text)
) AS seed(id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type)
WHERE NOT EXISTS (SELECT 1 FROM accounts WHERE id = seed.id);

INSERT INTO chart_roles (role, account_id)
SELECT seed.role, seed.account_id
FROM (
    VALUES
        ('store_sales', '01900000-0000-7000-8000-000000000025'::uuid),
        ('inventory', '01900000-0000-7000-8000-000000000026'::uuid),
        ('cost_of_goods', '01900000-0000-7000-8000-000000000027'::uuid)
) AS seed(role, account_id)
WHERE NOT EXISTS (SELECT 1 FROM chart_roles WHERE role = seed.role);

INSERT INTO chart_source_rules (role, source_type)
SELECT seed.role, seed.source_type
FROM (
    VALUES
        ('store_sales', 'sale'),
        ('store_sales', 'item_return'),
        ('inventory', 'sale'),
        ('inventory', 'item_return'),
        ('cost_of_goods', 'sale'),
        ('cost_of_goods', 'item_return')
) AS seed(role, source_type)
WHERE NOT EXISTS (
    SELECT 1 FROM chart_source_rules AS existing
    WHERE existing.role = seed.role AND existing.source_type = seed.source_type
);

CREATE TABLE item_buys (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    journal_id uuid NOT NULL UNIQUE REFERENCES journals (id),
    item_id uuid NOT NULL UNIQUE REFERENCES items (id),
    vendor_id uuid NOT NULL REFERENCES vendors (id),
    amount_minor bigint NOT NULL,
    CONSTRAINT item_buys_amount_chk CHECK (amount_minor >= 0)
);

GRANT SELECT, INSERT ON item_buys TO stall_poster;
REVOKE UPDATE, DELETE, TRUNCATE, TRIGGER ON item_buys FROM stall_poster;

CREATE TRIGGER item_buys_no_truncate
    BEFORE TRUNCATE ON item_buys
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE item_buys ENABLE ALWAYS TRIGGER item_buys_no_truncate;

CREATE TABLE item_dispositions (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    item_id uuid NOT NULL UNIQUE REFERENCES items (id),
    kind text NOT NULL,
    posted_on date NOT NULL,
    CONSTRAINT item_dispositions_kind_chk CHECK (kind IN ('return', 'donate'))
);

GRANT SELECT, INSERT ON item_dispositions TO stall_poster;
REVOKE UPDATE, DELETE, TRUNCATE, TRIGGER ON item_dispositions FROM stall_poster;

CREATE TRIGGER item_dispositions_no_truncate
    BEFORE TRUNCATE ON item_dispositions
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE item_dispositions ENABLE ALWAYS TRIGGER item_dispositions_no_truncate;

CREATE TABLE markdown_steps (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    days_on_floor integer NOT NULL,
    percent integer NOT NULL,
    CONSTRAINT markdown_steps_days_chk CHECK (days_on_floor >= 1 AND days_on_floor <= 365),
    CONSTRAINT markdown_steps_percent_chk CHECK (percent >= 1 AND percent <= 99),
    CONSTRAINT markdown_steps_days_key UNIQUE (days_on_floor),
    CONSTRAINT markdown_steps_percent_key UNIQUE (percent)
);

GRANT SELECT, INSERT, UPDATE, DELETE ON markdown_steps TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON markdown_steps FROM stall_poster;

CREATE TRIGGER markdown_steps_no_truncate
    BEFORE TRUNCATE ON markdown_steps
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE markdown_steps ENABLE ALWAYS TRIGGER markdown_steps_no_truncate;

CREATE TABLE item_price_events (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    item_id uuid NOT NULL REFERENCES items (id),
    step_id uuid NOT NULL REFERENCES markdown_steps (id),
    prior_minor bigint NOT NULL,
    price_minor bigint NOT NULL,
    applied_on date NOT NULL,
    CONSTRAINT item_price_events_step_key UNIQUE (item_id, step_id),
    CONSTRAINT item_price_events_prices_chk CHECK (prior_minor > 0 AND price_minor > 0 AND price_minor < prior_minor)
);

GRANT SELECT, INSERT ON item_price_events TO stall_poster;
REVOKE UPDATE, DELETE, TRUNCATE, TRIGGER ON item_price_events FROM stall_poster;

CREATE TRIGGER item_price_events_no_truncate
    BEFORE TRUNCATE ON item_price_events
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE item_price_events ENABLE ALWAYS TRIGGER item_price_events_no_truncate;

CREATE OR REPLACE FUNCTION stall_guard_item_placement()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    today date;
BEGIN
    IF NEW.placement = 'booth' AND NEW.expires_on IS NOT NULL THEN
        RAISE EXCEPTION 'a booth piece does not expire'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.placement = 'store' AND NOT (SELECT store_stock_enabled FROM public.mall) THEN
        RAISE EXCEPTION 'store stock is off'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.placement = 'consigned' AND NOT (SELECT consignment_enabled FROM public.mall) THEN
        RAISE EXCEPTION 'consignment is off'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.placement = 'booth' AND NOT (SELECT booth_rentals_enabled FROM public.mall) THEN
        RAISE EXCEPTION 'booth rentals are off'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.placement = 'booth' AND (TG_OP = 'INSERT' OR OLD.placement IS DISTINCT FROM 'booth') THEN
        today := (SELECT (clock_timestamp() AT TIME ZONE mall.time_zone)::date FROM public.mall);
        IF NOT EXISTS (
            SELECT 1
            FROM public.booth_assignments AS assignment
            WHERE assignment.vendor_id = NEW.vendor_id
              AND assignment.starts_on <= today
              AND (assignment.ends_on IS NULL OR assignment.ends_on >= today)
        ) THEN
            RAISE EXCEPTION 'a booth piece needs an open assignment'
                USING ERRCODE = '23514';
        END IF;
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.cost_minor IS DISTINCT FROM NEW.cost_minor AND EXISTS (
        SELECT 1 FROM public.sale_lines WHERE item_id = NEW.id
    ) THEN
        RAISE EXCEPTION 'a sold piece keeps its cost'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS items_placement ON items;
CREATE TRIGGER items_placement
    BEFORE INSERT OR UPDATE OF placement, vendor_id, commission_bps, expires_on, cost_minor ON items
    FOR EACH ROW
    EXECUTE FUNCTION stall_guard_item_placement();

CREATE OR REPLACE FUNCTION stall_guard_capabilities_stock()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF OLD.consignment_enabled AND NOT NEW.consignment_enabled AND EXISTS (
        SELECT 1 FROM public.items WHERE placement = 'consigned' AND quantity > 0
    ) THEN
        RAISE EXCEPTION 'consignment still has items on hand'
            USING ERRCODE = '23514';
    END IF;
    IF OLD.store_stock_enabled AND NOT NEW.store_stock_enabled AND EXISTS (
        SELECT 1 FROM public.items WHERE placement = 'store' AND quantity > 0
    ) THEN
        RAISE EXCEPTION 'store stock still has items on hand'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS mall_stock_on_hand ON mall;
CREATE TRIGGER mall_stock_on_hand
    BEFORE UPDATE OF consignment_enabled, store_stock_enabled ON mall
    FOR EACH ROW
    EXECUTE FUNCTION stall_guard_capabilities_stock();

CREATE OR REPLACE FUNCTION stall_apply_item_buy()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
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

DROP TRIGGER IF EXISTS item_buys_apply ON item_buys;
CREATE TRIGGER item_buys_apply
    BEFORE INSERT ON item_buys
    FOR EACH ROW
    EXECUTE FUNCTION stall_apply_item_buy();

CREATE OR REPLACE FUNCTION stall_apply_disposition()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    today date;
BEGIN
    today := (SELECT (clock_timestamp() AT TIME ZONE mall.time_zone)::date FROM public.mall);
    IF NOT EXISTS (
        SELECT 1 FROM public.items
        WHERE id = NEW.item_id
          AND placement = 'consigned'
          AND quantity > 0
          AND expires_on IS NOT NULL
          AND expires_on < today
    ) THEN
        RAISE EXCEPTION 'only an expired consigned piece can leave the floor'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.kind = 'return' THEN
        UPDATE public.items SET quantity = 0 WHERE id = NEW.item_id;
    ELSIF NEW.kind = 'donate' THEN
        UPDATE public.items
        SET placement = 'store', vendor_id = NULL, expires_on = NULL, commission_bps = NULL, cost_minor = NULL
        WHERE id = NEW.item_id;
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS item_dispositions_apply ON item_dispositions;
CREATE TRIGGER item_dispositions_apply
    BEFORE INSERT ON item_dispositions
    FOR EACH ROW
    EXECUTE FUNCTION stall_apply_disposition();

CREATE OR REPLACE FUNCTION stall_guard_markdown_step()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM public.markdown_steps AS earlier
        WHERE earlier.id IS DISTINCT FROM NEW.id
          AND (
              (earlier.days_on_floor < NEW.days_on_floor AND earlier.percent >= NEW.percent)
              OR (earlier.days_on_floor > NEW.days_on_floor AND earlier.percent <= NEW.percent)
          )
    ) THEN
        RAISE EXCEPTION 'markdown percents increase with days on the floor'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS markdown_steps_order ON markdown_steps;
CREATE TRIGGER markdown_steps_order
    BEFORE INSERT OR UPDATE ON markdown_steps
    FOR EACH ROW
    EXECUTE FUNCTION stall_guard_markdown_step();

CREATE OR REPLACE FUNCTION stall_apply_markdown()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    original bigint;
    expected bigint;
    next_step uuid;
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM public.items
        WHERE id = NEW.item_id AND placement = 'consigned' AND quantity > 0
    ) THEN
        RAISE EXCEPTION 'a markdown is for a consigned piece'
            USING ERRCODE = '23514';
    END IF;
    SELECT step.id
    INTO next_step
    FROM public.markdown_steps AS step
    WHERE NOT EXISTS (
        SELECT 1 FROM public.item_price_events AS prior
        WHERE prior.item_id = NEW.item_id AND prior.step_id = step.id
    )
    ORDER BY step.days_on_floor
    LIMIT 1;
    IF next_step IS DISTINCT FROM NEW.step_id THEN
        RAISE EXCEPTION 'a markdown follows the schedule'
            USING ERRCODE = '23514';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM public.items AS aged
        JOIN public.markdown_steps AS step ON step.id = NEW.step_id
        CROSS JOIN public.mall AS mall_clock
        WHERE aged.id = NEW.item_id
          AND aged.intake_on + step.days_on_floor > (clock_timestamp() AT TIME ZONE mall_clock.time_zone)::date
    ) THEN
        RAISE EXCEPTION 'a markdown waits for its day'
            USING ERRCODE = '23514';
    END IF;
    SELECT price_minor INTO original FROM public.items WHERE id = NEW.item_id;
    SELECT event.prior_minor INTO original
    FROM public.item_price_events AS event
    WHERE event.item_id = NEW.item_id
    ORDER BY event.applied_on, event.id
    LIMIT 1;
    IF original IS NULL THEN
        SELECT price_minor INTO original FROM public.items WHERE id = NEW.item_id;
    END IF;
    SELECT public.stall_half_up(original, 10000 - step.percent * 100)
    INTO expected
    FROM public.markdown_steps AS step
    WHERE step.id = NEW.step_id;
    IF NEW.price_minor IS DISTINCT FROM expected OR NEW.prior_minor IS DISTINCT FROM (
        SELECT price_minor FROM public.items WHERE id = NEW.item_id
    ) THEN
        RAISE EXCEPTION 'a markdown keeps the schedule price'
            USING ERRCODE = '23514';
    END IF;
    UPDATE public.items SET price_minor = NEW.price_minor WHERE id = NEW.item_id;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS item_price_events_apply ON item_price_events;
CREATE TRIGGER item_price_events_apply
    BEFORE INSERT ON item_price_events
    FOR EACH ROW
    EXECUTE FUNCTION stall_apply_markdown();


SET LOCAL event_triggers = false;

CREATE OR REPLACE FUNCTION stall_assert_sale_shape()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    sale_source text;
    sale_reversal boolean;
    tax_exempt boolean;
    commission_bps integer;
    tax_bps integer;
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

        SELECT journal.source_type, journal.is_reversal
        INTO sale_source, sale_reversal
        FROM public.journals AS journal
        WHERE journal.id = NEW.journal_id;

        IF sale_source IS DISTINCT FROM 'sale' OR sale_reversal IS DISTINCT FROM false THEN
            RAISE EXCEPTION 'a sale line belongs on a sale'
                USING ERRCODE = '23514';
        END IF;

        SELECT item.placement,
               vendor.tax_exempt,
               CASE
                   WHEN item.placement = 'store' THEN 0
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

        IF tax_exempt OR waiver THEN
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


SET LOCAL event_triggers = true;

SET LOCAL event_triggers = false;

CREATE OR REPLACE FUNCTION stall_assert_item_return()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    sale_price bigint;
    sale_commission bigint;
    sale_tax bigint;
    sale_discount bigint;
    sale_store bigint;
    sale_quantity integer;
    prior_quantity integer;
    prior_price bigint;
    prior_commission bigint;
    prior_tax bigint;
    prior_discount bigint;
    prior_store bigint;
    expected_price bigint;
    expected_commission bigint;
    expected_tax bigint;
    expected_discount bigint;
    expected_store bigint;
    tender_credit bigint;
    tender_lines integer;
    commission_debit bigint;
    tax_debit bigint;
    payable_debit bigint;
    promo_credit bigint;
    promo_debit bigint;
    store_debit bigint;
    placement text;
    cogs_credit bigint;
    inventory_debit bigint;
BEGIN
    IF TG_TABLE_NAME = 'item_returns' THEN
        IF TG_OP <> 'INSERT' THEN
            RAISE EXCEPTION 'a return cannot be changed'
                USING ERRCODE = '23514';
        END IF;
        IF NOT EXISTS (
            SELECT 1
            FROM public.journals AS journal
            WHERE journal.id = NEW.journal_id
              AND journal.source_type = 'item_return'
              AND journal.is_reversal = false
        ) THEN
            RAISE EXCEPTION 'a return belongs on a return'
                USING ERRCODE = '23514';
        END IF;
        SELECT sale.price_minor, sale.commission_minor, sale.tax_minor, sale.discount_minor,
               sale.store_discount_minor, sale.quantity
        INTO sale_price, sale_commission, sale_tax, sale_discount, sale_store, sale_quantity
        FROM public.sale_lines AS sale
        JOIN public.journals AS journal ON journal.id = sale.journal_id
        WHERE sale.journal_id = NEW.sale_journal_id
          AND sale.item_id = NEW.item_id
          AND journal.source_type = 'sale'
          AND journal.is_reversal = false
          AND NOT EXISTS (
              SELECT 1
              FROM public.journals AS reversal
              WHERE reversal.reverses_journal_id = journal.id
                AND reversal.is_reversal = true
          );
        SELECT COALESCE(sum(returned.quantity), 0),
               COALESCE(sum(returned.price_minor), 0),
               COALESCE(sum(returned.commission_minor), 0),
               COALESCE(sum(returned.tax_minor), 0),
               COALESCE(sum(returned.discount_minor), 0),
               COALESCE(sum(returned.store_discount_minor), 0)
        INTO prior_quantity, prior_price, prior_commission, prior_tax, prior_discount, prior_store
        FROM public.item_returns AS returned
        JOIN public.journals AS return_journal ON return_journal.id = returned.journal_id
        WHERE returned.sale_journal_id = NEW.sale_journal_id
          AND returned.item_id = NEW.item_id
          AND return_journal.is_reversal = false
          AND NOT EXISTS (
              SELECT 1
              FROM public.journals AS reversal
              WHERE reversal.reverses_journal_id = return_journal.id
                AND reversal.is_reversal = true
          );
        IF sale_price IS NULL
            OR sale_quantity IS NULL
            OR NEW.quantity < 1
            OR prior_quantity + NEW.quantity > sale_quantity THEN
            RAISE EXCEPTION 'a return is more than what was sold'
                USING ERRCODE = '23514';
        END IF;
        IF prior_quantity + NEW.quantity = sale_quantity THEN
            expected_price := sale_price * sale_quantity - prior_price;
            expected_commission := sale_commission - prior_commission;
            expected_tax := sale_tax - prior_tax;
            expected_discount := sale_discount - prior_discount;
            expected_store := sale_store - prior_store;
        ELSE
            expected_price := (sale_price * sale_quantity * NEW.quantity) / sale_quantity;
            expected_commission := (sale_commission * NEW.quantity) / sale_quantity;
            expected_tax := (sale_tax * NEW.quantity) / sale_quantity;
            expected_discount := (sale_discount * NEW.quantity) / sale_quantity;
            expected_store := (sale_store * NEW.quantity) / sale_quantity;
        END IF;
        IF NEW.price_minor <> expected_price
            OR NEW.commission_minor <> expected_commission
            OR NEW.tax_minor <> expected_tax
            OR NEW.discount_minor <> expected_discount
            OR NEW.store_discount_minor <> expected_store THEN
            RAISE EXCEPTION 'a return reverses that item'
                USING ERRCODE = '23514';
        END IF;

        UPDATE public.items
        SET quantity = quantity + NEW.quantity
        WHERE id = NEW.item_id;

        RETURN NEW;
    END IF;

    IF NEW.source_type <> 'item_return' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM public.item_returns WHERE journal_id = NEW.id
    ) THEN
        RAISE EXCEPTION 'a return needs an item'
            USING ERRCODE = '23514';
    END IF;

    SELECT price_minor, commission_minor, tax_minor, discount_minor, store_discount_minor
    INTO sale_price, sale_commission, sale_tax, sale_discount, sale_store
    FROM public.item_returns
    WHERE journal_id = NEW.id;

    SELECT count(*), COALESCE(sum(line.credit_minor), 0)
    INTO tender_lines, tender_credit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND line.credit_minor > 0
      AND role.role IN ('cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit');

    SELECT COALESCE(sum(line.debit_minor), 0)
    INTO commission_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'commission_revenue'
      AND line.debit_minor > 0;

    SELECT COALESCE(sum(line.debit_minor), 0)
    INTO tax_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'sales_tax_payable'
      AND line.debit_minor > 0;

    SELECT COALESCE(sum(line.debit_minor), 0)
    INTO payable_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'vendor_payable'
      AND line.debit_minor > 0
      AND line.subledger_type = 'vendor';

    SELECT COALESCE(sum(line.credit_minor), 0), COALESCE(sum(line.debit_minor), 0)
    INTO promo_credit, promo_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'promotion_expense';

    IF tender_lines <> 1
        OR tender_credit <> sale_price - sale_discount - sale_store + sale_tax
        OR commission_debit <> sale_commission
        OR tax_debit <> sale_tax
        OR promo_credit <> sale_store
        OR promo_debit <> 0 THEN
        RAISE EXCEPTION 'a return reverses that item'
            USING ERRCODE = '23514';
    END IF;

    SELECT item.placement
    INTO placement
    FROM public.item_returns AS returned_row
    JOIN public.items AS item ON item.id = returned_row.item_id
    WHERE returned_row.journal_id = NEW.id;

    SELECT COALESCE(sum(line.debit_minor), 0)
    INTO store_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'store_sales'
      AND line.debit_minor > 0;

    SELECT COALESCE(sum(line.credit_minor), 0)
    INTO cogs_credit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'cost_of_goods'
      AND line.credit_minor > 0;

    SELECT COALESCE(sum(line.debit_minor), 0)
    INTO inventory_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'inventory'
      AND line.debit_minor > 0;

    IF placement = 'store' THEN
        IF payable_debit <> 0
            OR store_debit <> sale_price - sale_discount
            OR commission_debit <> 0
            OR cogs_credit <> inventory_debit THEN
            RAISE EXCEPTION 'a return reverses that item'
                USING ERRCODE = '23514';
        END IF;
    ELSIF payable_debit <> sale_price - sale_discount - sale_commission
        OR store_debit <> 0 THEN
        RAISE EXCEPTION 'a return reverses that item'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;


SET LOCAL event_triggers = true;

ALTER TABLE vendors ADD COLUMN business_name text;

ALTER TABLE vendors ADD CONSTRAINT vendors_business_name_chk CHECK (
    business_name IS NULL OR (
        business_name = btrim(business_name)
        AND business_name <> ''
        AND char_length(business_name) <= 120
    )
);

ALTER TABLE vendors DROP COLUMN name;

ALTER TABLE vendors ADD COLUMN name text GENERATED ALWAYS AS (
    CASE
        WHEN business_name IS NOT NULL THEN business_name
        WHEN first_name = last_name THEN first_name
        ELSE btrim(first_name || ' ' || last_name)
    END
) STORED;
