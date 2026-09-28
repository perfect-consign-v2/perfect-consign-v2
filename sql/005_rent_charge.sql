-- Rent charge. One posting key per booth per month.
-- Debit that booth's receivable. Credit rent revenue. The amount is the stored rate.

INSERT INTO accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type)
VALUES (
    '01900000-0000-7000-8000-000000000017',
    '01900000-0000-7000-8000-000000000001',
    '4100',
    'Rent revenue',
    'revenue',
    'credit',
    true,
    false,
    NULL
);

INSERT INTO chart_roles (role, account_id)
VALUES ('rent_revenue', '01900000-0000-7000-8000-000000000017');

INSERT INTO chart_source_rules (role, source_type)
SELECT seed.role, seed.source_type
FROM (
    VALUES
        ('rent_revenue', 'sale'),
        ('rent_revenue', 'rent_charge'),
        ('rent_revenue', 'rent_payment'),
        ('rent_revenue', 'payout'),
        ('rent_revenue', 'adjustment')
) AS seed(role, source_type)
WHERE NOT EXISTS (
    SELECT 1
    FROM chart_source_rules AS existing
    WHERE existing.role = seed.role
      AND existing.source_type = seed.source_type
);

CREATE TABLE booth_rent_rates (
    booth_id uuid NOT NULL REFERENCES booths (id),
    month_start date NOT NULL,
    amount_minor bigint NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    PRIMARY KEY (booth_id, month_start),
    CONSTRAINT booth_rent_rates_amount_chk CHECK (amount_minor > 0),
    CONSTRAINT booth_rent_rates_month_chk CHECK (month_start = date_trunc('month', month_start)::date)
);

CREATE OR REPLACE FUNCTION stall_reject_rent_rate_rewrite()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM public.journals
        WHERE source_type = 'rent_charge'
          AND posting_key = 'rent-charge:' || OLD.booth_id::text || ':' || to_char(OLD.month_start, 'YYYY-MM')
    ) THEN
        RAISE EXCEPTION 'a rent rate cannot change after that month is charged'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER booth_rent_rates_frozen_after_charge
    BEFORE UPDATE OR DELETE ON booth_rent_rates
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_rent_rate_rewrite();

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

CREATE CONSTRAINT TRIGGER journals_rent_charge
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_rent_charge();

CREATE TRIGGER booth_rent_rates_no_truncate
    BEFORE TRUNCATE ON booth_rent_rates
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE booth_rent_rates ENABLE ALWAYS TRIGGER booth_rent_rates_no_truncate;
