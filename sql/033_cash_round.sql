-- Cash sales can round to the nearest nickel. The difference posts to cash rounding.
-- Apply after 032. The sale shape in 031 reads mall.cash_round_nickel.
-- The provisioner rewrites stall_poster.

ALTER TABLE mall ADD COLUMN IF NOT EXISTS cash_round_nickel boolean NOT NULL DEFAULT false;

INSERT INTO accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type)
SELECT
    '01900000-0000-7000-8000-000000000020',
    '01900000-0000-7000-8000-000000000001',
    '6160',
    'Cash rounding',
    'expense',
    'debit',
    true,
    false,
    NULL
WHERE NOT EXISTS (
    SELECT 1 FROM accounts WHERE id = '01900000-0000-7000-8000-000000000020'
);

INSERT INTO chart_roles (role, account_id)
SELECT 'cash_rounding', '01900000-0000-7000-8000-000000000020'
WHERE NOT EXISTS (
    SELECT 1 FROM chart_roles WHERE role = 'cash_rounding'
);

INSERT INTO chart_source_rules (role, source_type)
SELECT 'cash_rounding', 'sale'
WHERE NOT EXISTS (
    SELECT 1
    FROM chart_source_rules
    WHERE role = 'cash_rounding'
      AND source_type = 'sale'
);
