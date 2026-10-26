-- Single-vendor payout preview. Vendor pages must not rebuild the whole mall pack.

CREATE OR REPLACE FUNCTION public.stall_pack_vendor_id() RETURNS uuid
    LANGUAGE sql
    STABLE
    SET search_path TO 'pg_catalog'
    AS $$
    SELECT CASE
        WHEN current_setting('stall.pack_vendor', true) ~ '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'
        THEN current_setting('stall.pack_vendor', true)::uuid
        ELSE NULL
    END
$$;

CREATE OR REPLACE VIEW public.payout_missing_rent AS
 SELECT run.id AS run_id,
    vendor.id AS vendor_id,
    assignment.booth_id,
    (series.period_start)::date AS month_start,
    GREATEST((series.period_start)::date, assignment.starts_on) AS posting_date,
        CASE
            WHEN ((assignment.starts_on > (series.period_start)::date) AND (assignment.starts_on <= public.stall_rent_period_end((series.period_start)::date))) THEN ((((public.stall_rent_period_end((series.period_start)::date) - assignment.starts_on) + 1) * rate.amount_minor) / ((public.stall_rent_period_end((series.period_start)::date) - (series.period_start)::date) + 1))
            ELSE rate.amount_minor
        END AS amount
   FROM ((((public.stall_pack_runs() run(id, sales_from, sales_to, rent_month, posting_date)
     JOIN public.vendors vendor ON ((vendor.active AND ((public.stall_pack_vendor_id() IS NULL) OR (vendor.id = public.stall_pack_vendor_id())))))
     JOIN public.booth_assignments assignment ON ((assignment.vendor_id = vendor.id)))
     JOIN LATERAL generate_series((public.stall_rent_period_start(assignment.starts_on))::timestamp without time zone, (public.stall_rent_period_start(LEAST(COALESCE(assignment.ends_on, run.posting_date), run.posting_date)))::timestamp without time zone, '1 mon'::interval) series(period_start) ON ((assignment.starts_on <= LEAST(COALESCE(assignment.ends_on, run.posting_date), run.posting_date))))
     JOIN public.assignment_rent rate ON ((rate.assignment_id = assignment.id)))
  WHERE (( SELECT mall.booth_rentals_enabled
           FROM public.mall) AND (NOT (EXISTS ( SELECT 1
           FROM public.journals journal
          WHERE ((journal.source_type = 'rent_charge'::text) AND (journal.posting_key = ((('rent-charge:'::text || (assignment.booth_id)::text) || ':'::text) || public.stall_rent_charge_suffix((series.period_start)::date))) AND (NOT (EXISTS ( SELECT 1
                   FROM public.journals reversal
                  WHERE (reversal.reverses_journal_id = journal.id)))))))));

CREATE OR REPLACE VIEW public.payout_pack_sales AS
 SELECT run.id AS run_id,
    item.vendor_id,
    journal.id AS journal_id,
    sale.line_no,
    sale.item_id,
    journal.posting_date AS sold_on,
    item.code AS item_code,
    item.description,
    sale.price_minor,
    sale.commission_minor,
    sale.tax_minor,
    sale.discount_minor,
    sale.quantity
   FROM (((public.stall_pack_runs() run(id, sales_from, sales_to, rent_month, posting_date)
     JOIN public.journals journal ON ((((journal.posting_date >= run.sales_from) AND (journal.posting_date <= run.sales_to)) AND (journal.source_type = 'sale'::text) AND (NOT journal.is_reversal))))
     JOIN public.sale_lines sale ON ((sale.journal_id = journal.id)))
     JOIN public.items item ON ((item.id = sale.item_id)))
  WHERE ((NOT (EXISTS ( SELECT 1
           FROM public.payout_run_journals link
          WHERE ((link.run_id = run.id) AND (link.journal_id = journal.id))))) AND (journal.posting_key !~~ (('settlement:'::text || (run.id)::text) || ':%'::text)) AND (journal.posting_key !~~ (('disbursement:'::text || (run.id)::text) || ':%'::text)) AND ((public.stall_pack_vendor_id() IS NULL) OR (item.vendor_id = public.stall_pack_vendor_id())));

CREATE OR REPLACE VIEW public.payout_pack AS
 WITH params AS (
         SELECT run.id AS run_id,
            run.sales_from,
            run.sales_to,
            run.rent_month,
            run.posting_date,
            vendor.id AS vendor_id,
            vendor.code,
            btrim(((vendor.first_name || ' '::text) || vendor.last_name)) AS name,
            vendor.payout_mode AS mode
           FROM (public.stall_pack_runs() run(id, sales_from, sales_to, rent_month, posting_date)
             JOIN public.vendors vendor ON ((vendor.active AND ((public.stall_pack_vendor_id() IS NULL) OR (vendor.id = public.stall_pack_vendor_id())))))
        ), own AS (
         SELECT run.id AS run_id,
            journal.id AS journal_id
           FROM (public.stall_pack_runs() run(id, sales_from, sales_to, rent_month, posting_date)
             JOIN public.journals journal ON (((journal.posting_key ~~ (('settlement:'::text || (run.id)::text) || ':%'::text)) OR (journal.posting_key ~~ (('disbursement:'::text || (run.id)::text) || ':%'::text)))))
        UNION
         SELECT link.run_id,
            link.journal_id
           FROM public.payout_run_journals link
        ), pay AS (
         SELECT params.run_id,
            params.vendor_id,
            COALESCE(sum((line.credit_minor - line.debit_minor)) FILTER (WHERE (journal.posting_date < params.sales_from)), (0)::numeric) AS brought_forward_minor,
            COALESCE(sum((line.credit_minor - line.debit_minor)) FILTER (WHERE (journal.posting_date >= params.sales_from)), (0)::numeric) AS movement_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE ((journal.source_type = 'item_return'::text) AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS return_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE ((journal.source_type = 'sale'::text) AND journal.is_reversal AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS void_minor,
            COALESCE(sum(line.debit_minor) FILTER (WHERE ((journal.source_type = 'sale'::text) AND (NOT journal.is_reversal) AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS draw_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE ((journal.source_type = ANY (ARRAY['check_disbursement'::text, 'cash_disbursement'::text, 'safe_disbursement'::text])) AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS check_paid_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE ((journal.source_type = 'settlement'::text) AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS settlement_applied_minor
           FROM (((params
             JOIN public.journal_lines line ON (((line.subledger_type = 'vendor'::text) AND (line.subledger_ref = (params.vendor_id)::text))))
             JOIN public.journals journal ON ((journal.id = line.journal_id)))
             JOIN public.chart_roles role ON (((role.account_id = line.account_id) AND (role.role = 'vendor_payable'::text))))
          WHERE (NOT (EXISTS ( SELECT 1
                   FROM own
                  WHERE ((own.run_id = params.run_id) AND (own.journal_id = journal.id)))))
          GROUP BY params.run_id, params.vendor_id
        ), sale AS (
         SELECT payout_pack_sales.run_id,
            payout_pack_sales.vendor_id,
            COALESCE(sum((payout_pack_sales.price_minor * payout_pack_sales.quantity)), (0)::numeric) AS sales_minor,
            COALESCE(sum(payout_pack_sales.discount_minor), (0)::numeric) AS discount_minor,
            COALESCE(sum(payout_pack_sales.commission_minor), (0)::numeric) AS commission_minor,
            COALESCE(sum(payout_pack_sales.tax_minor), (0)::numeric) AS tax_minor
           FROM public.payout_pack_sales
          GROUP BY payout_pack_sales.run_id, payout_pack_sales.vendor_id
        ), rent_posted AS (
         SELECT params.run_id,
            params.vendor_id,
            COALESCE(sum((line.debit_minor - line.credit_minor)), (0)::numeric) AS total_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE (journal.posting_date < params.sales_from)), (0)::numeric) AS before_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE ((journal.source_type = 'rent_charge'::text) AND (public.stall_rent_period_start(journal.posting_date) = params.rent_month))), (0)::numeric) AS month_minor,
            COALESCE(sum((line.credit_minor - line.debit_minor)) FILTER (WHERE ((journal.source_type = 'rent_payment'::text) AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS paid_minor,
            COALESCE(sum((line.credit_minor - line.debit_minor)) FILTER (WHERE ((journal.source_type = 'settlement'::text) AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS settlement_minor
           FROM (((params
             JOIN public.journal_lines line ON ((line.subledger_type = 'booth'::text)))
             JOIN public.journals journal ON ((journal.id = line.journal_id)))
             JOIN public.chart_roles role ON (((role.account_id = line.account_id) AND (role.role = 'booth_rent_receivable'::text))))
          WHERE ((EXISTS ( SELECT 1
                   FROM public.booth_assignments assignment
                  WHERE ((assignment.vendor_id = params.vendor_id) AND (assignment.booth_id = (line.subledger_ref)::uuid) AND (assignment.assignment_range @> journal.posting_date)))) AND (NOT (EXISTS ( SELECT 1
                   FROM own
                  WHERE ((own.run_id = params.run_id) AND (own.journal_id = journal.id))))))
          GROUP BY params.run_id, params.vendor_id
        ), missing_month AS (
         SELECT payout_missing_rent.run_id,
            payout_missing_rent.vendor_id,
            payout_missing_rent.month_start,
            payout_missing_rent.posting_date,
            payout_missing_rent.amount
           FROM public.payout_missing_rent
        ), missing AS (
         SELECT missing_month.run_id,
            missing_month.vendor_id,
            COALESCE(sum(missing_month.amount), (0)::numeric) AS total_minor,
            COALESCE(sum(missing_month.amount) FILTER (WHERE (missing_month.posting_date < params.sales_from)), (0)::numeric) AS before_minor,
            COALESCE(sum(missing_month.amount) FILTER (WHERE (missing_month.month_start = params.rent_month)), (0)::numeric) AS month_minor
           FROM (missing_month
             JOIN params ON (((params.run_id = missing_month.run_id) AND (params.vendor_id = missing_month.vendor_id))))
          GROUP BY missing_month.run_id, missing_month.vendor_id
        ), own_rent AS (
         SELECT params.run_id,
            params.vendor_id,
            COALESCE(sum((line.debit_minor - line.credit_minor)), (0)::numeric) AS total_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE (journal.posting_date < params.sales_from)), (0)::numeric) AS before_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE ((journal.source_type = 'rent_charge'::text) AND (public.stall_rent_period_start(journal.posting_date) = params.rent_month))), (0)::numeric) AS month_minor
           FROM ((((params
             JOIN public.payout_run_journals link ON (((link.run_id = params.run_id) AND (link.role = 'rent_charge'::text))))
             JOIN public.journals journal ON ((journal.id = link.journal_id)))
             JOIN public.journal_lines line ON (((line.journal_id = journal.id) AND (line.subledger_type = 'booth'::text))))
             JOIN public.chart_roles role ON (((role.account_id = line.account_id) AND (role.role = 'booth_rent_receivable'::text))))
          WHERE (EXISTS ( SELECT 1
                   FROM public.booth_assignments assignment
                  WHERE ((assignment.vendor_id = params.vendor_id) AND (assignment.booth_id = (line.subledger_ref)::uuid) AND (assignment.assignment_range @> journal.posting_date))))
          GROUP BY params.run_id, params.vendor_id
        ), figures AS (
         SELECT params.run_id,
            params.vendor_id,
            params.code,
            params.name,
            params.mode,
            params.posting_date,
            COALESCE(sale.sales_minor, (0)::numeric) AS sales_minor,
            COALESCE(sale.commission_minor, (0)::numeric) AS commission_minor,
            COALESCE(sale.tax_minor, (0)::numeric) AS tax_minor,
            COALESCE(sale.discount_minor, (0)::numeric) AS discount_minor,
            COALESCE(pay.return_minor, (0)::numeric) AS return_minor,
            COALESCE(pay.void_minor, (0)::numeric) AS void_minor,
            COALESCE(pay.draw_minor, (0)::numeric) AS draw_minor,
            COALESCE(pay.check_paid_minor, (0)::numeric) AS check_paid_minor,
            COALESCE(pay.settlement_applied_minor, (0)::numeric) AS settlement_applied_minor,
            COALESCE(pay.brought_forward_minor, (0)::numeric) AS brought_forward_minor,
            (COALESCE(pay.brought_forward_minor, (0)::numeric) + COALESCE(pay.movement_minor, (0)::numeric)) AS carried_payable_minor,
            ((COALESCE(rent_posted.before_minor, (0)::numeric) + COALESCE(missing.before_minor, (0)::numeric)) + COALESCE(own_rent.before_minor, (0)::numeric)) AS rent_brought_forward_minor,
            ((COALESCE(rent_posted.month_minor, (0)::numeric) + COALESCE(missing.month_minor, (0)::numeric)) + COALESCE(own_rent.month_minor, (0)::numeric)) AS rent_minor,
            COALESCE(rent_posted.paid_minor, (0)::numeric) AS rent_paid_since_minor,
            COALESCE(rent_posted.settlement_minor, (0)::numeric) AS rent_settlement_minor,
            ((COALESCE(rent_posted.total_minor, (0)::numeric) + COALESCE(missing.total_minor, (0)::numeric)) + COALESCE(own_rent.total_minor, (0)::numeric)) AS rent_total_minor
           FROM (((((params
             LEFT JOIN pay ON (((pay.run_id = params.run_id) AND (pay.vendor_id = params.vendor_id))))
             LEFT JOIN sale ON (((sale.run_id = params.run_id) AND (sale.vendor_id = params.vendor_id))))
             LEFT JOIN rent_posted ON (((rent_posted.run_id = params.run_id) AND (rent_posted.vendor_id = params.vendor_id))))
             LEFT JOIN missing ON (((missing.run_id = params.run_id) AND (missing.vendor_id = params.vendor_id))))
             LEFT JOIN own_rent ON (((own_rent.run_id = params.run_id) AND (own_rent.vendor_id = params.vendor_id))))
        )
 SELECT figures.run_id,
    figures.vendor_id,
    figures.code,
    figures.name,
    COALESCE(spot.booth_code, ''::text) AS booth,
    figures.mode,
    figures.sales_minor,
    figures.commission_minor,
    figures.tax_minor,
    figures.discount_minor,
    figures.return_minor,
    figures.void_minor,
    figures.draw_minor,
    figures.check_paid_minor,
    figures.settlement_applied_minor,
    figures.rent_minor,
    ((((figures.rent_total_minor - figures.rent_brought_forward_minor) - figures.rent_minor) + figures.rent_paid_since_minor) + figures.rent_settlement_minor) AS rent_charged_minor,
    figures.rent_brought_forward_minor,
    figures.rent_paid_since_minor,
    ((figures.carried_payable_minor - figures.brought_forward_minor) - (((((((figures.sales_minor - figures.discount_minor) - figures.commission_minor) - figures.return_minor) - figures.void_minor) - figures.draw_minor) - figures.check_paid_minor) - figures.settlement_applied_minor)) AS adjustment_minor,
    figures.brought_forward_minor,
    figures.carried_payable_minor,
    figures.rent_total_minor AS carried_rent_minor,
        CASE
            WHEN (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) THEN LEAST(GREATEST(figures.carried_payable_minor, (0)::numeric), GREATEST(figures.rent_total_minor, (0)::numeric))
            ELSE (0)::numeric
        END AS settled_minor,
        CASE
            WHEN (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) THEN GREATEST((figures.carried_payable_minor - figures.rent_total_minor), (0)::numeric)
            ELSE GREATEST(figures.carried_payable_minor, (0)::numeric)
        END AS check_minor,
        CASE
            WHEN (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) THEN GREATEST((figures.rent_total_minor - figures.carried_payable_minor), (0)::numeric)
            ELSE GREATEST(figures.rent_total_minor, (0)::numeric)
        END AS owes_minor,
        CASE
            WHEN (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) THEN (figures.carried_payable_minor - figures.rent_total_minor)
            ELSE figures.carried_payable_minor
        END AS net_minor,
    figures.rent_brought_forward_minor AS prior,
    (((figures.rent_total_minor - figures.rent_brought_forward_minor) + figures.rent_paid_since_minor) + figures.rent_settlement_minor) AS charged,
    (figures.rent_paid_since_minor + figures.rent_settlement_minor) AS paid,
    figures.carried_payable_minor AS payable,
    figures.rent_total_minor AS rent,
        CASE
            WHEN (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) THEN GREATEST((figures.carried_payable_minor - figures.rent_total_minor), (0)::numeric)
            ELSE GREATEST(figures.carried_payable_minor, (0)::numeric)
        END AS "check",
        CASE
            WHEN (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) THEN GREATEST((figures.rent_total_minor - figures.carried_payable_minor), (0)::numeric)
            ELSE GREATEST(figures.rent_total_minor, (0)::numeric)
        END AS owes,
    (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) AS rent_from_sales,
    spot.booth_id
   FROM ((figures
     CROSS JOIN public.mall mall_settings)
     LEFT JOIN LATERAL ( SELECT booth.id AS booth_id,
            booth.code AS booth_code
           FROM (public.booth_assignments assignment
             JOIN public.booths booth ON ((booth.id = assignment.booth_id)))
          WHERE ((assignment.vendor_id = figures.vendor_id) AND (assignment.assignment_range @> figures.posting_date))
          ORDER BY booth.code
         LIMIT 1) spot ON (true));

CREATE OR REPLACE FUNCTION public.stall_payout_pack(target_run uuid, window_from date, window_to date, window_rent date, window_posting date) RETURNS SETOF public.payout_pack
    LANGUAGE plpgsql
    AS $$
DECLARE
    preview_id uuid := '00000000-0000-0000-0000-000000000000';
BEGIN
    PERFORM set_config('stall.pack_vendor', '', true);
    PERFORM set_config('stall.pack_from', window_from::text, true);
    PERFORM set_config('stall.pack_to', window_to::text, true);
    PERFORM set_config('stall.pack_rent', COALESCE(window_rent::text, ''), true);
    PERFORM set_config('stall.pack_posting', window_posting::text, true);
    RETURN QUERY
        SELECT *
        FROM payout_pack
        WHERE run_id = COALESCE(target_run, preview_id);
    PERFORM set_config('stall.pack_from', '', true);
    PERFORM set_config('stall.pack_to', '', true);
    PERFORM set_config('stall.pack_rent', '', true);
    PERFORM set_config('stall.pack_posting', '', true);
    PERFORM set_config('stall.pack_vendor', '', true);
END;
$$;

CREATE OR REPLACE FUNCTION public.stall_payout_quote(target_vendor uuid, window_from date, window_to date, window_rent date, window_posting date) RETURNS SETOF public.payout_pack
    LANGUAGE plpgsql
    AS $$
DECLARE
    preview_id uuid := '00000000-0000-0000-0000-000000000000';
BEGIN
    IF target_vendor IS NULL THEN
        RAISE EXCEPTION 'a payout quote needs a vendor'
            USING ERRCODE = '23514';
    END IF;
    PERFORM set_config('stall.pack_vendor', target_vendor::text, true);
    PERFORM set_config('stall.pack_from', window_from::text, true);
    PERFORM set_config('stall.pack_to', window_to::text, true);
    PERFORM set_config('stall.pack_rent', COALESCE(window_rent::text, ''), true);
    PERFORM set_config('stall.pack_posting', window_posting::text, true);
    RETURN QUERY
        SELECT *
        FROM payout_pack
        WHERE run_id = preview_id
          AND vendor_id = target_vendor;
    PERFORM set_config('stall.pack_from', '', true);
    PERFORM set_config('stall.pack_to', '', true);
    PERFORM set_config('stall.pack_rent', '', true);
    PERFORM set_config('stall.pack_posting', '', true);
    PERFORM set_config('stall.pack_vendor', '', true);
END;
$$;

REVOKE ALL ON FUNCTION public.stall_pack_vendor_id() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.stall_payout_quote(uuid, date, date, date, date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.stall_pack_vendor_id() TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_pack_vendor_id() TO stall_backup;
GRANT EXECUTE ON FUNCTION public.stall_payout_quote(uuid, date, date, date, date) TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_payout_quote(uuid, date, date, date, date) TO stall_backup;
