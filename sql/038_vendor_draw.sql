-- A sale may debit vendor payable once, up to what the store already owed that vendor.
-- Pending tenders sit on the open ticket. Apply after 037. The provisioner rewrites stall_poster.

CREATE TABLE IF NOT EXISTS ticket_tenders (
    ticket_id uuid NOT NULL REFERENCES tickets (id) ON DELETE CASCADE,
    role text NOT NULL,
    amount_minor bigint NOT NULL,
    reference text,
    PRIMARY KEY (ticket_id, role),
    CONSTRAINT ticket_tenders_role_chk CHECK (
        role IN ('cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit', 'vendor_draw')
    ),
    CONSTRAINT ticket_tenders_amount_chk CHECK (amount_minor > 0),
    CONSTRAINT ticket_tenders_reference_chk CHECK (
        reference IS NULL OR (reference = btrim(reference) AND char_length(reference) <= 40)
    )
);

GRANT SELECT, INSERT, UPDATE, DELETE ON ticket_tenders TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON ticket_tenders FROM stall_poster;

SET event_triggers = false;

CREATE OR REPLACE FUNCTION ledger_assert_journal_balance()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    checked_journal_id uuid;
    header_debit bigint;
    header_credit bigint;
    line_count integer;
    debit_sum bigint;
    credit_sum bigint;
    both_sides integer;
BEGIN
    IF TG_TABLE_NAME = 'journals' THEN
        checked_journal_id := NEW.id;
    ELSE
        checked_journal_id := NEW.journal_id;
    END IF;

    SELECT debit_total_minor, credit_total_minor
    INTO header_debit, header_credit
    FROM public.journals
    WHERE id = checked_journal_id;

    SELECT count(*), COALESCE(sum(debit_minor), 0), COALESCE(sum(credit_minor), 0)
    INTO line_count, debit_sum, credit_sum
    FROM public.journal_lines
    WHERE journal_id = checked_journal_id;

    IF line_count < 2 THEN
        RAISE EXCEPTION 'a journal must have at least two lines'
            USING ERRCODE = '23514';
    END IF;
    IF debit_sum <> credit_sum OR debit_sum <> header_debit OR credit_sum <> header_credit THEN
        RAISE EXCEPTION 'journal line totals must equal the header totals'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO both_sides
    FROM (
        SELECT line.account_id
        FROM public.journal_lines AS line
        WHERE line.journal_id = checked_journal_id
        GROUP BY line.account_id
        HAVING sum(line.debit_minor) > 0
           AND sum(line.credit_minor) > 0
           AND line.account_id NOT IN (
               SELECT role.account_id
               FROM public.chart_roles AS role
               WHERE role.role = 'vendor_payable'
           )
    ) AS sided;
    IF both_sides > 0 THEN
        RAISE EXCEPTION 'an account may appear on only one side of a journal'
            USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
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
            OR NEW.discount_minor > NEW.price_minor * NEW.quantity THEN
            RAISE EXCEPTION 'a sale discount does not match the ticket'
                USING ERRCODE = '23514';
        END IF;

        expected_commission := public.stall_half_up(NEW.price_minor * NEW.quantity - NEW.discount_minor, commission_bps);

        IF tax_exempt OR waiver THEN
            expected_tax := 0;
        ELSE
            expected_tax := public.stall_tax_minor(NEW.price_minor * NEW.quantity - NEW.discount_minor, tax_bps);
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
          role.role IN ('cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit')
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
        RAISE EXCEPTION 'a sale needs a piece'
            USING ERRCODE = '23514';
    END IF;

    SELECT COALESCE(sum(price_minor * quantity), 0),
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
                OR (role.role = 'vendor_payable' AND line.debit_minor > 0 AND line.subledger_type = 'vendor')
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

SET event_triggers = true;
