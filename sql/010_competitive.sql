-- Sales-tax worksheet, owner reports, and the operating facts around the same journals.

INSERT INTO public.accounts (
    id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type
) VALUES
    ('01900000-0000-7000-8000-00000000002a', '01900000-0000-7000-8000-000000000001', '1290', 'Returned checks', 'asset', 'debit', true, false, NULL),
    ('01900000-0000-7000-8000-00000000002b', '01900000-0000-7000-8000-000000000001', '4220', 'Booth extras', 'revenue', 'credit', true, false, NULL),
    ('01900000-0000-7000-8000-00000000002c', '01900000-0000-7000-8000-000000000001', '2150', 'Booth deposits', 'liability', 'credit', true, false, NULL);

INSERT INTO public.chart_roles (role, account_id) VALUES
    ('returned_checks', '01900000-0000-7000-8000-00000000002a'),
    ('booth_other_revenue', '01900000-0000-7000-8000-00000000002b'),
    ('booth_deposits', '01900000-0000-7000-8000-00000000002c');

SELECT public.stall_seed_export_accounts();

INSERT INTO public.chart_source_rules (role, source_type) VALUES
    ('returned_checks', 'check_return'),
    ('checks', 'check_return'),
    ('booth_rent_receivable', 'booth_charge'),
    ('booth_other_revenue', 'booth_charge'),
    ('cash', 'booth_deposit'),
    ('booth_deposits', 'booth_deposit');

CREATE FUNCTION public.stall_sales_tax_collected(starts_on date, ends_on date) RETURNS bigint
    LANGUAGE sql
    STABLE
    SET search_path TO 'pg_catalog'
    AS $$
    SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0)::bigint
    FROM public.journal_lines AS line
    JOIN public.journals AS journal ON journal.id = line.journal_id AND journal.book_id = line.book_id
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE role.role = 'sales_tax_payable'
      AND journal.posting_date >= starts_on
      AND journal.posting_date <= ends_on
      AND journal.source_type IN ('sale', 'item_return');
$$;

CREATE FUNCTION public.stall_sales_tax_sheet(starts_on date, ends_on date)
    RETURNS TABLE (
        section text,
        label text,
        sale_count bigint,
        items_sold bigint,
        subtotal_minor bigint,
        discount_minor bigint,
        tax_minor bigint,
        total_minor bigint
    )
    LANGUAGE sql
    STABLE
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
            item_return.price_minor * item_return.quantity AS gross_minor,
            item_return.discount_minor + item_return.store_discount_minor AS discount_minor,
            item_return.tax_minor
        FROM public.item_returns AS item_return
        JOIN public.journals AS journal ON journal.id = item_return.journal_id
        WHERE journal.source_type = 'item_return'
          AND journal.posting_date >= starts_on
          AND journal.posting_date <= ends_on
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
    SELECT 'void', 'Voids', count(DISTINCT journal.id)::bigint, 0,
           0, 0,
           (-COALESCE(sum(line.debit_minor - line.credit_minor), 0))::bigint,
           (-COALESCE(sum(line.debit_minor - line.credit_minor), 0))::bigint
    FROM public.journals AS journal
    JOIN public.journal_lines AS line ON line.journal_id = journal.id
    JOIN public.chart_roles AS role ON role.account_id = line.account_id AND role.role = 'sales_tax_payable'
    WHERE journal.source_type = 'sale'
      AND journal.is_reversal
      AND journal.posting_date >= starts_on
      AND journal.posting_date <= ends_on
    UNION ALL
    SELECT 'rate', sale.rate_bps::text, count(DISTINCT sale.id)::bigint, COALESCE(sum(sale.quantity), 0)::bigint,
           COALESCE(sum(sale.gross_minor - sale.discount_minor), 0)::bigint, 0,
           COALESCE(sum(sale.tax_minor), 0)::bigint,
           COALESCE(sum(sale.gross_minor - sale.discount_minor + sale.tax_minor), 0)::bigint
    FROM sale
    WHERE sale.exempt_reason = ''
    GROUP BY sale.rate_bps
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
    SELECT 'liability', 'Opening', 0, 0, 0, 0,
           COALESCE(sum(line.credit_minor - line.debit_minor), 0)::bigint, 0
    FROM public.journal_lines AS line
    JOIN public.journals AS journal ON journal.id = line.journal_id
    JOIN public.chart_roles AS role ON role.account_id = line.account_id AND role.role = 'sales_tax_payable'
    WHERE journal.posting_date < starts_on
    UNION ALL
    SELECT 'liability', 'Collected', 0, 0, 0, 0, public.stall_sales_tax_collected(starts_on, ends_on), 0
    UNION ALL
    SELECT 'liability', 'Paid', 0, 0, 0, 0,
           COALESCE(sum(line.debit_minor - line.credit_minor), 0)::bigint, 0
    FROM public.journal_lines AS line
    JOIN public.journals AS journal ON journal.id = line.journal_id
    JOIN public.chart_roles AS role ON role.account_id = line.account_id AND role.role = 'sales_tax_payable'
    WHERE journal.source_type = 'tax_payment'
      AND journal.posting_date >= starts_on
      AND journal.posting_date <= ends_on
    UNION ALL
    SELECT 'liability', 'Closing', 0, 0, 0, 0,
           (
               COALESCE((
                   SELECT sum(line.credit_minor - line.debit_minor)
                   FROM public.journal_lines AS line
                   JOIN public.journals AS journal ON journal.id = line.journal_id
                   JOIN public.chart_roles AS role ON role.account_id = line.account_id AND role.role = 'sales_tax_payable'
                   WHERE journal.posting_date <= ends_on
               ), 0)
           )::bigint,
           0;
$$;

CREATE FUNCTION public.stall_refuse_tax_pair(sheet_minor bigint, books_minor bigint) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF sheet_minor IS DISTINCT FROM books_minor THEN
        RAISE EXCEPTION 'sales tax disagrees with the books'
            USING ERRCODE = '23514';
    END IF;
END;
$$;

CREATE FUNCTION public.stall_refuse_tax_drift(starts_on date, ends_on date) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    sheet_minor bigint;
    books_minor bigint;
BEGIN
    SELECT COALESCE(sum(row.tax_minor), 0) INTO sheet_minor
    FROM public.stall_sales_tax_sheet(starts_on, ends_on) AS row
    WHERE row.section IN ('taxable', 'exempt', 'return', 'void');
    books_minor := public.stall_sales_tax_collected(starts_on, ends_on);
    PERFORM public.stall_refuse_tax_pair(sheet_minor, books_minor);
END;
$$;

CREATE FUNCTION public.stall_report_sales(starts_on date, ends_on date, grouped text)
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
    GROUP BY role.role;
$$;

CREATE FUNCTION public.stall_report_rent()
    RETURNS TABLE (booth text, vendor text, open_minor bigint)
    LANGUAGE sql
    STABLE
    SET search_path TO 'pg_catalog'
    AS $$
    SELECT booth.code, COALESCE(vendor.name, ''),
           COALESCE(sum(line.debit_minor - line.credit_minor), 0)::bigint
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id AND role.role = 'booth_rent_receivable'
    JOIN public.booths AS booth ON booth.id = line.subledger_ref::uuid
    LEFT JOIN public.booth_assignments AS assignment
      ON assignment.booth_id = booth.id AND assignment.ends_on IS NULL
    LEFT JOIN public.vendors AS vendor ON vendor.id = assignment.vendor_id
    GROUP BY booth.code, vendor.name
    HAVING COALESCE(sum(line.debit_minor - line.credit_minor), 0) <> 0
    ORDER BY booth.code;
$$;

CREATE FUNCTION public.stall_report_payouts(starts_on date, ends_on date)
    RETURNS TABLE (paid_on date, vendor text, amount_minor bigint, kind text)
    LANGUAGE sql
    STABLE
    SET search_path TO 'pg_catalog'
    AS $$
    SELECT journal.posting_date, COALESCE(vendor.name, ''),
           COALESCE(sum(line.debit_minor), 0)::bigint, journal.source_type
    FROM public.journals AS journal
    JOIN public.journal_lines AS line ON line.journal_id = journal.id
    JOIN public.chart_roles AS role ON role.account_id = line.account_id AND role.role = 'vendor_payable'
    LEFT JOIN public.vendors AS vendor ON vendor.id = line.subledger_ref::uuid
    WHERE journal.source_type IN ('check_disbursement', 'cash_disbursement', 'safe_disbursement')
      AND journal.posting_date >= starts_on
      AND journal.posting_date <= ends_on
    GROUP BY journal.posting_date, vendor.name, journal.source_type, journal.id
    ORDER BY journal.posting_date, vendor.name;
$$;

CREATE FUNCTION public.stall_report_stored_value()
    RETURNS TABLE (kind text, balance_minor bigint)
    LANGUAGE sql
    STABLE
    SET search_path TO 'pg_catalog'
    AS $$
    SELECT role.role, COALESCE(balance.balance_minor, 0)::bigint
    FROM public.chart_roles AS role
    JOIN public.account_balances AS balance ON balance.account_id = role.account_id
    WHERE role.role IN ('gift_card', 'gift_certificate', 'store_credit')
    ORDER BY role.role;
$$;

CREATE FUNCTION public.stall_refuse_role_drift(role_name text) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    from_lines bigint;
    from_view bigint;
    normal text;
BEGIN
    SELECT account.normal_balance INTO normal
    FROM public.chart_roles AS role
    JOIN public.accounts AS account ON account.id = role.account_id
    WHERE role.role = role_name;
    SELECT COALESCE(sum(
        CASE normal
            WHEN 'debit' THEN line.debit_minor - line.credit_minor
            ELSE line.credit_minor - line.debit_minor
        END
    ), 0) INTO from_lines
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE role.role = role_name;
    SELECT COALESCE(sum(balance.balance_minor), 0) INTO from_view
    FROM public.account_balances AS balance
    JOIN public.chart_roles AS role ON role.account_id = balance.account_id
    WHERE role.role = role_name;
    PERFORM public.stall_refuse_report_drift(from_lines, from_view);
END;
$$;

CREATE TABLE public.saved_reports (
    id uuid DEFAULT uuidv7() NOT NULL,
    name text NOT NULL,
    source text NOT NULL,
    grouped text NOT NULL,
    filter_text text DEFAULT ''::text NOT NULL,
    CONSTRAINT saved_reports_pkey PRIMARY KEY (id),
    CONSTRAINT saved_reports_name_key UNIQUE (name),
    CONSTRAINT saved_reports_name_chk CHECK (((name = btrim(name)) AND (name <> '') AND (char_length(name) <= 80) AND (lower(name) <> 'sales tax'))),
    CONSTRAINT saved_reports_source_chk CHECK ((source = ANY (ARRAY['sales'::text, 'rent'::text, 'payouts'::text, 'stored_value'::text]))),
    CONSTRAINT saved_reports_grouped_chk CHECK ((grouped = ANY (ARRAY['day'::text, 'tender'::text, 'vendor'::text, 'cashier'::text, 'none'::text])))
);

CREATE TABLE public.mall_sales (
    id uuid DEFAULT uuidv7() NOT NULL,
    name text NOT NULL,
    starts_on date NOT NULL,
    ends_on date NOT NULL,
    percent_bps integer NOT NULL,
    absorbed_by text NOT NULL,
    CONSTRAINT mall_sales_pkey PRIMARY KEY (id),
    CONSTRAINT mall_sales_name_chk CHECK (((name = btrim(name)) AND (name <> '') AND (char_length(name) <= 80))),
    CONSTRAINT mall_sales_span_chk CHECK ((starts_on <= ends_on)),
    CONSTRAINT mall_sales_percent_chk CHECK (((percent_bps > 0) AND (percent_bps <= 10000))),
    CONSTRAINT mall_sales_absorbed_chk CHECK ((absorbed_by = ANY (ARRAY['mall'::text, 'vendor'::text])))
);

CREATE TABLE public.floor_findings (
    id uuid DEFAULT uuidv7() NOT NULL,
    item_id uuid NOT NULL,
    kind text NOT NULL,
    noted_on date NOT NULL,
    noted_by text NOT NULL,
    CONSTRAINT floor_findings_pkey PRIMARY KEY (id),
    CONSTRAINT floor_findings_kind_chk CHECK ((kind = ANY (ARRAY['missing'::text, 'misplaced'::text]))),
    CONSTRAINT floor_findings_by_chk CHECK (((noted_by = btrim(noted_by)) AND (noted_by <> '') AND (char_length(noted_by) <= 80)))
);

CREATE TABLE public.pickups (
    id uuid DEFAULT uuidv7() NOT NULL,
    item_id uuid NOT NULL,
    vendor_id uuid NOT NULL,
    picked_up_on date NOT NULL,
    received_by text NOT NULL,
    CONSTRAINT pickups_pkey PRIMARY KEY (id),
    CONSTRAINT pickups_by_chk CHECK (((received_by = btrim(received_by)) AND (received_by <> '') AND (char_length(received_by) <= 80)))
);

ALTER TABLE public.floor_findings
    ADD CONSTRAINT floor_findings_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.items(id);
ALTER TABLE public.pickups
    ADD CONSTRAINT pickups_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.items(id);
ALTER TABLE public.pickups
    ADD CONSTRAINT pickups_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendors(id);

ALTER TABLE public.vendors
    ADD COLUMN discount_by text DEFAULT 'staff'::text NOT NULL,
    ADD COLUMN keep_days integer,
    ADD COLUMN expiry_action text DEFAULT 'return'::text NOT NULL,
    ADD COLUMN email_bounced boolean DEFAULT false NOT NULL,
    ADD CONSTRAINT vendors_discount_by_chk CHECK ((discount_by = ANY (ARRAY['staff'::text, 'owner'::text]))),
    ADD CONSTRAINT vendors_keep_days_chk CHECK (((keep_days IS NULL) OR ((keep_days >= 1) AND (keep_days <= 3650)))),
    ADD CONSTRAINT vendors_expiry_action_chk CHECK ((expiry_action = ANY (ARRAY['return'::text, 'donate'::text, 'buy'::text])));

ALTER TABLE public.staff
    ADD COLUMN discount_ceiling_bps integer DEFAULT 10000 NOT NULL,
    ADD COLUMN tax_free_ceiling_minor bigint DEFAULT 100000000 NOT NULL,
    ADD CONSTRAINT staff_discount_ceiling_chk CHECK (((discount_ceiling_bps >= 0) AND (discount_ceiling_bps <= 10000))),
    ADD CONSTRAINT staff_tax_free_ceiling_chk CHECK ((tax_free_ceiling_minor >= 0));

GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.saved_reports TO stall_poster;
GRANT SELECT, INSERT, DELETE ON TABLE public.mall_sales TO stall_poster;
GRANT SELECT, INSERT ON TABLE public.floor_findings TO stall_poster;
GRANT SELECT, INSERT ON TABLE public.pickups TO stall_poster;
