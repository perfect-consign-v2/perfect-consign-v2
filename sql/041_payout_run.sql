-- Payout runs. Settlement nets payable against rent. A disbursement moves the check.
-- Apply after 040. The provisioner rewrites public. and stall_poster.

ALTER TABLE vendors ADD COLUMN payout_mode text NOT NULL DEFAULT 'pay';
ALTER TABLE vendors ADD CONSTRAINT vendors_payout_mode_chk
    CHECK (payout_mode IN ('pay', 'credit_only', 'hold'));

ALTER TABLE mall ADD COLUMN operating_mode text NOT NULL DEFAULT 'both';
ALTER TABLE mall ADD CONSTRAINT mall_operating_mode_chk
    CHECK (operating_mode IN ('consignor', 'booth_mall', 'both'));

ALTER TABLE mall ADD COLUMN payout_cadence text NOT NULL DEFAULT 'monthly';
ALTER TABLE mall ADD CONSTRAINT mall_payout_cadence_chk
    CHECK (payout_cadence IN ('weekly', 'biweekly', 'monthly', 'ad_hoc'));

ALTER TABLE mall ADD COLUMN rent_prepaid_from_prior_sales boolean NOT NULL DEFAULT false;

INSERT INTO accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type)
SELECT '01900000-0000-7000-8000-000000000022', '01900000-0000-7000-8000-000000000001',
       '6100', 'Store purchases', 'expense', 'debit', true, false, NULL
WHERE NOT EXISTS (SELECT 1 FROM accounts WHERE id = '01900000-0000-7000-8000-000000000022');

INSERT INTO chart_roles (role, account_id)
SELECT 'store_purchases', '01900000-0000-7000-8000-000000000022'
WHERE NOT EXISTS (SELECT 1 FROM chart_roles WHERE role = 'store_purchases');

INSERT INTO chart_source_rules (role, source_type)
SELECT seed.role, seed.source_type
FROM (
    VALUES
        ('vendor_payable', 'settlement'),
        ('booth_rent_receivable', 'settlement'),
        ('vendor_payable', 'check_disbursement'),
        ('bank', 'check_disbursement'),
        ('vendor_payable', 'cash_disbursement'),
        ('cash', 'cash_disbursement'),
        ('vendor_payable', 'safe_disbursement'),
        ('cash_in_safe', 'safe_disbursement'),
        ('store_purchases', 'store_purchase'),
        ('vendor_payable', 'store_purchase')
) AS seed(role, source_type)
WHERE NOT EXISTS (
    SELECT 1 FROM chart_source_rules AS existing
    WHERE existing.role = seed.role AND existing.source_type = seed.source_type
);

CREATE TABLE IF NOT EXISTS payout_runs (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    book_id uuid NOT NULL REFERENCES ledger_books (id),
    run_no bigint NOT NULL,
    kind text NOT NULL,
    cadence text NOT NULL,
    sales_from date NOT NULL,
    sales_to date NOT NULL,
    rent_month date,
    tender text NOT NULL,
    status text NOT NULL DEFAULT 'draft',
    posting_date date NOT NULL,
    note text,
    reviewed_checksum text,
    reviewed_at timestamptz,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    settled_at timestamptz,
    paid_at timestamptz,
    reversed_at timestamptz,
    reverses_run_id uuid REFERENCES payout_runs (id),
    CONSTRAINT payout_runs_kind_chk CHECK (kind IN ('batch', 'single')),
    CONSTRAINT payout_runs_cadence_chk CHECK (cadence IN ('weekly', 'biweekly', 'monthly', 'ad_hoc')),
    CONSTRAINT payout_runs_tender_chk CHECK (tender IN ('drawer_cash', 'safe_cash', 'check')),
    CONSTRAINT payout_runs_status_chk CHECK (status IN ('draft', 'settled', 'paid', 'reversed')),
    CONSTRAINT payout_runs_window_chk CHECK (sales_from <= sales_to),
    CONSTRAINT payout_runs_rent_chk CHECK (rent_month IS NULL OR rent_month = date_trunc('month', rent_month)::date),
    CONSTRAINT payout_runs_note_chk CHECK (note IS NULL OR (note = btrim(note) AND note <> '' AND char_length(note) <= 2000)),
    CONSTRAINT payout_runs_reversal_chk CHECK (
        (reverses_run_id IS NULL) OR (status = 'settled' AND reverses_run_id <> id)
    ),
    CONSTRAINT payout_runs_book_no_key UNIQUE (book_id, run_no),
    CONSTRAINT payout_runs_book_id_key UNIQUE (book_id, id)
);

CREATE TABLE IF NOT EXISTS book_payout_run_counters (
    book_id uuid PRIMARY KEY REFERENCES ledger_books (id),
    last_no bigint NOT NULL,
    CONSTRAINT book_payout_run_counters_last_no_chk CHECK (last_no >= 0)
);

INSERT INTO book_payout_run_counters (book_id, last_no)
SELECT id, 0 FROM ledger_books
ON CONFLICT (book_id) DO NOTHING;

CREATE OR REPLACE FUNCTION stall_assign_payout_run_no()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    assigned bigint;
BEGIN
    UPDATE public.book_payout_run_counters
    SET last_no = last_no + 1
    WHERE book_id = NEW.book_id
    RETURNING last_no INTO assigned;
    IF assigned IS NULL THEN
        RAISE EXCEPTION 'a payout run needs a book counter'
            USING ERRCODE = '23514';
    END IF;
    NEW.run_no := assigned;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS payout_runs_assign_no ON payout_runs;
CREATE TRIGGER payout_runs_assign_no
    BEFORE INSERT ON payout_runs
    FOR EACH ROW
    EXECUTE FUNCTION stall_assign_payout_run_no();

CREATE TABLE IF NOT EXISTS payout_run_lines (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    run_id uuid NOT NULL REFERENCES payout_runs (id),
    vendor_id uuid NOT NULL REFERENCES vendors (id),
    mode text NOT NULL,
    sales_minor bigint NOT NULL DEFAULT 0,
    commission_minor bigint NOT NULL DEFAULT 0,
    tax_minor bigint NOT NULL DEFAULT 0,
    discount_minor bigint NOT NULL DEFAULT 0,
    return_minor bigint NOT NULL DEFAULT 0,
    void_minor bigint NOT NULL DEFAULT 0,
    draw_minor bigint NOT NULL DEFAULT 0,
    check_paid_minor bigint NOT NULL DEFAULT 0,
    settlement_applied_minor bigint NOT NULL DEFAULT 0,
    rent_minor bigint NOT NULL DEFAULT 0,
    rent_charged_minor bigint NOT NULL DEFAULT 0,
    rent_brought_forward_minor bigint NOT NULL DEFAULT 0,
    rent_paid_since_minor bigint NOT NULL DEFAULT 0,
    adjustment_minor bigint NOT NULL DEFAULT 0,
    brought_forward_minor bigint NOT NULL DEFAULT 0,
    carried_payable_minor bigint NOT NULL DEFAULT 0,
    carried_rent_minor bigint NOT NULL DEFAULT 0,
    settled_minor bigint NOT NULL DEFAULT 0,
    check_minor bigint NOT NULL DEFAULT 0,
    owes_minor bigint NOT NULL DEFAULT 0,
    net_minor bigint NOT NULL DEFAULT 0,
    settlement_journal_id uuid REFERENCES journals (id),
    disbursement_journal_id uuid REFERENCES journals (id),
    check_number text,
    CONSTRAINT payout_run_lines_mode_chk CHECK (mode IN ('pay', 'credit_only', 'hold')),
    CONSTRAINT payout_run_lines_run_vendor_key UNIQUE (run_id, vendor_id),
    CONSTRAINT payout_run_lines_check_number_chk CHECK (
        check_number IS NULL OR (check_number = btrim(check_number) AND check_number <> '')
    )
);

CREATE TABLE IF NOT EXISTS payout_run_sales (
    run_id uuid NOT NULL REFERENCES payout_runs (id),
    vendor_id uuid NOT NULL REFERENCES vendors (id),
    journal_id uuid NOT NULL REFERENCES journals (id),
    line_no integer NOT NULL,
    item_id uuid NOT NULL REFERENCES items (id),
    sold_on date NOT NULL,
    item_code text NOT NULL,
    description text NOT NULL,
    price_minor bigint NOT NULL,
    commission_minor bigint NOT NULL,
    tax_minor bigint NOT NULL,
    discount_minor bigint NOT NULL DEFAULT 0,
    quantity integer NOT NULL DEFAULT 1,
    PRIMARY KEY (run_id, journal_id, line_no)
);

CREATE TABLE IF NOT EXISTS payout_run_journals (
    run_id uuid NOT NULL REFERENCES payout_runs (id),
    journal_id uuid NOT NULL REFERENCES journals (id),
    role text NOT NULL,
    PRIMARY KEY (run_id, journal_id),
    CONSTRAINT payout_run_journals_role_chk
        CHECK (role IN ('settlement', 'disbursement', 'rent_charge', 'reversal'))
);

CREATE OR REPLACE FUNCTION stall_reject_closed_payout_window()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM public.payout_runs AS run
        WHERE run.status IN ('settled', 'paid')
          AND NEW.posting_date BETWEEN run.sales_from AND run.sales_to
    ) THEN
        RAISE EXCEPTION 'a settled payout window refuses a journal'
            USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS journals_closed_payout_window ON journals;
CREATE CONSTRAINT TRIGGER journals_closed_payout_window
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_closed_payout_window();

CREATE OR REPLACE FUNCTION stall_reject_duplicate_disbursement_check()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM public.journals
        WHERE id = NEW.journal_id
          AND source_type = 'check_disbursement'
    ) THEN
        RETURN NEW;
    END IF;
    IF EXISTS (
        SELECT 1
        FROM public.register_checks AS existing
        JOIN public.journals AS journal ON journal.id = existing.journal_id
        WHERE existing.check_number = NEW.check_number
          AND existing.journal_id <> NEW.journal_id
          AND journal.source_type = 'check_disbursement'
    ) THEN
        RAISE EXCEPTION 'a disbursement check number is unique'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS register_checks_disbursement_number ON register_checks;
CREATE TRIGGER register_checks_disbursement_number
    BEFORE INSERT ON register_checks
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_duplicate_disbursement_check();

CREATE OR REPLACE FUNCTION stall_assert_settlement()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    open_payable bigint;
    open_rent bigint;
    applied bigint;
    payable_ok integer;
    rent_gap integer;
BEGIN
    IF NEW.source_type <> 'settlement' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) INTO open_payable
    FROM public.journal_lines AS line
    JOIN public.journals AS posted ON posted.id = line.journal_id
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id <> NEW.id
      AND posted.source_type NOT IN ('check_disbursement', 'cash_disbursement', 'safe_disbursement')
      AND role.role = 'vendor_payable'
      AND line.subledger_type = 'vendor'
      AND line.subledger_ref = NEW.source_reference;

    SELECT COALESCE(sum(open_minor), 0) INTO open_rent
    FROM (
        SELECT sum(line.debit_minor - line.credit_minor) AS open_minor
        FROM public.journal_lines AS line
        JOIN public.journals AS posted ON posted.id = line.journal_id
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id <> NEW.id
          AND role.role = 'booth_rent_receivable'
          AND line.subledger_type = 'booth'
          AND EXISTS (
              SELECT 1
              FROM public.booth_assignments AS assignment
              WHERE assignment.vendor_id = NEW.source_reference::uuid
                AND assignment.booth_id = line.subledger_ref::uuid
                AND assignment.assignment_range @> posted.posting_date
          )
        GROUP BY line.subledger_ref
        HAVING sum(line.debit_minor - line.credit_minor) > 0
    ) AS booths;

    applied := LEAST(open_payable, open_rent);
    IF applied <= 0 THEN
        RAISE EXCEPTION 'a settlement nets a positive payable and open rent'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO payable_ok
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'vendor_payable'
      AND line.debit_minor = applied
      AND line.credit_minor = 0
      AND line.subledger_ref = NEW.source_reference;

    SELECT count(*) INTO rent_gap
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'booth_rent_receivable'
      AND (
          line.debit_minor <> 0
          OR line.credit_minor <= 0
          OR line.credit_minor > COALESCE((
              SELECT sum(earlier.debit_minor - earlier.credit_minor)
              FROM public.journal_lines AS earlier
              JOIN public.journals AS posted ON posted.id = earlier.journal_id
              JOIN public.chart_roles AS earlier_role ON earlier_role.account_id = earlier.account_id
              WHERE earlier.journal_id <> NEW.id
                AND earlier_role.role = 'booth_rent_receivable'
                AND earlier.subledger_ref = line.subledger_ref
                AND EXISTS (
                    SELECT 1
                    FROM public.booth_assignments AS assignment
                    WHERE assignment.vendor_id = NEW.source_reference::uuid
                      AND assignment.booth_id = earlier.subledger_ref::uuid
                      AND assignment.assignment_range @> posted.posting_date
                )
          ), 0)
      );
    IF rent_gap = 0 AND (
        SELECT COALESCE(sum(line.credit_minor), 0)
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role = 'booth_rent_receivable'
    ) <> applied THEN
        rent_gap := 1;
    END IF;

    IF payable_ok <> 1 OR rent_gap <> 0 THEN
        RAISE EXCEPTION 'a settlement clears the smaller of the open payable and the open rent'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role IN ('cash', 'bank', 'cash_in_safe')
    ) THEN
        RAISE EXCEPTION 'a settlement does not move cash'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS journals_settlement ON journals;
CREATE CONSTRAINT TRIGGER journals_settlement
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_settlement();

CREATE OR REPLACE FUNCTION stall_assert_disbursement()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    open_payable bigint;
    tender text;
    amount bigint;
BEGIN
    IF NEW.is_reversal OR NEW.source_type NOT IN ('check_disbursement', 'cash_disbursement', 'safe_disbursement') THEN
        RETURN NULL;
    END IF;
    tender := CASE NEW.source_type
        WHEN 'check_disbursement' THEN 'bank'
        WHEN 'cash_disbursement' THEN 'cash'
        ELSE 'cash_in_safe'
    END;

    SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) INTO open_payable
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id <> NEW.id
      AND role.role = 'vendor_payable'
      AND line.subledger_ref = NEW.source_reference;

    SELECT line.debit_minor INTO amount
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'vendor_payable'
      AND line.credit_minor = 0
      AND line.subledger_ref = NEW.source_reference;

    IF amount IS NULL OR amount <= 0 OR amount > open_payable THEN
        RAISE EXCEPTION 'a disbursement pays the open payable'
            USING ERRCODE = '23514';
    END IF;

    IF (
        SELECT count(*)
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role = tender
          AND line.credit_minor = amount
          AND line.debit_minor = 0
    ) <> 1 OR (
        SELECT count(*) FROM public.journal_lines WHERE journal_id = NEW.id
    ) <> 2 THEN
        RAISE EXCEPTION 'a disbursement credits only its tender'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS journals_disbursement ON journals;
CREATE CONSTRAINT TRIGGER journals_disbursement
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_disbursement();

CREATE OR REPLACE FUNCTION stall_assert_store_purchase()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF NEW.source_type <> 'store_purchase' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;
    IF (
        SELECT count(*) FROM public.journal_lines WHERE journal_id = NEW.id
    ) <> 2 OR NOT EXISTS (
        SELECT 1
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role = 'store_purchases'
          AND line.debit_minor > 0
          AND line.credit_minor = 0
    ) OR NOT EXISTS (
        SELECT 1
        FROM public.journal_lines AS expense
        JOIN public.chart_roles AS expense_role ON expense_role.account_id = expense.account_id
        JOIN public.journal_lines AS payable ON payable.journal_id = NEW.id
        JOIN public.chart_roles AS payable_role ON payable_role.account_id = payable.account_id
        WHERE expense.journal_id = NEW.id
          AND expense_role.role = 'store_purchases'
          AND payable_role.role = 'vendor_payable'
          AND payable.credit_minor = expense.debit_minor
          AND payable.debit_minor = 0
          AND payable.subledger_type = 'vendor'
    ) THEN
        RAISE EXCEPTION 'a store purchase debits store purchases and credits the vendor'
            USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS journals_store_purchase ON journals;
CREATE CONSTRAINT TRIGGER journals_store_purchase
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_store_purchase();

CREATE OR REPLACE FUNCTION stall_reject_posted_run_line()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    run_status text;
BEGIN
    SELECT status INTO run_status FROM public.payout_runs
    WHERE id = COALESCE(NEW.run_id, OLD.run_id);
    IF TG_OP = 'DELETE' THEN
        IF run_status <> 'draft' THEN
            RAISE EXCEPTION 'a settled payout run report cannot change'
                USING ERRCODE = '23514';
        END IF;
        RETURN OLD;
    END IF;
    IF run_status = 'draft' OR run_status IS NULL THEN
        RETURN NEW;
    END IF;
    IF (NEW.sales_minor, NEW.discount_minor, NEW.commission_minor, NEW.tax_minor,
        NEW.return_minor, NEW.void_minor, NEW.draw_minor, NEW.check_paid_minor,
        NEW.settlement_applied_minor, NEW.rent_minor, NEW.rent_charged_minor,
        NEW.rent_brought_forward_minor, NEW.rent_paid_since_minor,
        NEW.adjustment_minor, NEW.brought_forward_minor, NEW.carried_payable_minor,
        NEW.carried_rent_minor, NEW.settled_minor, NEW.check_minor, NEW.owes_minor,
        NEW.net_minor, NEW.settlement_journal_id, NEW.mode, NEW.vendor_id, NEW.run_id)
       IS DISTINCT FROM
       (OLD.sales_minor, OLD.discount_minor, OLD.commission_minor, OLD.tax_minor,
        OLD.return_minor, OLD.void_minor, OLD.draw_minor, OLD.check_paid_minor,
        OLD.settlement_applied_minor, OLD.rent_minor, OLD.rent_charged_minor,
        OLD.rent_brought_forward_minor, OLD.rent_paid_since_minor,
        OLD.adjustment_minor, OLD.brought_forward_minor, OLD.carried_payable_minor,
        OLD.carried_rent_minor, OLD.settled_minor, OLD.check_minor, OLD.owes_minor,
        OLD.net_minor, OLD.settlement_journal_id, OLD.mode, OLD.vendor_id, OLD.run_id)
    THEN
        RAISE EXCEPTION 'a settled payout run money line cannot change'
            USING ERRCODE = '23514';
    END IF;
    IF OLD.check_number IS NOT NULL AND NEW.check_number IS DISTINCT FROM OLD.check_number THEN
        RAISE EXCEPTION 'a check number is written once'
            USING ERRCODE = '23514';
    END IF;
    IF OLD.disbursement_journal_id IS NOT NULL
       AND NEW.disbursement_journal_id IS DISTINCT FROM OLD.disbursement_journal_id THEN
        RAISE EXCEPTION 'a disbursement journal is written once'
            USING ERRCODE = '23514';
    END IF;
    IF run_status <> 'settled'
       AND (NEW.check_number IS DISTINCT FROM OLD.check_number
            OR NEW.disbursement_journal_id IS DISTINCT FROM OLD.disbursement_journal_id) THEN
        RAISE EXCEPTION 'a check is written only while the run is settled'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION stall_reject_posted_run_sale()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    run_status text;
BEGIN
    SELECT status INTO run_status FROM public.payout_runs
    WHERE id = COALESCE(NEW.run_id, OLD.run_id);
    IF TG_OP = 'DELETE' THEN
        IF run_status <> 'draft' THEN
            RAISE EXCEPTION 'a settled payout run sale row cannot change'
                USING ERRCODE = '23514';
        END IF;
        RETURN OLD;
    END IF;
    IF run_status = 'draft' OR run_status IS NULL THEN
        RETURN NEW;
    END IF;
    IF (NEW.run_id, NEW.vendor_id, NEW.journal_id, NEW.line_no, NEW.item_id, NEW.sold_on,
        NEW.item_code, NEW.description, NEW.price_minor, NEW.commission_minor,
        NEW.tax_minor, NEW.discount_minor, NEW.quantity)
       IS DISTINCT FROM
       (OLD.run_id, OLD.vendor_id, OLD.journal_id, OLD.line_no, OLD.item_id, OLD.sold_on,
        OLD.item_code, OLD.description, OLD.price_minor, OLD.commission_minor,
        OLD.tax_minor, OLD.discount_minor, OLD.quantity)
    THEN
        RAISE EXCEPTION 'a settled payout run sale row cannot change'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS payout_run_lines_frozen ON payout_run_lines;
CREATE TRIGGER payout_run_lines_frozen
    BEFORE UPDATE OR DELETE ON payout_run_lines
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_posted_run_line();

DROP TRIGGER IF EXISTS payout_run_sales_frozen ON payout_run_sales;
CREATE TRIGGER payout_run_sales_frozen
    BEFORE UPDATE OR DELETE ON payout_run_sales
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_posted_run_sale();

CREATE OR REPLACE FUNCTION stall_reject_run_journal_change()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    RAISE EXCEPTION 'a payout run journal cannot change'
        USING ERRCODE = '23514';
END;
$$;

DROP TRIGGER IF EXISTS payout_run_journals_append_only ON payout_run_journals;
CREATE TRIGGER payout_run_journals_append_only
    BEFORE UPDATE OR DELETE ON payout_run_journals
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_run_journal_change();

DROP TRIGGER IF EXISTS payout_runs_no_truncate ON payout_runs;
CREATE TRIGGER payout_runs_no_truncate
    BEFORE TRUNCATE ON payout_runs
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

DROP TRIGGER IF EXISTS payout_run_lines_no_truncate ON payout_run_lines;
CREATE TRIGGER payout_run_lines_no_truncate
    BEFORE TRUNCATE ON payout_run_lines
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

DROP TRIGGER IF EXISTS payout_run_sales_no_truncate ON payout_run_sales;
CREATE TRIGGER payout_run_sales_no_truncate
    BEFORE TRUNCATE ON payout_run_sales
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

DROP TRIGGER IF EXISTS payout_run_journals_no_truncate ON payout_run_journals;
CREATE TRIGGER payout_run_journals_no_truncate
    BEFORE TRUNCATE ON payout_run_journals
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE payout_runs
    ENABLE ALWAYS TRIGGER payout_runs_assign_no,
    ENABLE ALWAYS TRIGGER payout_runs_no_truncate;
ALTER TABLE payout_run_lines
    ENABLE ALWAYS TRIGGER payout_run_lines_frozen,
    ENABLE ALWAYS TRIGGER payout_run_lines_no_truncate;
ALTER TABLE payout_run_sales
    ENABLE ALWAYS TRIGGER payout_run_sales_frozen,
    ENABLE ALWAYS TRIGGER payout_run_sales_no_truncate;
ALTER TABLE payout_run_journals
    ENABLE ALWAYS TRIGGER payout_run_journals_append_only,
    ENABLE ALWAYS TRIGGER payout_run_journals_no_truncate;
ALTER TABLE journals ENABLE ALWAYS TRIGGER journals_closed_payout_window;
ALTER TABLE journals ENABLE ALWAYS TRIGGER journals_settlement;
ALTER TABLE journals ENABLE ALWAYS TRIGGER journals_disbursement;
ALTER TABLE journals ENABLE ALWAYS TRIGGER journals_store_purchase;
ALTER TABLE register_checks ENABLE ALWAYS TRIGGER register_checks_disbursement_number;

GRANT SELECT, INSERT, UPDATE, DELETE ON payout_runs, payout_run_lines, payout_run_sales, payout_run_journals TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON payout_runs, payout_run_lines, payout_run_sales, payout_run_journals FROM stall_poster;
GRANT SELECT ON payout_runs, payout_run_lines, payout_run_sales, payout_run_journals TO stall_backup;
GRANT UPDATE ON book_payout_run_counters TO stall_poster;
GRANT SELECT ON book_payout_run_counters TO stall_backup;

SELECT stall_seed_export_accounts();
