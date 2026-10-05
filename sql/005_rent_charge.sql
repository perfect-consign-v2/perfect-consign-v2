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
        ('rent_revenue', 'adjustment')
) AS seed(role, source_type)
WHERE NOT EXISTS (
    SELECT 1
    FROM chart_source_rules AS existing
    WHERE existing.role = seed.role
      AND existing.source_type = seed.source_type
);

-- The asking price for the next assignment. One row per booth.
-- A change here does not change a stay that already copied its contract.

CREATE TABLE booth_rent_rates (
    booth_id uuid PRIMARY KEY REFERENCES booths (id),
    amount_minor bigint NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT booth_rent_rates_amount_chk CHECK (amount_minor > 0)
);

CREATE TRIGGER booth_rent_rates_no_truncate
    BEFORE TRUNCATE ON booth_rent_rates
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE booth_rent_rates ENABLE ALWAYS TRIGGER booth_rent_rates_no_truncate;
