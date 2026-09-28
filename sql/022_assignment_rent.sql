-- A vendor's rent when it differs from the booth's monthly rate.
-- Apply inside each tenant schema, after 005. The charge function below replaces the one in 005.

CREATE TABLE assignment_rent (
    assignment_id uuid PRIMARY KEY REFERENCES booth_assignments (id),
    amount_minor bigint NOT NULL,
    CONSTRAINT assignment_rent_amount_chk CHECK (amount_minor > 0)
);

CREATE OR REPLACE FUNCTION stall_reject_assignment_rent_rewrite()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM public.journal_lines AS line
        JOIN public.journals AS posted ON posted.id = line.journal_id
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        JOIN public.booth_assignments AS assignment ON assignment.id = OLD.assignment_id
        WHERE role.role = 'booth_rent_receivable'
          AND line.subledger_ref = assignment.booth_id::text
          AND assignment.assignment_range @> posted.posting_date
    ) THEN
        RAISE EXCEPTION 'an assignment rent cannot change after rent is posted'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER assignment_rent_frozen_after_charge
    BEFORE UPDATE OR DELETE ON assignment_rent
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_assignment_rent_rewrite();

CREATE TRIGGER assignment_rent_no_truncate
    BEFORE TRUNCATE ON assignment_rent
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE assignment_rent ENABLE ALWAYS TRIGGER assignment_rent_no_truncate;

CREATE OR REPLACE FUNCTION stall_assert_rent_charge()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog
AS $$
DECLARE
    booth_ref text;
    rate_minor bigint;
    override_minor bigint;
    assign_start date;
    charge_month date;
    charge_end date;
    days_in_month integer;
    days_charged integer;
    line_count integer;
    debit_ok integer;
    credit_ok integer;
BEGIN
    IF NEW.source_type <> 'rent_charge' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    SELECT line.subledger_ref INTO booth_ref
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'booth_rent_receivable'
      AND line.debit_minor > 0
      AND line.subledger_type = 'booth';

    IF booth_ref IS NULL OR NEW.posting_key <> 'rent-charge:' || booth_ref || ':' || to_char(date_trunc('month', NEW.posting_date)::date, 'YYYY-MM') THEN
        RAISE EXCEPTION 'a rent charge posting key is one booth and one month'
            USING ERRCODE = '23514';
    END IF;

    SELECT amount_minor INTO rate_minor
    FROM public.booth_rent_rates
    WHERE booth_id = booth_ref::uuid
      AND month_start = date_trunc('month', NEW.posting_date)::date
    FOR UPDATE;

    IF rate_minor IS NULL THEN
        RAISE EXCEPTION 'a rent charge requires the rate stored for that month'
            USING ERRCODE = '23514';
    END IF;

    SELECT override.amount_minor INTO override_minor
    FROM public.booth_assignments AS assignment
    JOIN public.assignment_rent AS override ON override.assignment_id = assignment.id
    WHERE assignment.booth_id = booth_ref::uuid
      AND assignment.assignment_range @> NEW.posting_date
    FOR UPDATE;

    IF override_minor IS NOT NULL THEN
        rate_minor := override_minor;
    END IF;

    SELECT assignment.starts_on INTO assign_start
    FROM public.booth_assignments AS assignment
    WHERE assignment.booth_id = booth_ref::uuid
      AND assignment.assignment_range @> NEW.posting_date
    FOR UPDATE;
    IF assign_start IS NULL THEN
        RAISE EXCEPTION 'a rent charge requires an assignment that covers the charge date'
            USING ERRCODE = '23514';
    END IF;

    charge_month := date_trunc('month', NEW.posting_date)::date;
    charge_end := (charge_month + interval '1 month' - interval '1 day')::date;
    IF assign_start > charge_month AND assign_start <= charge_end THEN
        days_in_month := (charge_end - charge_month) + 1;
        days_charged := (charge_end - assign_start) + 1;
        rate_minor := (days_charged * rate_minor) / days_in_month;
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

GRANT INSERT, UPDATE ON assignment_rent TO stall_poster;
REVOKE DELETE, TRUNCATE, TRIGGER ON assignment_rent FROM stall_poster;
