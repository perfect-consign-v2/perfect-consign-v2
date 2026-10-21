-- A vendor memo credits or debits what the mall owes that vendor.

INSERT INTO public.accounts (
    id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type
) VALUES
    (
        '01900000-0000-7000-8000-000000000028',
        '01900000-0000-7000-8000-000000000001',
        '6015',
        'Vendor allowance',
        'expense',
        'debit',
        true,
        false,
        NULL
    ),
    (
        '01900000-0000-7000-8000-000000000029',
        '01900000-0000-7000-8000-000000000001',
        '4210',
        'Vendor charge',
        'revenue',
        'credit',
        true,
        false,
        NULL
    );

INSERT INTO public.chart_roles (role, account_id) VALUES
    ('vendor_allowance', '01900000-0000-7000-8000-000000000028'),
    ('vendor_charge', '01900000-0000-7000-8000-000000000029');

SELECT public.stall_seed_export_accounts();

INSERT INTO public.chart_source_rules (role, source_type) VALUES
    ('vendor_allowance', 'vendor_memo'),
    ('vendor_charge', 'vendor_memo'),
    ('vendor_payable', 'vendor_memo');

CREATE OR REPLACE FUNCTION public.stall_assert_subledger_non_negative() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    journal_source text;
    worsened integer;
    negative_rent integer;
    tax_balance bigint;
    stored_role text;
BEGIN
    SELECT source_type INTO journal_source
    FROM public.journals
    WHERE id = NEW.id;

    SELECT count(*) INTO worsened
    FROM (
        SELECT touched.subledger_ref
        FROM public.journal_lines AS touched
        JOIN public.chart_roles AS touched_role ON touched_role.account_id = touched.account_id
        WHERE touched.journal_id = NEW.id
          AND touched_role.role = 'vendor_payable'
          AND touched.subledger_type = 'vendor'
        GROUP BY touched.subledger_ref
        HAVING COALESCE((
            SELECT sum(line.credit_minor - line.debit_minor)
            FROM public.journal_lines AS line
            JOIN public.chart_roles AS role ON role.account_id = line.account_id
            WHERE role.role = 'vendor_payable'
              AND line.subledger_type = 'vendor'
              AND line.subledger_ref = touched.subledger_ref
        ), 0) < 0
        AND COALESCE((
            SELECT sum(line.credit_minor - line.debit_minor)
            FROM public.journal_lines AS line
            JOIN public.chart_roles AS role ON role.account_id = line.account_id
            WHERE role.role = 'vendor_payable'
              AND line.subledger_type = 'vendor'
              AND line.subledger_ref = touched.subledger_ref
        ), 0) < COALESCE((
            SELECT sum(prior.credit_minor - prior.debit_minor)
            FROM public.journal_lines AS prior
            JOIN public.chart_roles AS prior_role ON prior_role.account_id = prior.account_id
            WHERE prior.journal_id <> NEW.id
              AND prior_role.role = 'vendor_payable'
              AND prior.subledger_type = 'vendor'
              AND prior.subledger_ref = touched.subledger_ref
        ), 0)
    ) AS worse;

    IF worsened > 0 AND journal_source IS DISTINCT FROM 'vendor_memo' THEN
        RAISE EXCEPTION 'a vendor payable cannot go below zero'
            USING ERRCODE = '23514';
    END IF;

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

    IF negative_rent > 0 THEN
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

CREATE FUNCTION public.stall_reject_vendor_memo_write() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'a vendor memo cannot be changed'
        USING ERRCODE = '25006';
END;
$$;

CREATE FUNCTION public.stall_apply_vendor_memo() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    allowance bigint;
    charge bigint;
    payable_credit bigint;
    payable_debit bigint;
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM public.journals
        WHERE id = NEW.journal_id
          AND source_type = 'vendor_memo'
          AND is_reversal = false
          AND posting_key = 'vendor-memo:' || source_reference
    ) THEN
        RAISE EXCEPTION 'a vendor memo names its journal'
            USING ERRCODE = '23514';
    END IF;

    SELECT COALESCE(sum(line.debit_minor), 0)
    INTO allowance
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.journal_id
      AND role.role = 'vendor_allowance'
      AND line.debit_minor > 0
      AND line.credit_minor = 0
      AND line.subledger_type IS NULL;

    SELECT COALESCE(sum(line.credit_minor), 0)
    INTO charge
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.journal_id
      AND role.role = 'vendor_charge'
      AND line.credit_minor > 0
      AND line.debit_minor = 0
      AND line.subledger_type IS NULL;

    SELECT COALESCE(sum(line.credit_minor), 0)
    INTO payable_credit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.journal_id
      AND role.role = 'vendor_payable'
      AND line.credit_minor > 0
      AND line.debit_minor = 0
      AND line.subledger_type = 'vendor'
      AND line.subledger_ref = NEW.vendor_id::text;

    SELECT COALESCE(sum(line.debit_minor), 0)
    INTO payable_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.journal_id
      AND role.role = 'vendor_payable'
      AND line.debit_minor > 0
      AND line.credit_minor = 0
      AND line.subledger_type = 'vendor'
      AND line.subledger_ref = NEW.vendor_id::text;

    IF (SELECT count(*) FROM public.journal_lines WHERE journal_id = NEW.journal_id) <> 2 THEN
        RAISE EXCEPTION 'a vendor memo posts the vendor and one other account'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.direction = 'credit' AND (allowance <> NEW.amount_minor OR payable_credit <> NEW.amount_minor OR charge <> 0 OR payable_debit <> 0) THEN
        RAISE EXCEPTION 'a vendor memo posts the vendor and one other account'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.direction = 'debit' AND (charge <> NEW.amount_minor OR payable_debit <> NEW.amount_minor OR allowance <> 0 OR payable_credit <> 0) THEN
        RAISE EXCEPTION 'a vendor memo posts the vendor and one other account'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_assert_vendor_memo() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF NEW.source_type <> 'vendor_memo' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;
    IF NEW.posting_key <> 'vendor-memo:' || NEW.source_reference THEN
        RAISE EXCEPTION 'a vendor memo posting key is its source reference'
            USING ERRCODE = '23514';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM public.vendor_memos WHERE journal_id = NEW.id
    ) THEN
        RAISE EXCEPTION 'a vendor memo names the vendor'
            USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

CREATE FUNCTION public.stall_reject_second_vendor_memo() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF NEW.is_reversal IS DISTINCT FROM true
        OR NEW.source_type <> 'vendor_memo'
        OR NEW.reverses_journal_id IS NULL THEN
        RETURN NEW;
    END IF;
    IF EXISTS (
        SELECT 1
        FROM public.journals AS other
        WHERE other.reverses_journal_id = NEW.reverses_journal_id
          AND other.is_reversal = true
          AND other.id <> NEW.id
    ) THEN
        RAISE EXCEPTION 'a second void is refused'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TABLE public.vendor_memos (
    id uuid DEFAULT uuidv7() NOT NULL,
    journal_id uuid NOT NULL,
    vendor_id uuid NOT NULL,
    direction text NOT NULL,
    amount_minor bigint NOT NULL,
    reason text NOT NULL,
    CONSTRAINT vendor_memos_pkey PRIMARY KEY (id),
    CONSTRAINT vendor_memos_journal_key UNIQUE (journal_id),
    CONSTRAINT vendor_memos_direction_chk CHECK ((direction = ANY (ARRAY['credit'::text, 'debit'::text]))),
    CONSTRAINT vendor_memos_amount_chk CHECK ((amount_minor > 0)),
    CONSTRAINT vendor_memos_reason_chk CHECK (((reason = btrim(reason)) AND (reason <> '') AND (char_length(reason) <= 200)))
);

CREATE TRIGGER vendor_memos_apply_no_delete
    BEFORE INSERT ON public.vendor_memos
    FOR EACH ROW EXECUTE FUNCTION public.stall_apply_vendor_memo();

CREATE TRIGGER vendor_memos_no_delete
    BEFORE UPDATE OR DELETE ON public.vendor_memos
    FOR EACH ROW EXECUTE FUNCTION public.stall_reject_vendor_memo_write();

CREATE TRIGGER vendor_memos_no_truncate
    BEFORE TRUNCATE ON public.vendor_memos
    FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.vendor_memos
    ENABLE ALWAYS TRIGGER vendor_memos_apply_no_delete,
    ENABLE ALWAYS TRIGGER vendor_memos_no_delete,
    ENABLE ALWAYS TRIGGER vendor_memos_no_truncate;

CREATE CONSTRAINT TRIGGER journals_vendor_memo_shape
    AFTER INSERT ON public.journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION public.stall_assert_vendor_memo();

CREATE TRIGGER journals_vendor_memo_once
    AFTER INSERT ON public.journals
    FOR EACH ROW EXECUTE FUNCTION public.stall_reject_second_vendor_memo();

CREATE CONSTRAINT TRIGGER journals_payable_floor_no_delete
    AFTER INSERT ON public.journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW EXECUTE FUNCTION public.stall_assert_subledger_non_negative();

ALTER TABLE public.journals ENABLE ALWAYS TRIGGER journals_payable_floor_no_delete;

ALTER TABLE ONLY public.vendor_memos
    ADD CONSTRAINT vendor_memos_journal_id_fkey FOREIGN KEY (journal_id) REFERENCES public.journals(id);
ALTER TABLE ONLY public.vendor_memos
    ADD CONSTRAINT vendor_memos_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendors(id);

GRANT SELECT, INSERT ON TABLE public.vendor_memos TO stall_poster;
