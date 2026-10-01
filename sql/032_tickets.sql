-- An open ticket is the cashier's cart. It is not a journal.
-- Pay posts the sale, then the ticket is frozen. Apply after 031.
-- The provisioner rewrites stall_poster.

CREATE TABLE IF NOT EXISTS tickets (
    id uuid PRIMARY KEY,
    session_id uuid NOT NULL REFERENCES register_sessions (id),
    status text NOT NULL DEFAULT 'open',
    posted_journal_id uuid REFERENCES journals (id),
    discount_kind text,
    discount_value bigint,
    discount_reason text,
    tax_waiver_reason text,
    buyer_id uuid REFERENCES vendors (id),
    CONSTRAINT tickets_status_chk CHECK (status IN ('open', 'posted', 'parked', 'layaway')),
    CONSTRAINT tickets_posted_chk CHECK (
        (status IN ('open', 'parked', 'layaway') AND posted_journal_id IS NULL)
        OR (status = 'posted' AND posted_journal_id IS NOT NULL)
    ),
    CONSTRAINT tickets_discount_chk CHECK (
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
    ),
    CONSTRAINT tickets_waiver_chk CHECK (
        tax_waiver_reason IS NULL
        OR tax_waiver_reason IN ('resale', 'charity', 'government or school', 'other')
    )
);

CREATE UNIQUE INDEX IF NOT EXISTS tickets_one_open
    ON tickets (session_id)
    WHERE status = 'open';

CREATE TABLE IF NOT EXISTS ticket_lines (
    ticket_id uuid NOT NULL REFERENCES tickets (id),
    line_no integer NOT NULL,
    item_id uuid NOT NULL REFERENCES items (id),
    price_minor bigint NOT NULL,
    quantity integer NOT NULL DEFAULT 1,
    line_discount_kind text,
    line_discount_value bigint,
    line_discount_reason text,
    line_discount_minor bigint NOT NULL DEFAULT 0,
    CONSTRAINT ticket_lines_pkey PRIMARY KEY (ticket_id, line_no),
    CONSTRAINT ticket_lines_item_key UNIQUE (ticket_id, item_id),
    CONSTRAINT ticket_lines_no_chk CHECK (line_no > 0),
    CONSTRAINT ticket_lines_price_chk CHECK (price_minor > 0),
    CONSTRAINT ticket_lines_quantity_chk CHECK (quantity > 0),
    CONSTRAINT ticket_lines_line_discount_chk CHECK (
        line_discount_minor >= 0
        AND (
            (
                line_discount_kind IS NULL
                AND line_discount_value IS NULL
                AND line_discount_reason IS NULL
                AND line_discount_minor = 0
            )
            OR (
                line_discount_kind = 'percent'
                AND line_discount_value > 0
                AND line_discount_value <= 10000
                AND line_discount_reason IN ('manager approval', 'damaged', 'loyalty', 'promotion', 'other')
                AND line_discount_minor > 0
            )
            OR (
                line_discount_kind = 'amount'
                AND line_discount_value > 0
                AND line_discount_reason IN ('manager approval', 'damaged', 'loyalty', 'promotion', 'other')
                AND line_discount_minor = line_discount_value
            )
        )
    )
);

GRANT SELECT, INSERT, UPDATE, DELETE ON tickets TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON tickets FROM stall_poster;
GRANT SELECT ON tickets TO stall_backup;

GRANT SELECT, INSERT, UPDATE, DELETE ON ticket_lines TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON ticket_lines FROM stall_poster;
GRANT SELECT ON ticket_lines TO stall_backup;


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
        SELECT public.stall_half_up(line.price_minor * line.quantity - line.line_discount_minor, value)
        INTO share
        FROM public.ticket_lines AS line
        WHERE line.ticket_id = ticket
          AND line.item_id = item;
        RETURN COALESCE(share, 0);
    END IF;
    IF kind IS DISTINCT FROM 'amount' THEN
        RETURN 0;
    END IF;
    SELECT COALESCE(sum(line.price_minor * line.quantity - line.line_discount_minor), 0), count(*)::integer
    INTO total, counted
    FROM public.ticket_lines AS line
    WHERE line.ticket_id = ticket;
    IF total = 0 OR counted = 0 THEN
        RETURN 0;
    END IF;
    FOR line_item, line_price IN
        SELECT line.item_id, line.price_minor * line.quantity - line.line_discount_minor
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
    FOR line_item, line_price, line_off IN
        SELECT line.item_id, line.price_minor * line.quantity, line.line_discount_minor
        FROM public.ticket_lines AS line
        WHERE line.ticket_id = ticket
        ORDER BY line.line_no
    LOOP
        share := public.stall_line_discount(ticket, line_item, kind, value);
        IF share < 0 OR line_off + share > line_price THEN
            RAISE EXCEPTION 'the discount is more than the price'
                USING ERRCODE = '23514';
        END IF;
    END LOOP;
END;
$$;

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

    IF OLD.status = 'open' AND NEW.status = 'layaway' AND NEW.posted_journal_id IS NULL
        AND NEW.session_id IS NOT DISTINCT FROM OLD.session_id THEN
        RETURN NEW;
    END IF;

    IF OLD.status = 'layaway' AND NEW.status = 'open' AND NEW.posted_journal_id IS NULL THEN
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
        SELECT item_id, price_minor, quantity
        FROM public.ticket_lines
        WHERE ticket_id = NEW.id
        EXCEPT ALL
        SELECT item_id, price_minor, quantity
        FROM public.sale_lines
        WHERE journal_id = NEW.posted_journal_id
    ) AS missing;

    SELECT count(*) INTO extra_lines
    FROM (
        SELECT item_id, price_minor, quantity
        FROM public.sale_lines
        WHERE journal_id = NEW.posted_journal_id
        EXCEPT ALL
        SELECT item_id, price_minor, quantity
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

CREATE OR REPLACE FUNCTION stall_assert_ticket_line()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    piece uuid;
    ticket_status text;
    line_item uuid;
    line_ticket uuid;
    on_hand integer;
    held integer;
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

    SELECT item.id, item.quantity INTO piece, on_hand
    FROM public.items AS item
    WHERE item.id = line_item
    FOR UPDATE OF item;

    IF piece IS NULL THEN
        RAISE EXCEPTION 'a ticket line needs a piece'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    IF NEW.line_discount_kind IS NULL THEN
        NEW.line_discount_minor := 0;
        NEW.line_discount_value := NULL;
        NEW.line_discount_reason := NULL;
    ELSE
        IF NEW.line_discount_kind = 'percent' THEN
            NEW.line_discount_minor := public.stall_half_up(NEW.price_minor * NEW.quantity, NEW.line_discount_value);
        ELSE
            NEW.line_discount_minor := NEW.line_discount_value;
        END IF;
        IF NEW.line_discount_minor < 1 OR NEW.line_discount_minor > NEW.price_minor * NEW.quantity THEN
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
            RAISE EXCEPTION 'a piece is already on a ticket'
                USING ERRCODE = '23514';
        END IF;
        RAISE EXCEPTION 'there are not enough on hand'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION stall_assert_sale_not_on_ticket()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    held integer;
    on_hand integer;
BEGIN
    SELECT COALESCE(sum(line.quantity), 0)::integer INTO held
    FROM public.ticket_lines AS line
    JOIN public.tickets AS ticket ON ticket.id = line.ticket_id
    WHERE line.item_id = NEW.item_id
      AND ticket.status IN ('open', 'parked', 'layaway');

    SELECT item.quantity INTO on_hand
    FROM public.items AS item
    WHERE item.id = NEW.item_id;

    IF held > on_hand THEN
        RAISE EXCEPTION 'a piece is already on a ticket'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE OR REPLACE FUNCTION stall_reject_close_with_open_ticket()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF TG_OP <> 'UPDATE' OR OLD.closed_at IS NOT NULL OR NEW.closed_at IS NULL THEN
        RETURN NEW;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.tickets AS ticket
        WHERE ticket.session_id = NEW.id
          AND ticket.status = 'open'
    ) THEN
        RAISE EXCEPTION 'close is refused while a ticket is open'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = 'tickets'::regclass AND tgname = 'tickets_no_delete' AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER tickets_no_delete
            BEFORE INSERT OR UPDATE OR DELETE ON tickets
            FOR EACH ROW
            EXECUTE FUNCTION stall_assert_ticket();
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = 'tickets'::regclass AND tgname = 'tickets_no_truncate' AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER tickets_no_truncate
            BEFORE TRUNCATE ON tickets
            FOR EACH STATEMENT
            EXECUTE FUNCTION ledger_reject_truncate();
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = 'ticket_lines'::regclass AND tgname = 'ticket_lines_no_delete' AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER ticket_lines_no_delete
            BEFORE INSERT OR UPDATE OR DELETE ON ticket_lines
            FOR EACH ROW
            EXECUTE FUNCTION stall_assert_ticket_line();
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = 'ticket_lines'::regclass AND tgname = 'ticket_lines_no_truncate' AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER ticket_lines_no_truncate
            BEFORE TRUNCATE ON ticket_lines
            FOR EACH STATEMENT
            EXECUTE FUNCTION ledger_reject_truncate();
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = 'sale_lines'::regclass           AND tgname = 'sale_lines_open_ticket_no_delete' AND NOT tgisinternal
    ) THEN
        CREATE CONSTRAINT TRIGGER sale_lines_open_ticket_no_delete
            AFTER INSERT ON sale_lines
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION stall_assert_sale_not_on_ticket();
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = 'register_sessions'::regclass
          AND tgname = 'register_sessions_open_ticket_no_delete'
          AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER register_sessions_open_ticket_no_delete
            BEFORE UPDATE ON register_sessions
            FOR EACH ROW
            EXECUTE FUNCTION stall_reject_close_with_open_ticket();
    END IF;
END $$;

ALTER TABLE tickets
    ENABLE ALWAYS TRIGGER tickets_no_delete,
    ENABLE ALWAYS TRIGGER tickets_no_truncate;

ALTER TABLE ticket_lines
    ENABLE ALWAYS TRIGGER ticket_lines_no_delete,
    ENABLE ALWAYS TRIGGER ticket_lines_no_truncate;

ALTER TABLE sale_lines ENABLE ALWAYS TRIGGER sale_lines_open_ticket_no_delete;

ALTER TABLE register_sessions ENABLE ALWAYS TRIGGER register_sessions_open_ticket_no_delete;

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
        SELECT public.stall_line_discount(ticket.id, NEW.item_id, ticket.discount_kind, ticket.discount_value) + line.line_discount_minor,
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
            )
      );

    IF foreign_lines > 0 THEN
        RAISE EXCEPTION 'a sale only credits commission, sales tax, and vendor payable'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER journals_sale_shape
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_sale_shape();

CREATE TRIGGER sale_lines_append_only
    BEFORE INSERT OR UPDATE OR DELETE ON sale_lines
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_sale_shape();

ALTER TABLE sale_lines ENABLE ALWAYS TRIGGER sale_lines_append_only;
