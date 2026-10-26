-- Vendor clawback: after payout, a return or void that would overdraw payable
-- posts the excess to vendor receivable (they owe us). Payable stays >= 0.
-- At payout close, apply receivable against payable before rent settlement.

INSERT INTO public.accounts (
    id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type
) VALUES (
    '01900000-0000-7000-8000-00000000002e',
    '01900000-0000-7000-8000-000000000001',
    '1210',
    'Vendor receivable',
    'asset',
    'debit',
    true,
    true,
    'vendor'
);

INSERT INTO public.chart_roles (role, account_id) VALUES
    ('vendor_receivable', '01900000-0000-7000-8000-00000000002e');

INSERT INTO public.chart_source_rules (role, source_type) VALUES
    ('vendor_receivable', 'item_return'),
    ('vendor_receivable', 'clawback_reclass'),
    ('vendor_receivable', 'clawback_apply'),
    ('vendor_payable', 'clawback_reclass'),
    ('vendor_payable', 'clawback_apply');

SELECT public.stall_seed_export_accounts();

ALTER TABLE public.payout_run_journals
    DROP CONSTRAINT payout_run_journals_role_chk;

ALTER TABLE public.payout_run_journals
    ADD CONSTRAINT payout_run_journals_role_chk
    CHECK (role = ANY (ARRAY[
        'settlement'::text,
        'disbursement'::text,
        'rent_charge'::text,
        'reversal'::text,
        'clawback_apply'::text
    ]));

-- item_returns_no_delete blocks CREATE OR REPLACE of its function; rename, replace, rename back.
ALTER TRIGGER item_returns_no_delete ON public.item_returns RENAME TO item_returns_shape_guard;

CREATE OR REPLACE FUNCTION public.stall_assert_item_return() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    sale_price bigint;
    sale_commission bigint;
    sale_tax bigint;
    sale_discount bigint;
    sale_store bigint;
    sale_quantity integer;
    prior_quantity integer;
    prior_price bigint;
    prior_commission bigint;
    prior_tax bigint;
    prior_discount bigint;
    prior_store bigint;
    expected_price bigint;
    expected_commission bigint;
    expected_tax bigint;
    expected_discount bigint;
    expected_store bigint;
    tender_credit bigint;
    tender_lines integer;
    commission_debit bigint;
    tax_debit bigint;
    payable_debit bigint;
    receivable_debit bigint;
    promo_credit bigint;
    promo_debit bigint;
    store_debit bigint;
    placement text;
    cogs_credit bigint;
    inventory_debit bigint;
    holder_owed bigint;
BEGIN
    IF TG_TABLE_NAME = 'item_returns' THEN
        IF TG_OP <> 'INSERT' THEN
            RAISE EXCEPTION 'a return cannot be changed'
                USING ERRCODE = '23514';
        END IF;
        IF NOT EXISTS (
            SELECT 1
            FROM public.journals AS journal
            WHERE journal.id = NEW.journal_id
              AND journal.source_type = 'item_return'
              AND journal.is_reversal = false
        ) THEN
            RAISE EXCEPTION 'a return belongs on a return'
                USING ERRCODE = '23514';
        END IF;
        SELECT sale.price_minor, sale.commission_minor, sale.tax_minor, sale.discount_minor,
               sale.store_discount_minor, sale.quantity
        INTO sale_price, sale_commission, sale_tax, sale_discount, sale_store, sale_quantity
        FROM public.sale_lines AS sale
        JOIN public.journals AS journal ON journal.id = sale.journal_id
        WHERE sale.journal_id = NEW.sale_journal_id
          AND sale.item_id = NEW.item_id
          AND journal.source_type = 'sale'
          AND journal.is_reversal = false
          AND NOT EXISTS (
              SELECT 1
              FROM public.journals AS reversal
              WHERE reversal.reverses_journal_id = journal.id
                AND reversal.is_reversal = true
          );
        SELECT COALESCE(sum(returned.quantity), 0),
               COALESCE(sum(returned.price_minor), 0),
               COALESCE(sum(returned.commission_minor), 0),
               COALESCE(sum(returned.tax_minor), 0),
               COALESCE(sum(returned.discount_minor), 0),
               COALESCE(sum(returned.store_discount_minor), 0)
        INTO prior_quantity, prior_price, prior_commission, prior_tax, prior_discount, prior_store
        FROM public.item_returns AS returned
        JOIN public.journals AS return_journal ON return_journal.id = returned.journal_id
        WHERE returned.sale_journal_id = NEW.sale_journal_id
          AND returned.item_id = NEW.item_id
          AND return_journal.is_reversal = false
          AND NOT EXISTS (
              SELECT 1
              FROM public.journals AS reversal
              WHERE reversal.reverses_journal_id = return_journal.id
                AND reversal.is_reversal = true
          );
        IF sale_price IS NULL
            OR sale_quantity IS NULL
            OR NEW.quantity < 1
            OR prior_quantity + NEW.quantity > sale_quantity THEN
            RAISE EXCEPTION 'a return is more than what was sold'
                USING ERRCODE = '23514';
        END IF;
        IF prior_quantity + NEW.quantity = sale_quantity THEN
            expected_price := sale_price * sale_quantity - prior_price;
            expected_commission := sale_commission - prior_commission;
            expected_tax := sale_tax - prior_tax;
            expected_discount := sale_discount - prior_discount;
            expected_store := sale_store - prior_store;
        ELSE
            expected_price := (sale_price * sale_quantity * NEW.quantity) / sale_quantity;
            expected_commission := (sale_commission * NEW.quantity) / sale_quantity;
            expected_tax := (sale_tax * NEW.quantity) / sale_quantity;
            expected_discount := (sale_discount * NEW.quantity) / sale_quantity;
            expected_store := (sale_store * NEW.quantity) / sale_quantity;
        END IF;
        IF NEW.price_minor <> expected_price
            OR NEW.commission_minor <> expected_commission
            OR NEW.tax_minor <> expected_tax
            OR NEW.discount_minor <> expected_discount
            OR NEW.store_discount_minor <> expected_store THEN
            RAISE EXCEPTION 'a return reverses that item'
                USING ERRCODE = '23514';
        END IF;

        UPDATE public.items
        SET quantity = quantity + NEW.quantity
        WHERE id = NEW.item_id;

        RETURN NEW;
    END IF;

    IF NEW.source_type <> 'item_return' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM public.item_returns WHERE journal_id = NEW.id
    ) THEN
        RAISE EXCEPTION 'a return needs an item'
            USING ERRCODE = '23514';
    END IF;

    SELECT price_minor, commission_minor, tax_minor, discount_minor, store_discount_minor
    INTO sale_price, sale_commission, sale_tax, sale_discount, sale_store
    FROM public.item_returns
    WHERE journal_id = NEW.id;

    SELECT count(*), COALESCE(sum(line.credit_minor), 0)
    INTO tender_lines, tender_credit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND line.credit_minor > 0
      AND role.role IN ('cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit');

    SELECT COALESCE(sum(line.debit_minor), 0)
    INTO commission_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'commission_revenue'
      AND line.debit_minor > 0;

    SELECT COALESCE(sum(line.debit_minor), 0)
    INTO tax_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'sales_tax_payable'
      AND line.debit_minor > 0;

    SELECT COALESCE(sum(line.debit_minor), 0)
    INTO payable_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'vendor_payable'
      AND line.debit_minor > 0
      AND line.subledger_type = 'vendor';

    SELECT COALESCE(sum(line.debit_minor), 0)
    INTO receivable_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'vendor_receivable'
      AND line.debit_minor > 0
      AND line.subledger_type = 'vendor';

    SELECT COALESCE(sum(line.credit_minor), 0), COALESCE(sum(line.debit_minor), 0)
    INTO promo_credit, promo_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'promotion_expense';

    IF tender_lines <> 1
        OR tender_credit <> sale_price - sale_discount - sale_store + sale_tax
        OR commission_debit <> sale_commission
        OR tax_debit <> sale_tax
        OR promo_credit <> sale_store
        OR promo_debit <> 0 THEN
        RAISE EXCEPTION 'a return reverses that item'
            USING ERRCODE = '23514';
    END IF;

    SELECT item.placement
    INTO placement
    FROM public.item_returns AS returned_row
    JOIN public.items AS item ON item.id = returned_row.item_id
    WHERE returned_row.journal_id = NEW.id;

    SELECT COALESCE(sum(line.debit_minor), 0)
    INTO store_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'store_sales'
      AND line.debit_minor > 0;

    SELECT COALESCE(sum(line.credit_minor), 0)
    INTO cogs_credit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'cost_of_goods'
      AND line.credit_minor > 0;

    SELECT COALESCE(sum(line.debit_minor), 0)
    INTO inventory_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'inventory'
      AND line.debit_minor > 0;

    holder_owed := sale_price - sale_discount - sale_commission;

    IF placement = 'store' THEN
        IF payable_debit <> 0
            OR receivable_debit <> 0
            OR store_debit <> sale_price - sale_discount
            OR commission_debit <> 0
            OR cogs_credit <> inventory_debit THEN
            RAISE EXCEPTION 'a return reverses that item'
                USING ERRCODE = '23514';
        END IF;
    ELSIF payable_debit + receivable_debit <> holder_owed
        OR store_debit <> 0 THEN
        RAISE EXCEPTION 'a return reverses that item'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

ALTER TRIGGER item_returns_shape_guard ON public.item_returns RENAME TO item_returns_no_delete;

CREATE OR REPLACE FUNCTION public.stall_assert_clawback() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    lines integer;
    payable_debit bigint;
    payable_credit bigint;
    receivable_debit bigint;
    receivable_credit bigint;
    vendor_ref text;
BEGIN
    IF NEW.source_type NOT IN ('clawback_reclass', 'clawback_apply') OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    SELECT count(*) INTO lines FROM public.journal_lines WHERE journal_id = NEW.id;
    IF lines <> 2 THEN
        RAISE EXCEPTION 'a clawback posts payable and receivable for one vendor'
            USING ERRCODE = '23514';
    END IF;

    SELECT COALESCE(sum(line.debit_minor), 0), COALESCE(sum(line.credit_minor), 0), min(line.subledger_ref)
    INTO payable_debit, payable_credit, vendor_ref
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'vendor_payable'
      AND line.subledger_type = 'vendor';

    SELECT COALESCE(sum(line.debit_minor), 0), COALESCE(sum(line.credit_minor), 0)
    INTO receivable_debit, receivable_credit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'vendor_receivable'
      AND line.subledger_type = 'vendor'
      AND line.subledger_ref IS NOT DISTINCT FROM vendor_ref;

    IF vendor_ref IS NULL THEN
        RAISE EXCEPTION 'a clawback posts payable and receivable for one vendor'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.source_type = 'clawback_reclass' THEN
        IF receivable_debit < 1 OR payable_credit <> receivable_debit OR payable_debit <> 0 OR receivable_credit <> 0 THEN
            RAISE EXCEPTION 'a clawback reclass debits receivable and credits payable'
                USING ERRCODE = '23514';
        END IF;
    ELSIF payable_debit < 1 OR receivable_credit <> payable_debit OR payable_credit <> 0 OR receivable_debit <> 0 THEN
        RAISE EXCEPTION 'a clawback apply debits payable and credits receivable'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.posting_key IS DISTINCT FROM NEW.source_type || ':' || NEW.source_reference THEN
        RAISE EXCEPTION 'a clawback posting key is its source reference'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER journals_clawback_no_delete
    AFTER INSERT ON public.journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION public.stall_assert_clawback();

ALTER TABLE public.journals ENABLE ALWAYS TRIGGER journals_clawback_no_delete;

-- journals_payable_floor_no_delete blocks CREATE OR REPLACE of its function; rename, replace, rename back.
ALTER TRIGGER journals_payable_floor_no_delete ON public.journals RENAME TO journals_payable_floor_guard;

CREATE OR REPLACE FUNCTION public.stall_assert_subledger_non_negative() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    journal_source text;
    worsened integer;
    negative_rent integer;
    negative_receivable integer;
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

    SELECT count(*) INTO negative_receivable
    FROM (
        SELECT line.subledger_ref
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE role.role = 'vendor_receivable'
          AND line.subledger_type = 'vendor'
          AND line.subledger_ref IN (
              SELECT touched.subledger_ref
              FROM public.journal_lines AS touched
              JOIN public.chart_roles AS touched_role ON touched_role.account_id = touched.account_id
              WHERE touched.journal_id = NEW.id
                AND touched_role.role = 'vendor_receivable'
          )
        GROUP BY line.subledger_ref
        HAVING sum(line.debit_minor - line.credit_minor) < 0
    ) AS below;

    IF negative_receivable > 0 THEN
        RAISE EXCEPTION 'a vendor receivable cannot go below zero'
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

ALTER TRIGGER journals_payable_floor_guard ON public.journals RENAME TO journals_payable_floor_no_delete;

CREATE OR REPLACE FUNCTION public.stall_reject_control_source() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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

    IF chart_role IN ('vendor_payable', 'booth_rent_receivable', 'vendor_receivable') THEN
        RAISE EXCEPTION 'a control account line must come from a listed source type'
            USING ERRCODE = '23514';
    END IF;

    RAISE EXCEPTION 'a chart account line must come from a listed source type'
        USING ERRCODE = '23514';
END;
$$;
