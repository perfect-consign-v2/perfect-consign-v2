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
    reversed_by text,
    reversal_reason text,
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
    CONSTRAINT payout_runs_reversal_audit_chk CHECK (
        (reverses_run_id IS NULL AND reversed_by IS NULL AND reversal_reason IS NULL)
        OR (
            reverses_run_id IS NOT NULL
            AND reversed_by = btrim(reversed_by) AND reversed_by <> ''
            AND reversal_reason = btrim(reversal_reason) AND reversal_reason <> ''
            AND char_length(reversal_reason) <= 2000
        )
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
        WHERE run.kind = 'batch'
          AND run.reverses_run_id IS NULL
          AND run.status IN ('settled', 'paid')
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
          AND NOT EXISTS (
              SELECT 1 FROM public.journals AS reversal
              WHERE reversal.reverses_journal_id = journal.id
          )
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

-- One projection. Review hashes it live. Post stores the same rows and hashes those.

CREATE OR REPLACE VIEW payout_pack_sales AS
SELECT
    run.id AS run_id,
    item.vendor_id,
    journal.id AS journal_id,
    sale.line_no,
    sale.item_id,
    journal.posting_date AS sold_on,
    item.code AS item_code,
    item.description,
    sale.price_minor,
    sale.commission_minor,
    sale.tax_minor,
    sale.discount_minor,
    sale.quantity
FROM payout_runs AS run
JOIN journals AS journal
    ON journal.posting_date BETWEEN run.sales_from AND run.sales_to
   AND journal.source_type = 'sale'
   AND NOT journal.is_reversal
JOIN sale_lines AS sale ON sale.journal_id = journal.id
JOIN items AS item ON item.id = sale.item_id
WHERE NOT EXISTS (
    SELECT 1
    FROM payout_run_journals AS link
    WHERE link.run_id = run.id
      AND link.journal_id = journal.id
)
AND journal.posting_key NOT LIKE 'settlement:' || run.id::text || ':%'
AND journal.posting_key NOT LIKE 'disbursement:' || run.id::text || ':%';

CREATE OR REPLACE VIEW payout_pack AS
WITH params AS (
    SELECT
        run.id AS run_id,
        run.sales_from,
        run.sales_to,
        run.rent_month,
        run.posting_date,
        vendor.id AS vendor_id,
        vendor.code,
        btrim(vendor.first_name || ' ' || vendor.last_name) AS name,
        vendor.payout_mode AS mode
    FROM payout_runs AS run
    JOIN vendors AS vendor ON vendor.active
),
own AS (
    SELECT run.id AS run_id, journal.id AS journal_id
    FROM payout_runs AS run
    JOIN journals AS journal
        ON journal.posting_key LIKE 'settlement:' || run.id::text || ':%'
        OR journal.posting_key LIKE 'disbursement:' || run.id::text || ':%'
    UNION
    SELECT link.run_id, link.journal_id
    FROM payout_run_journals AS link
),
pay AS (
    SELECT
        params.run_id,
        params.vendor_id,
        COALESCE(sum(line.credit_minor - line.debit_minor) FILTER (
            WHERE journal.posting_date < params.sales_from
        ), 0) AS brought_forward_minor,
        COALESCE(sum(line.credit_minor - line.debit_minor) FILTER (
            WHERE journal.posting_date >= params.sales_from
        ), 0) AS movement_minor,
        COALESCE(sum(line.debit_minor - line.credit_minor) FILTER (
            WHERE journal.source_type = 'piece_return'
              AND journal.posting_date BETWEEN params.sales_from AND params.sales_to
        ), 0) AS return_minor,
        COALESCE(sum(line.debit_minor - line.credit_minor) FILTER (
            WHERE journal.source_type = 'sale'
              AND journal.is_reversal
              AND journal.posting_date BETWEEN params.sales_from AND params.sales_to
        ), 0) AS void_minor,
        COALESCE(sum(line.debit_minor) FILTER (
            WHERE journal.source_type = 'sale'
              AND NOT journal.is_reversal
              AND journal.posting_date BETWEEN params.sales_from AND params.sales_to
        ), 0) AS draw_minor,
        COALESCE(sum(line.debit_minor - line.credit_minor) FILTER (
            WHERE journal.source_type IN ('check_disbursement', 'cash_disbursement', 'safe_disbursement')
              AND journal.posting_date BETWEEN params.sales_from AND params.sales_to
        ), 0) AS check_paid_minor,
        COALESCE(sum(line.debit_minor - line.credit_minor) FILTER (
            WHERE journal.source_type = 'settlement'
              AND journal.posting_date BETWEEN params.sales_from AND params.sales_to
        ), 0) AS settlement_applied_minor
    FROM params
    JOIN journal_lines AS line
        ON line.subledger_type = 'vendor'
       AND line.subledger_ref = params.vendor_id::text
    JOIN journals AS journal ON journal.id = line.journal_id
    JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'vendor_payable'
    WHERE NOT EXISTS (
        SELECT 1 FROM own
        WHERE own.run_id = params.run_id AND own.journal_id = journal.id
    )
    GROUP BY params.run_id, params.vendor_id
),
sale AS (
    SELECT
        run_id,
        vendor_id,
        COALESCE(sum(price_minor * quantity), 0) AS sales_minor,
        COALESCE(sum(discount_minor), 0) AS discount_minor,
        COALESCE(sum(commission_minor), 0) AS commission_minor,
        COALESCE(sum(tax_minor), 0) AS tax_minor
    FROM payout_pack_sales
    GROUP BY run_id, vendor_id
),
rent_posted AS (
    SELECT
        params.run_id,
        params.vendor_id,
        COALESCE(sum(line.debit_minor - line.credit_minor), 0) AS total_minor,
        COALESCE(sum(line.debit_minor - line.credit_minor) FILTER (
            WHERE journal.posting_date < params.sales_from
        ), 0) AS before_minor,
        COALESCE(sum(line.debit_minor - line.credit_minor) FILTER (
            WHERE journal.source_type = 'rent_charge'
              AND date_trunc('month', journal.posting_date)::date = params.rent_month
        ), 0) AS month_minor,
        COALESCE(sum(line.credit_minor - line.debit_minor) FILTER (
            WHERE journal.source_type = 'rent_payment'
              AND journal.posting_date BETWEEN params.sales_from AND params.sales_to
        ), 0) AS paid_minor,
        COALESCE(sum(line.credit_minor - line.debit_minor) FILTER (
            WHERE journal.source_type = 'settlement'
              AND journal.posting_date BETWEEN params.sales_from AND params.sales_to
        ), 0) AS settlement_minor
    FROM params
    JOIN journal_lines AS line ON line.subledger_type = 'booth'
    JOIN journals AS journal ON journal.id = line.journal_id
    JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'booth_rent_receivable'
    WHERE EXISTS (
        SELECT 1
        FROM booth_assignments AS assignment
        WHERE assignment.vendor_id = params.vendor_id
          AND assignment.booth_id = line.subledger_ref::uuid
          AND assignment.assignment_range @> journal.posting_date
    )
    AND NOT EXISTS (
        SELECT 1 FROM own
        WHERE own.run_id = params.run_id AND own.journal_id = journal.id
    )
    GROUP BY params.run_id, params.vendor_id
),
missing_month AS (
    SELECT
        params.run_id,
        params.vendor_id,
        series.month_start::date AS month_start,
        GREATEST(series.month_start::date, assignment.starts_on) AS posting_date,
        CASE
            WHEN date_trunc('month', assignment.starts_on)::date = series.month_start::date
             AND EXTRACT(DAY FROM assignment.starts_on) > 1
            THEN (
                (
                    EXTRACT(DAY FROM (series.month_start::date + interval '1 month - 1 day'))::integer
                    - EXTRACT(DAY FROM assignment.starts_on)::integer
                    + 1
                ) * rate.amount_minor
            ) / EXTRACT(DAY FROM (series.month_start::date + interval '1 month - 1 day'))::integer
            ELSE rate.amount_minor
        END AS amount
    FROM params
    JOIN booth_assignments AS assignment ON assignment.vendor_id = params.vendor_id
    JOIN LATERAL generate_series(
        date_trunc('month', assignment.starts_on)::timestamp,
        date_trunc('month', LEAST(COALESCE(assignment.ends_on, params.posting_date), params.posting_date))::timestamp,
        interval '1 month'
    ) AS series(month_start) ON assignment.starts_on <= LEAST(COALESCE(assignment.ends_on, params.posting_date), params.posting_date)
    JOIN LATERAL (
        SELECT amount_minor
        FROM booth_rent_rates
        WHERE booth_id = assignment.booth_id
          AND month_start <= GREATEST(series.month_start::date, assignment.starts_on)
        ORDER BY month_start DESC
        LIMIT 1
    ) AS rate ON true
    WHERE NOT EXISTS (
        SELECT 1
        FROM journals AS journal
        WHERE journal.source_type = 'rent_charge'
          AND journal.posting_key = 'rent-charge:' || assignment.booth_id::text || ':' || to_char(series.month_start::date, 'YYYY-MM')
    )
),
missing AS (
    SELECT
        missing_month.run_id,
        missing_month.vendor_id,
        COALESCE(sum(missing_month.amount), 0) AS total_minor,
        COALESCE(sum(missing_month.amount) FILTER (
            WHERE missing_month.posting_date < params.sales_from
        ), 0) AS before_minor,
        COALESCE(sum(missing_month.amount) FILTER (
            WHERE date_trunc('month', missing_month.month_start)::date = params.rent_month
        ), 0) AS month_minor
    FROM missing_month
    JOIN params
        ON params.run_id = missing_month.run_id
       AND params.vendor_id = missing_month.vendor_id
    GROUP BY missing_month.run_id, missing_month.vendor_id
),
own_rent AS (
    SELECT
        params.run_id,
        params.vendor_id,
        COALESCE(sum(line.debit_minor - line.credit_minor), 0) AS total_minor,
        COALESCE(sum(line.debit_minor - line.credit_minor) FILTER (
            WHERE journal.posting_date < params.sales_from
        ), 0) AS before_minor,
        COALESCE(sum(line.debit_minor - line.credit_minor) FILTER (
            WHERE journal.source_type = 'rent_charge'
              AND date_trunc('month', journal.posting_date)::date = params.rent_month
        ), 0) AS month_minor
    FROM params
    JOIN payout_run_journals AS link
        ON link.run_id = params.run_id
       AND link.role = 'rent_charge'
    JOIN journals AS journal ON journal.id = link.journal_id
    JOIN journal_lines AS line ON line.journal_id = journal.id AND line.subledger_type = 'booth'
    JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'booth_rent_receivable'
    WHERE EXISTS (
        SELECT 1
        FROM booth_assignments AS assignment
        WHERE assignment.vendor_id = params.vendor_id
          AND assignment.booth_id = line.subledger_ref::uuid
          AND assignment.assignment_range @> journal.posting_date
    )
    GROUP BY params.run_id, params.vendor_id
),
figures AS (
    SELECT
        params.run_id,
        params.vendor_id,
        params.code,
        params.name,
        params.mode,
        COALESCE(sale.sales_minor, 0) AS sales_minor,
        COALESCE(sale.commission_minor, 0) AS commission_minor,
        COALESCE(sale.tax_minor, 0) AS tax_minor,
        COALESCE(sale.discount_minor, 0) AS discount_minor,
        COALESCE(pay.return_minor, 0) AS return_minor,
        COALESCE(pay.void_minor, 0) AS void_minor,
        COALESCE(pay.draw_minor, 0) AS draw_minor,
        COALESCE(pay.check_paid_minor, 0) AS check_paid_minor,
        COALESCE(pay.settlement_applied_minor, 0) AS settlement_applied_minor,
        COALESCE(pay.brought_forward_minor, 0) AS brought_forward_minor,
        COALESCE(pay.brought_forward_minor, 0) + COALESCE(pay.movement_minor, 0) AS carried_payable_minor,
        COALESCE(rent_posted.before_minor, 0) + COALESCE(missing.before_minor, 0) + COALESCE(own_rent.before_minor, 0) AS rent_brought_forward_minor,
        COALESCE(rent_posted.month_minor, 0) + COALESCE(missing.month_minor, 0) + COALESCE(own_rent.month_minor, 0) AS rent_minor,
        COALESCE(rent_posted.paid_minor, 0) AS rent_paid_since_minor,
        COALESCE(rent_posted.settlement_minor, 0) AS rent_settlement_minor,
        COALESCE(rent_posted.total_minor, 0) + COALESCE(missing.total_minor, 0) + COALESCE(own_rent.total_minor, 0) AS rent_total_minor
    FROM params
    LEFT JOIN pay ON pay.run_id = params.run_id AND pay.vendor_id = params.vendor_id
    LEFT JOIN sale ON sale.run_id = params.run_id AND sale.vendor_id = params.vendor_id
    LEFT JOIN rent_posted ON rent_posted.run_id = params.run_id AND rent_posted.vendor_id = params.vendor_id
    LEFT JOIN missing ON missing.run_id = params.run_id AND missing.vendor_id = params.vendor_id
    LEFT JOIN own_rent ON own_rent.run_id = params.run_id AND own_rent.vendor_id = params.vendor_id
)
SELECT
    figures.run_id,
    figures.vendor_id,
    figures.code,
    figures.name,
    COALESCE(spot.booth_code, '') AS booth,
    figures.mode,
    figures.sales_minor,
    figures.commission_minor,
    figures.tax_minor,
    figures.discount_minor,
    figures.return_minor,
    figures.void_minor,
    figures.draw_minor,
    figures.check_paid_minor,
    figures.settlement_applied_minor,
    figures.rent_minor,
    figures.rent_total_minor
        - figures.rent_brought_forward_minor
        - figures.rent_minor
        + figures.rent_paid_since_minor
        + figures.rent_settlement_minor AS rent_charged_minor,
    figures.rent_brought_forward_minor,
    figures.rent_paid_since_minor,
    (figures.carried_payable_minor - figures.brought_forward_minor)
        - (figures.sales_minor - figures.discount_minor - figures.commission_minor
           - figures.return_minor - figures.void_minor - figures.draw_minor
           - figures.check_paid_minor - figures.settlement_applied_minor) AS adjustment_minor,
    figures.brought_forward_minor,
    figures.carried_payable_minor,
    figures.rent_total_minor AS carried_rent_minor,
    LEAST(GREATEST(figures.carried_payable_minor, 0), GREATEST(figures.rent_total_minor, 0)) AS settled_minor,
    GREATEST(figures.carried_payable_minor - figures.rent_total_minor, 0) AS check_minor,
    GREATEST(figures.rent_total_minor - figures.carried_payable_minor, 0) AS owes_minor,
    figures.carried_payable_minor - figures.rent_total_minor AS net_minor,
    figures.rent_brought_forward_minor AS prior,
    (figures.rent_total_minor - figures.rent_brought_forward_minor + figures.rent_paid_since_minor + figures.rent_settlement_minor) AS charged,
    figures.rent_paid_since_minor + figures.rent_settlement_minor AS paid,
    figures.carried_payable_minor AS payable,
    figures.rent_total_minor AS rent,
    GREATEST(figures.carried_payable_minor - figures.rent_total_minor, 0) AS "check",
    GREATEST(figures.rent_total_minor - figures.carried_payable_minor, 0) AS owes
FROM figures
LEFT JOIN LATERAL (
    SELECT booth.code AS booth_code
    FROM booth_assignments AS assignment
    JOIN booths AS booth ON booth.id = assignment.booth_id
    JOIN payout_runs AS run ON run.id = figures.run_id
    WHERE assignment.vendor_id = figures.vendor_id
      AND assignment.assignment_range @> run.posting_date
    ORDER BY booth.code
    LIMIT 1
) AS spot ON true;

CREATE OR REPLACE FUNCTION stall_pack_checksum(run uuid)
RETURNS text
LANGUAGE sql
STABLE
SET search_path = pg_catalog
AS $$
    SELECT md5(
        COALESCE((
            SELECT string_agg(
                vendor_id::text || ':' || brought_forward_minor::text || ':' || discount_minor::text || ':' ||
                return_minor::text || ':' || void_minor::text || ':' || draw_minor::text || ':' ||
                check_paid_minor::text || ':' || settlement_applied_minor::text || ':' ||
                rent_brought_forward_minor::text || ':' || rent_charged_minor::text || ':' ||
                rent_minor::text || ':' || rent_paid_since_minor::text || ':' || adjustment_minor::text || ':' ||
                carried_payable_minor::text || ':' || carried_rent_minor::text || ':' || net_minor::text,
                E'\n' ORDER BY vendor_id
            )
            FROM public.payout_pack
            WHERE run_id = run
        ), '')
        || COALESCE((
            SELECT string_agg(
                vendor_id::text || '#' || line_no::text || ':' || item_id::text || ':' || quantity::text || ':' ||
                price_minor::text || ':' || commission_minor::text || ':' || tax_minor::text || ':' || discount_minor::text,
                E'\n' ORDER BY vendor_id, journal_id, line_no
            )
            FROM public.payout_pack_sales
            WHERE run_id = run
        ), '')
    );
$$;

CREATE OR REPLACE FUNCTION stall_pack_checksum_frozen(run uuid)
RETURNS text
LANGUAGE sql
STABLE
SET search_path = pg_catalog
AS $$
    SELECT md5(
        COALESCE((
            SELECT string_agg(
                vendor_id::text || ':' || brought_forward_minor::text || ':' || discount_minor::text || ':' ||
                return_minor::text || ':' || void_minor::text || ':' || draw_minor::text || ':' ||
                check_paid_minor::text || ':' || settlement_applied_minor::text || ':' ||
                rent_brought_forward_minor::text || ':' || rent_charged_minor::text || ':' ||
                rent_minor::text || ':' || rent_paid_since_minor::text || ':' || adjustment_minor::text || ':' ||
                carried_payable_minor::text || ':' || carried_rent_minor::text || ':' || net_minor::text,
                E'\n' ORDER BY vendor_id
            )
            FROM public.payout_run_lines
            WHERE run_id = run
        ), '')
        || COALESCE((
            SELECT string_agg(
                vendor_id::text || '#' || line_no::text || ':' || item_id::text || ':' || quantity::text || ':' ||
                price_minor::text || ':' || commission_minor::text || ':' || tax_minor::text || ':' || discount_minor::text,
                E'\n' ORDER BY vendor_id, journal_id, line_no
            )
            FROM public.payout_run_sales
            WHERE run_id = run
        ), '')
    );
$$;

CREATE OR REPLACE FUNCTION stall_assert_pack_checksum(run uuid)
RETURNS void
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    reviewed text;
    live text;
BEGIN
    SELECT reviewed_checksum INTO reviewed FROM public.payout_runs WHERE id = run;
    live := public.stall_pack_checksum(run);
    IF reviewed IS NULL OR reviewed IS DISTINCT FROM live THEN
        RAISE EXCEPTION 'the ledger moved since you reviewed'
            USING ERRCODE = '23514';
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION stall_assert_frozen_checksum(run uuid)
RETURNS void
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    reviewed text;
    frozen text;
BEGIN
    SELECT reviewed_checksum INTO reviewed FROM public.payout_runs WHERE id = run;
    frozen := public.stall_pack_checksum_frozen(run);
    IF reviewed IS NULL OR reviewed IS DISTINCT FROM frozen THEN
        RAISE EXCEPTION 'the stored report does not match the review'
            USING ERRCODE = '23514';
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION stall_reject_run_status()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status <> 'draft' THEN
            RAISE EXCEPTION 'a payout run leaves draft only through settle'
                USING ERRCODE = '23514';
        END IF;
        RETURN OLD;
    END IF;
    IF OLD.status = 'reversed' THEN
        RAISE EXCEPTION 'a reversed payout run cannot change'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
        (OLD.status = 'draft' AND NEW.status = 'settled')
        OR (OLD.status = 'settled' AND NEW.status = 'paid')
        OR (OLD.status IN ('settled', 'paid') AND NEW.status = 'reversed')
    ) THEN
        RAISE EXCEPTION 'a payout run status cannot skip'
            USING ERRCODE = '23514';
    END IF;
    IF OLD.status <> 'draft' AND (
        NEW.sales_from IS DISTINCT FROM OLD.sales_from
        OR NEW.sales_to IS DISTINCT FROM OLD.sales_to
        OR NEW.rent_month IS DISTINCT FROM OLD.rent_month
        OR NEW.tender IS DISTINCT FROM OLD.tender
        OR NEW.kind IS DISTINCT FROM OLD.kind
        OR NEW.book_id IS DISTINCT FROM OLD.book_id
        OR NEW.run_no IS DISTINCT FROM OLD.run_no
    ) THEN
        RAISE EXCEPTION 'a settled payout run window cannot change'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS payout_runs_status ON payout_runs;
CREATE TRIGGER payout_runs_status
    BEFORE UPDATE OR DELETE ON payout_runs
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_run_status();

ALTER TABLE payout_runs ENABLE ALWAYS TRIGGER payout_runs_status;

GRANT SELECT ON payout_pack, payout_pack_sales TO stall_poster, stall_backup;

GRANT SELECT, INSERT, UPDATE, DELETE ON payout_runs, payout_run_lines, payout_run_sales, payout_run_journals TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON payout_runs, payout_run_lines, payout_run_sales, payout_run_journals FROM stall_poster;
GRANT SELECT ON payout_runs, payout_run_lines, payout_run_sales, payout_run_journals TO stall_backup;
GRANT SELECT, UPDATE ON book_payout_run_counters TO stall_poster;
GRANT SELECT ON book_payout_run_counters TO stall_backup;

SELECT stall_seed_export_accounts();
