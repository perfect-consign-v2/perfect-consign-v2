-- A rent receipt debits cash, checks, or card and credits that booth's receivable.
-- Cash moves the open drawer. A check does not. A card does not.
-- The checks role arrives in sql/028_tenders.sql, so this file runs after it.
-- journals_rent_payment already exists on a mall that ran sql/006_rent_payment.sql.
-- Create it here only when it is missing. Do not drop it.

CREATE OR REPLACE FUNCTION stall_assert_rent_payment()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    tender bigint;
    credit bigint;
    other integer;
    checks_debit bigint;
    check_rows integer;
BEGIN
    IF NEW.source_type <> 'rent_payment' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    SELECT COALESCE(sum(line.debit_minor), 0) INTO tender
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role IN ('cash', 'checks', 'card')
      AND line.debit_minor > 0
      AND line.credit_minor = 0
      AND line.subledger_type IS NULL;

    SELECT COALESCE(sum(line.credit_minor), 0) INTO credit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'booth_rent_receivable'
      AND line.credit_minor > 0
      AND line.debit_minor = 0
      AND line.subledger_type = 'booth'
      AND line.subledger_ref IS NOT NULL;

    SELECT count(*) INTO other
    FROM public.journal_lines AS line
    WHERE line.journal_id = NEW.id
      AND NOT EXISTS (
          SELECT 1
          FROM public.chart_roles AS role
          WHERE role.account_id = line.account_id
            AND role.role IN ('cash', 'checks', 'card')
            AND line.debit_minor > 0
            AND line.credit_minor = 0
            AND line.subledger_type IS NULL
      )
      AND NOT EXISTS (
          SELECT 1
          FROM public.chart_roles AS role
          WHERE role.account_id = line.account_id
            AND role.role = 'booth_rent_receivable'
            AND line.credit_minor > 0
            AND line.debit_minor = 0
            AND line.subledger_type = 'booth'
            AND line.subledger_ref IS NOT NULL
      );

    SELECT COALESCE(sum(line.debit_minor), 0) INTO checks_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'checks'
      AND line.debit_minor > 0
      AND line.credit_minor = 0
      AND line.subledger_type IS NULL;

    SELECT count(*) INTO check_rows
    FROM public.register_checks
    WHERE journal_id = NEW.id;

    IF tender < 1
        OR tender <> credit
        OR tender <> NEW.debit_total_minor
        OR credit <> NEW.credit_total_minor
        OR other <> 0
    THEN
        RAISE EXCEPTION 'a rent receipt debits a tender and credits that booth receivable'
            USING ERRCODE = '23514';
    END IF;

    IF checks_debit > 0 AND check_rows <> 1 THEN
        RAISE EXCEPTION 'a rent check needs a check number'
            USING ERRCODE = '23514';
    END IF;

    IF checks_debit = 0 AND check_rows <> 0 THEN
        RAISE EXCEPTION 'a cash or card rent receipt has no check number'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

INSERT INTO chart_source_rules (role, source_type)
SELECT seed.role, seed.source_type
FROM (
    VALUES ('checks', 'rent_payment')
) AS seed(role, source_type)
WHERE NOT EXISTS (
    SELECT 1
    FROM chart_source_rules AS existing
    WHERE existing.role = seed.role
      AND existing.source_type = seed.source_type
);

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgname = 'journals_rent_payment'
          AND tgrelid = 'journals'::regclass
          AND NOT tgisinternal
    ) THEN
        CREATE CONSTRAINT TRIGGER journals_rent_payment
            AFTER INSERT ON journals
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION stall_assert_rent_payment();
    END IF;
END $$;
