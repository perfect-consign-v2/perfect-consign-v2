-- Checks, cash in the safe, over/short, and stored value.
-- A certificate, card, or store credit is an identity. Its open credit cannot go below zero.
-- Apply after 027. The provisioner rewrites stall_poster.

INSERT INTO accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type)
SELECT seed.id, seed.book_id, seed.code, seed.name, seed.account_type, seed.normal_balance, seed.is_postable, seed.is_control, seed.subledger_type
FROM (
    VALUES
        ('01900000-0000-7000-8000-00000000001a'::uuid, '01900000-0000-7000-8000-000000000001'::uuid, '1015', 'Cash in safe', 'asset', 'debit', true, false, NULL::text),
        ('01900000-0000-7000-8000-00000000001b'::uuid, '01900000-0000-7000-8000-000000000001'::uuid, '1100', 'Checks', 'asset', 'debit', true, false, NULL::text),
        ('01900000-0000-7000-8000-00000000001c'::uuid, '01900000-0000-7000-8000-000000000001'::uuid, '2200', 'Gift certificates', 'liability', 'credit', true, true, 'gift_certificate'),
        ('01900000-0000-7000-8000-00000000001d'::uuid, '01900000-0000-7000-8000-000000000001'::uuid, '2210', 'Gift cards', 'liability', 'credit', true, true, 'gift_card'),
        ('01900000-0000-7000-8000-00000000001e'::uuid, '01900000-0000-7000-8000-000000000001'::uuid, '2220', 'Store credit', 'liability', 'credit', true, true, 'store_credit'),
        ('01900000-0000-7000-8000-00000000001f'::uuid, '01900000-0000-7000-8000-000000000001'::uuid, '6150', 'Cash over/short', 'expense', 'debit', true, false, NULL)
) AS seed(id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type)
WHERE NOT EXISTS (
    SELECT 1 FROM accounts WHERE id = seed.id
);

INSERT INTO chart_roles (role, account_id)
SELECT seed.role, seed.account_id
FROM (
    VALUES
        ('cash_in_safe', '01900000-0000-7000-8000-00000000001a'::uuid),
        ('checks', '01900000-0000-7000-8000-00000000001b'::uuid),
        ('gift_certificate', '01900000-0000-7000-8000-00000000001c'::uuid),
        ('gift_card', '01900000-0000-7000-8000-00000000001d'::uuid),
        ('store_credit', '01900000-0000-7000-8000-00000000001e'::uuid),
        ('cash_over_short', '01900000-0000-7000-8000-00000000001f'::uuid)
) AS seed(role, account_id)
WHERE NOT EXISTS (
    SELECT 1 FROM chart_roles WHERE role = seed.role
);

INSERT INTO chart_source_rules (role, source_type)
SELECT seed.role, seed.source_type
FROM (
    VALUES
        ('cash', 'gift_certificate_issue'),
        ('cash', 'gift_card_issue'),
        ('cash', 'store_credit_issue'),
        ('cash', 'cash_drop'),
        ('cash', 'register_variance'),
        ('card', 'gift_certificate_issue'),
        ('card', 'gift_card_issue'),
        ('card', 'store_credit_issue'),
        ('checks', 'sale'),
        ('checks', 'gift_certificate_issue'),
        ('checks', 'gift_card_issue'),
        ('checks', 'store_credit_issue'),
        ('cash_in_safe', 'cash_drop'),
        ('cash_over_short', 'register_variance'),
        ('gift_certificate', 'gift_certificate_issue'),
        ('gift_certificate', 'sale'),
        ('gift_card', 'gift_card_issue'),
        ('gift_card', 'sale'),
        ('store_credit', 'store_credit_issue'),
        ('store_credit', 'sale')
) AS seed(role, source_type)
WHERE NOT EXISTS (
    SELECT 1
    FROM chart_source_rules AS existing
    WHERE existing.role = seed.role
      AND existing.source_type = seed.source_type
);

CREATE TABLE stored_values (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    kind text NOT NULL,
    number text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT stored_values_kind_chk CHECK (
        kind IN ('gift_certificate', 'gift_card', 'store_credit')
    ),
    CONSTRAINT stored_values_number_chk CHECK (
        number = btrim(number) AND number <> '' AND char_length(number) <= 40
    )
);

CREATE UNIQUE INDEX stored_values_number_key ON stored_values (kind, lower(number));

GRANT SELECT, INSERT, UPDATE, DELETE ON stored_values TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON stored_values FROM stall_poster;
GRANT SELECT ON stored_values TO stall_backup;

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
    ELSIF NEW.subledger_type IN ('gift_certificate', 'gift_card', 'store_credit') THEN
        PERFORM 1
        FROM public.stored_values
        WHERE id = NEW.subledger_ref::uuid
          AND kind = NEW.subledger_type
        FOR UPDATE;
        IF NOT FOUND THEN
            RAISE EXCEPTION 'stored value subledger ref must be that kind of number'
                USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION stall_assert_subledger_non_negative()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    negative_payable integer;
    negative_rent integer;
    tax_balance bigint;
    stored_role text;
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

    SELECT role.role INTO stored_role
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE role.role IN ('gift_certificate', 'gift_card', 'store_credit')
      AND line.subledger_ref IN (
          SELECT touched.subledger_ref
          FROM public.journal_lines AS touched
          JOIN public.chart_roles AS touched_role ON touched_role.account_id = touched.account_id
          WHERE touched.journal_id = NEW.id
            AND touched_role.role = role.role
      )
    GROUP BY role.role, line.subledger_ref
    HAVING sum(line.credit_minor - line.debit_minor) < 0
    LIMIT 1;

    IF stored_role = 'gift_certificate' THEN
        RAISE EXCEPTION 'a gift certificate cannot go below zero'
            USING ERRCODE = '23514';
    ELSIF stored_role = 'gift_card' THEN
        RAISE EXCEPTION 'a gift card cannot go below zero'
            USING ERRCODE = '23514';
    ELSIF stored_role = 'store_credit' THEN
        RAISE EXCEPTION 'store credit cannot go below zero'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE OR REPLACE FUNCTION stall_assert_stored_value_issue()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    kind text;
    line_count integer;
    debit_ok integer;
    credit_ok integer;
BEGIN
    IF NEW.is_reversal OR NEW.source_type NOT IN (
        'gift_certificate_issue',
        'gift_card_issue',
        'store_credit_issue'
    ) THEN
        RETURN NULL;
    END IF;

    kind := CASE NEW.source_type
        WHEN 'gift_certificate_issue' THEN 'gift_certificate'
        WHEN 'gift_card_issue' THEN 'gift_card'
        ELSE 'store_credit'
    END;

    SELECT count(*) INTO line_count
    FROM public.journal_lines
    WHERE journal_id = NEW.id;

    SELECT count(*) INTO debit_ok
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role IN ('cash', 'card', 'checks')
      AND line.debit_minor = NEW.debit_total_minor
      AND line.credit_minor = 0
      AND line.subledger_type IS NULL;

    SELECT count(*) INTO credit_ok
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = kind
      AND line.credit_minor = NEW.credit_total_minor
      AND line.debit_minor = 0
      AND line.subledger_type = kind;

    IF NEW.debit_total_minor < 1 OR line_count <> 2 OR debit_ok <> 1 OR credit_ok <> 1 THEN
        RAISE EXCEPTION 'stored value is issued for cash, card, or a check'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER journals_stored_value_issue
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_stored_value_issue();

CREATE OR REPLACE FUNCTION stall_reject_stored_value_change()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF EXISTS (
            SELECT 1
            FROM public.journal_lines
            WHERE subledger_type = OLD.kind
              AND subledger_ref = OLD.id::text
        ) THEN
            RAISE EXCEPTION 'a stored value referenced by a journal line cannot be deleted'
                USING ERRCODE = '23514';
        END IF;
        RETURN OLD;
    END IF;

    RAISE EXCEPTION 'a stored value number cannot be changed'
        USING ERRCODE = '23514';
END;
$$;

CREATE TRIGGER stored_values_append_only
    BEFORE UPDATE ON stored_values
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_stored_value_change();

CREATE TRIGGER stored_values_no_delete
    BEFORE DELETE ON stored_values
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_stored_value_change();

CREATE TRIGGER stored_values_no_truncate
    BEFORE TRUNCATE ON stored_values
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE stored_values
    ENABLE ALWAYS TRIGGER stored_values_append_only,
    ENABLE ALWAYS TRIGGER stored_values_no_delete,
    ENABLE ALWAYS TRIGGER stored_values_no_truncate;

INSERT INTO export_accounts (system, role, external_code)
SELECT systems.system, role.role, account.code
FROM chart_roles AS role
JOIN accounts AS account ON account.id = role.account_id
CROSS JOIN (VALUES ('quickbooks'), ('xero')) AS systems (system)
WHERE NOT EXISTS (
    SELECT 1
    FROM export_accounts AS existing
    WHERE existing.system = systems.system
      AND existing.role = role.role
);
