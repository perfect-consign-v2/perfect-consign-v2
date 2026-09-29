-- A discount lowers the sold price. Store take is the percent of that final price. Tax can be waived for this ticket only.
-- Apply after 035. The provisioner rewrites stall_poster.

ALTER TABLE tickets ADD COLUMN IF NOT EXISTS discount_kind text;
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS discount_value bigint;
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS discount_reason text;
ALTER TABLE tickets ADD COLUMN IF NOT EXISTS tax_waiver_reason text;

ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_discount_chk;
ALTER TABLE tickets ADD CONSTRAINT tickets_discount_chk CHECK (
    (
        discount_kind IS NULL
        AND discount_value IS NULL
        AND discount_reason IS NULL
    )
    OR (
        discount_kind = 'percent'
        AND discount_value > 0
        AND discount_value <= 10000
        AND discount_reason IN ('manager approval', 'damaged', 'loyalty', 'promotion', 'other')
    )
    OR (
        discount_kind = 'amount'
        AND discount_value > 0
        AND discount_reason IN ('manager approval', 'damaged', 'loyalty', 'promotion', 'other')
    )
);

ALTER TABLE tickets DROP CONSTRAINT IF EXISTS tickets_waiver_chk;
ALTER TABLE tickets ADD CONSTRAINT tickets_waiver_chk CHECK (
    tax_waiver_reason IS NULL
    OR tax_waiver_reason IN ('resale', 'charity', 'government or school', 'other')
);

ALTER TABLE sale_lines ADD COLUMN IF NOT EXISTS discount_minor bigint NOT NULL DEFAULT 0;
ALTER TABLE sale_lines DROP CONSTRAINT IF EXISTS sale_lines_discount_chk;
ALTER TABLE sale_lines ADD CONSTRAINT sale_lines_discount_chk CHECK (
    discount_minor >= 0 AND discount_minor <= price_minor
);

ALTER TABLE piece_returns ADD COLUMN IF NOT EXISTS discount_minor bigint NOT NULL DEFAULT 0;
ALTER TABLE piece_returns DROP CONSTRAINT IF EXISTS piece_returns_discount_chk;
ALTER TABLE piece_returns ADD CONSTRAINT piece_returns_discount_chk CHECK (
    discount_minor >= 0 AND discount_minor <= price_minor
);

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
        SELECT (line.price_minor * value + 5000) / 10000
        INTO share
        FROM public.ticket_lines AS line
        WHERE line.ticket_id = ticket
          AND line.item_id = item;
        RETURN COALESCE(share, 0);
    END IF;
    IF kind IS DISTINCT FROM 'amount' THEN
        RETURN 0;
    END IF;
    SELECT COALESCE(sum(line.price_minor), 0), count(*)::integer
    INTO total, counted
    FROM public.ticket_lines AS line
    WHERE line.ticket_id = ticket;
    IF total = 0 OR counted = 0 THEN
        RETURN 0;
    END IF;
    FOR line_item, line_price IN
        SELECT line.item_id, line.price_minor
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
    share bigint;
    pieces integer;
BEGIN
    IF kind IS NULL THEN
        RETURN;
    END IF;
    SELECT count(*)::integer INTO pieces FROM public.ticket_lines WHERE ticket_id = ticket;
    IF pieces = 0 THEN
        RAISE EXCEPTION 'a ticket needs a piece'
            USING ERRCODE = '23514';
    END IF;
    FOR line_item, line_price IN
        SELECT line.item_id, line.price_minor
        FROM public.ticket_lines AS line
        WHERE line.ticket_id = ticket
        ORDER BY line.line_no
    LOOP
        share := public.stall_line_discount(ticket, line_item, kind, value);
        IF share < 0 OR share > line_price THEN
            RAISE EXCEPTION 'the discount is more than the price'
                USING ERRCODE = '23514';
        END IF;
    END LOOP;
END;
$$;

GRANT EXECUTE ON FUNCTION stall_line_discount(uuid, uuid, text, bigint) TO stall_poster;
GRANT EXECUTE ON FUNCTION stall_refuse_ticket_discount(uuid, text, bigint) TO stall_poster;

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

    IF OLD.status = 'open'
        AND NEW.status = 'open'
        AND NEW.session_id IS NOT DISTINCT FROM OLD.session_id
        AND NEW.posted_journal_id IS NULL THEN
        PERFORM public.stall_refuse_ticket_discount(NEW.id, NEW.discount_kind, NEW.discount_value);
        RETURN NEW;
    END IF;

    IF NEW.discount_kind IS DISTINCT FROM OLD.discount_kind
        OR NEW.discount_value IS DISTINCT FROM OLD.discount_value
        OR NEW.discount_reason IS DISTINCT FROM OLD.discount_reason
        OR NEW.tax_waiver_reason IS DISTINCT FROM OLD.tax_waiver_reason THEN
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
    waiver boolean;
    tender_lines integer;
    tender_roles integer;
    tender_sum bigint;
    prices bigint;
    commissions bigint;
    taxes bigint;
    discounts bigint;
    net_commission bigint;
    commission_lines integer;
    commission_credit bigint;
    tax_lines integer;
    tax_credit bigint;
    payable_gaps integer;
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
               mall.tax_bps
        INTO tax_exempt, commission_bps, tax_bps
        FROM public.items AS item
        JOIN public.vendors AS vendor ON vendor.id = item.vendor_id
        CROSS JOIN public.mall AS mall
        WHERE item.id = NEW.item_id
        FOR UPDATE OF item;

        IF tax_exempt IS NULL THEN
            RAISE EXCEPTION 'a sale line needs a piece'
                USING ERRCODE = '23514';
        END IF;

        expected_discount := 0;
        waiver := false;
        SELECT public.stall_line_discount(ticket.id, NEW.item_id, ticket.discount_kind, ticket.discount_value),
               ticket.tax_waiver_reason IS NOT NULL
        INTO expected_discount, waiver
        FROM public.tickets AS ticket
        JOIN public.ticket_lines AS line ON line.ticket_id = ticket.id
        WHERE line.item_id = NEW.item_id
          AND ticket.status = 'open';
        IF NOT FOUND THEN
            expected_discount := 0;
            waiver := false;
        END IF;

        IF NEW.discount_minor IS DISTINCT FROM expected_discount
            OR NEW.discount_minor < 0
            OR NEW.discount_minor > NEW.price_minor THEN
            RAISE EXCEPTION 'a sale discount does not match the ticket'
                USING ERRCODE = '23514';
        END IF;

        expected_commission := ((NEW.price_minor - NEW.discount_minor) * commission_bps + 5000) / 10000;

        IF tax_exempt OR waiver THEN
            expected_tax := 0;
        ELSE
            expected_tax := ((NEW.price_minor - NEW.discount_minor) * tax_bps + 5000) / 10000;
        END IF;

        IF NEW.commission_minor IS DISTINCT FROM expected_commission THEN
            RAISE EXCEPTION 'a sale commission does not match the store take'
                USING ERRCODE = '23514';
        END IF;

        IF NEW.tax_minor IS DISTINCT FROM expected_tax THEN
            RAISE EXCEPTION 'a sale tax does not match the mall rate'
                USING ERRCODE = '23514';
        END IF;

        IF EXISTS (
            SELECT 1
            FROM public.sale_lines AS other
            JOIN public.journals AS journal ON journal.id = other.journal_id
            WHERE other.item_id = NEW.item_id
              AND other.journal_id IS DISTINCT FROM NEW.journal_id
              AND journal.source_type = 'sale'
              AND journal.is_reversal = false
              AND NOT EXISTS (
                  SELECT 1
                  FROM public.journals AS reversal
                  WHERE reversal.reverses_journal_id = journal.id
                    AND reversal.is_reversal = true
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM public.piece_returns AS returned
                  JOIN public.journals AS return_journal ON return_journal.id = returned.journal_id
                  WHERE returned.sale_journal_id = journal.id
                    AND returned.item_id = NEW.item_id
                    AND return_journal.is_reversal = false
                    AND NOT EXISTS (
                        SELECT 1
                        FROM public.journals AS reversal
                        WHERE reversal.reverses_journal_id = return_journal.id
                          AND reversal.is_reversal = true
                    )
              )
        ) THEN
            RAISE EXCEPTION 'a piece is already sold'
                USING ERRCODE = '23514';
        END IF;

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
      AND role.role IN ('cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit');

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
        RAISE EXCEPTION 'a sale needs a piece'
            USING ERRCODE = '23514';
    END IF;

    SELECT COALESCE(sum(price_minor), 0),
           COALESCE(sum(commission_minor), 0),
           COALESCE(sum(tax_minor), 0),
           COALESCE(sum(discount_minor), 0)
    INTO prices, commissions, taxes, discounts
    FROM public.sale_lines
    WHERE journal_id = NEW.id;

    net_commission := commissions;

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

    exact_cash := prices - discounts + taxes - (tender_sum - cash_debit);
    round_delta := CASE exact_cash % 5
        WHEN 1 THEN -1
        WHEN 2 THEN -2
        WHEN 3 THEN 2
        WHEN 4 THEN 1
        ELSE 0
    END;

    IF cash_debit = 0 OR cash_debit <> tender_sum OR round_cash IS DISTINCT FROM true THEN
        IF round_lines <> 0 OR tender_sum <> prices - discounts + taxes THEN
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

    IF tender_sum + round_debit <> NEW.debit_total_minor
        OR tender_sum <> prices - discounts + taxes + round_credit - round_debit THEN
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
        SELECT item.vendor_id, sum(sale.price_minor - sale.discount_minor - sale.commission_minor) AS owed
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

    SELECT count(*) INTO foreign_lines
    FROM public.journal_lines AS line
    WHERE line.journal_id = NEW.id
      AND NOT EXISTS (
          SELECT 1
          FROM public.chart_roles AS role
          WHERE role.account_id = line.account_id
            AND (
                (role.role IN ('cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit') AND line.debit_minor > 0)
                OR (role.role = 'commission_revenue' AND line.credit_minor > 0)
                OR (role.role = 'sales_tax_payable' AND line.credit_minor > 0)
                OR (role.role = 'vendor_payable' AND line.credit_minor > 0)
                OR (role.role = 'cash_rounding' AND (line.debit_minor > 0 OR line.credit_minor > 0))
            )
      );

    IF foreign_lines > 0 THEN
        RAISE EXCEPTION 'a sale only credits commission, sales tax, and vendor payable'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE OR REPLACE FUNCTION stall_assert_piece_return()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    sale_price bigint;
    sale_commission bigint;
    sale_tax bigint;
    sale_discount bigint;
    tender_credit bigint;
    tender_lines integer;
    commission_debit bigint;
    tax_debit bigint;
    payable_debit bigint;
BEGIN
    IF TG_TABLE_NAME = 'piece_returns' THEN
        IF TG_OP <> 'INSERT' THEN
            RAISE EXCEPTION 'a return cannot be changed'
                USING ERRCODE = '23514';
        END IF;
        IF NOT EXISTS (
            SELECT 1
            FROM public.journals AS journal
            WHERE journal.id = NEW.journal_id
              AND journal.source_type = 'piece_return'
              AND journal.is_reversal = false
        ) THEN
            RAISE EXCEPTION 'a return belongs on a return'
                USING ERRCODE = '23514';
        END IF;
        SELECT sale.price_minor, sale.commission_minor, sale.tax_minor, sale.discount_minor
        INTO sale_price, sale_commission, sale_tax, sale_discount
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
        IF sale_price IS NULL
            OR NEW.price_minor <> sale_price
            OR NEW.commission_minor <> sale_commission
            OR NEW.tax_minor <> sale_tax
            OR NEW.discount_minor <> sale_discount THEN
            RAISE EXCEPTION 'a return reverses that piece'
                USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
    END IF;

    IF NEW.source_type <> 'piece_return' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM public.piece_returns WHERE journal_id = NEW.id
    ) THEN
        RAISE EXCEPTION 'a return needs a piece'
            USING ERRCODE = '23514';
    END IF;

    SELECT price_minor, commission_minor, tax_minor, discount_minor
    INTO sale_price, sale_commission, sale_tax, sale_discount
    FROM public.piece_returns
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

    IF tender_lines <> 1
        OR tender_credit <> sale_price - sale_discount + sale_tax
        OR commission_debit <> sale_commission
        OR tax_debit <> sale_tax
        OR payable_debit <> sale_price - sale_discount - sale_commission THEN
        RAISE EXCEPTION 'a return reverses that piece'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

SET event_triggers = true;
