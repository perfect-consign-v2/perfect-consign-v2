-- Booth rent can be off, and a rent period can start on a day other than the 1st.
-- Day 1 stays a calendar month. A posted rent charge freezes the cycle day.
-- Apply inside each tenant schema, after 058. The provisioner rewrites public. and stall_poster.

ALTER TABLE mall
    ADD COLUMN booth_rentals_enabled boolean NOT NULL DEFAULT true;

ALTER TABLE mall
    ADD COLUMN rent_cycle_day smallint NOT NULL DEFAULT 1;

ALTER TABLE mall
    ADD CONSTRAINT mall_rent_cycle_day_chk CHECK (rent_cycle_day >= 1 AND rent_cycle_day <= 28);

CREATE FUNCTION stall_rent_cycle_day()
RETURNS integer
LANGUAGE sql
STABLE
AS $$
    SELECT rent_cycle_day FROM public.mall
$$;

CREATE FUNCTION stall_rent_period_start(on_date date)
RETURNS date
LANGUAGE plpgsql
STABLE
AS $$
DECLARE
    cycle_day integer;
    month_start date;
    anchor date;
BEGIN
    cycle_day := public.stall_rent_cycle_day();
    IF cycle_day = 1 THEN
        RETURN date_trunc('month', on_date)::date;
    END IF;
    month_start := date_trunc('month', on_date)::date;
    anchor := month_start + (cycle_day - 1);
    IF on_date >= anchor THEN
        RETURN anchor;
    END IF;
    RETURN ((month_start - interval '1 month')::date + (cycle_day - 1));
END;
$$;

CREATE FUNCTION stall_rent_period_end(period_start date)
RETURNS date
LANGUAGE sql
STABLE
AS $$
    SELECT (period_start + interval '1 month' - interval '1 day')::date
$$;

CREATE FUNCTION stall_rent_charge_suffix(period_start date)
RETURNS text
LANGUAGE sql
STABLE
AS $$
    SELECT CASE
        WHEN public.stall_rent_cycle_day() = 1 THEN to_char(period_start, 'YYYY-MM')
        ELSE to_char(period_start, 'YYYY-MM-DD')
    END
$$;

CREATE OR REPLACE FUNCTION stall_assert_rent_charge()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog
AS $$
DECLARE
    booth_ref text;
    rate_minor bigint;
    assign_start date;
    charge_start date;
    charge_end date;
    days_in_period integer;
    days_charged integer;
    line_count integer;
    debit_ok integer;
    credit_ok integer;
BEGIN
    IF NEW.source_type <> 'rent_charge' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    IF NOT (SELECT booth_rentals_enabled FROM public.mall) THEN
        RAISE EXCEPTION 'booth rentals are turned off'
            USING ERRCODE = '23514';
    END IF;

    SELECT line.subledger_ref INTO booth_ref
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'booth_rent_receivable'
      AND line.debit_minor > 0
      AND line.subledger_type = 'booth';

    charge_start := public.stall_rent_period_start(NEW.posting_date);
    IF booth_ref IS NULL
       OR NEW.posting_key <> 'rent-charge:' || booth_ref || ':' || public.stall_rent_charge_suffix(charge_start) THEN
        RAISE EXCEPTION 'a rent charge posting key is one booth and one month'
            USING ERRCODE = '23514';
    END IF;

    SELECT assignment.starts_on, contract.amount_minor
    INTO assign_start, rate_minor
    FROM public.booth_assignments AS assignment
    JOIN public.assignment_rent AS contract ON contract.assignment_id = assignment.id
    WHERE assignment.booth_id = booth_ref::uuid
      AND assignment.assignment_range @> NEW.posting_date
    ORDER BY assignment.starts_on DESC
    LIMIT 1
    FOR UPDATE OF assignment, contract;

    IF assign_start IS NULL THEN
        IF EXISTS (
            SELECT 1
            FROM public.booth_assignments AS assignment
            WHERE assignment.booth_id = booth_ref::uuid
              AND assignment.assignment_range @> NEW.posting_date
        ) THEN
            RAISE EXCEPTION 'a rent charge requires the booth rate from the assignment'
                USING ERRCODE = '23514';
        END IF;
        RAISE EXCEPTION 'a rent charge requires an assignment that covers the charge date'
            USING ERRCODE = '23514';
    END IF;

    charge_end := public.stall_rent_period_end(charge_start);
    IF assign_start > charge_start AND assign_start <= charge_end THEN
        days_in_period := (charge_end - charge_start) + 1;
        days_charged := (charge_end - assign_start) + 1;
        rate_minor := (days_charged * rate_minor) / days_in_period;
    END IF;

    SELECT count(*) INTO line_count
    FROM public.journal_lines
    WHERE journal_id = NEW.id;

    SELECT count(*) INTO debit_ok
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'booth_rent_receivable'
      AND line.debit_minor = rate_minor
      AND line.subledger_ref = booth_ref;

    SELECT count(*) INTO credit_ok
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'rent_revenue'
      AND line.credit_minor = rate_minor
      AND line.subledger_type IS NULL;

    IF line_count <> 2 OR debit_ok <> 1 OR credit_ok <> 1 THEN
        RAISE EXCEPTION 'a rent charge debits that booth receivable and credits rent revenue for the stored rate'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE FUNCTION stall_reject_rent_policy()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF NEW.rent_cycle_day IS DISTINCT FROM OLD.rent_cycle_day AND EXISTS (
        SELECT 1
        FROM public.journals AS posted
        WHERE posted.source_type = 'rent_charge'
          AND NOT posted.is_reversal
          AND NOT EXISTS (
              SELECT 1
              FROM public.journals AS reversal
              WHERE reversal.reverses_journal_id = posted.id
          )
    ) THEN
        RAISE EXCEPTION 'the rent cycle day cannot change after a rent charge is posted'
            USING ERRCODE = '23514';
    END IF;

    IF OLD.booth_rentals_enabled AND NOT NEW.booth_rentals_enabled AND EXISTS (
        SELECT 1
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE role.role = 'booth_rent_receivable'
          AND line.subledger_type = 'booth'
        GROUP BY line.subledger_ref
        HAVING sum(line.debit_minor - line.credit_minor) > 0
    ) THEN
        RAISE EXCEPTION 'booth rentals stay on while a booth still owes rent'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER mall_rent_policy
    BEFORE UPDATE OF rent_cycle_day, booth_rentals_enabled ON mall
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_rent_policy();

ALTER TABLE payout_runs DROP CONSTRAINT payout_runs_rent_chk;

CREATE FUNCTION stall_reject_payout_rent_month()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.rent_month IS NOT DISTINCT FROM OLD.rent_month THEN
        RETURN NEW;
    END IF;
    IF NEW.rent_month IS NOT NULL AND NEW.rent_month IS DISTINCT FROM public.stall_rent_period_start(NEW.rent_month) THEN
        RAISE EXCEPTION 'a payout rent month is the start of a rent period'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER payout_runs_rent_period
    BEFORE INSERT OR UPDATE OF rent_month ON payout_runs
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_payout_rent_month();

CREATE OR REPLACE VIEW payout_missing_rent AS
SELECT
    run.id AS run_id,
    vendor.id AS vendor_id,
    assignment.booth_id,
    series.period_start::date AS month_start,
    GREATEST(series.period_start::date, assignment.starts_on) AS posting_date,
    CASE
        WHEN assignment.starts_on > series.period_start::date
         AND assignment.starts_on <= stall_rent_period_end(series.period_start::date)
        THEN (
            ((stall_rent_period_end(series.period_start::date) - assignment.starts_on) + 1) * rate.amount_minor
        ) / ((stall_rent_period_end(series.period_start::date) - series.period_start::date) + 1)
        ELSE rate.amount_minor
    END AS amount
FROM stall_pack_runs() AS run
JOIN vendors AS vendor ON vendor.active
JOIN booth_assignments AS assignment ON assignment.vendor_id = vendor.id
JOIN LATERAL generate_series(
    stall_rent_period_start(assignment.starts_on)::timestamp,
    stall_rent_period_start(LEAST(COALESCE(assignment.ends_on, run.posting_date), run.posting_date))::timestamp,
    interval '1 month'
) AS series(period_start) ON assignment.starts_on <= LEAST(COALESCE(assignment.ends_on, run.posting_date), run.posting_date)
JOIN assignment_rent AS rate ON rate.assignment_id = assignment.id
WHERE (SELECT booth_rentals_enabled FROM mall)
  AND NOT EXISTS (
    SELECT 1
    FROM journals AS journal
    WHERE journal.source_type = 'rent_charge'
      AND journal.posting_key = 'rent-charge:' || assignment.booth_id::text || ':' || stall_rent_charge_suffix(series.period_start::date)
      AND NOT EXISTS (
          SELECT 1 FROM journals AS reversal
          WHERE reversal.reverses_journal_id = journal.id
      )
);

GRANT EXECUTE ON FUNCTION stall_rent_cycle_day() TO stall_poster, stall_backup;
GRANT EXECUTE ON FUNCTION stall_rent_period_start(date) TO stall_poster, stall_backup;
GRANT EXECUTE ON FUNCTION stall_rent_period_end(date) TO stall_poster, stall_backup;
GRANT EXECUTE ON FUNCTION stall_rent_charge_suffix(date) TO stall_poster, stall_backup;

CREATE OR REPLACE VIEW payout_pack AS
WITH params AS (
    SELECT
        run.id AS run_id,
        run.sales_from,
        run.sales_to,
        run.rent_month,
        run.posting_date,
        vendor.id AS vendor_id,
        vendor.code,
        btrim(vendor.first_name || ' ' || vendor.last_name) AS name,
        vendor.payout_mode AS mode
    FROM stall_pack_runs() AS run
    JOIN vendors AS vendor ON vendor.active
),
own AS (
    SELECT run.id AS run_id, journal.id AS journal_id
    FROM stall_pack_runs() AS run
    JOIN journals AS journal
        ON journal.posting_key LIKE 'settlement:' || run.id::text || ':%'
        OR journal.posting_key LIKE 'disbursement:' || run.id::text || ':%'
    UNION
    SELECT link.run_id, link.journal_id
    FROM payout_run_journals AS link
),
pay AS (
    SELECT
        params.run_id,
        params.vendor_id,
        COALESCE(sum(line.credit_minor - line.debit_minor) FILTER (
            WHERE journal.posting_date < params.sales_from
        ), 0) AS brought_forward_minor,
        COALESCE(sum(line.credit_minor - line.debit_minor) FILTER (
            WHERE journal.posting_date >= params.sales_from
        ), 0) AS movement_minor,
        COALESCE(sum(line.debit_minor - line.credit_minor) FILTER (
            WHERE journal.source_type = 'item_return'
              AND journal.posting_date BETWEEN params.sales_from AND params.sales_to
        ), 0) AS return_minor,
        COALESCE(sum(line.debit_minor - line.credit_minor) FILTER (
            WHERE journal.source_type = 'sale'
              AND journal.is_reversal
              AND journal.posting_date BETWEEN params.sales_from AND params.sales_to
        ), 0) AS void_minor,
        COALESCE(sum(line.debit_minor) FILTER (
            WHERE journal.source_type = 'sale'
              AND NOT journal.is_reversal
              AND journal.posting_date BETWEEN params.sales_from AND params.sales_to
        ), 0) AS draw_minor,
        COALESCE(sum(line.debit_minor - line.credit_minor) FILTER (
            WHERE journal.source_type IN ('check_disbursement', 'cash_disbursement', 'safe_disbursement')
              AND journal.posting_date BETWEEN params.sales_from AND params.sales_to
        ), 0) AS check_paid_minor,
        COALESCE(sum(line.debit_minor - line.credit_minor) FILTER (
            WHERE journal.source_type = 'settlement'
              AND journal.posting_date BETWEEN params.sales_from AND params.sales_to
        ), 0) AS settlement_applied_minor
    FROM params
    JOIN journal_lines AS line
        ON line.subledger_type = 'vendor'
       AND line.subledger_ref = params.vendor_id::text
    JOIN journals AS journal ON journal.id = line.journal_id
    JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'vendor_payable'
    WHERE NOT EXISTS (
        SELECT 1 FROM own
        WHERE own.run_id = params.run_id AND own.journal_id = journal.id
    )
    GROUP BY params.run_id, params.vendor_id
),
sale AS (
    SELECT
        run_id,
        vendor_id,
        COALESCE(sum(price_minor * quantity), 0) AS sales_minor,
        COALESCE(sum(discount_minor), 0) AS discount_minor,
        COALESCE(sum(commission_minor), 0) AS commission_minor,
        COALESCE(sum(tax_minor), 0) AS tax_minor
    FROM payout_pack_sales
    GROUP BY run_id, vendor_id
),
rent_posted AS (
    SELECT
        params.run_id,
        params.vendor_id,
        COALESCE(sum(line.debit_minor - line.credit_minor), 0) AS total_minor,
        COALESCE(sum(line.debit_minor - line.credit_minor) FILTER (
            WHERE journal.posting_date < params.sales_from
        ), 0) AS before_minor,
        COALESCE(sum(line.debit_minor - line.credit_minor) FILTER (
            WHERE journal.source_type = 'rent_charge'
              AND stall_rent_period_start(journal.posting_date) = params.rent_month
        ), 0) AS month_minor,
        COALESCE(sum(line.credit_minor - line.debit_minor) FILTER (
            WHERE journal.source_type = 'rent_payment'
              AND journal.posting_date BETWEEN params.sales_from AND params.sales_to
        ), 0) AS paid_minor,
        COALESCE(sum(line.credit_minor - line.debit_minor) FILTER (
            WHERE journal.source_type = 'settlement'
              AND journal.posting_date BETWEEN params.sales_from AND params.sales_to
        ), 0) AS settlement_minor
    FROM params
    JOIN journal_lines AS line ON line.subledger_type = 'booth'
    JOIN journals AS journal ON journal.id = line.journal_id
    JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'booth_rent_receivable'
    WHERE EXISTS (
        SELECT 1
        FROM booth_assignments AS assignment
        WHERE assignment.vendor_id = params.vendor_id
          AND assignment.booth_id = line.subledger_ref::uuid
          AND assignment.assignment_range @> journal.posting_date
    )
    AND NOT EXISTS (
        SELECT 1 FROM own
        WHERE own.run_id = params.run_id AND own.journal_id = journal.id
    )
    GROUP BY params.run_id, params.vendor_id
),
missing_month AS (
    SELECT run_id, vendor_id, month_start, posting_date, amount
    FROM payout_missing_rent
),
missing AS (
    SELECT
        missing_month.run_id,
        missing_month.vendor_id,
        COALESCE(sum(missing_month.amount), 0) AS total_minor,
        COALESCE(sum(missing_month.amount) FILTER (
            WHERE missing_month.posting_date < params.sales_from
        ), 0) AS before_minor,
        COALESCE(sum(missing_month.amount) FILTER (
            WHERE missing_month.month_start = params.rent_month
        ), 0) AS month_minor
    FROM missing_month
    JOIN params
        ON params.run_id = missing_month.run_id
       AND params.vendor_id = missing_month.vendor_id
    GROUP BY missing_month.run_id, missing_month.vendor_id
),
own_rent AS (
    SELECT
        params.run_id,
        params.vendor_id,
        COALESCE(sum(line.debit_minor - line.credit_minor), 0) AS total_minor,
        COALESCE(sum(line.debit_minor - line.credit_minor) FILTER (
            WHERE journal.posting_date < params.sales_from
        ), 0) AS before_minor,
        COALESCE(sum(line.debit_minor - line.credit_minor) FILTER (
            WHERE journal.source_type = 'rent_charge'
              AND stall_rent_period_start(journal.posting_date) = params.rent_month
        ), 0) AS month_minor
    FROM params
    JOIN payout_run_journals AS link
        ON link.run_id = params.run_id
       AND link.role = 'rent_charge'
    JOIN journals AS journal ON journal.id = link.journal_id
    JOIN journal_lines AS line ON line.journal_id = journal.id AND line.subledger_type = 'booth'
    JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'booth_rent_receivable'
    WHERE EXISTS (
        SELECT 1
        FROM booth_assignments AS assignment
        WHERE assignment.vendor_id = params.vendor_id
          AND assignment.booth_id = line.subledger_ref::uuid
          AND assignment.assignment_range @> journal.posting_date
    )
    GROUP BY params.run_id, params.vendor_id
),
figures AS (
    SELECT
        params.run_id,
        params.vendor_id,
        params.code,
        params.name,
        params.mode,
        params.posting_date,
        COALESCE(sale.sales_minor, 0) AS sales_minor,
        COALESCE(sale.commission_minor, 0) AS commission_minor,
        COALESCE(sale.tax_minor, 0) AS tax_minor,
        COALESCE(sale.discount_minor, 0) AS discount_minor,
        COALESCE(pay.return_minor, 0) AS return_minor,
        COALESCE(pay.void_minor, 0) AS void_minor,
        COALESCE(pay.draw_minor, 0) AS draw_minor,
        COALESCE(pay.check_paid_minor, 0) AS check_paid_minor,
        COALESCE(pay.settlement_applied_minor, 0) AS settlement_applied_minor,
        COALESCE(pay.brought_forward_minor, 0) AS brought_forward_minor,
        COALESCE(pay.brought_forward_minor, 0) + COALESCE(pay.movement_minor, 0) AS carried_payable_minor,
        COALESCE(rent_posted.before_minor, 0) + COALESCE(missing.before_minor, 0) + COALESCE(own_rent.before_minor, 0) AS rent_brought_forward_minor,
        COALESCE(rent_posted.month_minor, 0) + COALESCE(missing.month_minor, 0) + COALESCE(own_rent.month_minor, 0) AS rent_minor,
        COALESCE(rent_posted.paid_minor, 0) AS rent_paid_since_minor,
        COALESCE(rent_posted.settlement_minor, 0) AS rent_settlement_minor,
        COALESCE(rent_posted.total_minor, 0) + COALESCE(missing.total_minor, 0) + COALESCE(own_rent.total_minor, 0) AS rent_total_minor
    FROM params
    LEFT JOIN pay ON pay.run_id = params.run_id AND pay.vendor_id = params.vendor_id
    LEFT JOIN sale ON sale.run_id = params.run_id AND sale.vendor_id = params.vendor_id
    LEFT JOIN rent_posted ON rent_posted.run_id = params.run_id AND rent_posted.vendor_id = params.vendor_id
    LEFT JOIN missing ON missing.run_id = params.run_id AND missing.vendor_id = params.vendor_id
    LEFT JOIN own_rent ON own_rent.run_id = params.run_id AND own_rent.vendor_id = params.vendor_id
)
SELECT
    figures.run_id,
    figures.vendor_id,
    figures.code,
    figures.name,
    COALESCE(spot.booth_code, '') AS booth,
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
    figures.rent_total_minor
        - figures.rent_brought_forward_minor
        - figures.rent_minor
        + figures.rent_paid_since_minor
        + figures.rent_settlement_minor AS rent_charged_minor,
    figures.rent_brought_forward_minor,
    figures.rent_paid_since_minor,
    (figures.carried_payable_minor - figures.brought_forward_minor)
        - (figures.sales_minor - figures.discount_minor - figures.commission_minor
           - figures.return_minor - figures.void_minor - figures.draw_minor
           - figures.check_paid_minor - figures.settlement_applied_minor) AS adjustment_minor,
    figures.brought_forward_minor,
    figures.carried_payable_minor,
    figures.rent_total_minor AS carried_rent_minor,
    CASE WHEN mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales
        THEN LEAST(GREATEST(figures.carried_payable_minor, 0), GREATEST(figures.rent_total_minor, 0))
        ELSE 0
    END AS settled_minor,
    CASE WHEN mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales
        THEN GREATEST(figures.carried_payable_minor - figures.rent_total_minor, 0)
        ELSE GREATEST(figures.carried_payable_minor, 0)
    END AS check_minor,
    CASE WHEN mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales
        THEN GREATEST(figures.rent_total_minor - figures.carried_payable_minor, 0)
        ELSE GREATEST(figures.rent_total_minor, 0)
    END AS owes_minor,
    CASE WHEN mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales
        THEN figures.carried_payable_minor - figures.rent_total_minor
        ELSE figures.carried_payable_minor
    END AS net_minor,
    figures.rent_brought_forward_minor AS prior,
    (figures.rent_total_minor - figures.rent_brought_forward_minor + figures.rent_paid_since_minor + figures.rent_settlement_minor) AS charged,
    figures.rent_paid_since_minor + figures.rent_settlement_minor AS paid,
    figures.carried_payable_minor AS payable,
    figures.rent_total_minor AS rent,
    CASE WHEN mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales
        THEN GREATEST(figures.carried_payable_minor - figures.rent_total_minor, 0)
        ELSE GREATEST(figures.carried_payable_minor, 0)
    END AS "check",
    CASE WHEN mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales
        THEN GREATEST(figures.rent_total_minor - figures.carried_payable_minor, 0)
        ELSE GREATEST(figures.rent_total_minor, 0)
    END AS owes,
    (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) AS rent_from_sales,
    spot.booth_id
FROM figures
CROSS JOIN mall AS mall_settings
LEFT JOIN LATERAL (
    SELECT booth.id AS booth_id, booth.code AS booth_code
    FROM booth_assignments AS assignment
    JOIN booths AS booth ON booth.id = assignment.booth_id
    WHERE assignment.vendor_id = figures.vendor_id
      AND assignment.assignment_range @> figures.posting_date
    ORDER BY booth.code
    LIMIT 1
) AS spot ON true;

