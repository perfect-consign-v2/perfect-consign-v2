-- Hourly + category sales groups; owner expiring / donated / category sell-through reports.

CREATE OR REPLACE FUNCTION public.stall_report_sales(starts_on date, ends_on date, grouped text)
    RETURNS TABLE (label text, sales_minor bigint, tax_minor bigint, sale_count bigint)
    LANGUAGE sql
    STABLE
    SET search_path TO 'pg_catalog'
    AS $$
    SELECT journal.posting_date::text,
           COALESCE(sum(sale_line.price_minor * sale_line.quantity - sale_line.discount_minor - sale_line.store_discount_minor), 0)::bigint,
           COALESCE(sum(sale_line.tax_minor), 0)::bigint,
           count(DISTINCT journal.id)::bigint
    FROM public.journals AS journal
    JOIN public.sale_lines AS sale_line ON sale_line.journal_id = journal.id
    WHERE grouped = 'day'
      AND journal.source_type = 'sale'
      AND NOT journal.is_reversal
      AND journal.posting_date >= starts_on
      AND journal.posting_date <= ends_on
    GROUP BY journal.posting_date
    UNION ALL
    SELECT COALESCE(vendor.name, 'Store'),
           COALESCE(sum(sale_line.price_minor * sale_line.quantity - sale_line.discount_minor - sale_line.store_discount_minor), 0)::bigint,
           COALESCE(sum(sale_line.tax_minor), 0)::bigint,
           count(DISTINCT journal.id)::bigint
    FROM public.journals AS journal
    JOIN public.sale_lines AS sale_line ON sale_line.journal_id = journal.id
    JOIN public.items AS item ON item.id = sale_line.item_id
    LEFT JOIN public.vendors AS vendor ON vendor.id = item.vendor_id
    WHERE grouped = 'vendor'
      AND journal.source_type = 'sale'
      AND NOT journal.is_reversal
      AND journal.posting_date >= starts_on
      AND journal.posting_date <= ends_on
    GROUP BY vendor.name
    UNION ALL
    SELECT COALESCE(session.cashier, ''),
           COALESCE(sum(sale_line.price_minor * sale_line.quantity - sale_line.discount_minor - sale_line.store_discount_minor), 0)::bigint,
           COALESCE(sum(sale_line.tax_minor), 0)::bigint,
           count(DISTINCT journal.id)::bigint
    FROM public.journals AS journal
    JOIN public.sale_lines AS sale_line ON sale_line.journal_id = journal.id
    LEFT JOIN public.register_session_posts AS posted ON posted.journal_id = journal.id
    LEFT JOIN public.register_sessions AS session ON session.id = posted.session_id
    WHERE grouped = 'cashier'
      AND journal.source_type = 'sale'
      AND NOT journal.is_reversal
      AND journal.posting_date >= starts_on
      AND journal.posting_date <= ends_on
    GROUP BY session.cashier
    UNION ALL
    SELECT role.role,
           COALESCE(sum(line.debit_minor - line.credit_minor), 0)::bigint,
           0::bigint,
           count(DISTINCT journal.id)::bigint
    FROM public.journals AS journal
    JOIN public.journal_lines AS line ON line.journal_id = journal.id
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE grouped = 'tender'
      AND journal.source_type = 'sale'
      AND NOT journal.is_reversal
      AND journal.posting_date >= starts_on
      AND journal.posting_date <= ends_on
      AND role.role IN ('cash', 'card', 'checks', 'gift_card', 'gift_certificate', 'store_credit')
    GROUP BY role.role
    UNION ALL
    SELECT to_char((journal.occurred_at AT TIME ZONE mall.time_zone), 'HH24') || ':00',
           COALESCE(sum(sale_line.price_minor * sale_line.quantity - sale_line.discount_minor - sale_line.store_discount_minor), 0)::bigint,
           COALESCE(sum(sale_line.tax_minor), 0)::bigint,
           count(DISTINCT journal.id)::bigint
    FROM public.journals AS journal
    JOIN public.sale_lines AS sale_line ON sale_line.journal_id = journal.id
    CROSS JOIN public.mall
    WHERE grouped = 'hour'
      AND journal.source_type = 'sale'
      AND NOT journal.is_reversal
      AND journal.posting_date >= starts_on
      AND journal.posting_date <= ends_on
    GROUP BY to_char((journal.occurred_at AT TIME ZONE mall.time_zone), 'HH24')
    UNION ALL
    SELECT COALESCE(category.name, 'Uncategorized'),
           COALESCE(sum(sale_line.price_minor * sale_line.quantity - sale_line.discount_minor - sale_line.store_discount_minor), 0)::bigint,
           COALESCE(sum(sale_line.tax_minor), 0)::bigint,
           count(DISTINCT journal.id)::bigint
    FROM public.journals AS journal
    JOIN public.sale_lines AS sale_line ON sale_line.journal_id = journal.id
    JOIN public.items AS item ON item.id = sale_line.item_id
    LEFT JOIN public.categories AS category ON category.id = item.category_id
    WHERE grouped = 'category'
      AND journal.source_type = 'sale'
      AND NOT journal.is_reversal
      AND journal.posting_date >= starts_on
      AND journal.posting_date <= ends_on
    GROUP BY category.name;
$$;

ALTER TABLE public.saved_reports
    DROP CONSTRAINT saved_reports_grouped_chk;

ALTER TABLE public.saved_reports
    ADD CONSTRAINT saved_reports_grouped_chk
    CHECK (grouped = ANY (ARRAY[
        'day'::text, 'tender'::text, 'vendor'::text, 'cashier'::text,
        'hour'::text, 'category'::text, 'none'::text
    ]));

CREATE OR REPLACE FUNCTION public.stall_report_expiring()
    RETURNS TABLE (
        code text,
        description text,
        vendor text,
        expires_on date,
        quantity bigint,
        price_minor bigint
    )
    LANGUAGE sql
    STABLE
    SET search_path TO 'pg_catalog'
    AS $$
    SELECT item.code,
           item.description,
           COALESCE(vendor.name, vendor.code, ''),
           item.expires_on,
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

CREATE OR REPLACE FUNCTION public.stall_report_donated(starts_on date, ends_on date)
    RETURNS TABLE (
        posted_on date,
        code text,
        description text,
        quantity bigint
    )
    LANGUAGE sql
    STABLE
    SET search_path TO 'pg_catalog'
    AS $$
    SELECT disposition.posted_on,
           item.code,
           item.description,
           item.quantity::bigint
    FROM public.item_dispositions AS disposition
    JOIN public.items AS item ON item.id = disposition.item_id
    WHERE disposition.kind = 'donate'
      AND disposition.posted_on >= starts_on
      AND disposition.posted_on <= ends_on
    ORDER BY disposition.posted_on, lower(item.code), item.code;
$$;

CREATE OR REPLACE FUNCTION public.stall_report_sell_through(starts_on date, ends_on date)
    RETURNS TABLE (
        category text,
        sold_qty bigint,
        on_hand_qty bigint,
        sales_minor bigint
    )
    LANGUAGE sql
    STABLE
    SET search_path TO 'pg_catalog'
    AS $$
    WITH sold AS (
        SELECT COALESCE(category.name, 'Uncategorized') AS category,
               COALESCE(sum(sale_line.quantity), 0)::bigint AS sold_qty,
               COALESCE(sum(sale_line.price_minor * sale_line.quantity - sale_line.discount_minor - sale_line.store_discount_minor), 0)::bigint AS sales_minor
        FROM public.journals AS journal
        JOIN public.sale_lines AS sale_line ON sale_line.journal_id = journal.id
        JOIN public.items AS item ON item.id = sale_line.item_id
        LEFT JOIN public.categories AS category ON category.id = item.category_id
        WHERE journal.source_type = 'sale'
          AND NOT journal.is_reversal
          AND journal.posting_date >= starts_on
          AND journal.posting_date <= ends_on
        GROUP BY category.name
    ),
    on_hand AS (
        SELECT COALESCE(category.name, 'Uncategorized') AS category,
               COALESCE(sum(item.quantity), 0)::bigint AS on_hand_qty
        FROM public.items AS item
        LEFT JOIN public.categories AS category ON category.id = item.category_id
        WHERE item.quantity > 0
        GROUP BY category.name
    )
    SELECT COALESCE(sold.category, on_hand.category) AS category,
           COALESCE(sold.sold_qty, 0)::bigint,
           COALESCE(on_hand.on_hand_qty, 0)::bigint,
           COALESCE(sold.sales_minor, 0)::bigint
    FROM sold
    FULL OUTER JOIN on_hand ON on_hand.category = sold.category
    ORDER BY COALESCE(sold.category, on_hand.category);
$$;
