-- Rent paid outside a payout. Debit cash. Credit that booth's receivable.

CREATE OR REPLACE FUNCTION stall_assert_rent_payment()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    line_count integer;
    cash_ok integer;
    receivable_ok integer;
BEGIN
    IF NEW.source_type <> 'rent_payment' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    SELECT count(*) INTO line_count
    FROM public.journal_lines
    WHERE journal_id = NEW.id;

    SELECT count(*) INTO cash_ok
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'cash'
      AND line.debit_minor = NEW.debit_total_minor
      AND line.subledger_type IS NULL;

    SELECT count(*) INTO receivable_ok
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'booth_rent_receivable'
      AND line.credit_minor = NEW.credit_total_minor
      AND line.subledger_type = 'booth';

    IF line_count <> 2 OR cash_ok <> 1 OR receivable_ok <> 1 THEN
        RAISE EXCEPTION 'a rent payment debits cash and credits that booth receivable'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER journals_rent_payment
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_rent_payment();
