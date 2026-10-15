-- Quantity deals. The tag price stays. A vendor-funded deal lowers the sold price.
-- A store-funded deal is a mall expense and leaves the vendor's payable on the tag price.
-- Apply after 055. The provisioner rewrites public. and stall_poster.
-- Replacing the sale, return, and ticket guards is the superuser migration path.

SET LOCAL event_triggers = false;

INSERT INTO accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type)
SELECT
    '01900000-0000-7000-8000-000000000024',
    '01900000-0000-7000-8000-000000000001',
    '6200',
    'Promotion expense',
    'expense',
    'debit',
    true,
    false,
    NULL
WHERE NOT EXISTS (
    SELECT 1 FROM accounts WHERE id = '01900000-0000-7000-8000-000000000024'
);

INSERT INTO chart_roles (role, account_id)
SELECT 'promotion_expense', '01900000-0000-7000-8000-000000000024'::uuid
WHERE NOT EXISTS (
    SELECT 1 FROM chart_roles WHERE role = 'promotion_expense'
);

INSERT INTO chart_source_rules (role, source_type)
SELECT seed.role, seed.source_type
FROM (
    VALUES
        ('promotion_expense', 'sale'),
        ('promotion_expense', 'item_return')
) AS seed(role, source_type)
WHERE NOT EXISTS (
    SELECT 1
    FROM chart_source_rules AS existing
    WHERE existing.role = seed.role
      AND existing.source_type = seed.source_type
);

SELECT stall_seed_export_accounts();

CREATE TABLE offers (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    name text NOT NULL,
    scope text NOT NULL,
    scope_id uuid,
    kind text NOT NULL,
    threshold_qty integer NOT NULL,
    deal_minor bigint NOT NULL,
    funded_by text NOT NULL,
    starts_on date NOT NULL,
    ends_on date,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT offers_name_chk CHECK (
        name = btrim(name) AND name <> '' AND char_length(name) <= 80
    ),
    CONSTRAINT offers_scope_chk CHECK (
        (scope = 'store' AND scope_id IS NULL)
        OR (scope IN ('item', 'vendor') AND scope_id IS NOT NULL)
    ),
    CONSTRAINT offers_kind_chk CHECK (kind IN ('bundle', 'unit_break')),
    CONSTRAINT offers_threshold_chk CHECK (threshold_qty >= 2),
    CONSTRAINT offers_deal_chk CHECK (deal_minor > 0),
    CONSTRAINT offers_funded_chk CHECK (funded_by IN ('vendor', 'store')),
    CONSTRAINT offers_dates_chk CHECK (ends_on IS NULL OR starts_on <= ends_on)
);

GRANT SELECT, INSERT, UPDATE, DELETE ON offers TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON offers FROM stall_poster;
GRANT SELECT ON offers TO stall_backup;

CREATE OR REPLACE FUNCTION stall_assert_offer()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    tag bigint;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF EXISTS (SELECT 1 FROM public.ticket_lines WHERE offer_id = OLD.id)
            OR EXISTS (SELECT 1 FROM public.sale_lines WHERE offer_id = OLD.id) THEN
            RAISE EXCEPTION 'a deal that has been rung cannot be deleted'
                USING ERRCODE = '23514';
        END IF;
        RETURN OLD;
    END IF;

    IF NEW.scope = 'item' THEN
        SELECT item.price_minor INTO tag
        FROM public.items AS item
        WHERE item.id = NEW.scope_id;
        IF tag IS NULL THEN
            RAISE EXCEPTION 'that item is not on the floor'
                USING ERRCODE = '23514';
        END IF;
        IF NEW.kind = 'bundle' AND NEW.deal_minor >= tag * NEW.threshold_qty THEN
            RAISE EXCEPTION 'the deal is not cheaper than the tag'
                USING ERRCODE = '23514';
        END IF;
        IF NEW.kind = 'unit_break' AND NEW.deal_minor >= tag THEN
            RAISE EXCEPTION 'the deal is not cheaper than the tag'
                USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.scope = 'vendor' AND NOT EXISTS (
        SELECT 1 FROM public.vendors WHERE id = NEW.scope_id
    ) THEN
        RAISE EXCEPTION 'that vendor is not in this mall'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER offers_guard
    BEFORE INSERT OR UPDATE OR DELETE ON offers
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_offer();

CREATE TRIGGER offers_no_truncate
    BEFORE TRUNCATE ON offers
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE offers
    ENABLE ALWAYS TRIGGER offers_guard,
    ENABLE ALWAYS TRIGGER offers_no_truncate;

ALTER TABLE ticket_lines
    ADD COLUMN offer_id uuid REFERENCES offers (id),
    ADD COLUMN offer_discount_minor bigint NOT NULL DEFAULT 0,
    ADD COLUMN store_discount_minor bigint NOT NULL DEFAULT 0,
    ADD CONSTRAINT ticket_lines_offer_chk CHECK (
        offer_discount_minor >= 0
        AND store_discount_minor >= 0
        AND (
            (offer_id IS NULL AND offer_discount_minor = 0 AND store_discount_minor = 0)
            OR (
                offer_id IS NOT NULL
                AND (
                    (offer_discount_minor > 0 AND store_discount_minor = 0)
                    OR (store_discount_minor > 0 AND offer_discount_minor = 0)
                )
            )
        )
    );

ALTER TABLE sale_lines
    ADD COLUMN offer_id uuid REFERENCES offers (id),
    ADD COLUMN store_discount_minor bigint NOT NULL DEFAULT 0,
    ADD CONSTRAINT sale_lines_store_chk CHECK (
        store_discount_minor >= 0
        AND discount_minor + store_discount_minor <= price_minor * quantity
    );

ALTER TABLE item_returns
    ADD COLUMN store_discount_minor bigint NOT NULL DEFAULT 0,
    ADD CONSTRAINT item_returns_store_chk CHECK (
        store_discount_minor >= 0
        AND discount_minor + store_discount_minor <= price_minor
    );

CREATE OR REPLACE FUNCTION stall_quantity_deal(item uuid, units integer, tag bigint)
RETURNS TABLE (picked_offer uuid, vendor_discount bigint, store_discount bigint)
LANGUAGE plpgsql
STABLE
SET search_path = pg_catalog
AS $$
DECLARE
    mall_day date;
    vendor uuid;
    best_savings bigint := 0;
    best_rank integer := 4;
    best_offer uuid;
    best_funded text;
    row_offer uuid;
    row_funded text;
    row_kind text;
    row_threshold integer;
    row_deal bigint;
    row_rank integer;
    savings bigint;
    groups integer;
BEGIN
    IF units < 1 OR tag < 1 THEN
        RETURN;
    END IF;

    SELECT (clock_timestamp() AT TIME ZONE mall.time_zone)::date
    INTO mall_day
    FROM public.mall;

    SELECT items.vendor_id INTO vendor
    FROM public.items AS items
    WHERE items.id = item;

    FOR row_offer, row_funded, row_kind, row_threshold, row_deal, row_rank IN
        SELECT offer.id, offer.funded_by, offer.kind, offer.threshold_qty, offer.deal_minor,
               CASE offer.scope WHEN 'item' THEN 1 WHEN 'vendor' THEN 2 ELSE 3 END
        FROM public.offers AS offer
        WHERE offer.starts_on <= mall_day
          AND (offer.ends_on IS NULL OR offer.ends_on >= mall_day)
          AND (
              (offer.scope = 'item' AND offer.scope_id = item)
              OR (offer.scope = 'vendor' AND offer.scope_id = vendor)
              OR offer.scope = 'store'
          )
    LOOP
        savings := 0;
        IF row_kind = 'bundle' THEN
            groups := units / row_threshold;
            IF groups >= 1 THEN
                savings := tag * units - (groups * row_deal + (units % row_threshold) * tag);
            END IF;
        ELSIF units >= row_threshold AND row_deal < tag THEN
            savings := units * (tag - row_deal);
        END IF;
        IF savings > best_savings
            OR (
                savings = best_savings
                AND savings > 0
                AND (row_rank < best_rank OR (row_rank = best_rank AND row_offer < best_offer))
            ) THEN
            best_savings := savings;
            best_rank := row_rank;
            best_offer := row_offer;
            best_funded := row_funded;
        END IF;
    END LOOP;

    IF best_savings < 1 OR best_offer IS NULL THEN
        RETURN;
    END IF;

    picked_offer := best_offer;
    vendor_discount := CASE WHEN best_funded = 'store' THEN 0 ELSE best_savings END;
    store_discount := CASE WHEN best_funded = 'store' THEN best_savings ELSE 0 END;
    RETURN NEXT;
END;
$$;

CREATE OR REPLACE FUNCTION stall_sticker_deal(item uuid)
RETURNS text
LANGUAGE plpgsql
STABLE
SET search_path = pg_catalog
AS $$
DECLARE
    mall_day date;
    tag bigint;
    best_savings bigint := 0;
    best_name text := '';
    best_offer uuid;
    row_offer uuid;
    row_name text;
    row_kind text;
    row_threshold integer;
    row_deal bigint;
    savings bigint;
BEGIN
    SELECT item_row.price_minor INTO tag
    FROM public.items AS item_row
    WHERE item_row.id = item;
    IF tag IS NULL THEN
        RETURN '';
    END IF;

    SELECT (clock_timestamp() AT TIME ZONE mall.time_zone)::date
    INTO mall_day
    FROM public.mall;

    FOR row_offer, row_name, row_kind, row_threshold, row_deal IN
        SELECT offer.id, offer.name, offer.kind, offer.threshold_qty, offer.deal_minor
        FROM public.offers AS offer
        WHERE offer.scope = 'item'
          AND offer.scope_id = item
          AND offer.starts_on <= mall_day
          AND (offer.ends_on IS NULL OR offer.ends_on >= mall_day)
    LOOP
        savings := 0;
        IF row_kind = 'bundle' AND row_deal < tag * row_threshold THEN
            savings := tag * row_threshold - row_deal;
        ELSIF row_kind = 'unit_break' AND row_deal < tag THEN
            savings := row_threshold * (tag - row_deal);
        END IF;
        IF savings > best_savings OR (savings = best_savings AND savings > 0 AND row_offer < best_offer) THEN
            best_savings := savings;
            best_name := row_name;
            best_offer := row_offer;
        END IF;
    END LOOP;

    RETURN best_name;
END;
$$;

CREATE OR REPLACE FUNCTION stall_line_discount(ticket uuid, item uuid, kind text, value bigint)
RETURNS bigint
LANGUAGE plpgsql
STABLE
SET search_path = pg_catalog
AS $$
DECLARE
    total bigint;
    share bigint;
    running bigint := 0;
    counted integer := 0;
    index integer := 0;
    line_item uuid;
    line_price bigint;
BEGIN
    IF kind IS NULL OR value IS NULL THEN
        RETURN 0;
    END IF;
    IF kind = 'percent' THEN
        SELECT public.stall_half_up(
            line.price_minor * line.quantity
                - line.line_discount_minor
                - line.offer_discount_minor
                - line.store_discount_minor,
            value
        )
        INTO share
        FROM public.ticket_lines AS line
        WHERE line.ticket_id = ticket
          AND line.item_id = item;
        RETURN COALESCE(share, 0);
    END IF;
    IF kind IS DISTINCT FROM 'amount' THEN
        RETURN 0;
    END IF;
    SELECT COALESCE(sum(
            line.price_minor * line.quantity
                - line.line_discount_minor
                - line.offer_discount_minor
                - line.store_discount_minor
        ), 0), count(*)::integer
    INTO total, counted
    FROM public.ticket_lines AS line
    WHERE line.ticket_id = ticket;
    IF total = 0 OR counted = 0 THEN
        RETURN 0;
    END IF;
    FOR line_item, line_price IN
        SELECT line.item_id,
               line.price_minor * line.quantity
                   - line.line_discount_minor
                   - line.offer_discount_minor
                   - line.store_discount_minor
        FROM public.ticket_lines AS line
        WHERE line.ticket_id = ticket
        ORDER BY line.line_no
    LOOP
        index := index + 1;
        IF index = counted THEN
            share := value - running;
        ELSE
            share := (line_price * value + total / 2) / total;
            running := running + share;
        END IF;
        IF line_item = item THEN
            RETURN share;
        END IF;
    END LOOP;
    RETURN 0;
END;
$$;

CREATE OR REPLACE FUNCTION stall_refuse_ticket_discount(ticket uuid, kind text, value bigint)
RETURNS void
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    line_price bigint;
    line_item uuid;
    line_off bigint;
    offer_off bigint;
    store_off bigint;
    share bigint;
    items integer;
BEGIN
    IF kind IS NULL THEN
        RETURN;
    END IF;
    SELECT count(*)::integer INTO items FROM public.ticket_lines WHERE ticket_id = ticket;
    IF items = 0 THEN
        RAISE EXCEPTION 'a ticket needs an item'
            USING ERRCODE = '23514';
    END IF;
    FOR line_item, line_price, line_off, offer_off, store_off IN
        SELECT line.item_id,
               line.price_minor * line.quantity,
               line.line_discount_minor,
               line.offer_discount_minor,
               line.store_discount_minor
        FROM public.ticket_lines AS line
        WHERE line.ticket_id = ticket
        ORDER BY line.line_no
    LOOP
        share := public.stall_line_discount(ticket, line_item, kind, value);
        IF share < 0 OR line_off + offer_off + store_off + share > line_price THEN
            RAISE EXCEPTION 'the discount is more than the price'
                USING ERRCODE = '23514';
        END IF;
    END LOOP;
END;
$$;

CREATE OR REPLACE FUNCTION stall_assert_ticket_line()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    ticket_status text;
    item uuid;
    line_item uuid;
    line_ticket uuid;
    on_hand integer;
    held integer;
    picked uuid;
    vendor_off bigint;
    store_off bigint;
    net bigint;
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

    IF ticket_status <> 'open' AND NOT (TG_OP = 'DELETE' AND ticket_status IN ('parked', 'layaway')) THEN
        RAISE EXCEPTION 'a posted ticket cannot change'
            USING ERRCODE = '23514';
    END IF;

    SELECT item_row.id, item_row.quantity INTO item, on_hand
    FROM public.items AS item_row
    WHERE item_row.id = line_item
    FOR UPDATE OF item_row;

    IF item IS NULL THEN
        RAISE EXCEPTION 'a ticket line needs an item'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    SELECT deal.picked_offer, deal.vendor_discount, deal.store_discount
    INTO picked, vendor_off, store_off
    FROM public.stall_quantity_deal(NEW.item_id, NEW.quantity, NEW.price_minor) AS deal;

    IF NOT FOUND THEN
        picked := NULL;
        vendor_off := 0;
        store_off := 0;
    END IF;

    NEW.offer_id := picked;
    NEW.offer_discount_minor := COALESCE(vendor_off, 0);
    NEW.store_discount_minor := COALESCE(store_off, 0);
    net := NEW.price_minor * NEW.quantity - NEW.offer_discount_minor - NEW.store_discount_minor;

    IF NEW.line_discount_kind IS NULL THEN
        NEW.line_discount_minor := 0;
        NEW.line_discount_value := NULL;
        NEW.line_discount_reason := NULL;
    ELSE
        IF NEW.line_discount_kind = 'percent' THEN
            NEW.line_discount_minor := public.stall_half_up(net, NEW.line_discount_value);
        ELSE
            NEW.line_discount_minor := NEW.line_discount_value;
        END IF;
        IF NEW.line_discount_minor < 1 OR NEW.line_discount_minor > net THEN
            RAISE EXCEPTION 'the discount is more than the price'
                USING ERRCODE = '23514';
        END IF;
    END IF;

    SELECT COALESCE(sum(line.quantity), 0)::integer INTO held
    FROM public.ticket_lines AS line
    JOIN public.tickets AS ticket ON ticket.id = line.ticket_id
    WHERE line.item_id = NEW.item_id
      AND ticket.status IN ('open', 'parked', 'layaway')
      AND line.ticket_id <> NEW.ticket_id;

    IF NEW.quantity + held > on_hand THEN
        IF held > 0 THEN
            RAISE EXCEPTION 'an item is already on a ticket'
                USING ERRCODE = '23514';
        END IF;
        RAISE EXCEPTION 'there are not enough on hand'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

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

        SELECT vendor.tax_exempt,
               COALESCE(item.commission_bps, vendor.commission_bps, mall.commission_bps),
               mall.tax_bps,
               item.quantity
        INTO tax_exempt, commission_bps, tax_bps, on_hand
        FROM public.items AS item
        JOIN public.vendors AS vendor ON vendor.id = item.vendor_id
        CROSS JOIN public.mall AS mall
        WHERE item.id = NEW.item_id
        FOR UPDATE OF item;

        IF tax_exempt IS NULL THEN
            RAISE EXCEPTION 'a sale line needs an item'
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

    IF tender_sum + round_debit + promo_debit <> NEW.debit_total_minor
        OR tender_sum <> prices - discounts - store_discounts + taxes + round_credit - round_debit THEN
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
            )
      );

    IF foreign_lines > 0 THEN
        RAISE EXCEPTION 'a sale only credits commission, sales tax, and vendor payable'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

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
        OR payable_debit <> sale_price - sale_discount - sale_commission
        OR promo_credit <> sale_store
        OR promo_debit <> 0 THEN
        RAISE EXCEPTION 'a return reverses that item'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;
