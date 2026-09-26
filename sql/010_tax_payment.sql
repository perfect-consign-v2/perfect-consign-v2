-- A tax payment debits sales-tax payable and credits cash or bank.
-- Sales-tax payable cannot go below zero. Safe to run again.

CREATE OR REPLACE FUNCTION stall_assert_tax_payment()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    line_count integer;
    tax_ok integer;
    cash_ok integer;
BEGIN
    IF NEW.source_type <> 'tax_payment' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    IF NEW.posting_key <> 'tax-payment:' || NEW.source_reference THEN
        RAISE EXCEPTION 'a tax payment posting key is its source reference'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO line_count
    FROM public.journal_lines
    WHERE journal_id = NEW.id;

    SELECT count(*) INTO tax_ok
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'sales_tax_payable'
      AND line.debit_minor > 0
      AND line.subledger_type IS NULL;

    SELECT count(*) INTO cash_ok
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role IN ('cash', 'bank')
      AND line.credit_minor > 0
      AND line.subledger_type IS NULL;

    IF line_count <> 2 OR tax_ok <> 1 OR cash_ok <> 1 THEN
        RAISE EXCEPTION 'a tax payment debits sales tax payable and credits cash or bank'
            USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS journals_tax_payment ON journals;
CREATE CONSTRAINT TRIGGER journals_tax_payment
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_tax_payment();
