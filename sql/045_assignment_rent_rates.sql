-- Effective-dated assignment rent. Append-only history; charges read the rate in force on the period start.

CREATE TABLE public.assignment_rent_rates (
    id uuid DEFAULT uuidv7() NOT NULL,
    assignment_id uuid NOT NULL,
    starts_on date NOT NULL,
    amount_minor bigint NOT NULL,
    created_at timestamptz DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT assignment_rent_rates_pkey PRIMARY KEY (id),
    CONSTRAINT assignment_rent_rates_amount_chk CHECK (amount_minor > 0),
    CONSTRAINT assignment_rent_rates_assignment_starts_key UNIQUE (assignment_id, starts_on),
    CONSTRAINT assignment_rent_rates_assignment_fkey
        FOREIGN KEY (assignment_id) REFERENCES public.booth_assignments (id)
);

CREATE INDEX assignment_rent_rates_assignment_idx
    ON public.assignment_rent_rates (assignment_id, starts_on DESC);

CREATE FUNCTION public.stall_reject_assignment_rent_rate_rewrite() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'an assignment rent rate cannot change'
        USING ERRCODE = '23514';
END;
$$;

CREATE TRIGGER assignment_rent_rates_append_only
    BEFORE DELETE OR UPDATE ON public.assignment_rent_rates
    FOR EACH ROW EXECUTE FUNCTION public.stall_reject_assignment_rent_rate_rewrite();

ALTER TABLE public.assignment_rent_rates ENABLE ALWAYS TRIGGER assignment_rent_rates_append_only;

CREATE TRIGGER assignment_rent_rates_no_truncate
    BEFORE TRUNCATE ON public.assignment_rent_rates
    FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.assignment_rent_rates ENABLE ALWAYS TRIGGER assignment_rent_rates_no_truncate;

CREATE FUNCTION public.stall_seed_assignment_rent_rate() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    stay_start date;
BEGIN
    SELECT starts_on INTO stay_start
    FROM public.booth_assignments
    WHERE id = NEW.assignment_id;
    IF stay_start IS NULL THEN
        RAISE EXCEPTION 'a rent rate needs an assignment'
            USING ERRCODE = '23514';
    END IF;
    INSERT INTO public.assignment_rent_rates (assignment_id, starts_on, amount_minor)
    VALUES (NEW.assignment_id, stay_start, NEW.amount_minor)
    ON CONFLICT (assignment_id, starts_on) DO NOTHING;
    RETURN NEW;
END;
$$;

CREATE TRIGGER assignment_rent_seed_rate
    AFTER INSERT ON public.assignment_rent
    FOR EACH ROW EXECUTE FUNCTION public.stall_seed_assignment_rent_rate();

INSERT INTO public.assignment_rent_rates (assignment_id, starts_on, amount_minor)
SELECT contract.assignment_id, assignment.starts_on, contract.amount_minor
FROM public.assignment_rent AS contract
JOIN public.booth_assignments AS assignment ON assignment.id = contract.assignment_id
ON CONFLICT (assignment_id, starts_on) DO NOTHING;

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
    lease_start date;
    lease_end date;
    assignment_id uuid;
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

    SELECT lease.rent_minor, lease.starts_on, lease.ends_on
    INTO rate_minor, lease_start, lease_end
    FROM public.booth_leases AS lease
    WHERE lease.booth_id = booth_ref::uuid
      AND lease.status = 'active'
      AND lease.lease_range @> NEW.posting_date
    ORDER BY lease.starts_on DESC
    LIMIT 1;

    IF rate_minor IS NULL THEN
        SELECT assignment.id, assignment.starts_on
        INTO assignment_id, assign_start
        FROM public.booth_assignments AS assignment
        WHERE assignment.booth_id = booth_ref::uuid
          AND assignment.assignment_range @> NEW.posting_date
        ORDER BY assignment.starts_on DESC
        LIMIT 1
        FOR UPDATE OF assignment;

        IF assignment_id IS NOT NULL THEN
            SELECT rate.amount_minor
            INTO rate_minor
            FROM public.assignment_rent_rates AS rate
            WHERE rate.assignment_id = assignment_id
              AND rate.starts_on <= charge_start
            ORDER BY rate.starts_on DESC
            LIMIT 1;
        END IF;
    ELSE
        assign_start := lease_start;
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
    IF rate_minor IS NOT NULL AND lease_start IS NOT NULL THEN
        days_charged := (LEAST(charge_end, COALESCE(lease_end, charge_end)) - GREATEST(charge_start, lease_start)) + 1;
        IF days_charged < days_in_period THEN
            rate_minor := (days_charged * rate_minor) / days_in_period;
        END IF;
    ELSIF assign_start > charge_start AND assign_start <= charge_end THEN
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

REVOKE ALL ON TABLE public.assignment_rent_rates FROM PUBLIC;
GRANT SELECT, INSERT ON TABLE public.assignment_rent_rates TO stall_poster;
GRANT SELECT ON TABLE public.assignment_rent_rates TO stall_backup;
