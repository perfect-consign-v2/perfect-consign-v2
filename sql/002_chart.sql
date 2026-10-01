-- Mall chart. One book and the accounts later facts post to.
-- Apply after sql/prove.sql. This file commits.

INSERT INTO ledger_books (id, code, name, currency, currency_scale, is_default)
VALUES (
    '01900000-0000-7000-8000-000000000001',
    'mall',
    'Mall',
    'USD',
    2,
    true
);

INSERT INTO accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type)
VALUES
    ('01900000-0000-7000-8000-000000000010', '01900000-0000-7000-8000-000000000001', '1000', 'Cash', 'asset', 'debit', true, false, NULL),
    ('01900000-0000-7000-8000-000000000011', '01900000-0000-7000-8000-000000000001', '1010', 'Bank', 'asset', 'debit', true, false, NULL),
    ('01900000-0000-7000-8000-000000000012', '01900000-0000-7000-8000-000000000001', '4000', 'Commission revenue', 'revenue', 'credit', true, false, NULL),
    ('01900000-0000-7000-8000-000000000013', '01900000-0000-7000-8000-000000000001', '2100', 'Sales tax payable', 'liability', 'credit', true, false, NULL),
    ('01900000-0000-7000-8000-000000000014', '01900000-0000-7000-8000-000000000001', '2000', 'Vendor payable', 'liability', 'credit', true, true, 'vendor'),
    ('01900000-0000-7000-8000-000000000015', '01900000-0000-7000-8000-000000000001', '1200', 'Booth rent receivable', 'asset', 'debit', true, true, 'booth');

CREATE TABLE chart_roles (
    role text PRIMARY KEY,
    account_id uuid NOT NULL UNIQUE REFERENCES accounts (id),
    CONSTRAINT chart_roles_role_chk CHECK (role = btrim(role) AND role <> '')
);

INSERT INTO chart_roles (role, account_id)
VALUES
    ('cash', '01900000-0000-7000-8000-000000000010'),
    ('bank', '01900000-0000-7000-8000-000000000011'),
    ('commission_revenue', '01900000-0000-7000-8000-000000000012'),
    ('sales_tax_payable', '01900000-0000-7000-8000-000000000013'),
    ('vendor_payable', '01900000-0000-7000-8000-000000000014'),
    ('booth_rent_receivable', '01900000-0000-7000-8000-000000000015');

CREATE OR REPLACE FUNCTION stall_reject_second_book()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    RAISE EXCEPTION 'the mall has one book'
        USING ERRCODE = '23514';
END;
$$;

CREATE TRIGGER ledger_books_one_book
    BEFORE INSERT ON ledger_books
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_second_book();

CREATE OR REPLACE FUNCTION stall_reject_chart_role_change()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    RAISE EXCEPTION 'a chart role cannot be changed or removed'
        USING ERRCODE = '23514';
END;
$$;

CREATE TRIGGER chart_roles_frozen
    BEFORE UPDATE OR DELETE ON chart_roles
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_chart_role_change();

CREATE OR REPLACE FUNCTION stall_reject_chart_account_change()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM public.chart_roles WHERE account_id = OLD.id) THEN
        IF NEW.code IS DISTINCT FROM OLD.code
            OR NEW.account_type IS DISTINCT FROM OLD.account_type
            OR NEW.normal_balance IS DISTINCT FROM OLD.normal_balance
            OR NEW.is_postable IS DISTINCT FROM OLD.is_postable
            OR NEW.is_control IS DISTINCT FROM OLD.is_control
            OR NEW.subledger_type IS DISTINCT FROM OLD.subledger_type
            OR NEW.book_id IS DISTINCT FROM OLD.book_id THEN
            RAISE EXCEPTION 'a chart account cannot change its posting identity'
                USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER accounts_chart_frozen
    BEFORE UPDATE OF book_id, code, account_type, normal_balance, is_postable, is_control, subledger_type
    ON accounts
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_chart_account_change();

CREATE OR REPLACE FUNCTION stall_reject_chart_account_delete()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM public.chart_roles WHERE account_id = OLD.id) THEN
        RAISE EXCEPTION 'a chart account cannot be deleted'
            USING ERRCODE = '23514';
    END IF;
    RETURN OLD;
END;
$$;

CREATE TRIGGER accounts_chart_no_delete
    BEFORE DELETE ON accounts
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_chart_account_delete();

CREATE TRIGGER chart_roles_no_truncate
    BEFORE TRUNCATE ON chart_roles
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE accounts ENABLE ALWAYS TRIGGER accounts_chart_no_delete;

ALTER TABLE chart_roles ENABLE ALWAYS TRIGGER chart_roles_no_truncate;

CREATE OR REPLACE FUNCTION ledger_assert_journal_balance()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    checked_journal_id uuid;
    header_debit bigint;
    header_credit bigint;
    line_count integer;
    debit_sum bigint;
    credit_sum bigint;
    both_sides integer;
BEGIN
    IF TG_TABLE_NAME = 'journals' THEN
        checked_journal_id := NEW.id;
    ELSE
        checked_journal_id := NEW.journal_id;
    END IF;

    SELECT debit_total_minor, credit_total_minor
    INTO header_debit, header_credit
    FROM public.journals
    WHERE id = checked_journal_id;

    SELECT count(*), COALESCE(sum(debit_minor), 0), COALESCE(sum(credit_minor), 0)
    INTO line_count, debit_sum, credit_sum
    FROM public.journal_lines
    WHERE journal_id = checked_journal_id;

    IF line_count < 2 THEN
        RAISE EXCEPTION 'a journal must have at least two lines'
            USING ERRCODE = '23514';
    END IF;
    IF debit_sum <> credit_sum OR debit_sum <> header_debit OR credit_sum <> header_credit THEN
        RAISE EXCEPTION 'journal line totals must equal the header totals'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO both_sides
    FROM (
        SELECT line.account_id
        FROM public.journal_lines AS line
        WHERE line.journal_id = checked_journal_id
        GROUP BY line.account_id
        HAVING sum(line.debit_minor) > 0
           AND sum(line.credit_minor) > 0
           AND line.account_id NOT IN (
               SELECT role.account_id
               FROM public.chart_roles AS role
               WHERE role.role = 'vendor_payable'
           )
    ) AS sided;
    IF both_sides > 0 THEN
        RAISE EXCEPTION 'an account may appear on only one side of a journal'
            USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER journals_balanced
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION ledger_assert_journal_balance();
