-- Mid-month move-in: the opening contract starts on the stay day, not the period start.

CREATE OR REPLACE FUNCTION public.stall_assert_rent_charge() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog'
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
    stay_id uuid;
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

    SELECT assignment.id, assignment.starts_on
    INTO stay_id, assign_start
    FROM public.booth_assignments AS assignment
    WHERE assignment.booth_id = booth_ref::uuid
      AND assignment.assignment_range @> NEW.posting_date
    ORDER BY assignment.starts_on DESC
    LIMIT 1
    FOR UPDATE OF assignment;

    IF stay_id IS NOT NULL THEN
        SELECT rate.amount_minor
        INTO rate_minor
        FROM public.assignment_rent_rates AS rate
        WHERE rate.assignment_id = stay_id
          AND rate.starts_on <= GREATEST(charge_start, assign_start)
        ORDER BY rate.starts_on DESC
        LIMIT 1;
    END IF;

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

    IF rate_minor IS NULL THEN
        RAISE EXCEPTION 'a rent charge requires the booth rate from the assignment'
            USING ERRCODE = '23514';
    END IF;

    charge_end := public.stall_rent_period_end(charge_start);
    days_in_period := (charge_end - charge_start) + 1;
    IF assign_start > charge_start AND assign_start <= charge_end THEN
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
