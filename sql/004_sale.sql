-- A sale debits one or more tenders once each. Tax is on top of the sold prices.
-- Credits are commission, sales tax, and one payable for each vendor who is owed.
-- The posting key is the sale. A second insert of that key is refused. A void is a reversal.

CREATE OR REPLACE FUNCTION stall_half_up(amount bigint, bps bigint)
RETURNS bigint
LANGUAGE sql
IMMUTABLE
SET search_path = pg_catalog
AS $$
    SELECT (amount * bps + 5000) / 10000;
$$;

CREATE OR REPLACE FUNCTION stall_tax_minor(net bigint, tax_bps bigint)
RETURNS bigint
LANGUAGE sql
IMMUTABLE
SET search_path = pg_catalog
AS $$
    SELECT (net * tax_bps + 50000) / 100000;
$$;

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
        ('card', 'adjustment')
) AS seed(role, source_type)
WHERE NOT EXISTS (
    SELECT 1
    FROM chart_source_rules AS existing
    WHERE existing.role = seed.role
      AND existing.source_type = seed.source_type
);
