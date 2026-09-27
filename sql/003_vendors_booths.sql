-- Vendors and booths. Identity and contact. No money columns.
-- A vendor id is the payable subledger ref. A booth id is the rent subledger ref.

CREATE TABLE vendors (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    code text NOT NULL,
    first_name text NOT NULL,
    last_name text NOT NULL,
    name text GENERATED ALWAYS AS (
        CASE WHEN first_name = last_name THEN first_name ELSE btrim(first_name || ' ' || last_name) END
    ) STORED,
    active boolean NOT NULL DEFAULT true,
    phone text,
    email text,
    address text,
    commission_bps integer,
    agreement_starts_on date,
    agreement_ends_on date,
    tax_id text,
    tax_exempt boolean NOT NULL DEFAULT false,
    tax_exempt_number text,
    notes text,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT vendors_code_chk CHECK (code = btrim(code) AND code <> ''),
    CONSTRAINT vendors_first_name_chk CHECK (first_name = btrim(first_name) AND first_name <> ''),
    CONSTRAINT vendors_last_name_chk CHECK (last_name = btrim(last_name) AND last_name <> ''),
    CONSTRAINT vendors_code_key UNIQUE (code),
    CONSTRAINT vendors_phone_chk CHECK (
        phone IS NULL OR (phone = btrim(phone) AND phone <> '' AND char_length(phone) <= 40)
    ),
    CONSTRAINT vendors_email_chk CHECK (
        email IS NULL OR (
            email = btrim(email)
            AND email ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'
            AND char_length(email) <= 200
        )
    ),
    CONSTRAINT vendors_address_chk CHECK (
        address IS NULL OR (address = btrim(address) AND address <> '' AND char_length(address) <= 400)
    ),
    CONSTRAINT vendors_commission_chk CHECK (commission_bps >= 0 AND commission_bps <= 10000),
    CONSTRAINT vendors_agreement_chk CHECK (
        agreement_ends_on IS NULL OR agreement_starts_on IS NULL OR agreement_starts_on <= agreement_ends_on
    ),
    CONSTRAINT vendors_tax_id_chk CHECK (
        tax_id IS NULL OR (tax_id = btrim(tax_id) AND tax_id <> '' AND char_length(tax_id) <= 40)
    ),
    CONSTRAINT vendors_tax_exempt_number_chk CHECK (
        tax_exempt_number IS NULL OR (
            tax_exempt_number = btrim(tax_exempt_number)
            AND tax_exempt_number <> ''
            AND char_length(tax_exempt_number) <= 100
        )
    ),
    CONSTRAINT vendors_tax_exempt_requires_number_chk CHECK (
        tax_exempt = false OR tax_exempt_number IS NOT NULL
    ),
    CONSTRAINT vendors_notes_chk CHECK (
        notes IS NULL OR (notes = btrim(notes) AND notes <> '' AND char_length(notes) <= 2000)
    )
);

CREATE TABLE booths (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    code text NOT NULL,
    name text NOT NULL,
    width_ft integer,
    depth_ft integer,
    x_ft integer,
    z_ft integer,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT booths_code_chk CHECK (code = btrim(code) AND code <> ''),
    CONSTRAINT booths_name_chk CHECK (name = btrim(name) AND name <> ''),
    CONSTRAINT booths_code_key UNIQUE (code),
    CONSTRAINT booths_size_chk CHECK (
        (width_ft IS NULL) = (depth_ft IS NULL)
        AND (width_ft IS NULL OR (width_ft > 0 AND depth_ft > 0))
    ),
    CONSTRAINT booths_place_chk CHECK (
        (x_ft IS NULL) = (z_ft IS NULL)
        AND (x_ft IS NULL OR (x_ft >= 0 AND z_ft >= 0))
    )
);

CREATE TABLE booth_assignments (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    booth_id uuid NOT NULL REFERENCES booths (id),
    vendor_id uuid NOT NULL REFERENCES vendors (id),
    starts_on date NOT NULL,
    ends_on date,
    assignment_range daterange GENERATED ALWAYS AS (
        daterange(starts_on, COALESCE(ends_on, 'infinity'::date), '[]')
    ) STORED,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT booth_assignments_dates_chk CHECK (ends_on IS NULL OR starts_on <= ends_on),
    CONSTRAINT booth_assignments_no_overlap EXCLUDE USING gist (
        booth_id WITH =,
        assignment_range WITH &&
    )
);

CREATE OR REPLACE FUNCTION stall_reject_assignment_rewrite()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF EXISTS (
            SELECT 1
            FROM public.journal_lines AS line
            JOIN public.journals AS posted ON posted.id = line.journal_id
            JOIN public.chart_roles AS role ON role.account_id = line.account_id
            WHERE role.role = 'booth_rent_receivable'
              AND line.subledger_ref = OLD.booth_id::text
              AND OLD.assignment_range @> posted.posting_date
        ) THEN
            RAISE EXCEPTION 'an assignment delete cannot uncover rent already posted'
                USING ERRCODE = '23514';
        END IF;
        RETURN OLD;
    END IF;

    IF NEW.booth_id IS DISTINCT FROM OLD.booth_id
        OR NEW.vendor_id IS DISTINCT FROM OLD.vendor_id
        OR NEW.starts_on IS DISTINCT FROM OLD.starts_on THEN
        RAISE EXCEPTION 'a booth assignment identity cannot be rewritten'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.ends_on IS DISTINCT FROM OLD.ends_on AND EXISTS (
        SELECT 1
        FROM public.journal_lines AS line
        JOIN public.journals AS posted ON posted.id = line.journal_id
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE role.role = 'booth_rent_receivable'
          AND line.subledger_ref = OLD.booth_id::text
          AND OLD.assignment_range @> posted.posting_date
          AND NOT daterange(NEW.starts_on, COALESCE(NEW.ends_on, 'infinity'::date), '[]') @> posted.posting_date
    ) THEN
        RAISE EXCEPTION 'an assignment end cannot uncover rent already posted'
            USING ERRCODE = '23514';
    END IF;

    IF OLD.ends_on IS NOT NULL AND NEW.ends_on IS DISTINCT FROM OLD.ends_on THEN
        RAISE EXCEPTION 'a booth assignment end date is frozen once set'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER booth_assignments_append_only
    BEFORE UPDATE OR DELETE ON booth_assignments
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_assignment_rewrite();

CREATE OR REPLACE FUNCTION stall_reject_unknown_subledger()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog
AS $$
BEGIN
    IF NEW.subledger_type = 'vendor' THEN
        PERFORM 1
        FROM public.vendors
        WHERE id = NEW.subledger_ref::uuid
        FOR UPDATE;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'vendor payable subledger ref must be a vendor id'
                USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.subledger_type = 'booth' THEN
        PERFORM 1
        FROM public.booths
        WHERE id = NEW.subledger_ref::uuid
        FOR UPDATE;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'booth rent subledger ref must be a booth id'
                USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER journal_lines_subledger_identity
    BEFORE INSERT ON journal_lines
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_unknown_subledger();

CREATE OR REPLACE FUNCTION stall_reject_referenced_party_delete()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF TG_TABLE_NAME = 'vendors' AND EXISTS (
        SELECT 1
        FROM public.journal_lines
        WHERE subledger_type = 'vendor'
          AND subledger_ref = OLD.id::text
    ) THEN
        RAISE EXCEPTION 'a vendor referenced by a journal line cannot be deleted'
            USING ERRCODE = '23514';
    END IF;

    IF TG_TABLE_NAME = 'booths' AND EXISTS (
        SELECT 1
        FROM public.journal_lines
        WHERE subledger_type = 'booth'
          AND subledger_ref = OLD.id::text
    ) THEN
        RAISE EXCEPTION 'a booth referenced by a journal line cannot be deleted'
            USING ERRCODE = '23514';
    END IF;

    IF TG_TABLE_NAME = 'booths' AND EXISTS (
        SELECT 1 FROM public.booth_assignments WHERE booth_id = OLD.id
    ) THEN
        RAISE EXCEPTION 'a booth with an assignment cannot be deleted'
            USING ERRCODE = '23514';
    END IF;

    IF TG_TABLE_NAME = 'booths' AND EXISTS (
        SELECT 1 FROM public.booth_rent_rates WHERE booth_id = OLD.id
    ) THEN
        RAISE EXCEPTION 'a booth with a rent rate cannot be deleted'
            USING ERRCODE = '23514';
    END IF;
    RETURN OLD;
END;
$$;

CREATE TRIGGER vendors_referenced_no_delete
    BEFORE DELETE ON vendors
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_referenced_party_delete();

CREATE TRIGGER booths_referenced_no_delete
    BEFORE DELETE ON booths
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_referenced_party_delete();

CREATE TABLE IF NOT EXISTS chart_source_rules (
    role text NOT NULL REFERENCES chart_roles (role),
    source_type text NOT NULL,
    PRIMARY KEY (role, source_type),
    CONSTRAINT chart_source_rules_source_chk CHECK (source_type = btrim(source_type) AND source_type <> '')
);

INSERT INTO chart_source_rules (role, source_type)
SELECT seed.role, seed.source_type
FROM (
    VALUES
        ('vendor_payable', 'sale'),
        ('vendor_payable', 'rent_charge'),
        ('vendor_payable', 'rent_payment'),
        ('vendor_payable', 'payout'),
        ('booth_rent_receivable', 'sale'),
        ('booth_rent_receivable', 'rent_charge'),
        ('booth_rent_receivable', 'rent_payment'),
        ('booth_rent_receivable', 'payout'),
        ('cash', 'sale'),
        ('cash', 'rent_charge'),
        ('cash', 'rent_payment'),
        ('cash', 'payout'),
        ('cash', 'adjustment'),
        ('cash', 'tax_payment'),
        ('bank', 'sale'),
        ('bank', 'rent_charge'),
        ('bank', 'rent_payment'),
        ('bank', 'payout'),
        ('bank', 'adjustment'),
        ('bank', 'tax_payment'),
        ('sales_tax_payable', 'sale'),
        ('sales_tax_payable', 'rent_charge'),
        ('sales_tax_payable', 'rent_payment'),
        ('sales_tax_payable', 'payout'),
        ('sales_tax_payable', 'adjustment'),
        ('sales_tax_payable', 'tax_payment'),
        ('commission_revenue', 'sale'),
        ('commission_revenue', 'rent_charge'),
        ('commission_revenue', 'rent_payment'),
        ('commission_revenue', 'payout'),
        ('commission_revenue', 'adjustment')
) AS seed(role, source_type)
WHERE NOT EXISTS (
    SELECT 1
    FROM chart_source_rules AS existing
    WHERE existing.role = seed.role
      AND existing.source_type = seed.source_type
);

CREATE OR REPLACE FUNCTION stall_reject_control_source()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    chart_role text;
    journal_source text;
BEGIN
    SELECT role.role INTO chart_role
    FROM public.chart_roles AS role
    WHERE role.account_id = NEW.account_id;

    IF chart_role IS NULL THEN
        RETURN NEW;
    END IF;

    SELECT source_type INTO journal_source
    FROM public.journals
    WHERE id = NEW.journal_id;

    IF journal_source = 'opening' THEN
        RETURN NEW;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.chart_source_rules AS rule
        WHERE rule.role = chart_role
          AND rule.source_type = journal_source
    ) THEN
        RETURN NEW;
    END IF;

    IF chart_role IN ('vendor_payable', 'booth_rent_receivable') THEN
        RAISE EXCEPTION 'a control account line must come from a sale, rent charge, rent payment, or payout'
            USING ERRCODE = '23514';
    END IF;

    RAISE EXCEPTION 'a chart account line must come from a listed source type'
        USING ERRCODE = '23514';
END;
$$;

DROP TRIGGER IF EXISTS chart_source_rules_no_truncate ON chart_source_rules;
CREATE TRIGGER chart_source_rules_no_truncate
    BEFORE TRUNCATE ON chart_source_rules
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

CREATE TRIGGER journal_lines_control_source
    BEFORE INSERT ON journal_lines
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_control_source();

CREATE OR REPLACE FUNCTION stall_assert_subledger_non_negative()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    negative_payable integer;
    negative_rent integer;
    tax_balance bigint;
BEGIN
    SELECT count(*) INTO negative_payable
    FROM (
        SELECT line.subledger_ref
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE role.role = 'vendor_payable'
          AND line.subledger_type = 'vendor'
          AND line.subledger_ref IN (
              SELECT touched.subledger_ref
              FROM public.journal_lines AS touched
              JOIN public.chart_roles AS touched_role ON touched_role.account_id = touched.account_id
              WHERE touched.journal_id = NEW.id
                AND touched_role.role = 'vendor_payable'
          )
        GROUP BY line.subledger_ref
        HAVING sum(line.credit_minor - line.debit_minor) < 0
    ) AS below;

    SELECT count(*) INTO negative_rent
    FROM (
        SELECT line.subledger_ref
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE role.role = 'booth_rent_receivable'
          AND line.subledger_type = 'booth'
          AND line.subledger_ref IN (
              SELECT touched.subledger_ref
              FROM public.journal_lines AS touched
              JOIN public.chart_roles AS touched_role ON touched_role.account_id = touched.account_id
              WHERE touched.journal_id = NEW.id
                AND touched_role.role = 'booth_rent_receivable'
          )
        GROUP BY line.subledger_ref
        HAVING sum(line.debit_minor - line.credit_minor) < 0
    ) AS below;

    IF negative_payable > 0 OR negative_rent > 0 THEN
        RAISE EXCEPTION 'a vendor payable and a booth rent receivable cannot go below zero'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.journal_lines AS touched
        JOIN public.chart_roles AS touched_role ON touched_role.account_id = touched.account_id
        WHERE touched.journal_id = NEW.id
          AND touched_role.role = 'sales_tax_payable'
    ) THEN
        SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) INTO tax_balance
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE role.role = 'sales_tax_payable';
        IF tax_balance < 0 THEN
            RAISE EXCEPTION 'sales tax payable cannot go below zero'
                USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER journals_subledger_non_negative
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_subledger_non_negative();

CREATE TRIGGER vendors_no_truncate
    BEFORE TRUNCATE ON vendors
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

CREATE TRIGGER booths_no_truncate
    BEFORE TRUNCATE ON booths
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

CREATE TRIGGER booth_assignments_no_truncate
    BEFORE TRUNCATE ON booth_assignments
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE vendors
    ENABLE ALWAYS TRIGGER vendors_referenced_no_delete,
    ENABLE ALWAYS TRIGGER vendors_no_truncate;

ALTER TABLE booths
    ENABLE ALWAYS TRIGGER booths_referenced_no_delete,
    ENABLE ALWAYS TRIGGER booths_no_truncate;

ALTER TABLE booth_assignments
    ENABLE ALWAYS TRIGGER booth_assignments_append_only,
    ENABLE ALWAYS TRIGGER booth_assignments_no_truncate;

ALTER TABLE chart_source_rules ENABLE ALWAYS TRIGGER chart_source_rules_no_truncate;
