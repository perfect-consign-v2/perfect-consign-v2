-- A lease names the rent for a booth. The charge key stays rent-charge:{booth}:{period}.
-- Two active leases cannot cover the same booth on the same day.

CREATE TABLE public.booth_leases (
    id uuid DEFAULT uuidv7() NOT NULL,
    booth_id uuid NOT NULL,
    starts_on date NOT NULL,
    ends_on date,
    rent_minor bigint NOT NULL,
    deposit_minor bigint DEFAULT 0 NOT NULL,
    status text DEFAULT 'active'::text NOT NULL,
    lease_range daterange GENERATED ALWAYS AS (
        CASE
            WHEN status = 'active'::text THEN daterange(starts_on, COALESCE(ends_on, 'infinity'::date), '[]'::text)
            ELSE 'empty'::daterange
        END
    ) STORED,
    CONSTRAINT booth_leases_pkey PRIMARY KEY (id),
    CONSTRAINT booth_leases_dates_chk CHECK (((ends_on IS NULL) OR (starts_on <= ends_on))),
    CONSTRAINT booth_leases_deposit_chk CHECK ((deposit_minor >= 0)),
    CONSTRAINT booth_leases_rent_chk CHECK ((rent_minor > 0)),
    CONSTRAINT booth_leases_status_chk CHECK ((status = ANY (ARRAY['active'::text, 'ended'::text]))),
    CONSTRAINT booth_leases_no_overlap EXCLUDE USING gist (booth_id WITH =, lease_range WITH &&) WHERE (status = 'active'::text)
);

CREATE FUNCTION public.stall_end_lease(lease uuid, ended_on date) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    UPDATE public.booth_leases
    SET status = 'ended', ends_on = ended_on
    WHERE id = lease
      AND status = 'active'
      AND starts_on <= ended_on;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'that lease is not active'
            USING ERRCODE = '23514';
    END IF;
END;
$$;

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
        SELECT assignment.starts_on, contract.amount_minor
        INTO assign_start, rate_minor
        FROM public.booth_assignments AS assignment
        JOIN public.assignment_rent AS contract ON contract.assignment_id = assignment.id
        WHERE assignment.booth_id = booth_ref::uuid
          AND assignment.assignment_range @> NEW.posting_date
        ORDER BY assignment.starts_on DESC
        LIMIT 1
        FOR UPDATE OF assignment, contract;
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

GRANT ALL ON TABLE public.booth_leases TO stall_poster;
GRANT ALL ON TABLE public.booth_leases TO stall_backup;
GRANT ALL ON FUNCTION public.stall_end_lease(lease uuid, ended_on date) TO stall_poster;
GRANT ALL ON FUNCTION public.stall_end_lease(lease uuid, ended_on date) TO stall_backup;
