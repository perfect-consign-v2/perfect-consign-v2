-- Proof for the mall chart. The seed stays. This transaction rolls back.
-- Expected refusals are caught. An accepted illegal change aborts the script.

BEGIN;

DO $$
DECLARE
    book_count integer;
    role_count integer;
BEGIN
    SELECT count(*) INTO book_count
    FROM ledger_books
    WHERE id = '01900000-0000-7000-8000-000000000001'
      AND code = 'mall'
      AND currency = 'USD'
      AND currency_scale = 2
      AND is_default;

    IF book_count <> 1 THEN
        RAISE EXCEPTION 'mall book is missing';
    END IF;

    SELECT count(*) INTO role_count
    FROM chart_roles AS roles
    JOIN accounts AS account ON account.id = roles.account_id
    JOIN (
        VALUES
            ('cash', '1000', 'asset', 'debit', true, false, NULL::text),
            ('bank', '1010', 'asset', 'debit', true, false, NULL),
            ('commission_revenue', '4000', 'revenue', 'credit', true, false, NULL),
            ('sales_tax_payable', '2100', 'liability', 'credit', true, false, NULL),
            ('vendor_payable', '2000', 'liability', 'credit', true, true, 'vendor'),
            ('booth_rent_receivable', '1200', 'asset', 'debit', true, true, 'booth'),
            ('card', '1020', 'asset', 'debit', true, false, NULL),
            ('rent_revenue', '4100', 'revenue', 'credit', true, false, NULL),
            ('operating_expense', '6000', 'expense', 'debit', true, false, NULL),
            ('retained_earnings', '3000', 'equity', 'credit', true, false, NULL),
            ('cash_in_safe', '1015', 'asset', 'debit', true, false, NULL),
            ('checks', '1100', 'asset', 'debit', true, false, NULL),
            ('gift_certificate', '2200', 'liability', 'credit', true, true, 'gift_certificate'),
            ('gift_card', '2210', 'liability', 'credit', true, true, 'gift_card'),
            ('store_credit', '2220', 'liability', 'credit', true, true, 'store_credit'),
            ('cash_over_short', '6150', 'expense', 'debit', true, false, NULL),
            ('cash_rounding', '6160', 'expense', 'debit', true, false, NULL),
            ('layaway_deposit', '2230', 'liability', 'credit', true, true, 'ticket'),
            ('store_purchases', '6100', 'expense', 'debit', true, false, NULL),
            ('late_fee_income', '4110', 'revenue', 'credit', true, false, NULL),
            ('promotion_expense', '6200', 'expense', 'debit', true, false, NULL),
            ('store_sales', '4120', 'revenue', 'credit', true, false, NULL),
            ('inventory', '1300', 'asset', 'debit', true, false, NULL),
            ('cost_of_goods', '5000', 'expense', 'debit', true, false, NULL),
            ('vendor_allowance', '6015', 'expense', 'debit', true, false, NULL),
            ('vendor_charge', '4210', 'revenue', 'credit', true, false, NULL),
            ('returned_checks', '1290', 'asset', 'debit', true, false, NULL),
            ('booth_other_revenue', '4220', 'revenue', 'credit', true, false, NULL),
            ('booth_deposits', '2150', 'liability', 'credit', true, false, NULL)
    ) AS expected (role, code, account_type, normal_balance, is_postable, is_control, subledger_type)
        ON expected.role = roles.role
        AND expected.code = account.code
        AND expected.account_type = account.account_type
        AND expected.normal_balance = account.normal_balance
        AND expected.is_postable = account.is_postable
        AND expected.is_control = account.is_control
        AND expected.subledger_type IS NOT DISTINCT FROM account.subledger_type;

    IF role_count <> 29 OR (SELECT count(*) FROM chart_roles) <> 29 THEN
        RAISE EXCEPTION 'chart roles do not match the mall chart';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO ledger_books (code, name, currency, currency_scale, is_default)
    VALUES ('other', 'Other', 'USD', 2, false);
    RAISE EXCEPTION 'second book was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    DELETE FROM chart_roles WHERE role = 'cash';
    RAISE EXCEPTION 'chart role delete was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE chart_roles
    SET account_id = '01900000-0000-7000-8000-000000000011'
    WHERE role = 'cash';
    RAISE EXCEPTION 'chart role retarget was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE accounts
    SET account_type = 'expense'
    WHERE id = '01900000-0000-7000-8000-000000000010';
    RAISE EXCEPTION 'chart account type change was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE accounts
    SET is_control = false, subledger_type = NULL
    WHERE id = '01900000-0000-7000-8000-000000000014';
    RAISE EXCEPTION 'vendor payable control change was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    DELETE FROM accounts WHERE id = '01900000-0000-7000-8000-000000000015';
    RAISE EXCEPTION 'chart account delete was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    TRUNCATE export_accounts, chart_source_rules, chart_roles;
    RAISE EXCEPTION 'chart role truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        NULL;
END $$;

ROLLBACK;

DO $$
DECLARE
    role_count integer;
BEGIN
    SELECT count(*) INTO role_count FROM chart_roles;
    IF role_count <> 29
        OR (
            SELECT count(*)
            FROM chart_roles
            WHERE role IN ('returned_checks', 'booth_other_revenue', 'booth_deposits')
        ) <> 3 THEN
        RAISE EXCEPTION 'chart roles changed after rollback';
    END IF;
END $$;
