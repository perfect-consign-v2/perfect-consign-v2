-- Proof for account balances. One transaction, then ROLLBACK.

BEGIN;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-000000000b21',
    '01900000-0000-7000-8000-000000000001',
    '2026-01-01',
    '2026-12-31',
    'open'
);

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
) VALUES (
    '01900000-0000-7000-8000-000000000b01',
    '01900000-0000-7000-8000-000000000001',
    'adjustment:balance-proof',
    '2026-04-01',
    '2026-04-01 12:00:00+00',
    '01900000-0000-7000-8000-000000000b21',
    'USD',
    'adjustment',
    'balance-proof',
    'Balance proof',
    2500,
    2500
);

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
VALUES
    ('01900000-0000-7000-8000-000000000b01', '01900000-0000-7000-8000-000000000001', 1, '01900000-0000-7000-8000-000000000010', 2500, 0),
    ('01900000-0000-7000-8000-000000000b01', '01900000-0000-7000-8000-000000000001', 2, '01900000-0000-7000-8000-000000000017', 0, 2500);

SET CONSTRAINTS ALL IMMEDIATE;

DO $$
DECLARE
    side_debit bigint;
    side_credit bigint;
    signed_balance bigint;
BEGIN
    SELECT debit_minor, credit_minor, balance_minor
    INTO side_debit, side_credit, signed_balance
    FROM account_balances
    WHERE account_id = '01900000-0000-7000-8000-000000000010';

    IF side_debit IS DISTINCT FROM 2500
        OR side_credit IS DISTINCT FROM 0
        OR signed_balance IS DISTINCT FROM 2500 THEN
        RAISE EXCEPTION 'cash balance is % % %', side_debit, side_credit, signed_balance;
    END IF;

    SELECT debit_minor, credit_minor, balance_minor
    INTO side_debit, side_credit, signed_balance
    FROM account_balances
    WHERE account_id = '01900000-0000-7000-8000-000000000017';

    IF side_debit IS DISTINCT FROM 0
        OR side_credit IS DISTINCT FROM 2500
        OR signed_balance IS DISTINCT FROM 2500 THEN
        RAISE EXCEPTION 'rent revenue balance is % % %', side_debit, side_credit, signed_balance;
    END IF;

    SELECT debit_minor, credit_minor, balance_minor
    INTO side_debit, side_credit, signed_balance
    FROM account_balances
    WHERE account_id = '01900000-0000-7000-8000-000000000011';

    IF side_debit IS DISTINCT FROM 0
        OR side_credit IS DISTINCT FROM 0
        OR signed_balance IS DISTINCT FROM 0 THEN
        RAISE EXCEPTION 'bank balance is % % %', side_debit, side_credit, signed_balance;
    END IF;
END $$;

SET LOCAL ROLE stall_poster;

DO $$
DECLARE
    readable integer;
BEGIN
    SELECT count(*) INTO readable FROM account_balances;
    IF readable < 10 THEN
        RAISE EXCEPTION 'poster read % account balances', readable;
    END IF;
END $$;

ROLLBACK;

SELECT 'ACCOUNT_BALANCES_OK' AS result;
