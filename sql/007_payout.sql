-- Payout. Clear the open vendor payable and the open booth rent receivable against cash.
-- The amounts come from the subledger lines. A payout does not read sales.
-- One posting key per vendor per month. A wrong payout is a reversal.

CREATE OR REPLACE FUNCTION stall_assert_payout()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    vendor_ref text;
    month_start date;
    open_payable bigint;
    open_rent bigint;
    cash_minor bigint;
    expected_lines integer;
    payable_ok integer;
    rent_ok integer;
    cash_ok integer;
    line_count integer;
BEGIN
    IF NEW.source_type <> 'payout' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    IF NEW.posting_key !~ '^payout:[0-9a-f-]{36}:[0-9]{4}-[0-9]{2}$' THEN
        RAISE EXCEPTION 'a payout posting key is one vendor and one month'
            USING ERRCODE = '23514';
    END IF;

    vendor_ref := substring(NEW.posting_key FROM 8 FOR 36);
    month_start := (substring(NEW.posting_key FROM 45) || '-01')::date;
    IF NEW.posting_date < month_start OR NEW.posting_date >= (month_start + interval '1 month')::date THEN
        RAISE EXCEPTION 'a payout posting date must fall in its month'
            USING ERRCODE = '23514';
    END IF;

    SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) INTO open_payable
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id <> NEW.id
      AND role.role = 'vendor_payable'
      AND line.subledger_type = 'vendor'
      AND line.subledger_ref = vendor_ref;

    SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) INTO open_rent
    FROM public.journal_lines AS line
    JOIN public.journals AS posted ON posted.id = line.journal_id
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id <> NEW.id
      AND role.role = 'booth_rent_receivable'
      AND line.subledger_type = 'booth'
      AND EXISTS (
          SELECT 1
          FROM public.booth_assignments AS assignment
          WHERE assignment.vendor_id = vendor_ref::uuid
            AND assignment.booth_id = line.subledger_ref::uuid
            AND assignment.assignment_range @> posted.posting_date
      );

    IF open_payable < 0 OR open_rent < 0 OR (open_payable = 0 AND open_rent = 0) THEN
        RAISE EXCEPTION 'a payout clears a positive open payable or open rent receivable'
            USING ERRCODE = '23514';
    END IF;

    cash_minor := open_payable - open_rent;
    expected_lines := 0;
    IF open_payable > 0 THEN
        expected_lines := expected_lines + 1;
    END IF;
    SELECT count(*) INTO rent_ok
    FROM (
        SELECT line.subledger_ref
        FROM public.journal_lines AS line
        JOIN public.journals AS posted ON posted.id = line.journal_id
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id <> NEW.id
          AND role.role = 'booth_rent_receivable'
          AND line.subledger_type = 'booth'
          AND EXISTS (
              SELECT 1
              FROM public.booth_assignments AS assignment
              WHERE assignment.vendor_id = vendor_ref::uuid
                AND assignment.booth_id = line.subledger_ref::uuid
                AND assignment.assignment_range @> posted.posting_date
          )
        GROUP BY line.subledger_ref
        HAVING sum(line.debit_minor - line.credit_minor) > 0
    ) AS open_booths;
    expected_lines := expected_lines + rent_ok;
    IF cash_minor <> 0 THEN
        expected_lines := expected_lines + 1;
    END IF;

    SELECT count(*) INTO line_count
    FROM public.journal_lines
    WHERE journal_id = NEW.id;

    SELECT count(*) INTO payable_ok
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'vendor_payable'
      AND line.debit_minor = open_payable
      AND line.credit_minor = 0
      AND line.subledger_ref = vendor_ref;

    SELECT count(*) INTO rent_ok
    FROM (
        SELECT line.subledger_ref, sum(line.debit_minor - line.credit_minor) AS open_minor
        FROM public.journal_lines AS line
        JOIN public.journals AS posted ON posted.id = line.journal_id
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id <> NEW.id
          AND role.role = 'booth_rent_receivable'
          AND line.subledger_type = 'booth'
          AND EXISTS (
              SELECT 1
              FROM public.booth_assignments AS assignment
              WHERE assignment.vendor_id = vendor_ref::uuid
                AND assignment.booth_id = line.subledger_ref::uuid
                AND assignment.assignment_range @> posted.posting_date
          )
        GROUP BY line.subledger_ref
        HAVING sum(line.debit_minor - line.credit_minor) > 0
    ) AS open_booth
    FULL JOIN (
        SELECT line.subledger_ref, line.credit_minor
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role = 'booth_rent_receivable'
          AND line.debit_minor = 0
    ) AS cleared ON cleared.subledger_ref = open_booth.subledger_ref
        AND cleared.credit_minor = open_booth.open_minor
    WHERE open_booth.subledger_ref IS NULL
       OR cleared.subledger_ref IS NULL;

    IF cash_minor > 0 THEN
        SELECT count(*) INTO cash_ok
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role = 'cash'
          AND line.credit_minor = cash_minor
          AND line.debit_minor = 0;
    ELSIF cash_minor < 0 THEN
        SELECT count(*) INTO cash_ok
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role = 'cash'
          AND line.debit_minor = -cash_minor
          AND line.credit_minor = 0;
    ELSE
        cash_ok := 0;
        IF EXISTS (
            SELECT 1
            FROM public.journal_lines AS line
            JOIN public.chart_roles AS role ON role.account_id = line.account_id
            WHERE line.journal_id = NEW.id
              AND role.role = 'cash'
        ) THEN
            cash_ok := 1;
        END IF;
    END IF;

    IF line_count <> expected_lines
        OR (open_payable > 0 AND payable_ok <> 1)
        OR (open_payable = 0 AND payable_ok <> 0)
        OR rent_ok <> 0
        OR (cash_minor <> 0 AND cash_ok <> 1)
        OR (cash_minor = 0 AND cash_ok <> 0) THEN
        RAISE EXCEPTION 'a payout clears the open payable and open rent against cash'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER journals_payout
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_payout();
