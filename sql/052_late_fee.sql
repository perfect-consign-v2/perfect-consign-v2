-- A late fee is its own rent charge. It does not reduce what the store owes the vendor.
-- Apply after 051. The provisioner rewrites stall_poster.

INSERT INTO accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type)
SELECT
    '01900000-0000-7000-8000-000000000023',
    '01900000-0000-7000-8000-000000000001',
    '4110',
    'Late fee income',
    'revenue',
    'credit',
    true,
    false,
    NULL
WHERE NOT EXISTS (
    SELECT 1 FROM accounts WHERE id = '01900000-0000-7000-8000-000000000023'
);

INSERT INTO chart_roles (role, account_id)
SELECT 'late_fee_income', '01900000-0000-7000-8000-000000000023'::uuid
WHERE NOT EXISTS (
    SELECT 1 FROM chart_roles WHERE role = 'late_fee_income'
);

INSERT INTO chart_source_rules (role, source_type)
SELECT seed.role, seed.source_type
FROM (
    VALUES
        ('booth_rent_receivable', 'late_fee'),
        ('late_fee_income', 'late_fee')
) AS seed(role, source_type)
WHERE NOT EXISTS (
    SELECT 1
    FROM chart_source_rules AS existing
    WHERE existing.role = seed.role
      AND existing.source_type = seed.source_type
);

SELECT stall_seed_export_accounts();
