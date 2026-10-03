-- Operating expense, and an adjustment that cannot touch a control account.
-- A chart account refuses a source type it does not list.
-- Safe to run again.

INSERT INTO accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type)
SELECT
    '01900000-0000-7000-8000-000000000018',
    '01900000-0000-7000-8000-000000000001',
    '6000',
    'Operating expense',
    'expense',
    'debit',
    true,
    false,
    NULL
WHERE NOT EXISTS (
    SELECT 1 FROM accounts WHERE id = '01900000-0000-7000-8000-000000000018'
);

INSERT INTO chart_roles (role, account_id)
SELECT 'operating_expense', '01900000-0000-7000-8000-000000000018'
WHERE NOT EXISTS (
    SELECT 1 FROM chart_roles WHERE role = 'operating_expense'
);

INSERT INTO chart_source_rules (role, source_type)
SELECT seed.role, seed.source_type
FROM (
    VALUES
        ('operating_expense', 'sale'),
        ('operating_expense', 'rent_charge'),
        ('operating_expense', 'rent_payment'),
        ('operating_expense', 'adjustment')
) AS seed(role, source_type)
WHERE NOT EXISTS (
    SELECT 1
    FROM chart_source_rules AS existing
    WHERE existing.role = seed.role
      AND existing.source_type = seed.source_type
);

CREATE OR REPLACE FUNCTION stall_assert_adjustment()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    line_count integer;
    bad_lines integer;
BEGIN
    IF NEW.source_type <> 'adjustment' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    IF NEW.posting_key <> 'adjustment:' || NEW.source_reference THEN
        RAISE EXCEPTION 'an adjustment posting key is its source reference'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO line_count
    FROM public.journal_lines
    WHERE journal_id = NEW.id;

    SELECT count(*) INTO bad_lines
    FROM public.journal_lines AS line
    WHERE line.journal_id = NEW.id
      AND (
          line.subledger_type IS NOT NULL
          OR NOT EXISTS (
              SELECT 1
              FROM public.chart_roles AS role
              WHERE role.account_id = line.account_id
                AND role.role IN (
                    'cash', 'bank', 'card', 'commission_revenue',
                    'sales_tax_payable', 'rent_revenue', 'operating_expense'
                )
          )
      );

    IF line_count < 2 OR bad_lines > 0 THEN
        RAISE EXCEPTION 'an adjustment posts only to non-control chart accounts'
            USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS journals_adjustment ON journals;
CREATE CONSTRAINT TRIGGER journals_adjustment
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_adjustment();
