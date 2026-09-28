-- A sale debits one or more tenders once each, and credits commission, sales tax, and that vendor's payable.
-- The posting key is the sale. A second insert of that key is refused. A void is a reversal.

INSERT INTO accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type)
VALUES (
    '01900000-0000-7000-8000-000000000016',
    '01900000-0000-7000-8000-000000000001',
    '1020',
    'Card',
    'asset',
    'debit',
    true,
    false,
    NULL
);

INSERT INTO chart_roles (role, account_id)
VALUES ('card', '01900000-0000-7000-8000-000000000016');

INSERT INTO chart_source_rules (role, source_type)
SELECT seed.role, seed.source_type
FROM (
    VALUES
        ('card', 'sale'),
        ('card', 'rent_charge'),
        ('card', 'rent_payment'),
        ('card', 'payout'),
        ('card', 'adjustment')
) AS seed(role, source_type)
WHERE NOT EXISTS (
    SELECT 1
    FROM chart_source_rules AS existing
    WHERE existing.role = seed.role
      AND existing.source_type = seed.source_type
);

CREATE OR REPLACE FUNCTION stall_assert_sale_shape()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    tender_lines integer;
    tender_roles integer;
    tender_sum bigint;
    payable_lines integer;
    foreign_lines integer;
BEGIN
    IF NEW.source_type <> 'sale' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    SELECT count(*), count(DISTINCT role.role), COALESCE(sum(line.debit_minor), 0)
    INTO tender_lines, tender_roles, tender_sum
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND line.debit_minor > 0
      AND role.role IN ('cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit');

    IF tender_lines < 1 OR tender_lines <> tender_roles OR tender_sum <> NEW.debit_total_minor THEN
        RAISE EXCEPTION 'a sale debits each tender once'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND line.debit_minor > 0
          AND (
              (role.role IN ('cash', 'card', 'checks') AND line.subledger_type IS NOT NULL)
              OR (
                  role.role IN ('gift_certificate', 'gift_card', 'store_credit')
                  AND line.subledger_type IS DISTINCT FROM role.role
              )
          )
    ) THEN
        RAISE EXCEPTION 'a sale debits each tender once'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO payable_lines
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'vendor_payable'
      AND line.credit_minor > 0
      AND line.subledger_type = 'vendor';

    IF payable_lines <> 1 THEN
        RAISE EXCEPTION 'a sale credits one vendor payable'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO foreign_lines
    FROM public.journal_lines AS line
    WHERE line.journal_id = NEW.id
      AND NOT EXISTS (
          SELECT 1
          FROM public.chart_roles AS role
          WHERE role.account_id = line.account_id
            AND (
                (role.role IN ('cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit') AND line.debit_minor > 0)
                OR (role.role = 'commission_revenue' AND line.credit_minor > 0)
                OR (role.role = 'sales_tax_payable' AND line.credit_minor > 0)
                OR (role.role = 'vendor_payable' AND line.credit_minor > 0)
            )
      );

    IF foreign_lines > 0 THEN
        RAISE EXCEPTION 'a sale only credits commission, sales tax, and vendor payable'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER journals_sale_shape
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_sale_shape();
