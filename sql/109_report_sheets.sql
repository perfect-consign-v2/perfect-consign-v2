-- Report sheets read one honest query each.
-- A void leaves the sale month and comes out of the month the reversal posts.
-- A donation keeps the vendor and the quantity after the item becomes store stock.

ALTER TABLE public.item_dispositions
    ADD COLUMN code text,
    ADD COLUMN description text,
    ADD COLUMN vendor_id uuid,
    ADD COLUMN vendor_name text,
    ADD COLUMN quantity integer;

UPDATE public.item_dispositions AS disposition
SET code = item.code,
    description = item.description
FROM public.items AS item
WHERE item.id = disposition.item_id
  AND disposition.code IS NULL;

ALTER TABLE public.item_dispositions
    ALTER COLUMN code SET NOT NULL,
    ALTER COLUMN description SET NOT NULL;

ALTER TABLE public.item_dispositions
    ADD CONSTRAINT item_dispositions_code_chk CHECK ((code = btrim(code)) AND (code <> '') AND (char_length(code) <= 40)),
    ADD CONSTRAINT item_dispositions_description_chk CHECK ((description = btrim(description)) AND (description <> '') AND (char_length(description) <= 200)),
    ADD CONSTRAINT item_dispositions_quantity_chk CHECK ((quantity IS NULL) OR (quantity > 0)),
    ADD CONSTRAINT item_dispositions_vendor_name_chk CHECK ((vendor_name IS NULL) OR ((vendor_name = btrim(vendor_name)) AND (vendor_name <> '') AND (char_length(vendor_name) <= 240)));

CREATE OR REPLACE FUNCTION public.stall_apply_disposition() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    today date;
    piece_placement text;
    piece_vendor uuid;
    piece_quantity integer;
    piece_code text;
    piece_description text;
    piece_expires date;
    piece_vendor_name text;
BEGIN
    SELECT item.placement, item.vendor_id, item.quantity, item.code, item.description, item.expires_on, vendor.name
    INTO piece_placement, piece_vendor, piece_quantity, piece_code, piece_description, piece_expires, piece_vendor_name
    FROM public.items AS item
    LEFT JOIN public.vendors AS vendor ON vendor.id = item.vendor_id
    WHERE item.id = NEW.item_id;

    IF NOT FOUND OR piece_vendor IS NULL OR piece_vendor_name IS NULL OR btrim(piece_vendor_name) = '' THEN
        RAISE EXCEPTION 'a disposition must name the vendor'
            USING ERRCODE = '23514';
    END IF;

    IF piece_quantity IS NULL OR piece_quantity < 1 THEN
        RAISE EXCEPTION 'a disposition must name the quantity'
            USING ERRCODE = '23514';
    END IF;

    today := (SELECT (clock_timestamp() AT TIME ZONE mall.time_zone)::date FROM public.mall);
    IF piece_placement IS DISTINCT FROM 'consigned'
       OR piece_expires IS NULL
       OR piece_expires >= today THEN
        RAISE EXCEPTION 'only an expired consigned piece can leave the floor'
            USING ERRCODE = '23514';
    END IF;

    NEW.code := piece_code;
    NEW.description := piece_description;
    NEW.vendor_id := piece_vendor;
    NEW.vendor_name := piece_vendor_name;
    NEW.quantity := piece_quantity;

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

DROP FUNCTION IF EXISTS public.stall_report_sales(date, date, text);
DROP FUNCTION IF EXISTS public.stall_report_payouts(date, date);
DROP FUNCTION IF EXISTS public.stall_report_rent();
DROP FUNCTION IF EXISTS public.stall_report_stored_value();
DROP FUNCTION IF EXISTS public.stall_report_expiring();
DROP FUNCTION IF EXISTS public.stall_report_donated(date, date);
DROP FUNCTION IF EXISTS public.stall_report_sell_through(date, date);

CREATE FUNCTION public.stall_report_sales(starts_on date, ends_on date, grouped text)
RETURNS TABLE(label text, merchandise_minor bigint, tax_minor bigint, receipt_count bigint, taken_minor bigint)
    LANGUAGE plpgsql STABLE
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF starts_on IS NULL OR ends_on IS NULL OR ends_on < starts_on THEN
        RAISE EXCEPTION 'To is before From.'
            USING ERRCODE = '23514';
    END IF;
    IF grouped = 'hour' AND starts_on <> ends_on THEN
        RAISE EXCEPTION 'An hourly report is one day.'
            USING ERRCODE = '23514';
    END IF;
    IF grouped = 'tender' THEN
        RETURN QUERY
        SELECT role.role,
               0::bigint,
               0::bigint,
               0::bigint,
               COALESCE(sum(line.debit_minor - line.credit_minor), 0)::bigint
        FROM public.journals AS journal
        JOIN public.journal_lines AS line ON line.journal_id = journal.id
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE journal.posting_date >= starts_on
          AND journal.posting_date <= ends_on
          AND journal.source_type IN ('sale', 'item_return')
          AND role.role IN ('cash', 'card', 'checks', 'gift_card', 'gift_certificate', 'store_credit')
        GROUP BY role.role
        ORDER BY CASE role.role
            WHEN 'cash' THEN 1
            WHEN 'card' THEN 2
            WHEN 'checks' THEN 3
            WHEN 'gift_card' THEN 4
            WHEN 'gift_certificate' THEN 5
            WHEN 'store_credit' THEN 6
            ELSE 7
        END;
        RETURN;
    END IF;
    IF grouped NOT IN ('day', 'vendor', 'cashier', 'hour', 'category') THEN
        RAISE EXCEPTION 'That grouping is not on this page.'
            USING ERRCODE = '23514';
    END IF;

    RETURN QUERY
    WITH event AS (
        SELECT journal.posting_date AS posted_on,
               journal.occurred_at,
               journal.id AS receipt_id,
               1 AS receipt_sign,
               COALESCE(vendor.name, 'Store') AS vendor_name,
               COALESCE(NULLIF(btrim(session.cashier), ''), 'Not recorded') AS cashier_name,
               COALESCE(category.name, 'Uncategorized') AS category_name,
               (sale_line.price_minor * sale_line.quantity
                   - sale_line.discount_minor
                   - sale_line.store_discount_minor)::bigint AS merchandise_minor,
               sale_line.tax_minor::bigint AS tax_minor
        FROM public.journals AS journal
        JOIN public.sale_lines AS sale_line ON sale_line.journal_id = journal.id
        JOIN public.items AS item ON item.id = sale_line.item_id
        LEFT JOIN public.vendors AS vendor ON vendor.id = item.vendor_id
        LEFT JOIN public.categories AS category ON category.id = item.category_id
        LEFT JOIN public.register_session_posts AS posted ON posted.journal_id = journal.id
        LEFT JOIN public.register_sessions AS session ON session.id = posted.session_id
        WHERE journal.source_type = 'sale'
          AND NOT journal.is_reversal
          AND journal.posting_date >= starts_on
          AND journal.posting_date <= ends_on
        UNION ALL
        SELECT reversal.posting_date,
               reversal.occurred_at,
               original.id,
               -1,
               COALESCE(vendor.name, 'Store'),
               COALESCE(NULLIF(btrim(session.cashier), ''), 'Not recorded'),
               COALESCE(category.name, 'Uncategorized'),
               (-(sale_line.price_minor * sale_line.quantity
                   - sale_line.discount_minor
                   - sale_line.store_discount_minor))::bigint,
               (-sale_line.tax_minor)::bigint
        FROM public.journals AS reversal
        JOIN public.journals AS original ON original.id = reversal.reverses_journal_id
        JOIN public.sale_lines AS sale_line ON sale_line.journal_id = original.id
        JOIN public.items AS item ON item.id = sale_line.item_id
        LEFT JOIN public.vendors AS vendor ON vendor.id = item.vendor_id
        LEFT JOIN public.categories AS category ON category.id = item.category_id
        LEFT JOIN public.register_session_posts AS posted ON posted.journal_id = original.id
        LEFT JOIN public.register_sessions AS session ON session.id = posted.session_id
        WHERE reversal.source_type = 'sale'
          AND reversal.is_reversal
          AND reversal.posting_date >= starts_on
          AND reversal.posting_date <= ends_on
        UNION ALL
        SELECT journal.posting_date,
               journal.occurred_at,
               NULL::uuid,
               0,
               COALESCE(vendor.name, 'Store'),
               COALESCE(NULLIF(btrim(session.cashier), ''), 'Not recorded'),
               COALESCE(category.name, 'Uncategorized'),
               (-(item_return.price_minor - item_return.discount_minor - item_return.store_discount_minor))::bigint,
               (-item_return.tax_minor)::bigint
        FROM public.item_returns AS item_return
        JOIN public.journals AS journal ON journal.id = item_return.journal_id
        JOIN public.journals AS sale ON sale.id = item_return.sale_journal_id
        JOIN public.items AS item ON item.id = item_return.item_id
        LEFT JOIN public.vendors AS vendor ON vendor.id = item.vendor_id
        LEFT JOIN public.categories AS category ON category.id = item.category_id
        LEFT JOIN public.register_session_posts AS posted ON posted.journal_id = sale.id
        LEFT JOIN public.register_sessions AS session ON session.id = posted.session_id
        WHERE journal.source_type = 'item_return'
          AND journal.posting_date >= starts_on
          AND journal.posting_date <= ends_on
    ),
    bucketed AS (
        SELECT CASE grouped
                   WHEN 'day' THEN event.posted_on::text
                   WHEN 'vendor' THEN event.vendor_name
                   WHEN 'cashier' THEN event.cashier_name
                   WHEN 'category' THEN event.category_name
                   ELSE to_char(event.occurred_at AT TIME ZONE (SELECT mall.time_zone FROM public.mall), 'HH24') || ':00'
               END AS bucket,
               event.receipt_id,
               event.receipt_sign,
               event.merchandise_minor,
               event.tax_minor
        FROM event
    )
    SELECT bucketed.bucket,
           COALESCE(sum(bucketed.merchandise_minor), 0)::bigint,
           COALESCE(sum(bucketed.tax_minor), 0)::bigint,
           (
               count(DISTINCT bucketed.receipt_id) FILTER (WHERE bucketed.receipt_sign = 1)
               - count(DISTINCT bucketed.receipt_id) FILTER (WHERE bucketed.receipt_sign = -1)
           )::bigint,
           0::bigint
    FROM bucketed
    GROUP BY bucketed.bucket
    ORDER BY CASE WHEN grouped IN ('day', 'hour') THEN bucketed.bucket END,
             lower(bucketed.bucket),
             bucketed.bucket;
END;
$$;

CREATE FUNCTION public.stall_report_payouts(starts_on date, ends_on date)
RETURNS TABLE(paid_on date, vendor text, amount_minor bigint, source_type text)
    LANGUAGE sql STABLE
    SET search_path TO 'pg_catalog'
    AS $$
    SELECT journal.posting_date,
           COALESCE(vendor.name, ''),
           COALESCE(sum(line.debit_minor), 0)::bigint,
           journal.source_type
    FROM public.journals AS journal
    JOIN public.journal_lines AS line ON line.journal_id = journal.id
    JOIN public.chart_roles AS role ON role.account_id = line.account_id AND role.role = 'vendor_payable'
    LEFT JOIN public.vendors AS vendor ON vendor.id = line.subledger_ref::uuid
    WHERE journal.source_type IN ('check_disbursement', 'cash_disbursement', 'safe_disbursement', 'settlement')
      AND journal.posting_date >= starts_on
      AND journal.posting_date <= ends_on
      AND line.debit_minor > 0
    GROUP BY journal.id, journal.posting_date, vendor.name, journal.source_type
    ORDER BY journal.posting_date, lower(COALESCE(vendor.name, '')), journal.id;
$$;

CREATE FUNCTION public.stall_report_rent()
RETURNS TABLE(booth text, vendor text, open_minor bigint)
    LANGUAGE sql STABLE
    SET search_path TO 'pg_catalog'
    AS $$
    SELECT booth.code,
           COALESCE(vendor.name, ''),
           COALESCE(sum(line.debit_minor - line.credit_minor), 0)::bigint
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id AND role.role = 'booth_rent_receivable'
    JOIN public.booths AS booth ON booth.id::text = line.subledger_ref
    LEFT JOIN public.booth_assignments AS assignment
      ON assignment.booth_id = booth.id AND assignment.ends_on IS NULL
    LEFT JOIN public.vendors AS vendor ON vendor.id = assignment.vendor_id
    WHERE line.subledger_type = 'booth'
    GROUP BY booth.code, vendor.name
    HAVING COALESCE(sum(line.debit_minor - line.credit_minor), 0) <> 0
    ORDER BY booth.code;
$$;

CREATE FUNCTION public.stall_refuse_rent_roll() RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    stray integer;
    roll_minor bigint;
    books_minor bigint;
BEGIN
    SELECT count(*) INTO stray
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id AND role.role = 'booth_rent_receivable'
    WHERE line.subledger_type IS DISTINCT FROM 'booth'
       OR line.subledger_ref IS NULL
       OR NOT EXISTS (
           SELECT 1 FROM public.booths AS booth WHERE booth.id::text = line.subledger_ref
       );
    IF stray > 0 THEN
        RAISE EXCEPTION 'The rent roll does not match the books.'
            USING ERRCODE = '23514';
    END IF;

    SELECT COALESCE(sum(open_minor), 0) INTO roll_minor FROM public.stall_report_rent();
    SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) INTO books_minor
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id AND role.role = 'booth_rent_receivable';
    IF roll_minor IS DISTINCT FROM books_minor THEN
        RAISE EXCEPTION 'The rent roll does not match the books.'
            USING ERRCODE = '23514';
    END IF;
END;
$$;

CREATE FUNCTION public.stall_report_stored_value()
RETURNS TABLE(kind text, balance_minor bigint)
    LANGUAGE sql STABLE
    SET search_path TO 'pg_catalog'
    AS $$
    SELECT role.role, COALESCE(balance.balance_minor, 0)::bigint
    FROM public.chart_roles AS role
    LEFT JOIN public.account_balances AS balance ON balance.account_id = role.account_id
    WHERE role.role IN ('gift_card', 'gift_certificate', 'store_credit')
    ORDER BY CASE role.role
        WHEN 'gift_card' THEN 1
        WHEN 'gift_certificate' THEN 2
        WHEN 'store_credit' THEN 3
        ELSE 4
    END;
$$;

CREATE FUNCTION public.stall_refuse_stored_value() RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    listed record;
    from_lines bigint;
    normal text;
BEGIN
    FOR listed IN
        SELECT kind, balance_minor FROM public.stall_report_stored_value()
    LOOP
        SELECT account.normal_balance INTO normal
        FROM public.chart_roles AS role
        JOIN public.accounts AS account ON account.id = role.account_id
        WHERE role.role = listed.kind;
        SELECT COALESCE(sum(
            CASE normal
                WHEN 'debit' THEN line.debit_minor - line.credit_minor
                ELSE line.credit_minor - line.debit_minor
            END
        ), 0) INTO from_lines
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE role.role = listed.kind;
        IF listed.balance_minor IS DISTINCT FROM from_lines THEN
            RAISE EXCEPTION 'Stored value does not match the books.'
                USING ERRCODE = '23514';
        END IF;
    END LOOP;
END;
$$;

CREATE FUNCTION public.stall_report_expiring()
RETURNS TABLE(code text, description text, vendor text, expires_on date, days_left integer, quantity bigint, price_minor bigint)
    LANGUAGE sql STABLE
    SET search_path TO 'pg_catalog'
    AS $$
    SELECT item.code,
           item.description,
           COALESCE(vendor.name, vendor.code, ''),
           item.expires_on,
           (item.expires_on - (clock_timestamp() AT TIME ZONE mall.time_zone)::date)::integer,
           item.quantity::bigint,
           item.price_minor
    FROM public.items AS item
    LEFT JOIN public.vendors AS vendor ON vendor.id = item.vendor_id
    CROSS JOIN public.mall
    WHERE item.placement = 'consigned'
      AND item.quantity > 0
      AND item.expires_on IS NOT NULL
      AND item.expires_on <= ((clock_timestamp() AT TIME ZONE mall.time_zone)::date + 14)
      AND NOT EXISTS (SELECT 1 FROM public.item_dispositions AS disposition WHERE disposition.item_id = item.id)
      AND NOT EXISTS (SELECT 1 FROM public.item_buys AS buy WHERE buy.item_id = item.id)
    ORDER BY item.expires_on, lower(item.code), item.code;
$$;

CREATE FUNCTION public.stall_report_donated(starts_on date, ends_on date)
RETURNS TABLE(posted_on date, code text, description text, vendor_name text, quantity integer)
    LANGUAGE sql STABLE
    SET search_path TO 'pg_catalog'
    AS $$
    SELECT disposition.posted_on,
           disposition.code,
           disposition.description,
           disposition.vendor_name,
           disposition.quantity
    FROM public.item_dispositions AS disposition
    WHERE disposition.kind = 'donate'
      AND disposition.posted_on >= starts_on
      AND disposition.posted_on <= ends_on
    ORDER BY disposition.posted_on, lower(disposition.code), disposition.code;
$$;

CREATE FUNCTION public.stall_report_sell_through(starts_on date, ends_on date)
RETURNS TABLE(category text, sold_qty bigint, on_hand_qty bigint, sales_minor bigint, sold_percent integer)
    LANGUAGE sql STABLE
    SET search_path TO 'pg_catalog'
    AS $$
    WITH moved AS (
        SELECT COALESCE(category.name, 'Uncategorized') AS category,
               sale_line.quantity::bigint AS sold_qty,
               (sale_line.price_minor * sale_line.quantity
                   - sale_line.discount_minor
                   - sale_line.store_discount_minor)::bigint AS sales_minor
        FROM public.journals AS journal
        JOIN public.sale_lines AS sale_line ON sale_line.journal_id = journal.id
        JOIN public.items AS item ON item.id = sale_line.item_id
        LEFT JOIN public.categories AS category ON category.id = item.category_id
        WHERE journal.source_type = 'sale'
          AND NOT journal.is_reversal
          AND journal.posting_date >= starts_on
          AND journal.posting_date <= ends_on
        UNION ALL
        SELECT COALESCE(category.name, 'Uncategorized'),
               (-sale_line.quantity)::bigint,
               (-(sale_line.price_minor * sale_line.quantity
                   - sale_line.discount_minor
                   - sale_line.store_discount_minor))::bigint
        FROM public.journals AS reversal
        JOIN public.journals AS original ON original.id = reversal.reverses_journal_id
        JOIN public.sale_lines AS sale_line ON sale_line.journal_id = original.id
        JOIN public.items AS item ON item.id = sale_line.item_id
        LEFT JOIN public.categories AS category ON category.id = item.category_id
        WHERE reversal.source_type = 'sale'
          AND reversal.is_reversal
          AND reversal.posting_date >= starts_on
          AND reversal.posting_date <= ends_on
        UNION ALL
        SELECT COALESCE(category.name, 'Uncategorized'),
               (-item_return.quantity)::bigint,
               (-(item_return.price_minor - item_return.discount_minor - item_return.store_discount_minor))::bigint
        FROM public.item_returns AS item_return
        JOIN public.journals AS journal ON journal.id = item_return.journal_id
        JOIN public.items AS item ON item.id = item_return.item_id
        LEFT JOIN public.categories AS category ON category.id = item.category_id
        WHERE journal.source_type = 'item_return'
          AND journal.posting_date >= starts_on
          AND journal.posting_date <= ends_on
    ),
    sold AS (
        SELECT moved.category,
               COALESCE(sum(moved.sold_qty), 0)::bigint AS sold_qty,
               COALESCE(sum(moved.sales_minor), 0)::bigint AS sales_minor
        FROM moved
        GROUP BY moved.category
    ),
    on_hand AS (
        SELECT COALESCE(category.name, 'Uncategorized') AS category,
               COALESCE(sum(item.quantity), 0)::bigint AS on_hand_qty
        FROM public.items AS item
        LEFT JOIN public.categories AS category ON category.id = item.category_id
        WHERE item.quantity > 0
        GROUP BY category.name
    ),
    joined AS (
        SELECT COALESCE(sold.category, on_hand.category) AS category,
               COALESCE(sold.sold_qty, 0)::bigint AS sold_qty,
               COALESCE(on_hand.on_hand_qty, 0)::bigint AS on_hand_qty,
               COALESCE(sold.sales_minor, 0)::bigint AS sales_minor
        FROM sold
        FULL OUTER JOIN on_hand ON on_hand.category = sold.category
    )
    SELECT joined.category,
           joined.sold_qty,
           joined.on_hand_qty,
           joined.sales_minor,
           CASE
               WHEN joined.sold_qty + joined.on_hand_qty = 0 THEN NULL
               ELSE round((joined.sold_qty::numeric * 100) / (joined.sold_qty + joined.on_hand_qty))::integer
           END
    FROM joined
    WHERE joined.sold_qty <> 0 OR joined.on_hand_qty <> 0
    ORDER BY lower(joined.category), joined.category;
$$;

CREATE OR REPLACE FUNCTION public.stall_sales_tax_sheet(starts_on date, ends_on date)
RETURNS TABLE(section text, label text, sale_count bigint, items_sold bigint, subtotal_minor bigint, discount_minor bigint, tax_minor bigint, total_minor bigint)
    LANGUAGE sql STABLE
    SET search_path TO 'pg_catalog'
    AS $$
    WITH sale AS (
        SELECT
            journal.id,
            sale_line.quantity,
            sale_line.price_minor * sale_line.quantity AS gross_minor,
            sale_line.discount_minor + sale_line.store_discount_minor AS discount_minor,
            sale_line.tax_minor,
            CASE
                WHEN ticket.tax_waiver_reason IS NOT NULL THEN ticket.tax_waiver_reason
                WHEN vendor.tax_exempt THEN 'vendor resale'
                WHEN item.tax_exempt THEN 'item exempt'
                ELSE ''
            END AS exempt_reason,
            CASE
                WHEN (sale_line.price_minor * sale_line.quantity - sale_line.discount_minor - sale_line.store_discount_minor) > 0
                    THEN (
                        (sale_line.tax_minor * 100000)
                        + ((sale_line.price_minor * sale_line.quantity - sale_line.discount_minor - sale_line.store_discount_minor) / 2)
                    ) / (sale_line.price_minor * sale_line.quantity - sale_line.discount_minor - sale_line.store_discount_minor)
                ELSE 0
            END AS rate_bps
        FROM public.journals AS journal
        JOIN public.sale_lines AS sale_line ON sale_line.journal_id = journal.id
        JOIN public.items AS item ON item.id = sale_line.item_id
        LEFT JOIN public.vendors AS vendor ON vendor.id = item.vendor_id
        LEFT JOIN public.tickets AS ticket ON ticket.posted_journal_id = journal.id
        WHERE journal.source_type = 'sale'
          AND NOT journal.is_reversal
          AND journal.posting_date >= starts_on
          AND journal.posting_date <= ends_on
    ),
    returned AS (
        SELECT
            item_return.quantity,
            item_return.price_minor AS gross_minor,
            item_return.discount_minor + item_return.store_discount_minor AS discount_minor,
            item_return.tax_minor
        FROM public.item_returns AS item_return
        JOIN public.journals AS journal ON journal.id = item_return.journal_id
        WHERE journal.source_type = 'item_return'
          AND journal.posting_date >= starts_on
          AND journal.posting_date <= ends_on
    ),
    voided AS (
        SELECT
            reversal.id,
            sale_line.quantity,
            sale_line.price_minor * sale_line.quantity AS gross_minor,
            sale_line.discount_minor + sale_line.store_discount_minor AS discount_minor,
            sale_line.tax_minor,
            CASE
                WHEN ticket.tax_waiver_reason IS NOT NULL THEN ticket.tax_waiver_reason
                WHEN vendor.tax_exempt THEN 'vendor resale'
                WHEN item.tax_exempt THEN 'item exempt'
                ELSE ''
            END AS exempt_reason,
            CASE
                WHEN (sale_line.price_minor * sale_line.quantity - sale_line.discount_minor - sale_line.store_discount_minor) > 0
                    THEN (
                        (sale_line.tax_minor * 100000)
                        + ((sale_line.price_minor * sale_line.quantity - sale_line.discount_minor - sale_line.store_discount_minor) / 2)
                    ) / (sale_line.price_minor * sale_line.quantity - sale_line.discount_minor - sale_line.store_discount_minor)
                ELSE 0
            END AS rate_bps
        FROM public.journals AS reversal
        JOIN public.journals AS original ON original.id = reversal.reverses_journal_id
        JOIN public.sale_lines AS sale_line ON sale_line.journal_id = original.id
        JOIN public.items AS item ON item.id = sale_line.item_id
        LEFT JOIN public.vendors AS vendor ON vendor.id = item.vendor_id
        LEFT JOIN public.tickets AS ticket ON ticket.posted_journal_id = original.id
        WHERE reversal.source_type = 'sale'
          AND reversal.is_reversal
          AND reversal.posting_date >= starts_on
          AND reversal.posting_date <= ends_on
    )
    SELECT 'taxable', 'Taxable sales', count(DISTINCT sale.id)::bigint, COALESCE(sum(sale.quantity), 0)::bigint,
           COALESCE(sum(sale.gross_minor), 0)::bigint, COALESCE(sum(sale.discount_minor), 0)::bigint,
           COALESCE(sum(sale.tax_minor), 0)::bigint,
           COALESCE(sum(sale.gross_minor - sale.discount_minor + sale.tax_minor), 0)::bigint
    FROM sale
    WHERE sale.exempt_reason = ''
    UNION ALL
    SELECT 'exempt', sale.exempt_reason, count(DISTINCT sale.id)::bigint, COALESCE(sum(sale.quantity), 0)::bigint,
           COALESCE(sum(sale.gross_minor), 0)::bigint, COALESCE(sum(sale.discount_minor), 0)::bigint,
           COALESCE(sum(sale.tax_minor), 0)::bigint,
           COALESCE(sum(sale.gross_minor - sale.discount_minor + sale.tax_minor), 0)::bigint
    FROM sale
    WHERE sale.exempt_reason <> ''
    GROUP BY sale.exempt_reason
    UNION ALL
    SELECT 'return', 'Returns', count(*)::bigint, COALESCE(sum(returned.quantity), 0)::bigint,
           (-COALESCE(sum(returned.gross_minor), 0))::bigint, (-COALESCE(sum(returned.discount_minor), 0))::bigint,
           (-COALESCE(sum(returned.tax_minor), 0))::bigint,
           (-COALESCE(sum(returned.gross_minor - returned.discount_minor + returned.tax_minor), 0))::bigint
    FROM returned
    UNION ALL
    SELECT 'void', 'Voids', count(DISTINCT voided.id)::bigint,
           (-COALESCE(sum(voided.quantity), 0))::bigint,
           (-COALESCE(sum(voided.gross_minor), 0))::bigint,
           (-COALESCE(sum(voided.discount_minor), 0))::bigint,
           (-COALESCE(sum(voided.tax_minor), 0))::bigint,
           (-COALESCE(sum(voided.gross_minor - voided.discount_minor + voided.tax_minor), 0))::bigint
    FROM voided
    WHERE voided.exempt_reason = ''
    UNION ALL
    SELECT 'void_exempt', 'Exempt voids', count(DISTINCT voided.id)::bigint,
           (-COALESCE(sum(voided.quantity), 0))::bigint,
           (-COALESCE(sum(voided.gross_minor), 0))::bigint,
           (-COALESCE(sum(voided.discount_minor), 0))::bigint,
           (-COALESCE(sum(voided.tax_minor), 0))::bigint,
           (-COALESCE(sum(voided.gross_minor - voided.discount_minor + voided.tax_minor), 0))::bigint
    FROM voided
    WHERE voided.exempt_reason <> ''
    UNION ALL
    SELECT 'rate', rated.rate_bps::text, count(DISTINCT rated.id)::bigint, COALESCE(sum(rated.quantity), 0)::bigint,
           COALESCE(sum(rated.gross_minor - rated.discount_minor), 0)::bigint, 0,
           COALESCE(sum(rated.tax_minor), 0)::bigint,
           COALESCE(sum(rated.gross_minor - rated.discount_minor + rated.tax_minor), 0)::bigint
    FROM (
        SELECT sale.id, sale.quantity::bigint AS quantity, sale.gross_minor, sale.discount_minor, sale.tax_minor, sale.rate_bps
        FROM sale
        WHERE sale.exempt_reason = ''
        UNION ALL
        SELECT voided.id, (-voided.quantity)::bigint, -voided.gross_minor, -voided.discount_minor, -voided.tax_minor, voided.rate_bps
        FROM voided
        WHERE voided.exempt_reason = ''
    ) AS rated
    GROUP BY rated.rate_bps
    HAVING COALESCE(sum(rated.gross_minor - rated.discount_minor), 0) <> 0
    UNION ALL
    SELECT 'nontaxable', 'Booth rent collected', count(DISTINCT journal.id)::bigint, 0,
           COALESCE(sum(line.credit_minor - line.debit_minor), 0)::bigint, 0, 0,
           COALESCE(sum(line.credit_minor - line.debit_minor), 0)::bigint
    FROM public.journals AS journal
    JOIN public.journal_lines AS line ON line.journal_id = journal.id
    JOIN public.chart_roles AS role ON role.account_id = line.account_id AND role.role = 'booth_rent_receivable'
    WHERE journal.source_type = 'rent_payment'
      AND journal.posting_date >= starts_on
      AND journal.posting_date <= ends_on
    UNION ALL
    SELECT 'nontaxable', kind.label, count(DISTINCT journal.id)::bigint, 0,
           COALESCE(sum(line.credit_minor - line.debit_minor), 0)::bigint, 0, 0,
           COALESCE(sum(line.credit_minor - line.debit_minor), 0)::bigint
    FROM (VALUES
        ('gift_certificate_issue', 'gift_certificate', 'Gift certificates issued'),
        ('gift_card_issue', 'gift_card', 'Gift cards issued'),
        ('store_credit_issue', 'store_credit', 'Store credit issued')
    ) AS kind(source_type, role_name, label)
    JOIN public.journals AS journal ON journal.source_type = kind.source_type
    JOIN public.journal_lines AS line ON line.journal_id = journal.id
    JOIN public.chart_roles AS role ON role.account_id = line.account_id AND role.role = kind.role_name
    WHERE journal.posting_date >= starts_on
      AND journal.posting_date <= ends_on
      AND NOT journal.is_reversal
    GROUP BY kind.label
    UNION ALL
    SELECT 'liability', 'Opening', 0, 0, 0, 0, 0,
           COALESCE(sum(line.credit_minor - line.debit_minor), 0)::bigint
    FROM public.journal_lines AS line
    JOIN public.journals AS journal ON journal.id = line.journal_id
    JOIN public.chart_roles AS role ON role.account_id = line.account_id AND role.role = 'sales_tax_payable'
    WHERE journal.posting_date < starts_on
    UNION ALL
    SELECT 'liability', 'Collected', 0, 0, 0, 0, 0, public.stall_sales_tax_collected(starts_on, ends_on)
    UNION ALL
    SELECT 'liability', 'Paid', 0, 0, 0, 0, 0,
           COALESCE(sum(line.debit_minor - line.credit_minor), 0)::bigint
    FROM public.journal_lines AS line
    JOIN public.journals AS journal ON journal.id = line.journal_id
    JOIN public.chart_roles AS role ON role.account_id = line.account_id AND role.role = 'sales_tax_payable'
    WHERE journal.source_type = 'tax_payment'
      AND journal.posting_date >= starts_on
      AND journal.posting_date <= ends_on
    UNION ALL
    SELECT 'liability', 'Closing', 0, 0, 0, 0, 0,
           (
               COALESCE((
                   SELECT sum(line.credit_minor - line.debit_minor)
                   FROM public.journal_lines AS line
                   JOIN public.journals AS journal ON journal.id = line.journal_id
                   JOIN public.chart_roles AS role ON role.account_id = line.account_id AND role.role = 'sales_tax_payable'
                   WHERE journal.posting_date <= ends_on
               ), 0)
           )::bigint;
$$;

CREATE OR REPLACE FUNCTION public.stall_refuse_tax_drift(starts_on date, ends_on date) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    sheet_minor bigint;
    books_minor bigint;
BEGIN
    SELECT COALESCE(sum(row.tax_minor), 0) INTO sheet_minor
    FROM public.stall_sales_tax_sheet(starts_on, ends_on) AS row
    WHERE row.section IN ('taxable', 'exempt', 'return', 'void', 'void_exempt');
    books_minor := public.stall_sales_tax_collected(starts_on, ends_on);
    PERFORM public.stall_refuse_tax_pair(sheet_minor, books_minor);
END;
$$;

ALTER TABLE public.saved_reports ADD COLUMN sheet text;
ALTER TABLE public.saved_reports ADD COLUMN range_key text;

UPDATE public.saved_reports
SET sheet = source,
    range_key = CASE
        WHEN source = 'sales' AND grouped = 'hour' THEN 'today'
        WHEN source IN ('rent', 'stored_value') THEN 'now'
        ELSE 'this_month'
    END,
    grouped = CASE
        WHEN source = 'sales' AND grouped = 'none' THEN 'day'
        WHEN source = 'sales' THEN grouped
        ELSE 'none'
    END;

ALTER TABLE public.saved_reports DROP CONSTRAINT saved_reports_grouped_chk;
ALTER TABLE public.saved_reports DROP CONSTRAINT saved_reports_source_chk;
ALTER TABLE public.saved_reports DROP COLUMN source;
ALTER TABLE public.saved_reports DROP COLUMN filter_text;
ALTER TABLE public.saved_reports ALTER COLUMN sheet SET NOT NULL;
ALTER TABLE public.saved_reports ALTER COLUMN range_key SET NOT NULL;

ALTER TABLE public.saved_reports
    ADD CONSTRAINT saved_reports_pair_chk CHECK (
        (sheet = 'sales'
            AND grouped = ANY (ARRAY['day', 'tender', 'vendor', 'cashier', 'category'])
            AND range_key = ANY (ARRAY['today', 'yesterday', 'this_week', 'this_month', 'last_month']))
        OR (sheet = 'sales' AND grouped = 'hour' AND range_key = 'today')
        OR (sheet = ANY (ARRAY['rent', 'stored_value', 'expiring']) AND grouped = 'none' AND range_key = 'now')
        OR (sheet = ANY (ARRAY['payouts', 'donated', 'sell_through'])
            AND grouped = 'none'
            AND range_key = ANY (ARRAY['today', 'yesterday', 'this_week', 'this_month', 'last_month']))
    );
