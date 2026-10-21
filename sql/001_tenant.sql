-- One mall schema. The provisioner creates the schema, then applies this file.
-- public is rewritten to that schema. stall_poster is rewritten to its poster role.
-- Apply once. An existing mall is not upgraded.

SET check_function_bodies = false;
CREATE FUNCTION public.items_reject_disabled_attribute() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    use_color boolean;
    use_category boolean;
    use_size boolean;
    use_condition boolean;
    use_department boolean;
BEGIN
    SELECT mall.use_color, mall.use_category, mall.use_size, mall.use_condition, mall.use_department
      INTO use_color, use_category, use_size, use_condition, use_department
      FROM mall;

    IF (TG_OP = 'INSERT' OR NEW.color_id IS DISTINCT FROM OLD.color_id)
       AND NEW.color_id IS NOT NULL AND NOT use_color THEN
        RAISE EXCEPTION 'color is turned off' USING ERRCODE = '23514';
    END IF;
    IF (TG_OP = 'INSERT' OR NEW.category_id IS DISTINCT FROM OLD.category_id)
       AND NEW.category_id IS NOT NULL AND NOT use_category THEN
        RAISE EXCEPTION 'category is turned off' USING ERRCODE = '23514';
    END IF;
    IF (TG_OP = 'INSERT' OR NEW.size_id IS DISTINCT FROM OLD.size_id)
       AND NEW.size_id IS NOT NULL AND NOT use_size THEN
        RAISE EXCEPTION 'size is turned off' USING ERRCODE = '23514';
    END IF;
    IF (TG_OP = 'INSERT' OR NEW.condition_id IS DISTINCT FROM OLD.condition_id)
       AND NEW.condition_id IS NOT NULL AND NOT use_condition THEN
        RAISE EXCEPTION 'condition is turned off' USING ERRCODE = '23514';
    END IF;
    IF (TG_OP = 'INSERT' OR NEW.department_id IS DISTINCT FROM OLD.department_id)
       AND NEW.department_id IS NOT NULL AND NOT use_department THEN
        RAISE EXCEPTION 'department is turned off' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE FUNCTION public.items_reject_disabled_brand() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    use_brand boolean;
BEGIN
    SELECT mall.use_brand INTO use_brand FROM mall;

    IF (TG_OP = 'INSERT' OR NEW.brand_id IS DISTINCT FROM OLD.brand_id)
       AND NEW.brand_id IS NOT NULL AND NOT use_brand THEN
        RAISE EXCEPTION 'brand is turned off' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE FUNCTION public.ledger_assert_journal_balance() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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

CREATE FUNCTION public.ledger_assert_reversal() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    original_book uuid;
    original_currency char(3);
    original_occurred timestamptz;
    original_posting date;
    swapped_missing integer;
    extra_lines integer;
BEGIN
    IF NOT NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    SELECT book_id, currency, occurred_at, posting_date
    INTO original_book, original_currency, original_occurred, original_posting
    FROM public.journals
    WHERE id = NEW.reverses_journal_id;

    IF original_book IS NULL THEN
        RAISE EXCEPTION 'reversal target journal does not exist'
            USING ERRCODE = '23503';
    END IF;
    IF original_book <> NEW.book_id OR original_currency <> NEW.currency THEN
        RAISE EXCEPTION 'a reversal must use the same book and currency'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.occurred_at <= original_occurred THEN
        RAISE EXCEPTION 'a reversal must be at a later time'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.posting_date < original_posting THEN
        RAISE EXCEPTION 'a reversal posting date must be on or after the original'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO swapped_missing
    FROM (
        SELECT account_id, credit_minor AS debit_minor, debit_minor AS credit_minor, subledger_type, subledger_ref
        FROM public.journal_lines
        WHERE journal_id = NEW.reverses_journal_id
        EXCEPT ALL
        SELECT account_id, debit_minor, credit_minor, subledger_type, subledger_ref
        FROM public.journal_lines
        WHERE journal_id = NEW.id
    ) AS missing;

    SELECT count(*) INTO extra_lines
    FROM (
        SELECT account_id, debit_minor, credit_minor, subledger_type, subledger_ref
        FROM public.journal_lines
        WHERE journal_id = NEW.id
        EXCEPT ALL
        SELECT account_id, credit_minor, debit_minor, subledger_type, subledger_ref
        FROM public.journal_lines
        WHERE journal_id = NEW.reverses_journal_id
    ) AS extra;

    IF swapped_missing > 0 OR extra_lines > 0 THEN
        RAISE EXCEPTION 'a reversal must swap the debit and credit sides'
            USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

CREATE FUNCTION public.ledger_reject_account_change() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF NEW.book_id IS DISTINCT FROM OLD.book_id
        OR NEW.is_postable IS DISTINCT FROM OLD.is_postable
        OR NEW.is_control IS DISTINCT FROM OLD.is_control
        OR NEW.subledger_type IS DISTINCT FROM OLD.subledger_type
        OR NEW.account_type IS DISTINCT FROM OLD.account_type
        OR NEW.normal_balance IS DISTINCT FROM OLD.normal_balance
        OR NEW.code IS DISTINCT FROM OLD.code THEN
        IF EXISTS (SELECT 1 FROM public.journal_lines WHERE account_id = OLD.id) THEN
            RAISE EXCEPTION 'a posted account book, postable flag, control flag, subledger type, type, normal balance, and code are frozen'
                USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.ledger_reject_book_currency() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF NEW.currency IS DISTINCT FROM OLD.currency
        OR NEW.currency_scale IS DISTINCT FROM OLD.currency_scale THEN
        IF EXISTS (SELECT 1 FROM public.journals WHERE book_id = OLD.id) THEN
            RAISE EXCEPTION 'book currency and scale are frozen once a journal exists'
                USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.ledger_reject_journal_context() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    book_currency char(3);
    period_start date;
    period_end date;
    period_status text;
    next_no bigint;
BEGIN
    IF NEW.journal_no IS NOT NULL THEN
        RAISE EXCEPTION 'a journal number is assigned by the database'
            USING ERRCODE = '23514';
    END IF;

    SELECT last_no INTO next_no
    FROM public.book_journal_counters
    WHERE book_id = NEW.book_id
    FOR UPDATE;
    IF next_no IS NULL THEN
        RAISE EXCEPTION 'journal book has no number counter'
            USING ERRCODE = '23503';
    END IF;
    next_no := next_no + 1;
    UPDATE public.book_journal_counters
    SET last_no = next_no
    WHERE book_id = NEW.book_id;
    NEW.journal_no := next_no;

    SELECT currency INTO book_currency
    FROM public.ledger_books
    WHERE id = NEW.book_id
    FOR UPDATE;
    IF book_currency IS NULL THEN
        RAISE EXCEPTION 'journal book does not exist'
            USING ERRCODE = '23503';
    END IF;
    IF NEW.currency <> book_currency THEN
        RAISE EXCEPTION 'journal currency must equal its book currency'
            USING ERRCODE = '23514';
    END IF;

    SELECT starts_on, ends_on, status
    INTO period_start, period_end, period_status
    FROM public.accounting_periods
    WHERE id = NEW.period_id
    FOR UPDATE;
    IF period_start IS NULL THEN
        RAISE EXCEPTION 'journal period does not exist'
            USING ERRCODE = '23503';
    END IF;
    IF NEW.posting_date < period_start OR NEW.posting_date > period_end THEN
        RAISE EXCEPTION 'journal posting date must fall inside its period'
            USING ERRCODE = '23514';
    END IF;
    IF period_status <> 'open' THEN
        RAISE EXCEPTION 'a closed period refuses a new journal'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.ledger_reject_line_account() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    account_postable boolean;
    account_control boolean;
    account_subledger text;
BEGIN
    SELECT is_postable, is_control, subledger_type
    INTO account_postable, account_control, account_subledger
    FROM public.accounts
    WHERE id = NEW.account_id
    FOR UPDATE;
    IF account_postable IS NULL THEN
        RAISE EXCEPTION 'journal line account does not exist'
            USING ERRCODE = '23503';
    END IF;
    IF NOT account_postable THEN
        RAISE EXCEPTION 'journal line account must be postable'
            USING ERRCODE = '23514';
    END IF;
    IF account_control THEN
        IF NEW.subledger_type IS DISTINCT FROM account_subledger OR NEW.subledger_ref IS NULL THEN
            RAISE EXCEPTION 'a line on a control account requires that subledger'
                USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.subledger_type IS NOT NULL THEN
        RAISE EXCEPTION 'a line on a non-control account must not carry a subledger'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.ledger_reject_line_after_post() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    journal_xid xid8;
BEGIN
    SELECT posting_xid INTO journal_xid
    FROM public.journals
    WHERE id = NEW.journal_id;
    IF journal_xid IS NULL THEN
        RAISE EXCEPTION 'journal line journal does not exist'
            USING ERRCODE = '23503';
    END IF;
    IF journal_xid IS DISTINCT FROM pg_current_xact_id() THEN
        RAISE EXCEPTION 'a journal line cannot be added after its journal is posted'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.ledger_reject_mutation() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'a posted journal and its lines cannot be updated or deleted'
        USING ERRCODE = '25006';
END;
$$;

CREATE FUNCTION public.ledger_reject_period_change() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status = 'closed' OR EXISTS (
            SELECT 1 FROM public.journals WHERE period_id = OLD.id
        ) THEN
            RAISE EXCEPTION 'a closed or referenced period cannot be deleted'
                USING ERRCODE = '23514';
        END IF;
        RETURN OLD;
    END IF;

    IF OLD.status = 'closed' AND (
        NEW.status IS DISTINCT FROM OLD.status
        OR NEW.starts_on IS DISTINCT FROM OLD.starts_on
        OR NEW.ends_on IS DISTINCT FROM OLD.ends_on
        OR NEW.book_id IS DISTINCT FROM OLD.book_id
    ) THEN
        RAISE EXCEPTION 'a closed period cannot be reopened or moved'
            USING ERRCODE = '23514';
    END IF;

    IF (
        NEW.book_id IS DISTINCT FROM OLD.book_id
        OR NEW.starts_on IS DISTINCT FROM OLD.starts_on
        OR NEW.ends_on IS DISTINCT FROM OLD.ends_on
    ) AND EXISTS (
        SELECT 1 FROM public.journals WHERE period_id = OLD.id
    ) THEN
        RAISE EXCEPTION 'a referenced period cannot be moved'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.ledger_reject_truncate() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'table % is append-only and cannot be truncated', TG_TABLE_NAME
        USING ERRCODE = '25006';
END;
$$;

CREATE FUNCTION public.ledger_seed_journal_counter() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    INSERT INTO public.book_journal_counters (book_id, last_no)
    VALUES (NEW.id, 0);
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.mall_reject_delete() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'the mall clock cannot be removed'
        USING ERRCODE = '25006';
END;
$$;

CREATE FUNCTION public.mall_reject_zone() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    BEGIN
        PERFORM (CURRENT_TIMESTAMP AT TIME ZONE NEW.time_zone);
    EXCEPTION
        WHEN invalid_parameter_value THEN
            RAISE EXCEPTION 'mall time zone is not an IANA name'
                USING ERRCODE = '23514';
    END;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_apply_disposition() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    today date;
BEGIN
    today := (SELECT (clock_timestamp() AT TIME ZONE mall.time_zone)::date FROM public.mall);
    IF NOT EXISTS (
        SELECT 1 FROM public.items
        WHERE id = NEW.item_id
          AND placement = 'consigned'
          AND quantity > 0
          AND expires_on IS NOT NULL
          AND expires_on < today
    ) THEN
        RAISE EXCEPTION 'only an expired consigned piece can leave the floor'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.kind = 'return' THEN
        UPDATE public.items SET quantity = 0 WHERE id = NEW.item_id;
    ELSIF NEW.kind = 'donate' THEN
        UPDATE public.items
        SET placement = 'store', vendor_id = NULL, expires_on = NULL, commission_bps = NULL, cost_minor = NULL
        WHERE id = NEW.item_id;
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_apply_item_buy() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    expense bigint;
    payable bigint;
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM public.journals
        WHERE id = NEW.journal_id AND source_type = 'store_purchase' AND is_reversal = false
    ) THEN
        RAISE EXCEPTION 'a buy belongs on a store purchase'
            USING ERRCODE = '23514';
    END IF;
    SELECT COALESCE(sum(line.debit_minor), 0)
    INTO expense
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.journal_id AND role.role = 'store_purchases' AND line.debit_minor > 0;
    SELECT COALESCE(sum(line.credit_minor), 0)
    INTO payable
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.journal_id
      AND role.role = 'vendor_payable'
      AND line.credit_minor > 0
      AND line.subledger_type = 'vendor'
      AND line.subledger_ref = NEW.vendor_id::text;
    IF expense <> NEW.amount_minor OR payable <> NEW.amount_minor THEN
        RAISE EXCEPTION 'a buy pays the consignor'
            USING ERRCODE = '23514';
    END IF;
    IF EXISTS (SELECT 1 FROM public.sale_lines WHERE item_id = NEW.item_id) THEN
        RAISE EXCEPTION 'a sold piece cannot be bought'
            USING ERRCODE = '23514';
    END IF;
    UPDATE public.items
    SET placement = 'store',
        vendor_id = NULL,
        expires_on = NULL,
        commission_bps = NULL,
        cost_minor = NEW.amount_minor
    WHERE id = NEW.item_id
      AND placement = 'consigned'
      AND vendor_id = NEW.vendor_id
      AND quantity > 0;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'a buy takes a consigned piece'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_apply_markdown() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    original bigint;
    expected bigint;
    next_step uuid;
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM public.items
        WHERE id = NEW.item_id AND placement = 'consigned' AND quantity > 0
    ) THEN
        RAISE EXCEPTION 'a markdown is for a consigned piece'
            USING ERRCODE = '23514';
    END IF;
    SELECT step.id
    INTO next_step
    FROM public.markdown_steps AS step
    WHERE NOT EXISTS (
        SELECT 1 FROM public.item_price_events AS prior
        WHERE prior.item_id = NEW.item_id AND prior.step_id = step.id
    )
    ORDER BY step.days_on_floor
    LIMIT 1;
    IF next_step IS DISTINCT FROM NEW.step_id THEN
        RAISE EXCEPTION 'a markdown follows the schedule'
            USING ERRCODE = '23514';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM public.items AS aged
        JOIN public.markdown_steps AS step ON step.id = NEW.step_id
        CROSS JOIN public.mall AS mall_clock
        WHERE aged.id = NEW.item_id
          AND aged.intake_on + step.days_on_floor > (clock_timestamp() AT TIME ZONE mall_clock.time_zone)::date
    ) THEN
        RAISE EXCEPTION 'a markdown waits for its day'
            USING ERRCODE = '23514';
    END IF;
    SELECT price_minor INTO original FROM public.items WHERE id = NEW.item_id;
    SELECT event.prior_minor INTO original
    FROM public.item_price_events AS event
    WHERE event.item_id = NEW.item_id
    ORDER BY event.applied_on, event.id
    LIMIT 1;
    IF original IS NULL THEN
        SELECT price_minor INTO original FROM public.items WHERE id = NEW.item_id;
    END IF;
    SELECT public.stall_half_up(original, 10000 - step.percent * 100)
    INTO expected
    FROM public.markdown_steps AS step
    WHERE step.id = NEW.step_id;
    IF NEW.price_minor IS DISTINCT FROM expected OR NEW.prior_minor IS DISTINCT FROM (
        SELECT price_minor FROM public.items WHERE id = NEW.item_id
    ) THEN
        RAISE EXCEPTION 'a markdown keeps the schedule price'
            USING ERRCODE = '23514';
    END IF;
    UPDATE public.items SET price_minor = NEW.price_minor WHERE id = NEW.item_id;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_assert_adjustment() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    line_count integer;
    bad_lines integer;
BEGIN
    IF NEW.source_type <> 'adjustment' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    IF NEW.posting_key <> 'adjustment:' || NEW.source_reference THEN
        RAISE EXCEPTION 'an adjustment posting key is its source reference'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO line_count
    FROM public.journal_lines
    WHERE journal_id = NEW.id;

    SELECT count(*) INTO bad_lines
    FROM public.journal_lines AS line
    WHERE line.journal_id = NEW.id
      AND (
          line.subledger_type IS NOT NULL
          OR NOT EXISTS (
              SELECT 1
              FROM public.chart_roles AS role
              WHERE role.account_id = line.account_id
                AND role.role IN (
                    'cash', 'bank', 'card', 'commission_revenue',
                    'sales_tax_payable', 'rent_revenue', 'operating_expense'
                )
          )
      );

    IF line_count < 2 OR bad_lines > 0 THEN
        RAISE EXCEPTION 'an adjustment posts only to non-control chart accounts'
            USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

CREATE FUNCTION public.stall_assert_booth_on_floor(booth uuid, place_floor uuid, place_x integer, place_z integer, place_width integer, place_depth integer, default_width integer, default_depth integer, floor_width integer, floor_depth integer) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    resolved_width integer;
    resolved_depth integer;
BEGIN
    IF place_x IS NULL THEN
        RETURN;
    END IF;
    resolved_width := COALESCE(place_width, default_width);
    resolved_depth := COALESCE(place_depth, default_depth);
    IF place_x + resolved_width > floor_width OR place_z + resolved_depth > floor_depth THEN
        RAISE EXCEPTION 'a booth cannot extend past the floor'
            USING ERRCODE = '23514';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM public.booths AS other
        WHERE other.id IS DISTINCT FROM booth
          AND other.x_ft IS NOT NULL
          AND other.floor_id IS NOT DISTINCT FROM place_floor
          AND NOT (
              place_x + resolved_width <= other.x_ft
              OR other.x_ft + COALESCE(other.width_ft, default_width) <= place_x
              OR place_z + resolved_depth <= other.z_ft
              OR other.z_ft + COALESCE(other.depth_ft, default_depth) <= place_z
          )
    ) THEN
        RAISE EXCEPTION 'a booth cannot overlap another booth'
            USING ERRCODE = '23514';
    END IF;
END;
$$;

CREATE FUNCTION public.stall_assert_cash_drop() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    line_count integer;
    safe_ok integer;
    cash_ok integer;
BEGIN
    IF NEW.source_type <> 'cash_drop' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    SELECT count(*) INTO line_count
    FROM public.journal_lines
    WHERE journal_id = NEW.id;

    SELECT count(*) INTO safe_ok
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'cash_in_safe'
      AND line.debit_minor = NEW.debit_total_minor
      AND line.credit_minor = 0;

    SELECT count(*) INTO cash_ok
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'cash'
      AND line.credit_minor = NEW.credit_total_minor
      AND line.debit_minor = 0;

    IF NEW.debit_total_minor < 1 OR line_count <> 2 OR safe_ok <> 1 OR cash_ok <> 1 THEN
        RAISE EXCEPTION 'a cash drop moves drawer cash into the safe'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE FUNCTION public.stall_assert_disbursement() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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

CREATE FUNCTION public.stall_assert_frozen_checksum(run uuid) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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

CREATE FUNCTION public.stall_assert_item_return() RETURNS trigger
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
    promo_credit bigint;
    promo_debit bigint;
    store_debit bigint;
    placement text;
    cogs_credit bigint;
    inventory_debit bigint;
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

    IF placement = 'store' THEN
        IF payable_debit <> 0
            OR store_debit <> sale_price - sale_discount
            OR commission_debit <> 0
            OR cogs_credit <> inventory_debit THEN
            RAISE EXCEPTION 'a return reverses that item'
                USING ERRCODE = '23514';
        END IF;
    ELSIF payable_debit <> sale_price - sale_discount - sale_commission
        OR store_debit <> 0 THEN
        RAISE EXCEPTION 'a return reverses that item'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE FUNCTION public.stall_assert_layaway() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    lines integer;
    layaway_credit bigint;
    tender_debit bigint;
    held_ticket text;
BEGIN
    IF NEW.source_type NOT IN ('layaway_deposit', 'layaway_refund') THEN
        RETURN NULL;
    END IF;

    SELECT count(*)::integer INTO lines FROM public.journal_lines WHERE journal_id = NEW.id;
    IF lines <> 2 THEN
        RAISE EXCEPTION 'a layaway deposit is the tender and the deposit'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.source_type = 'layaway_deposit' THEN
        SELECT COALESCE(sum(line.debit_minor), 0) INTO tender_debit
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role IN ('cash', 'card', 'checks')
          AND line.subledger_type IS NULL;
        SELECT COALESCE(sum(line.credit_minor), 0), min(line.subledger_ref) INTO layaway_credit, held_ticket
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role = 'layaway_deposit'
          AND line.subledger_type = 'ticket';
    ELSE
        SELECT COALESCE(sum(line.credit_minor), 0) INTO tender_debit
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role IN ('cash', 'card', 'checks')
          AND line.subledger_type IS NULL;
        SELECT COALESCE(sum(line.debit_minor), 0), min(line.subledger_ref) INTO layaway_credit, held_ticket
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role = 'layaway_deposit'
          AND line.subledger_type = 'ticket';
    END IF;

    IF tender_debit < 1 OR tender_debit IS DISTINCT FROM layaway_credit THEN
        RAISE EXCEPTION 'a layaway deposit is the tender and the deposit'
            USING ERRCODE = '23514';
    END IF;

    IF held_ticket IS NULL THEN
        RAISE EXCEPTION 'a layaway deposit needs its ticket'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.source_type = 'layaway_deposit' AND NOT EXISTS (
        SELECT 1 FROM public.tickets WHERE id = held_ticket::uuid AND status IN ('open', 'layaway')
    ) THEN
        RAISE EXCEPTION 'a layaway deposit needs its ticket'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.source_type = 'layaway_deposit' AND tender_debit > COALESCE((
        SELECT sum(line.price_minor * line.quantity - line.line_discount_minor)
        FROM public.ticket_lines AS line
        WHERE line.ticket_id = held_ticket::uuid
    ), 0) - COALESCE((
        SELECT sum(prior.credit_minor - prior.debit_minor)
        FROM public.journal_lines AS prior
        JOIN public.chart_roles AS role ON role.account_id = prior.account_id
        WHERE role.role = 'layaway_deposit'
          AND prior.subledger_ref = held_ticket
          AND prior.journal_id <> NEW.id
    ), 0) THEN
        RAISE EXCEPTION 'a layaway deposit cannot pass the amount due'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.source_type = 'layaway_refund' AND tender_debit > COALESCE((
        SELECT sum(prior.credit_minor - prior.debit_minor)
        FROM public.journal_lines AS prior
        JOIN public.chart_roles AS role ON role.account_id = prior.account_id
        WHERE role.role = 'layaway_deposit'
          AND prior.subledger_ref = held_ticket
          AND prior.journal_id <> NEW.id
    ), 0) THEN
        RAISE EXCEPTION 'a layaway refund cannot pass what was paid in'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE FUNCTION public.stall_assert_offer() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    tag bigint;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF EXISTS (SELECT 1 FROM public.ticket_lines WHERE offer_id = OLD.id)
            OR EXISTS (SELECT 1 FROM public.sale_lines WHERE offer_id = OLD.id) THEN
            RAISE EXCEPTION 'a deal that has been rung cannot be deleted'
                USING ERRCODE = '23514';
        END IF;
        RETURN OLD;
    END IF;

    IF NEW.scope = 'item' THEN
        SELECT item.price_minor INTO tag
        FROM public.items AS item
        WHERE item.id = NEW.scope_id;
        IF tag IS NULL THEN
            RAISE EXCEPTION 'that item is not on the floor'
                USING ERRCODE = '23514';
        END IF;
        IF NEW.kind = 'bundle' AND NEW.deal_minor >= tag * NEW.threshold_qty THEN
            RAISE EXCEPTION 'the deal is not cheaper than the tag'
                USING ERRCODE = '23514';
        END IF;
        IF NEW.kind = 'unit_break' AND NEW.deal_minor >= tag THEN
            RAISE EXCEPTION 'the deal is not cheaper than the tag'
                USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.scope = 'vendor' AND NOT EXISTS (
        SELECT 1 FROM public.vendors WHERE id = NEW.scope_id
    ) THEN
        RAISE EXCEPTION 'that vendor is not in this mall'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_assert_opening() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    line_count integer;
    bad_lines integer;
BEGIN
    IF NEW.source_type <> 'opening' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    IF NEW.posting_key <> 'opening' THEN
        RAISE EXCEPTION 'an opening posting key is opening'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO line_count
    FROM public.journal_lines
    WHERE journal_id = NEW.id;

    SELECT count(*) INTO bad_lines
    FROM public.journal_lines AS line
    WHERE line.journal_id = NEW.id
      AND NOT EXISTS (
          SELECT 1
          FROM public.chart_roles AS role
          WHERE role.account_id = line.account_id
      );

    IF line_count < 2 OR bad_lines > 0 THEN
        RAISE EXCEPTION 'an opening posts only to chart accounts'
            USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

CREATE FUNCTION public.stall_assert_pack_checksum(run uuid) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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

CREATE FUNCTION public.stall_assert_payout() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF NEW.source_type = 'payout' AND NOT NEW.is_reversal THEN
        RAISE EXCEPTION 'a payout journal is retired'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE FUNCTION public.stall_assert_period_close() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    retained_id uuid;
    period_start date;
BEGIN
    IF NEW.is_reversal THEN
        IF EXISTS (
            SELECT 1
            FROM public.journals AS original
            WHERE original.id = NEW.reverses_journal_id
              AND original.source_type = 'period_close'
        ) THEN
            RAISE EXCEPTION 'a period close is not reversible'
                USING ERRCODE = '23514';
        END IF;
        RETURN NULL;
    END IF;

    IF NEW.source_type <> 'period_close' THEN
        RETURN NULL;
    END IF;

    IF NEW.posting_key <> 'period-close:' || NEW.period_id::text THEN
        RAISE EXCEPTION 'a period close posting key is its period'
            USING ERRCODE = '23514';
    END IF;

    SELECT starts_on INTO period_start
    FROM public.accounting_periods
    WHERE id = NEW.period_id;
    PERFORM public.stall_reject_earlier_open_period(NEW.book_id, period_start);

    SELECT account_id INTO retained_id
    FROM public.chart_roles
    WHERE role = 'retained_earnings';
    IF retained_id IS NULL THEN
        RAISE EXCEPTION 'a period close requires retained earnings'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        WITH movement AS (
            SELECT
                account.id AS account_id,
                sum(line.credit_minor - line.debit_minor) AS net_minor
            FROM public.journal_lines AS line
            JOIN public.journals AS posted ON posted.id = line.journal_id
            JOIN public.accounts AS account ON account.id = line.account_id
            WHERE posted.period_id = NEW.period_id
              AND posted.id <> NEW.id
              AND account.account_type IN ('revenue', 'expense')
            GROUP BY account.id
            HAVING sum(line.credit_minor - line.debit_minor) <> 0
        ),
        expected AS (
            SELECT
                movement.account_id,
                CASE WHEN movement.net_minor > 0 THEN movement.net_minor ELSE 0 END AS debit_minor,
                CASE WHEN movement.net_minor < 0 THEN -movement.net_minor ELSE 0 END AS credit_minor,
                NULL::text AS subledger_type,
                NULL::text AS subledger_ref
            FROM movement
            UNION ALL
            SELECT
                retained_id,
                CASE WHEN income.net_minor < 0 THEN -income.net_minor ELSE 0 END,
                CASE WHEN income.net_minor > 0 THEN income.net_minor ELSE 0 END,
                NULL::text,
                NULL::text
            FROM (
                SELECT COALESCE(sum(movement.net_minor), 0) AS net_minor
                FROM movement
            ) AS income
            WHERE income.net_minor <> 0
        ),
        actual AS (
            SELECT account_id, debit_minor, credit_minor, subledger_type, subledger_ref
            FROM public.journal_lines
            WHERE journal_id = NEW.id
        )
        SELECT account_id, debit_minor, credit_minor, subledger_type, subledger_ref FROM expected
        EXCEPT ALL
        SELECT account_id, debit_minor, credit_minor, subledger_type, subledger_ref FROM actual
        UNION ALL
        (
            SELECT account_id, debit_minor, credit_minor, subledger_type, subledger_ref FROM actual
            EXCEPT ALL
            SELECT account_id, debit_minor, credit_minor, subledger_type, subledger_ref FROM expected
        )
    ) THEN
        RAISE EXCEPTION 'a period close zeros that period income and expense into retained earnings'
            USING ERRCODE = '23514';
    END IF;

    UPDATE public.accounting_periods
    SET status = 'closed'
    WHERE id = NEW.period_id
      AND status = 'open';

    RETURN NULL;
END;
$$;

CREATE FUNCTION public.stall_assert_register_close() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    expected bigint;
    difference bigint;
    variance_count integer;
    variance_cash bigint;
BEGIN
    IF TG_OP <> 'UPDATE' OR OLD.closed_at IS NOT NULL OR NEW.closed_at IS NULL THEN
        RETURN NEW;
    END IF;

    SELECT session.opening_cash_minor + COALESCE((
        SELECT sum(line.debit_minor - line.credit_minor)
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        JOIN public.register_session_posts AS post ON post.journal_id = line.journal_id
        JOIN public.journals AS journal ON journal.id = line.journal_id
        WHERE post.session_id = session.id
          AND role.role = 'cash'
          AND journal.source_type <> 'register_variance'
    ), 0)
    INTO expected
    FROM public.register_sessions AS session
    WHERE session.id = NEW.id;

    difference := NEW.counted_cash_minor - expected;

    SELECT count(*), COALESCE(sum(line.debit_minor - line.credit_minor), 0)
    INTO variance_count, variance_cash
    FROM public.register_session_posts AS post
    JOIN public.journals AS journal ON journal.id = post.journal_id
    JOIN public.journal_lines AS line ON line.journal_id = journal.id
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE post.session_id = NEW.id
      AND journal.source_type = 'register_variance'
      AND role.role = 'cash';

    IF difference = 0 AND variance_count = 0 THEN
        RETURN NEW;
    END IF;

    IF difference <> 0 AND variance_count = 1 AND variance_cash = difference THEN
        RETURN NEW;
    END IF;

    RAISE EXCEPTION 'counted cash does not match the expected drawer'
        USING ERRCODE = '23514';
END;
$$;

CREATE FUNCTION public.stall_assert_register_session() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    checks_count integer;
    check_row integer;
BEGIN
    IF NEW.source_type IN (
        'sale',
        'item_return',
        'rent_payment',
        'cash_disbursement',
        'gift_certificate_issue',
        'gift_card_issue',
        'store_credit_issue',
        'cash_drop',
        'register_variance',
        'layaway_deposit',
        'layaway_refund'
    ) AND NOT EXISTS (
        SELECT 1
        FROM public.register_session_posts
        WHERE journal_id = NEW.id
    ) THEN
        RAISE EXCEPTION 'a drawer journal needs an open register'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.source_type IN ('sale', 'item_return', 'layaway_deposit', 'layaway_refund') AND NOT NEW.is_reversal THEN
        SELECT count(*) INTO checks_count
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role = 'checks'
          AND (
              (NEW.source_type IN ('sale', 'layaway_deposit') AND line.debit_minor > 0)
              OR (NEW.source_type IN ('item_return', 'layaway_refund') AND line.credit_minor > 0)
          );

        SELECT count(*) INTO check_row
        FROM public.register_checks
        WHERE journal_id = NEW.id;

        IF checks_count <> check_row THEN
            RAISE EXCEPTION 'a check sale needs a check number'
                USING ERRCODE = '23514';
        END IF;
    END IF;

    RETURN NULL;
END;
$$;

CREATE FUNCTION public.stall_assert_register_variance() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    line_count integer;
    cash_line integer;
    short_line integer;
BEGIN
    IF NEW.source_type <> 'register_variance' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    SELECT count(*) INTO line_count
    FROM public.journal_lines
    WHERE journal_id = NEW.id;

    SELECT count(*) INTO cash_line
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'cash'
      AND line.subledger_type IS NULL
      AND (
          (line.debit_minor = NEW.debit_total_minor AND line.credit_minor = 0)
          OR (line.credit_minor = NEW.credit_total_minor AND line.debit_minor = 0)
      );

    SELECT count(*) INTO short_line
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'cash_over_short'
      AND line.subledger_type IS NULL
      AND (
          (line.debit_minor = NEW.debit_total_minor AND line.credit_minor = 0)
          OR (line.credit_minor = NEW.credit_total_minor AND line.debit_minor = 0)
      );

    IF NEW.debit_total_minor < 1 OR line_count <> 2 OR cash_line <> 1 OR short_line <> 1 THEN
        RAISE EXCEPTION 'register variance moves the difference between cash and over/short'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE FUNCTION public.stall_assert_rent_charge() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    booth_ref text;
    rate_minor bigint;
    assign_start date;
    charge_start date;
    charge_end date;
    days_in_period integer;
    days_charged integer;
    line_count integer;
    debit_ok integer;
    credit_ok integer;
BEGIN
    IF NEW.source_type <> 'rent_charge' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    IF NOT (SELECT booth_rentals_enabled FROM public.mall) THEN
        RAISE EXCEPTION 'booth rentals are turned off'
            USING ERRCODE = '23514';
    END IF;

    SELECT line.subledger_ref INTO booth_ref
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'booth_rent_receivable'
      AND line.debit_minor > 0
      AND line.subledger_type = 'booth';

    charge_start := public.stall_rent_period_start(NEW.posting_date);
    IF booth_ref IS NULL
       OR NEW.posting_key <> 'rent-charge:' || booth_ref || ':' || public.stall_rent_charge_suffix(charge_start) THEN
        RAISE EXCEPTION 'a rent charge posting key is one booth and one month'
            USING ERRCODE = '23514';
    END IF;

    SELECT assignment.starts_on, contract.amount_minor
    INTO assign_start, rate_minor
    FROM public.booth_assignments AS assignment
    JOIN public.assignment_rent AS contract ON contract.assignment_id = assignment.id
    WHERE assignment.booth_id = booth_ref::uuid
      AND assignment.assignment_range @> NEW.posting_date
    ORDER BY assignment.starts_on DESC
    LIMIT 1
    FOR UPDATE OF assignment, contract;

    IF assign_start IS NULL THEN
        IF EXISTS (
            SELECT 1
            FROM public.booth_assignments AS assignment
            WHERE assignment.booth_id = booth_ref::uuid
              AND assignment.assignment_range @> NEW.posting_date
        ) THEN
            RAISE EXCEPTION 'a rent charge requires the booth rate from the assignment'
                USING ERRCODE = '23514';
        END IF;
        RAISE EXCEPTION 'a rent charge requires an assignment that covers the charge date'
            USING ERRCODE = '23514';
    END IF;

    charge_end := public.stall_rent_period_end(charge_start);
    IF assign_start > charge_start AND assign_start <= charge_end THEN
        days_in_period := (charge_end - charge_start) + 1;
        days_charged := (charge_end - assign_start) + 1;
        rate_minor := (days_charged * rate_minor) / days_in_period;
    END IF;

    SELECT count(*) INTO line_count
    FROM public.journal_lines
    WHERE journal_id = NEW.id;

    SELECT count(*) INTO debit_ok
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'booth_rent_receivable'
      AND line.debit_minor = rate_minor
      AND line.subledger_ref = booth_ref;

    SELECT count(*) INTO credit_ok
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'rent_revenue'
      AND line.credit_minor = rate_minor
      AND line.subledger_type IS NULL;

    IF line_count <> 2 OR debit_ok <> 1 OR credit_ok <> 1 THEN
        RAISE EXCEPTION 'a rent charge debits that booth receivable and credits rent revenue for the stored rate'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE FUNCTION public.stall_assert_rent_payment() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    tender bigint;
    credit bigint;
    other integer;
    checks_debit bigint;
    check_rows integer;
BEGIN
    IF NEW.source_type <> 'rent_payment' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    SELECT COALESCE(sum(line.debit_minor), 0) INTO tender
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role IN ('cash', 'checks', 'card')
      AND line.debit_minor > 0
      AND line.credit_minor = 0
      AND line.subledger_type IS NULL;

    SELECT COALESCE(sum(line.credit_minor), 0) INTO credit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'booth_rent_receivable'
      AND line.credit_minor > 0
      AND line.debit_minor = 0
      AND line.subledger_type = 'booth'
      AND line.subledger_ref IS NOT NULL;

    SELECT count(*) INTO other
    FROM public.journal_lines AS line
    WHERE line.journal_id = NEW.id
      AND NOT EXISTS (
          SELECT 1
          FROM public.chart_roles AS role
          WHERE role.account_id = line.account_id
            AND role.role IN ('cash', 'checks', 'card')
            AND line.debit_minor > 0
            AND line.credit_minor = 0
            AND line.subledger_type IS NULL
      )
      AND NOT EXISTS (
          SELECT 1
          FROM public.chart_roles AS role
          WHERE role.account_id = line.account_id
            AND role.role = 'booth_rent_receivable'
            AND line.credit_minor > 0
            AND line.debit_minor = 0
            AND line.subledger_type = 'booth'
            AND line.subledger_ref IS NOT NULL
      );

    SELECT COALESCE(sum(line.debit_minor), 0) INTO checks_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'checks'
      AND line.debit_minor > 0
      AND line.credit_minor = 0
      AND line.subledger_type IS NULL;

    SELECT count(*) INTO check_rows
    FROM public.register_checks
    WHERE journal_id = NEW.id;

    IF tender < 1
        OR tender <> credit
        OR tender <> NEW.debit_total_minor
        OR credit <> NEW.credit_total_minor
        OR other <> 0
    THEN
        RAISE EXCEPTION 'a rent receipt debits a tender and credits that booth receivable'
            USING ERRCODE = '23514';
    END IF;

    IF checks_debit > 0 AND check_rows <> 1 THEN
        RAISE EXCEPTION 'a rent check needs a check number'
            USING ERRCODE = '23514';
    END IF;

    IF checks_debit = 0 AND check_rows <> 0 THEN
        RAISE EXCEPTION 'a cash or card rent receipt has no check number'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE FUNCTION public.stall_assert_sale_not_on_ticket() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    held integer;
    on_hand integer;
BEGIN
    SELECT COALESCE(sum(line.quantity), 0)::integer INTO held
    FROM public.ticket_lines AS line
    JOIN public.tickets AS ticket ON ticket.id = line.ticket_id
    WHERE line.item_id = NEW.item_id
      AND ticket.status IN ('open', 'parked', 'layaway');

    SELECT item.quantity INTO on_hand
    FROM public.items AS item
    WHERE item.id = NEW.item_id;

    IF held > on_hand THEN
        RAISE EXCEPTION 'an item is already on a ticket'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE FUNCTION public.stall_assert_sale_shape() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    sale_source text;
    sale_reversal boolean;
    tax_exempt boolean;
    commission_bps integer;
    tax_bps integer;
    expected_commission bigint;
    expected_tax bigint;
    expected_discount bigint;
    expected_store bigint;
    picked_offer uuid;
    waiver boolean;
    tender_lines integer;
    tender_roles integer;
    tender_sum bigint;
    prices bigint;
    commissions bigint;
    taxes bigint;
    discounts bigint;
    store_discounts bigint;
    promo_debit bigint;
    promo_lines integer;
    net_commission bigint;
    commission_lines integer;
    commission_credit bigint;
    tax_lines integer;
    tax_credit bigint;
    payable_gaps integer;
    on_hand integer;
    foreign_lines integer;
    round_debit bigint;
    round_credit bigint;
    round_lines integer;
    cash_debit bigint;
    exact_cash bigint;
    round_delta integer;
    round_cash boolean;
    placement text;
    store_net bigint;
    store_lines integer;
    store_credit bigint;
    cogs_amount bigint;
    cogs_lines integer;
    cogs_debit bigint;
    inventory_credit bigint;
BEGIN
    IF TG_TABLE_NAME = 'sale_lines' THEN
        IF TG_OP <> 'INSERT' THEN
            RAISE EXCEPTION 'a sale line cannot be changed'
                USING ERRCODE = '23514';
        END IF;

        SELECT journal.source_type, journal.is_reversal
        INTO sale_source, sale_reversal
        FROM public.journals AS journal
        WHERE journal.id = NEW.journal_id;

        IF sale_source IS DISTINCT FROM 'sale' OR sale_reversal IS DISTINCT FROM false THEN
            RAISE EXCEPTION 'a sale line belongs on a sale'
                USING ERRCODE = '23514';
        END IF;

        SELECT item.placement,
               vendor.tax_exempt,
               CASE
                   WHEN item.placement = 'store' THEN 0
                   ELSE COALESCE(item.commission_bps, vendor.commission_bps, mall.commission_bps)
               END,
               mall.tax_bps,
               item.quantity
        INTO placement, tax_exempt, commission_bps, tax_bps, on_hand
        FROM public.items AS item
        LEFT JOIN public.vendors AS vendor ON vendor.id = item.vendor_id
        CROSS JOIN public.mall AS mall
        WHERE item.id = NEW.item_id
        FOR UPDATE OF item;

        IF placement IS NULL THEN
            RAISE EXCEPTION 'a sale line needs an item'
                USING ERRCODE = '23514';
        END IF;

        IF placement = 'store' THEN
            tax_exempt := false;
        ELSIF tax_exempt IS NULL THEN
            RAISE EXCEPTION 'a sale line needs an item'
                USING ERRCODE = '23514';
        END IF;

        IF placement = 'consigned'
            AND EXISTS (
                SELECT 1
                FROM public.items AS aged
                CROSS JOIN public.mall AS mall_clock
                WHERE aged.id = NEW.item_id
                  AND aged.expires_on IS NOT NULL
                  AND aged.expires_on < (clock_timestamp() AT TIME ZONE mall_clock.time_zone)::date
                  AND aged.quantity > 0
                  AND NOT EXISTS (
                      SELECT 1 FROM public.item_dispositions AS disposition
                      WHERE disposition.item_id = aged.id
                  )
                  AND NOT EXISTS (
                      SELECT 1 FROM public.item_buys AS bought
                      WHERE bought.item_id = aged.id
                  )
            ) THEN
            RAISE EXCEPTION 'an expired piece needs a disposition'
                USING ERRCODE = '23514';
        END IF;

        expected_discount := 0;
        expected_store := 0;
        picked_offer := NULL;
        waiver := false;
        SELECT public.stall_line_discount(ticket.id, NEW.item_id, ticket.discount_kind, ticket.discount_value)
                   + line.line_discount_minor
                   + line.offer_discount_minor,
               line.store_discount_minor,
               line.offer_id,
               ticket.tax_waiver_reason IS NOT NULL
        INTO expected_discount, expected_store, picked_offer, waiver
        FROM public.tickets AS ticket
        JOIN public.ticket_lines AS line ON line.ticket_id = ticket.id
        WHERE line.item_id = NEW.item_id
          AND ticket.status = 'open';
        IF NOT FOUND THEN
            expected_discount := 0;
            expected_store := 0;
            picked_offer := NULL;
            waiver := false;
        END IF;

        NEW.offer_id := picked_offer;

        IF NEW.discount_minor IS DISTINCT FROM expected_discount
            OR NEW.store_discount_minor IS DISTINCT FROM expected_store
            OR NEW.discount_minor < 0
            OR NEW.store_discount_minor < 0
            OR NEW.discount_minor + NEW.store_discount_minor > NEW.price_minor * NEW.quantity THEN
            RAISE EXCEPTION 'a sale discount does not match the ticket'
                USING ERRCODE = '23514';
        END IF;

        expected_commission := public.stall_half_up(NEW.price_minor * NEW.quantity - NEW.discount_minor, commission_bps);

        IF tax_exempt OR waiver THEN
            expected_tax := 0;
        ELSE
            expected_tax := public.stall_tax_minor(
                NEW.price_minor * NEW.quantity - NEW.discount_minor - NEW.store_discount_minor,
                tax_bps
            );
        END IF;

        IF NEW.commission_minor IS DISTINCT FROM expected_commission THEN
            RAISE EXCEPTION 'a sale commission does not match the store take'
                USING ERRCODE = '23514';
        END IF;

        IF NEW.tax_minor IS DISTINCT FROM expected_tax THEN
            RAISE EXCEPTION 'a sale tax does not match the mall rate'
                USING ERRCODE = '23514';
        END IF;

        IF NEW.quantity > on_hand THEN
            RAISE EXCEPTION 'there are not enough on hand'
                USING ERRCODE = '23514';
        END IF;

        UPDATE public.items
        SET quantity = quantity - NEW.quantity
        WHERE id = NEW.item_id;

        RETURN NEW;
    END IF;

    IF NEW.source_type <> 'sale' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    SELECT count(*), count(DISTINCT role.role), COALESCE(sum(line.debit_minor), 0)
    INTO tender_lines, tender_roles, tender_sum
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND line.debit_minor > 0
      AND (
          role.role IN ('cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit', 'layaway_deposit')
          OR (role.role = 'vendor_payable' AND line.subledger_type = 'vendor')
      );

    IF tender_lines < 1 OR tender_lines <> tender_roles THEN
        RAISE EXCEPTION 'a sale debits each tender once'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND line.debit_minor > 0
          AND (
              (role.role IN ('cash', 'card', 'checks') AND line.subledger_type IS NOT NULL)
              OR (
                  role.role IN ('gift_certificate', 'gift_card', 'store_credit')
                  AND line.subledger_type IS DISTINCT FROM role.role
              )
          )
    ) THEN
        RAISE EXCEPTION 'a sale debits each tender once'
            USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (SELECT 1 FROM public.sale_lines WHERE journal_id = NEW.id) THEN
        RAISE EXCEPTION 'a sale needs an item'
            USING ERRCODE = '23514';
    END IF;

    SELECT COALESCE(sum(price_minor * quantity), 0),
           COALESCE(sum(commission_minor), 0),
           COALESCE(sum(tax_minor), 0),
           COALESCE(sum(discount_minor), 0),
           COALESCE(sum(store_discount_minor), 0)
    INTO prices, commissions, taxes, discounts, store_discounts
    FROM public.sale_lines
    WHERE journal_id = NEW.id;

    net_commission := commissions;

    SELECT COALESCE(sum(line.debit_minor), 0), count(*)::integer
    INTO promo_debit, promo_lines
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'promotion_expense'
      AND line.debit_minor > 0
      AND line.credit_minor = 0;

    IF store_discounts = 0 THEN
        IF promo_lines <> 0 OR promo_debit <> 0 THEN
            RAISE EXCEPTION 'a store deal is the mall''s expense'
                USING ERRCODE = '23514';
        END IF;
    ELSIF promo_lines <> 1 OR promo_debit <> store_discounts THEN
        RAISE EXCEPTION 'a store deal is the mall''s expense'
            USING ERRCODE = '23514';
    END IF;

    SELECT COALESCE(sum(line.debit_minor), 0), COALESCE(sum(line.credit_minor), 0), count(*)
    INTO round_debit, round_credit, round_lines
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'cash_rounding'
      AND (line.debit_minor > 0 OR line.credit_minor > 0);

    SELECT COALESCE(sum(line.debit_minor), 0)
    INTO cash_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'cash'
      AND line.debit_minor > 0;

    SELECT cash_round_nickel INTO round_cash FROM public.mall;

    exact_cash := prices - discounts - store_discounts + taxes - (tender_sum - cash_debit);
    round_delta := CASE exact_cash % 5
        WHEN 1 THEN -1
        WHEN 2 THEN -2
        WHEN 3 THEN 2
        WHEN 4 THEN 1
        ELSE 0
    END;

    IF cash_debit = 0 OR cash_debit <> tender_sum OR round_cash IS DISTINCT FROM true THEN
        IF round_lines <> 0 OR tender_sum <> prices - discounts - store_discounts + taxes THEN
            RAISE EXCEPTION 'a sale debits the sold prices plus tax'
                USING ERRCODE = '23514';
        END IF;
    ELSIF exact_cash < 0
        OR cash_debit <> exact_cash + round_delta
        OR (round_delta = 0 AND round_lines <> 0)
        OR (round_delta > 0 AND (round_lines <> 1 OR round_credit <> round_delta OR round_debit <> 0))
        OR (round_delta < 0 AND (round_lines <> 1 OR round_debit <> -round_delta OR round_credit <> 0)) THEN
        RAISE EXCEPTION 'a cash sale rounds to the nearest nickel'
            USING ERRCODE = '23514';
    END IF;

    IF tender_sum <> prices - discounts - store_discounts + taxes + round_credit - round_debit THEN
        RAISE EXCEPTION 'a sale debits the sold prices plus tax'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*), COALESCE(sum(line.credit_minor), 0)
    INTO commission_lines, commission_credit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'commission_revenue'
      AND line.credit_minor > 0;

    IF net_commission = 0 THEN
        IF commission_lines <> 0 THEN
            RAISE EXCEPTION 'a sale commission does not match the store take'
                USING ERRCODE = '23514';
        END IF;
    ELSIF commission_lines <> 1 OR commission_credit <> net_commission THEN
        RAISE EXCEPTION 'a sale commission does not match the store take'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*), COALESCE(sum(line.credit_minor), 0)
    INTO tax_lines, tax_credit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'sales_tax_payable'
      AND line.credit_minor > 0;

    IF taxes = 0 THEN
        IF tax_lines <> 0 THEN
            RAISE EXCEPTION 'a sale tax does not match the mall rate'
                USING ERRCODE = '23514';
        END IF;
    ELSIF tax_lines <> 1 OR tax_credit <> taxes THEN
        RAISE EXCEPTION 'a sale tax does not match the mall rate'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO payable_gaps
    FROM (
        SELECT item.vendor_id, sum(sale.price_minor * sale.quantity - sale.discount_minor - sale.commission_minor) AS owed
        FROM public.sale_lines AS sale
        JOIN public.items AS item ON item.id = sale.item_id
        WHERE sale.journal_id = NEW.id
          AND item.vendor_id IS NOT NULL
        GROUP BY item.vendor_id
    ) AS owed
    FULL OUTER JOIN (
        SELECT line.subledger_ref::uuid AS vendor_id,
               sum(line.credit_minor)::bigint AS credit_minor,
               count(*)::integer AS line_count
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role = 'vendor_payable'
          AND line.credit_minor > 0
          AND line.subledger_type = 'vendor'
        GROUP BY line.subledger_ref
    ) AS posted ON posted.vendor_id = owed.vendor_id
    WHERE COALESCE(owed.owed, 0) IS DISTINCT FROM COALESCE(posted.credit_minor, 0)
       OR COALESCE(posted.line_count, 0) > 1
       OR (COALESCE(owed.owed, 0) = 0 AND posted.vendor_id IS NOT NULL)
       OR (COALESCE(owed.owed, 0) > 0 AND COALESCE(posted.line_count, 0) <> 1);

    IF payable_gaps > 0 THEN
        RAISE EXCEPTION 'a sale credits that vendor''s payable'
            USING ERRCODE = '23514';
    END IF;

    SELECT COALESCE(sum(sale.price_minor * sale.quantity - sale.discount_minor), 0)
    INTO store_net
    FROM public.sale_lines AS sale
    JOIN public.items AS item ON item.id = sale.item_id
    WHERE sale.journal_id = NEW.id
      AND item.placement = 'store';

    SELECT count(*), COALESCE(sum(line.credit_minor), 0)
    INTO store_lines, store_credit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'store_sales'
      AND line.credit_minor > 0;

    IF store_net = 0 THEN
        IF store_lines <> 0 THEN
            RAISE EXCEPTION 'a store sale credits store sales'
                USING ERRCODE = '23514';
        END IF;
    ELSIF store_lines <> 1 OR store_credit <> store_net THEN
        RAISE EXCEPTION 'a store sale credits store sales'
            USING ERRCODE = '23514';
    END IF;

    SELECT COALESCE(sum(item.cost_minor * sale.quantity), 0)
    INTO cogs_amount
    FROM public.sale_lines AS sale
    JOIN public.items AS item ON item.id = sale.item_id
    WHERE sale.journal_id = NEW.id
      AND item.placement = 'store'
      AND item.cost_minor IS NOT NULL;

    SELECT count(*), COALESCE(sum(line.debit_minor), 0)
    INTO cogs_lines, cogs_debit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'cost_of_goods'
      AND line.debit_minor > 0;

    SELECT COALESCE(sum(line.credit_minor), 0)
    INTO inventory_credit
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'inventory'
      AND line.credit_minor > 0;

    IF tender_sum + round_debit + promo_debit + cogs_amount <> NEW.debit_total_minor
        OR tender_sum <> prices - discounts - store_discounts + taxes + round_credit - round_debit THEN
        RAISE EXCEPTION 'a sale debits the sold prices plus tax'
            USING ERRCODE = '23514';
    END IF;

    IF cogs_amount = 0 THEN
        IF cogs_lines <> 0 OR inventory_credit <> 0 THEN
            RAISE EXCEPTION 'a store sale relieves its cost'
                USING ERRCODE = '23514';
        END IF;
    ELSIF cogs_lines <> 1 OR cogs_debit <> cogs_amount OR inventory_credit <> cogs_amount THEN
        RAISE EXCEPTION 'a store sale relieves its cost'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.journal_lines AS draw
        JOIN public.chart_roles AS draw_role ON draw_role.account_id = draw.account_id
        WHERE draw.journal_id = NEW.id
          AND draw_role.role = 'vendor_payable'
          AND draw.debit_minor > 0
          AND (
              draw.subledger_type IS DISTINCT FROM 'vendor'
              OR draw.debit_minor > COALESCE((
                  SELECT sum(prior.credit_minor - prior.debit_minor)
                  FROM public.journal_lines AS prior
                  JOIN public.chart_roles AS prior_role ON prior_role.account_id = prior.account_id
                  WHERE prior.journal_id <> NEW.id
                    AND prior_role.role = 'vendor_payable'
                    AND prior.subledger_type = 'vendor'
                    AND prior.subledger_ref = draw.subledger_ref
              ), 0)
          )
    ) THEN
        RAISE EXCEPTION 'a vendor draw is limited to the open payable'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.journal_lines AS layaway
        JOIN public.chart_roles AS layaway_role ON layaway_role.account_id = layaway.account_id
        WHERE layaway.journal_id = NEW.id
          AND layaway_role.role = 'layaway_deposit'
          AND layaway.debit_minor > 0
          AND (
              layaway.subledger_type IS DISTINCT FROM 'ticket'
              OR layaway.debit_minor > COALESCE((
                  SELECT sum(prior.credit_minor - prior.debit_minor)
                  FROM public.journal_lines AS prior
                  JOIN public.chart_roles AS prior_role ON prior_role.account_id = prior.account_id
                  WHERE prior.journal_id <> NEW.id
                    AND prior_role.role = 'layaway_deposit'
                    AND prior.subledger_type = 'ticket'
                    AND prior.subledger_ref = layaway.subledger_ref
              ), 0)
          )
    ) THEN
        RAISE EXCEPTION 'a layaway deposit is limited to what was paid in'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO foreign_lines
    FROM public.journal_lines AS line
    WHERE line.journal_id = NEW.id
      AND NOT EXISTS (
          SELECT 1
          FROM public.chart_roles AS role
          WHERE role.account_id = line.account_id
            AND (
                (role.role IN ('cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit', 'layaway_deposit') AND line.debit_minor > 0)
                OR (role.role = 'commission_revenue' AND line.credit_minor > 0)
                OR (role.role = 'sales_tax_payable' AND line.credit_minor > 0)
                OR (role.role = 'vendor_payable' AND line.credit_minor > 0)
                OR (role.role = 'vendor_payable' AND line.debit_minor > 0 AND line.subledger_type = 'vendor')
                OR (role.role = 'cash_rounding' AND (line.debit_minor > 0 OR line.credit_minor > 0))
                OR (role.role = 'promotion_expense' AND line.debit_minor > 0 AND line.credit_minor = 0)
                OR (role.role = 'store_sales' AND line.credit_minor > 0)
                OR (role.role = 'cost_of_goods' AND line.debit_minor > 0 AND line.credit_minor = 0)
                OR (role.role = 'inventory' AND line.credit_minor > 0 AND line.debit_minor = 0)
            )
      );

    IF foreign_lines > 0 THEN
        RAISE EXCEPTION 'a sale only credits commission, sales tax, and vendor payable'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE FUNCTION public.stall_assert_settlement() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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

    -- A check already written has left the payable. Counting it again lets the
    -- settlement ask for more than the vendor is owed when rent sits between
    -- the real payable and that inflated figure.
    SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) INTO open_payable
    FROM public.journal_lines AS line
    JOIN public.journals AS posted ON posted.id = line.journal_id
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id <> NEW.id
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

CREATE FUNCTION public.stall_assert_store_purchase() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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

CREATE FUNCTION public.stall_assert_stored_value_issue() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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

CREATE FUNCTION public.stall_assert_subledger_non_negative() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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

CREATE FUNCTION public.stall_assert_tax_payment() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    line_count integer;
    tax_ok integer;
    cash_ok integer;
BEGIN
    IF NEW.source_type <> 'tax_payment' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    IF NEW.posting_key <> 'tax-payment:' || NEW.source_reference THEN
        RAISE EXCEPTION 'a tax payment posting key is its source reference'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO line_count
    FROM public.journal_lines
    WHERE journal_id = NEW.id;

    SELECT count(*) INTO tax_ok
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'sales_tax_payable'
      AND line.debit_minor > 0
      AND line.subledger_type IS NULL;

    SELECT count(*) INTO cash_ok
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'bank'
      AND line.credit_minor > 0
      AND line.subledger_type IS NULL;

    IF line_count <> 2 OR tax_ok <> 1 OR cash_ok <> 1 THEN
        RAISE EXCEPTION 'a tax payment debits sales tax payable and credits the bank'
            USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

CREATE FUNCTION public.stall_assert_ticket() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    missing_lines integer;
    extra_lines integer;
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status = 'posted' THEN
            RAISE EXCEPTION 'a posted ticket cannot be discarded'
                USING ERRCODE = '23514';
        END IF;
        DELETE FROM public.ticket_lines WHERE ticket_id = OLD.id;
        RETURN OLD;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM public.register_sessions AS session
        WHERE session.id = NEW.session_id
          AND session.closed_at IS NULL
    ) THEN
        RAISE EXCEPTION 'a ticket belongs on an open register'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'INSERT' THEN
        IF NEW.status <> 'open' OR NEW.posted_journal_id IS NOT NULL THEN
            RAISE EXCEPTION 'a ticket opens before it is paid'
                USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
    END IF;

    IF NEW.id IS DISTINCT FROM OLD.id OR OLD.status = 'posted' THEN
        RAISE EXCEPTION 'a posted ticket cannot change'
            USING ERRCODE = '23514';
    END IF;

    IF OLD.status = 'open'
        AND NEW.status = 'open'
        AND NEW.session_id IS NOT DISTINCT FROM OLD.session_id
        AND NEW.posted_journal_id IS NULL THEN
        PERFORM public.stall_refuse_ticket_discount(NEW.id, NEW.discount_kind, NEW.discount_value);
        RETURN NEW;
    END IF;

    IF NEW.discount_kind IS DISTINCT FROM OLD.discount_kind
        OR NEW.discount_value IS DISTINCT FROM OLD.discount_value
        OR NEW.discount_reason IS DISTINCT FROM OLD.discount_reason
        OR NEW.tax_waiver_reason IS DISTINCT FROM OLD.tax_waiver_reason THEN
        RAISE EXCEPTION 'a posted ticket cannot change'
            USING ERRCODE = '23514';
    END IF;

    IF OLD.status = 'open' AND NEW.status = 'parked' AND NEW.posted_journal_id IS NULL
        AND NEW.session_id IS NOT DISTINCT FROM OLD.session_id THEN
        RETURN NEW;
    END IF;

    IF OLD.status = 'parked' AND NEW.status = 'open' AND NEW.posted_journal_id IS NULL THEN
        RETURN NEW;
    END IF;

    IF OLD.status = 'open' AND NEW.status = 'layaway' AND NEW.posted_journal_id IS NULL
        AND NEW.session_id IS NOT DISTINCT FROM OLD.session_id THEN
        RETURN NEW;
    END IF;

    IF OLD.status = 'layaway' AND NEW.status = 'open' AND NEW.posted_journal_id IS NULL THEN
        RETURN NEW;
    END IF;

    IF OLD.status <> 'open' OR NEW.status <> 'posted' OR NEW.session_id IS DISTINCT FROM OLD.session_id THEN
        RAISE EXCEPTION 'a posted ticket cannot change'
            USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM public.ticket_lines WHERE ticket_id = NEW.id
    ) THEN
        RAISE EXCEPTION 'a ticket needs an item'
            USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM public.journals AS journal
        JOIN public.register_session_posts AS post ON post.journal_id = journal.id
        WHERE journal.id = NEW.posted_journal_id
          AND journal.source_type = 'sale'
          AND journal.is_reversal = false
          AND post.session_id = NEW.session_id
    ) THEN
        RAISE EXCEPTION 'a paid ticket must match its sale'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO missing_lines
    FROM (
        SELECT item_id, price_minor, quantity
        FROM public.ticket_lines
        WHERE ticket_id = NEW.id
        EXCEPT ALL
        SELECT item_id, price_minor, quantity
        FROM public.sale_lines
        WHERE journal_id = NEW.posted_journal_id
    ) AS missing;

    SELECT count(*) INTO extra_lines
    FROM (
        SELECT item_id, price_minor, quantity
        FROM public.sale_lines
        WHERE journal_id = NEW.posted_journal_id
        EXCEPT ALL
        SELECT item_id, price_minor, quantity
        FROM public.ticket_lines
        WHERE ticket_id = NEW.id
    ) AS extra;

    IF missing_lines > 0 OR extra_lines > 0 THEN
        RAISE EXCEPTION 'a paid ticket must match its sale'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_assert_ticket_line() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    ticket_status text;
    item uuid;
    line_item uuid;
    line_ticket uuid;
    on_hand integer;
    held integer;
    picked uuid;
    vendor_off bigint;
    store_off bigint;
    net bigint;
BEGIN
    IF TG_OP = 'DELETE' THEN
        line_item := OLD.item_id;
        line_ticket := OLD.ticket_id;
    ELSE
        line_item := NEW.item_id;
        line_ticket := NEW.ticket_id;
    END IF;

    SELECT ticket.status INTO ticket_status
    FROM public.tickets AS ticket
    WHERE ticket.id = line_ticket
    FOR UPDATE;

    IF ticket_status IS NULL THEN
        RAISE EXCEPTION 'a ticket line needs a ticket'
            USING ERRCODE = '23514';
    END IF;

    IF ticket_status <> 'open' AND NOT (TG_OP = 'DELETE' AND ticket_status IN ('parked', 'layaway')) THEN
        RAISE EXCEPTION 'a posted ticket cannot change'
            USING ERRCODE = '23514';
    END IF;

    SELECT item_row.id, item_row.quantity INTO item, on_hand
    FROM public.items AS item_row
    WHERE item_row.id = line_item
    FOR UPDATE OF item_row;

    IF item IS NULL THEN
        RAISE EXCEPTION 'a ticket line needs an item'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;

    SELECT deal.picked_offer, deal.vendor_discount, deal.store_discount
    INTO picked, vendor_off, store_off
    FROM public.stall_quantity_deal(NEW.item_id, NEW.quantity, NEW.price_minor) AS deal;

    IF NOT FOUND THEN
        picked := NULL;
        vendor_off := 0;
        store_off := 0;
    END IF;

    NEW.offer_id := picked;
    NEW.offer_discount_minor := COALESCE(vendor_off, 0);
    NEW.store_discount_minor := COALESCE(store_off, 0);
    net := NEW.price_minor * NEW.quantity - NEW.offer_discount_minor - NEW.store_discount_minor;

    IF NEW.line_discount_kind IS NULL THEN
        NEW.line_discount_minor := 0;
        NEW.line_discount_value := NULL;
        NEW.line_discount_reason := NULL;
    ELSE
        IF NEW.line_discount_kind = 'percent' THEN
            NEW.line_discount_minor := public.stall_half_up(net, NEW.line_discount_value);
        ELSE
            NEW.line_discount_minor := NEW.line_discount_value;
        END IF;
        IF NEW.line_discount_minor < 1 OR NEW.line_discount_minor > net THEN
            RAISE EXCEPTION 'the discount is more than the price'
                USING ERRCODE = '23514';
        END IF;
    END IF;

    SELECT COALESCE(sum(line.quantity), 0)::integer INTO held
    FROM public.ticket_lines AS line
    JOIN public.tickets AS ticket ON ticket.id = line.ticket_id
    WHERE line.item_id = NEW.item_id
      AND ticket.status IN ('open', 'parked', 'layaway')
      AND line.ticket_id <> NEW.ticket_id;

    IF NEW.quantity + held > on_hand THEN
        IF held > 0 THEN
            RAISE EXCEPTION 'an item is already on a ticket'
                USING ERRCODE = '23514';
        END IF;
        RAISE EXCEPTION 'there are not enough on hand'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_assign_payout_run_no() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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

CREATE FUNCTION public.stall_close_quiet_period(closed_period uuid) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    period_book uuid;
    period_start date;
    period_status text;
BEGIN
    SELECT book_id INTO period_book
    FROM public.accounting_periods
    WHERE id = closed_period;
    IF period_book IS NULL THEN
        RAISE EXCEPTION 'journal period does not exist'
            USING ERRCODE = '23503';
    END IF;

    PERFORM 1
    FROM public.ledger_books
    WHERE id = period_book
    FOR UPDATE;

    SELECT starts_on, status
    INTO period_start, period_status
    FROM public.accounting_periods
    WHERE id = closed_period
    FOR UPDATE;
    IF period_status IS NULL THEN
        RAISE EXCEPTION 'journal period does not exist'
            USING ERRCODE = '23503';
    END IF;
    IF period_status <> 'open' THEN
        RAISE EXCEPTION 'a closed period refuses a new journal'
            USING ERRCODE = '23514';
    END IF;

    PERFORM public.stall_reject_earlier_open_period(period_book, period_start);

    IF EXISTS (
        SELECT 1
        FROM public.journal_lines AS line
        JOIN public.journals AS posted ON posted.id = line.journal_id
        JOIN public.accounts AS account ON account.id = line.account_id
        WHERE posted.period_id = closed_period
          AND account.account_type IN ('revenue', 'expense')
        GROUP BY account.id
        HAVING sum(line.credit_minor - line.debit_minor) <> 0
    ) THEN
        RAISE EXCEPTION 'a period with income or expense closes by a journal'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.journals AS posted
        WHERE posted.period_id = closed_period
          AND EXISTS (
              SELECT 1
              FROM public.journal_lines AS line
              JOIN public.accounts AS account ON account.id = line.account_id
              WHERE line.journal_id = posted.id
                AND account.account_type IN ('revenue', 'expense')
          )
          AND NOT (
              (
                  posted.is_reversal
                  AND EXISTS (
                      SELECT 1
                      FROM public.journals AS original
                      WHERE original.id = posted.reverses_journal_id
                        AND original.period_id = closed_period
                  )
              )
              OR (
                  NOT posted.is_reversal
                  AND EXISTS (
                      SELECT 1
                      FROM public.journals AS reversal
                      WHERE reversal.reverses_journal_id = posted.id
                        AND reversal.period_id = closed_period
                  )
              )
          )
    ) THEN
        RAISE EXCEPTION 'a period with income or expense closes by a journal'
            USING ERRCODE = '23514';
    END IF;

    UPDATE public.accounting_periods
    SET status = 'closed'
    WHERE id = closed_period
      AND status = 'open';
END;
$$;

CREATE FUNCTION public.stall_csv_field(value text) RETURNS text
    LANGUAGE sql IMMUTABLE
    SET search_path TO 'pg_catalog'
    AS $$
    SELECT CASE
        WHEN value IS NULL OR value = '' THEN ''
        WHEN value ~ '[",\n\r]' THEN '"' || replace(value, '"', '""') || '"'
        ELSE value
    END;
$$;

CREATE FUNCTION public.stall_export_journal_csv(system text, from_date date, to_date date, tax_rate text DEFAULT 'Tax Exempt'::text) RETURNS SETOF text
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF system IS NULL OR system NOT IN ('quickbooks', 'xero') THEN
        RAISE EXCEPTION 'the export system must be quickbooks or xero'
            USING ERRCODE = '23514';
    END IF;
    IF from_date IS NULL OR to_date IS NULL OR from_date > to_date THEN
        RAISE EXCEPTION 'the export range ends before it starts'
            USING ERRCODE = '23514';
    END IF;
    IF tax_rate IS NULL OR btrim(tax_rate) = '' THEN
        RAISE EXCEPTION 'a tax rate is required'
            USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM public.export_lines AS line
        WHERE line.posting_date >= from_date
          AND line.posting_date <= to_date
    ) THEN
        RAISE EXCEPTION 'no posted lines in that range'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.export_lines AS line
        JOIN public.journals AS journal
            ON journal.journal_no = line.journal_no
           AND journal.posting_key = line.posting_key
        JOIN public.accounts AS account
            ON account.book_id = journal.book_id
           AND account.code = line.account_code
        LEFT JOIN public.chart_roles AS role
            ON role.account_id = account.id
        LEFT JOIN public.export_accounts AS mapped
            ON mapped.role = role.role
           AND mapped.system = stall_export_journal_csv.system
        WHERE line.posting_date >= from_date
          AND line.posting_date <= to_date
          AND mapped.external_code IS NULL
    ) THEN
        RAISE EXCEPTION 'a posted line has no export account'
            USING ERRCODE = '23514';
    END IF;

    IF system = 'quickbooks' THEN
        RETURN NEXT 'Journal No.,Journal Date,Account Name,Debits,Credits,Description,Name';
    ELSE
        RETURN NEXT '*Narration,*Date,Description,*AccountCode,*TaxRate,*Amount,TrackingName1,TrackingOption1,TrackingName2,TrackingOption2';
    END IF;

    RETURN QUERY
    SELECT
        CASE
            WHEN stall_export_journal_csv.system = 'quickbooks' THEN
                public.stall_csv_field(line.journal_no::text)
                || ',' || public.stall_csv_field(to_char(line.posting_date, 'MM/DD/YYYY'))
                || ',' || public.stall_csv_field(mapped.external_code)
                || ',' || public.stall_csv_field(
                    CASE
                        WHEN line.debit_minor > 0 THEN to_char(
                            line.debit_minor::numeric / (10::numeric ^ book.currency_scale),
                            'FM999999999999990' || CASE
                                WHEN book.currency_scale > 0 THEN '.' || repeat('0', book.currency_scale)
                                ELSE ''
                            END
                        )
                        ELSE ''
                    END
                )
                || ',' || public.stall_csv_field(
                    CASE
                        WHEN line.credit_minor > 0 THEN to_char(
                            line.credit_minor::numeric / (10::numeric ^ book.currency_scale),
                            'FM999999999999990' || CASE
                                WHEN book.currency_scale > 0 THEN '.' || repeat('0', book.currency_scale)
                                ELSE ''
                            END
                        )
                        ELSE ''
                    END
                )
                || ',' || public.stall_csv_field(line.posting_key || ' ' || line.description)
                || ',' || public.stall_csv_field(
                    CASE
                        WHEN line.subledger_type = 'vendor' THEN vendor.name
                        WHEN line.subledger_type = 'booth' THEN booth.name
                        ELSE ''
                    END
                )
            ELSE
                public.stall_csv_field(line.posting_key)
                || ',' || public.stall_csv_field(to_char(line.posting_date, 'YYYY-MM-DD'))
                || ',' || public.stall_csv_field(line.description)
                || ',' || public.stall_csv_field(mapped.external_code)
                || ',' || public.stall_csv_field(tax_rate)
                || ',' || public.stall_csv_field(
                    to_char(
                        CASE
                            WHEN line.debit_minor > 0 THEN line.debit_minor::numeric
                            ELSE -line.credit_minor::numeric
                        END / (10::numeric ^ book.currency_scale),
                        'FM999999999999990' || CASE
                            WHEN book.currency_scale > 0 THEN '.' || repeat('0', book.currency_scale)
                            ELSE ''
                        END
                    )
                )
                || ',,,,'
        END
    FROM public.export_lines AS line
    JOIN public.journals AS journal
        ON journal.journal_no = line.journal_no
       AND journal.posting_key = line.posting_key
    JOIN public.ledger_books AS book
        ON book.id = journal.book_id
    JOIN public.accounts AS account
        ON account.book_id = journal.book_id
       AND account.code = line.account_code
    JOIN public.chart_roles AS role
        ON role.account_id = account.id
    JOIN public.export_accounts AS mapped
        ON mapped.role = role.role
       AND mapped.system = stall_export_journal_csv.system
    LEFT JOIN public.vendors AS vendor
        ON line.subledger_type = 'vendor'
       AND vendor.id = line.subledger_ref::uuid
    LEFT JOIN public.booths AS booth
        ON line.subledger_type = 'booth'
       AND booth.id = line.subledger_ref::uuid
    WHERE line.posting_date >= from_date
      AND line.posting_date <= to_date
    ORDER BY line.posting_date, line.journal_no, line.line_no;
END;
$$;

CREATE FUNCTION public.stall_guard_capabilities_stock() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF OLD.consignment_enabled AND NOT NEW.consignment_enabled AND EXISTS (
        SELECT 1 FROM public.items WHERE placement = 'consigned' AND quantity > 0
    ) THEN
        RAISE EXCEPTION 'consignment still has items on hand'
            USING ERRCODE = '23514';
    END IF;
    IF OLD.store_stock_enabled AND NOT NEW.store_stock_enabled AND EXISTS (
        SELECT 1 FROM public.items WHERE placement = 'store' AND quantity > 0
    ) THEN
        RAISE EXCEPTION 'store stock still has items on hand'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_guard_item_placement() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    today date;
BEGIN
    IF NEW.placement = 'booth' AND NEW.expires_on IS NOT NULL THEN
        RAISE EXCEPTION 'a booth piece does not expire'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.placement = 'store' AND NOT (SELECT store_stock_enabled FROM public.mall) THEN
        RAISE EXCEPTION 'store stock is off'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.placement = 'consigned' AND NOT (SELECT consignment_enabled FROM public.mall) THEN
        RAISE EXCEPTION 'consignment is off'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.placement = 'booth' AND NOT (SELECT booth_rentals_enabled FROM public.mall) THEN
        RAISE EXCEPTION 'booth rentals are off'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.placement = 'booth' AND (TG_OP = 'INSERT' OR OLD.placement IS DISTINCT FROM 'booth') THEN
        today := (SELECT (clock_timestamp() AT TIME ZONE mall.time_zone)::date FROM public.mall);
        IF NOT EXISTS (
            SELECT 1
            FROM public.booth_assignments AS assignment
            WHERE assignment.vendor_id = NEW.vendor_id
              AND assignment.starts_on <= today
              AND (assignment.ends_on IS NULL OR assignment.ends_on >= today)
        ) THEN
            RAISE EXCEPTION 'a booth piece needs an open assignment'
                USING ERRCODE = '23514';
        END IF;
    END IF;
    IF TG_OP = 'UPDATE' AND OLD.cost_minor IS DISTINCT FROM NEW.cost_minor AND EXISTS (
        SELECT 1 FROM public.sale_lines WHERE item_id = NEW.id
    ) THEN
        RAISE EXCEPTION 'a sold piece keeps its cost'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_guard_markdown_step() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM public.markdown_steps AS earlier
        WHERE earlier.id IS DISTINCT FROM NEW.id
          AND (
              (earlier.days_on_floor < NEW.days_on_floor AND earlier.percent >= NEW.percent)
              OR (earlier.days_on_floor > NEW.days_on_floor AND earlier.percent <= NEW.percent)
          )
    ) THEN
        RAISE EXCEPTION 'markdown percents increase with days on the floor'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_guard_shop_capabilities() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF NEW.floor_days IS NOT NULL AND NEW.consignment_enabled IS DISTINCT FROM true THEN
        RAISE EXCEPTION 'floor days need consignment'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_half_up(amount bigint, bps bigint) RETURNS bigint
    LANGUAGE sql IMMUTABLE
    SET search_path TO 'pg_catalog'
    AS $$
    SELECT (amount * bps + 5000) / 10000;
$$;

CREATE FUNCTION public.stall_line_discount(ticket uuid, item uuid, kind text, value bigint) RETURNS bigint
    LANGUAGE plpgsql STABLE
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    total bigint;
    share bigint;
    running bigint := 0;
    counted integer := 0;
    index integer := 0;
    line_item uuid;
    line_price bigint;
BEGIN
    IF kind IS NULL OR value IS NULL THEN
        RETURN 0;
    END IF;
    IF kind = 'percent' THEN
        SELECT public.stall_half_up(
            line.price_minor * line.quantity
                - line.line_discount_minor
                - line.offer_discount_minor
                - line.store_discount_minor,
            value
        )
        INTO share
        FROM public.ticket_lines AS line
        WHERE line.ticket_id = ticket
          AND line.item_id = item;
        RETURN COALESCE(share, 0);
    END IF;
    IF kind IS DISTINCT FROM 'amount' THEN
        RETURN 0;
    END IF;
    SELECT COALESCE(sum(
            line.price_minor * line.quantity
                - line.line_discount_minor
                - line.offer_discount_minor
                - line.store_discount_minor
        ), 0), count(*)::integer
    INTO total, counted
    FROM public.ticket_lines AS line
    WHERE line.ticket_id = ticket;
    IF total = 0 OR counted = 0 THEN
        RETURN 0;
    END IF;
    FOR line_item, line_price IN
        SELECT line.item_id,
               line.price_minor * line.quantity
                   - line.line_discount_minor
                   - line.offer_discount_minor
                   - line.store_discount_minor
        FROM public.ticket_lines AS line
        WHERE line.ticket_id = ticket
        ORDER BY line.line_no
    LOOP
        index := index + 1;
        IF index = counted THEN
            share := value - running;
        ELSE
            share := (line_price * value + total / 2) / total;
            running := running + share;
        END IF;
        IF line_item = item THEN
            RETURN share;
        END IF;
    END LOOP;
    RETURN 0;
END;
$$;

CREATE FUNCTION public.stall_pack_checksum(run uuid) RETURNS text
    LANGUAGE sql STABLE
    SET search_path TO 'pg_catalog'
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

CREATE FUNCTION public.stall_pack_checksum_frozen(run uuid) RETURNS text
    LANGUAGE sql STABLE
    SET search_path TO 'pg_catalog'
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

CREATE FUNCTION public.stall_pack_runs() RETURNS TABLE(id uuid, sales_from date, sales_to date, rent_month date, posting_date date)
    LANGUAGE sql
    AS $_$
    SELECT run.id, run.sales_from, run.sales_to, run.rent_month, run.posting_date
    FROM public.payout_runs AS run
    UNION ALL
    SELECT
        '00000000-0000-0000-0000-000000000000'::uuid,
        CASE
            WHEN current_setting('stall.pack_from', true) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
            THEN current_setting('stall.pack_from', true)::date
        END,
        CASE
            WHEN current_setting('stall.pack_to', true) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
            THEN current_setting('stall.pack_to', true)::date
        END,
        CASE
            WHEN current_setting('stall.pack_rent', true) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
            THEN current_setting('stall.pack_rent', true)::date
        END,
        CASE
            WHEN current_setting('stall.pack_posting', true) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
            THEN current_setting('stall.pack_posting', true)::date
        END
    WHERE current_setting('stall.pack_from', true) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
      AND current_setting('stall.pack_to', true) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
      AND current_setting('stall.pack_posting', true) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$';
$_$;

CREATE FUNCTION public.stall_rent_charge_suffix(period_start date) RETURNS text
    LANGUAGE sql STABLE
    AS $$
    SELECT CASE
        WHEN public.stall_rent_cycle_day() = 1 THEN to_char(period_start, 'YYYY-MM')
        ELSE to_char(period_start, 'YYYY-MM-DD')
    END
$$;

CREATE FUNCTION public.stall_rent_period_end(period_start date) RETURNS date
    LANGUAGE sql STABLE
    AS $$
    SELECT (period_start + interval '1 month' - interval '1 day')::date
$$;

CREATE FUNCTION public.stall_rent_period_start(on_date date) RETURNS date
    LANGUAGE plpgsql STABLE
    AS $$
DECLARE
    cycle_day integer;
    month_start date;
    anchor date;
BEGIN
    cycle_day := public.stall_rent_cycle_day();
    IF cycle_day = 1 THEN
        RETURN date_trunc('month', on_date)::date;
    END IF;
    month_start := date_trunc('month', on_date)::date;
    anchor := month_start + (cycle_day - 1);
    IF on_date >= anchor THEN
        RETURN anchor;
    END IF;
    RETURN ((month_start - interval '1 month')::date + (cycle_day - 1));
END;
$$;

CREATE TABLE public.assignment_rent (
    assignment_id uuid NOT NULL,
    amount_minor bigint NOT NULL,
    CONSTRAINT assignment_rent_amount_chk CHECK ((amount_minor > 0))
);

CREATE TABLE public.booth_assignments (
    id uuid DEFAULT uuidv7() NOT NULL,
    booth_id uuid NOT NULL,
    vendor_id uuid NOT NULL,
    starts_on date NOT NULL,
    ends_on date,
    assignment_range daterange GENERATED ALWAYS AS (daterange(starts_on, COALESCE(ends_on, 'infinity'::date), '[]'::text)) STORED,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT booth_assignments_dates_chk CHECK (((ends_on IS NULL) OR (starts_on <= ends_on)))
);

CREATE TABLE public.booths (
    id uuid DEFAULT uuidv7() NOT NULL,
    code text NOT NULL,
    name text NOT NULL,
    width_ft integer,
    depth_ft integer,
    x_ft integer,
    z_ft integer,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    floor_id uuid,
    CONSTRAINT booths_code_chk CHECK (((code = btrim(code)) AND (code <> ''::text))),
    CONSTRAINT booths_floor_chk CHECK (((floor_id IS NULL) = (x_ft IS NULL))),
    CONSTRAINT booths_name_chk CHECK (((name = btrim(name)) AND (name <> ''::text))),
    CONSTRAINT booths_place_chk CHECK ((((x_ft IS NULL) = (z_ft IS NULL)) AND ((x_ft IS NULL) OR ((x_ft >= 0) AND (z_ft >= 0))))),
    CONSTRAINT booths_size_chk CHECK ((((width_ft IS NULL) = (depth_ft IS NULL)) AND ((width_ft IS NULL) OR ((width_ft > 0) AND (depth_ft > 0)))))
);

CREATE TABLE public.chart_roles (
    role text NOT NULL,
    account_id uuid NOT NULL,
    CONSTRAINT chart_roles_role_chk CHECK (((role = btrim(role)) AND (role <> ''::text)))
);

CREATE TABLE public.items (
    id uuid DEFAULT uuidv7() NOT NULL,
    code text NOT NULL,
    vendor_id uuid,
    brand_id uuid,
    description text NOT NULL,
    price_minor bigint NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    barcode text NOT NULL,
    commission_bps integer,
    intake_on date NOT NULL,
    expires_on date,
    quantity integer DEFAULT 1 NOT NULL,
    color_id uuid,
    category_id uuid,
    size_id uuid,
    condition_id uuid,
    department_id uuid,
    has_photo boolean DEFAULT false NOT NULL,
    placement text DEFAULT 'consigned'::text NOT NULL,
    cost_minor bigint,
    CONSTRAINT items_barcode_chk CHECK (((barcode = btrim(barcode)) AND (barcode <> ''::text) AND (char_length(barcode) <= 100))),
    CONSTRAINT items_code_chk CHECK (((code = btrim(code)) AND (code <> ''::text) AND (char_length(code) <= 40))),
    CONSTRAINT items_commission_chk CHECK (((commission_bps IS NULL) OR ((commission_bps >= 0) AND (commission_bps <= 10000)))),
    CONSTRAINT items_cost_chk CHECK (((cost_minor IS NULL) OR (cost_minor >= 0))),
    CONSTRAINT items_dates_chk CHECK (((expires_on IS NULL) OR (intake_on <= expires_on))),
    CONSTRAINT items_description_chk CHECK (((description = btrim(description)) AND (description <> ''::text) AND (char_length(description) <= 200))),
    CONSTRAINT items_placement_chk CHECK ((placement = ANY (ARRAY['booth'::text, 'consigned'::text, 'store'::text]))),
    CONSTRAINT items_price_chk CHECK ((price_minor > 0)),
    CONSTRAINT items_quantity_chk CHECK ((quantity >= 0)),
    CONSTRAINT items_store_owner_chk CHECK ((((placement = 'store'::text) AND (vendor_id IS NULL) AND (commission_bps IS NULL) AND (expires_on IS NULL)) OR ((placement = ANY (ARRAY['booth'::text, 'consigned'::text])) AND (vendor_id IS NOT NULL) AND (cost_minor IS NULL))))
);

CREATE TABLE public.journal_lines (
    id uuid DEFAULT uuidv7() NOT NULL,
    journal_id uuid NOT NULL,
    book_id uuid NOT NULL,
    line_no integer NOT NULL,
    account_id uuid NOT NULL,
    debit_minor bigint NOT NULL,
    credit_minor bigint NOT NULL,
    subledger_type text,
    subledger_ref text,
    memo text DEFAULT ''::text NOT NULL,
    CONSTRAINT journal_lines_amounts_chk CHECK (((debit_minor >= 0) AND (credit_minor >= 0))),
    CONSTRAINT journal_lines_no_chk CHECK ((line_no > 0)),
    CONSTRAINT journal_lines_one_side_chk CHECK ((((debit_minor > 0) AND (credit_minor = 0)) OR ((credit_minor > 0) AND (debit_minor = 0)))),
    CONSTRAINT journal_lines_subledger_chk CHECK ((((subledger_type IS NULL) AND (subledger_ref IS NULL)) OR ((subledger_type IS NOT NULL) AND (subledger_type = btrim(subledger_type)) AND (subledger_type <> ''::text) AND (subledger_ref IS NOT NULL) AND (subledger_ref = btrim(subledger_ref)) AND (subledger_ref <> ''::text))))
);

CREATE TABLE public.journals (
    id uuid DEFAULT uuidv7() NOT NULL,
    book_id uuid NOT NULL,
    journal_no bigint NOT NULL,
    posting_key text NOT NULL,
    posting_date date NOT NULL,
    occurred_at timestamp with time zone NOT NULL,
    posted_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    posting_xid xid8 DEFAULT pg_current_xact_id() NOT NULL,
    period_id uuid NOT NULL,
    currency character(3) NOT NULL,
    source_type text NOT NULL,
    source_reference text NOT NULL,
    description text NOT NULL,
    is_reversal boolean DEFAULT false NOT NULL,
    reverses_journal_id uuid,
    debit_total_minor bigint NOT NULL,
    credit_total_minor bigint NOT NULL,
    CONSTRAINT journals_currency_chk CHECK ((((currency)::text = upper((currency)::text)) AND (currency ~ '^[A-Z]{3}$'::text))),
    CONSTRAINT journals_description_chk CHECK (((description = btrim(description)) AND (description <> ''::text))),
    CONSTRAINT journals_key_chk CHECK (((posting_key = btrim(posting_key)) AND (posting_key <> ''::text))),
    CONSTRAINT journals_reversal_chk CHECK (((is_reversal AND (reverses_journal_id IS NOT NULL) AND (reverses_journal_id <> id)) OR ((NOT is_reversal) AND (reverses_journal_id IS NULL)))),
    CONSTRAINT journals_source_chk CHECK (((source_type = btrim(source_type)) AND (source_type <> ''::text) AND (source_reference = btrim(source_reference)) AND (source_reference <> ''::text))),
    CONSTRAINT journals_totals_chk CHECK (((debit_total_minor > 0) AND (credit_total_minor > 0) AND (debit_total_minor = credit_total_minor)))
);

CREATE TABLE public.mall (
    id boolean DEFAULT true NOT NULL,
    time_zone text NOT NULL,
    booth_width_ft integer NOT NULL,
    booth_depth_ft integer NOT NULL,
    floor_width_ft integer NOT NULL,
    floor_depth_ft integer NOT NULL,
    commission_bps integer DEFAULT 0 NOT NULL,
    floor_days integer,
    tax_bps integer DEFAULT 0 NOT NULL,
    cash_round_nickel boolean DEFAULT false NOT NULL,
    operating_mode text DEFAULT 'both'::text NOT NULL,
    payout_cadence text DEFAULT 'monthly'::text NOT NULL,
    rent_prepaid_from_prior_sales boolean DEFAULT false NOT NULL,
    use_color boolean DEFAULT true NOT NULL,
    use_category boolean DEFAULT true NOT NULL,
    use_size boolean DEFAULT true NOT NULL,
    use_condition boolean DEFAULT true NOT NULL,
    use_department boolean DEFAULT true NOT NULL,
    label_stock text DEFAULT 'avery_8160'::text NOT NULL,
    use_brand boolean DEFAULT true NOT NULL,
    booth_rentals_enabled boolean DEFAULT true NOT NULL,
    rent_cycle_day smallint DEFAULT 1 NOT NULL,
    street text,
    street2 text,
    city text,
    state text,
    postal text,
    phone text,
    email text,
    late_fees_enabled boolean DEFAULT false NOT NULL,
    late_fee_grace_days smallint DEFAULT 0 NOT NULL,
    late_fee_first_minor bigint DEFAULT 0 NOT NULL,
    late_fee_next_minor bigint DEFAULT 0 NOT NULL,
    receipt_footer text DEFAULT 'Thank you'::text NOT NULL,
    consignment_enabled boolean DEFAULT true NOT NULL,
    store_stock_enabled boolean DEFAULT false NOT NULL,
    receipt_mail_via text DEFAULT 'server'::text NOT NULL,
    gmail_address text,
    gmail_app_password text,
    CONSTRAINT mall_booth_size_chk CHECK (((booth_width_ft > 0) AND (booth_depth_ft > 0))),
    CONSTRAINT mall_city_chk CHECK (((city IS NULL) OR ((city = btrim(city)) AND (city <> ''::text) AND (char_length(city) <= 80)))),
    CONSTRAINT mall_commission_chk CHECK (((commission_bps >= 0) AND (commission_bps <= 10000))),
    CONSTRAINT mall_email_chk CHECK (((email IS NULL) OR ((email = btrim(email)) AND (char_length(email) <= 200) AND (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text)))),
    CONSTRAINT mall_floor_chk CHECK (((floor_width_ft > 0) AND (floor_depth_ft > 0))),
    CONSTRAINT mall_floor_days_chk CHECK (((floor_days IS NULL) OR ((floor_days >= 1) AND (floor_days <= 365)))),
    CONSTRAINT mall_label_stock_chk CHECK ((label_stock = ANY (ARRAY['avery_8160'::text, 'avery_94073'::text, 'bcw_consignment'::text, 'bcw_consignment_2up'::text, 'bcw_jewelry'::text]))),
    CONSTRAINT mall_late_fee_amounts_chk CHECK (((NOT late_fees_enabled) OR ((late_fee_first_minor > 0) AND (late_fee_next_minor > 0)))),
    CONSTRAINT mall_late_fee_first_chk CHECK ((late_fee_first_minor >= 0)),
    CONSTRAINT mall_late_fee_grace_chk CHECK (((late_fee_grace_days >= 0) AND (late_fee_grace_days <= 28))),
    CONSTRAINT mall_late_fee_next_chk CHECK ((late_fee_next_minor >= 0)),
    CONSTRAINT mall_one_row CHECK (id),
    CONSTRAINT mall_one_way_to_sell_chk CHECK ((booth_rentals_enabled OR consignment_enabled OR store_stock_enabled)),
    CONSTRAINT mall_operating_mode_chk CHECK ((operating_mode = ANY (ARRAY['consignor'::text, 'booth_mall'::text, 'both'::text]))),
    CONSTRAINT mall_payout_cadence_chk CHECK ((payout_cadence = ANY (ARRAY['weekly'::text, 'biweekly'::text, 'monthly'::text, 'ad_hoc'::text]))),
    CONSTRAINT mall_phone_chk CHECK (((phone IS NULL) OR ((phone = btrim(phone)) AND (phone <> ''::text) AND (char_length(phone) <= 40)))),
    CONSTRAINT mall_postal_chk CHECK (((postal IS NULL) OR ((postal = btrim(postal)) AND (postal <> ''::text) AND (char_length(postal) <= 20)))),
    CONSTRAINT mall_receipt_footer_chk CHECK (((receipt_footer = btrim(receipt_footer, E' \t\n\r')) AND (char_length(receipt_footer) <= 400))),
    CONSTRAINT mall_receipt_mail_chk CHECK (((receipt_mail_via = ANY (ARRAY['server'::text, 'gmail'::text])) AND ((gmail_address IS NULL) OR ((gmail_address = btrim(gmail_address)) AND (char_length(gmail_address) <= 200) AND (gmail_address ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'::text))) AND ((gmail_app_password IS NULL) OR ((gmail_app_password = btrim(gmail_app_password)) AND ((char_length(gmail_app_password) >= 16) AND (char_length(gmail_app_password) <= 64)) AND (gmail_app_password ~ '^[A-Za-z0-9]+$'::text))) AND ((receipt_mail_via = 'server'::text) OR ((gmail_address IS NOT NULL) AND (gmail_app_password IS NOT NULL))))),
    CONSTRAINT mall_rent_cycle_day_chk CHECK (((rent_cycle_day >= 1) AND (rent_cycle_day <= 28))),
    CONSTRAINT mall_state_chk CHECK (((state IS NULL) OR ((state = btrim(state)) AND (state <> ''::text) AND (char_length(state) <= 40)))),
    CONSTRAINT mall_street2_chk CHECK (((street2 IS NULL) OR ((street2 = btrim(street2)) AND (street2 <> ''::text) AND (char_length(street2) <= 200)))),
    CONSTRAINT mall_street_chk CHECK (((street IS NULL) OR ((street = btrim(street)) AND (street <> ''::text) AND (char_length(street) <= 200)))),
    CONSTRAINT mall_tax_millis_chk CHECK (((tax_bps >= 0) AND (tax_bps <= 100000))),
    CONSTRAINT mall_zone_chk CHECK (((time_zone = btrim(time_zone)) AND (time_zone <> ''::text)))
);

CREATE TABLE public.vendors (
    id uuid DEFAULT uuidv7() NOT NULL,
    code text NOT NULL,
    first_name text NOT NULL,
    last_name text NOT NULL,
    active boolean DEFAULT true NOT NULL,
    phone text,
    email text,
    address text,
    commission_bps integer,
    agreement_starts_on date,
    agreement_ends_on date,
    tax_id text,
    tax_exempt boolean DEFAULT false NOT NULL,
    tax_exempt_number text,
    notes text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    payout_mode text DEFAULT 'pay'::text NOT NULL,
    business_name text,
    name text GENERATED ALWAYS AS (
CASE
    WHEN (business_name IS NOT NULL) THEN business_name
    WHEN (first_name = last_name) THEN first_name
    ELSE btrim(((first_name || ' '::text) || last_name))
END) STORED,
    CONSTRAINT vendors_address_chk CHECK (((address IS NULL) OR ((address = btrim(address)) AND (address <> ''::text) AND (char_length(address) <= 400)))),
    CONSTRAINT vendors_agreement_chk CHECK (((agreement_ends_on IS NULL) OR (agreement_starts_on IS NULL) OR (agreement_starts_on <= agreement_ends_on))),
    CONSTRAINT vendors_business_name_chk CHECK (((business_name IS NULL) OR ((business_name = btrim(business_name)) AND (business_name <> ''::text) AND (char_length(business_name) <= 120)))),
    CONSTRAINT vendors_code_chk CHECK (((code = btrim(code)) AND (code <> ''::text))),
    CONSTRAINT vendors_commission_chk CHECK (((commission_bps >= 0) AND (commission_bps <= 10000))),
    CONSTRAINT vendors_email_chk CHECK (((email IS NULL) OR ((email = btrim(email)) AND (email ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'::text) AND (char_length(email) <= 200)))),
    CONSTRAINT vendors_first_name_chk CHECK (((first_name = btrim(first_name)) AND (first_name <> ''::text))),
    CONSTRAINT vendors_last_name_chk CHECK (((last_name = btrim(last_name)) AND (last_name <> ''::text))),
    CONSTRAINT vendors_notes_chk CHECK (((notes IS NULL) OR ((notes = btrim(notes)) AND (notes <> ''::text) AND (char_length(notes) <= 2000)))),
    CONSTRAINT vendors_payout_mode_chk CHECK ((payout_mode = ANY (ARRAY['pay'::text, 'credit_only'::text, 'hold'::text]))),
    CONSTRAINT vendors_phone_chk CHECK (((phone IS NULL) OR ((phone = btrim(phone)) AND (phone <> ''::text) AND (char_length(phone) <= 40)))),
    CONSTRAINT vendors_tax_exempt_number_chk CHECK (((tax_exempt_number IS NULL) OR ((tax_exempt_number = btrim(tax_exempt_number)) AND (tax_exempt_number <> ''::text) AND (char_length(tax_exempt_number) <= 100)))),
    CONSTRAINT vendors_tax_exempt_requires_number_chk CHECK (((tax_exempt = false) OR (tax_exempt_number IS NOT NULL))),
    CONSTRAINT vendors_tax_id_chk CHECK (((tax_id IS NULL) OR ((tax_id = btrim(tax_id)) AND (tax_id <> ''::text) AND (char_length(tax_id) <= 40))))
);

CREATE VIEW public.payout_missing_rent AS
 SELECT run.id AS run_id,
    vendor.id AS vendor_id,
    assignment.booth_id,
    (series.period_start)::date AS month_start,
    GREATEST((series.period_start)::date, assignment.starts_on) AS posting_date,
        CASE
            WHEN ((assignment.starts_on > (series.period_start)::date) AND (assignment.starts_on <= public.stall_rent_period_end((series.period_start)::date))) THEN ((((public.stall_rent_period_end((series.period_start)::date) - assignment.starts_on) + 1) * rate.amount_minor) / ((public.stall_rent_period_end((series.period_start)::date) - (series.period_start)::date) + 1))
            ELSE rate.amount_minor
        END AS amount
   FROM ((((public.stall_pack_runs() run(id, sales_from, sales_to, rent_month, posting_date)
     JOIN public.vendors vendor ON (vendor.active))
     JOIN public.booth_assignments assignment ON ((assignment.vendor_id = vendor.id)))
     JOIN LATERAL generate_series((public.stall_rent_period_start(assignment.starts_on))::timestamp without time zone, (public.stall_rent_period_start(LEAST(COALESCE(assignment.ends_on, run.posting_date), run.posting_date)))::timestamp without time zone, '1 mon'::interval) series(period_start) ON ((assignment.starts_on <= LEAST(COALESCE(assignment.ends_on, run.posting_date), run.posting_date))))
     JOIN public.assignment_rent rate ON ((rate.assignment_id = assignment.id)))
  WHERE (( SELECT mall.booth_rentals_enabled
           FROM public.mall) AND (NOT (EXISTS ( SELECT 1
           FROM public.journals journal
          WHERE ((journal.source_type = 'rent_charge'::text) AND (journal.posting_key = ((('rent-charge:'::text || (assignment.booth_id)::text) || ':'::text) || public.stall_rent_charge_suffix((series.period_start)::date))) AND (NOT (EXISTS ( SELECT 1
                   FROM public.journals reversal
                  WHERE (reversal.reverses_journal_id = journal.id)))))))));

CREATE TABLE public.payout_run_journals (
    run_id uuid NOT NULL,
    journal_id uuid NOT NULL,
    role text NOT NULL,
    CONSTRAINT payout_run_journals_role_chk CHECK ((role = ANY (ARRAY['settlement'::text, 'disbursement'::text, 'rent_charge'::text, 'reversal'::text])))
);

CREATE TABLE public.sale_lines (
    journal_id uuid NOT NULL,
    line_no integer NOT NULL,
    item_id uuid NOT NULL,
    price_minor bigint NOT NULL,
    commission_minor bigint NOT NULL,
    tax_minor bigint NOT NULL,
    discount_minor bigint DEFAULT 0 NOT NULL,
    quantity integer DEFAULT 1 NOT NULL,
    offer_id uuid,
    store_discount_minor bigint DEFAULT 0 NOT NULL,
    CONSTRAINT sale_lines_commission_chk CHECK (((commission_minor >= 0) AND (commission_minor <= (price_minor * quantity)))),
    CONSTRAINT sale_lines_discount_chk CHECK (((discount_minor >= 0) AND (discount_minor <= (price_minor * quantity)))),
    CONSTRAINT sale_lines_no_chk CHECK ((line_no > 0)),
    CONSTRAINT sale_lines_price_chk CHECK ((price_minor > 0)),
    CONSTRAINT sale_lines_quantity_chk CHECK ((quantity > 0)),
    CONSTRAINT sale_lines_store_chk CHECK (((store_discount_minor >= 0) AND ((discount_minor + store_discount_minor) <= (price_minor * quantity)))),
    CONSTRAINT sale_lines_tax_chk CHECK ((tax_minor >= 0))
);

CREATE VIEW public.payout_pack_sales AS
 SELECT run.id AS run_id,
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
   FROM (((public.stall_pack_runs() run(id, sales_from, sales_to, rent_month, posting_date)
     JOIN public.journals journal ON ((((journal.posting_date >= run.sales_from) AND (journal.posting_date <= run.sales_to)) AND (journal.source_type = 'sale'::text) AND (NOT journal.is_reversal))))
     JOIN public.sale_lines sale ON ((sale.journal_id = journal.id)))
     JOIN public.items item ON ((item.id = sale.item_id)))
  WHERE ((NOT (EXISTS ( SELECT 1
           FROM public.payout_run_journals link
          WHERE ((link.run_id = run.id) AND (link.journal_id = journal.id))))) AND (journal.posting_key !~~ (('settlement:'::text || (run.id)::text) || ':%'::text)) AND (journal.posting_key !~~ (('disbursement:'::text || (run.id)::text) || ':%'::text)));

CREATE VIEW public.payout_pack AS
 WITH params AS (
         SELECT run.id AS run_id,
            run.sales_from,
            run.sales_to,
            run.rent_month,
            run.posting_date,
            vendor.id AS vendor_id,
            vendor.code,
            btrim(((vendor.first_name || ' '::text) || vendor.last_name)) AS name,
            vendor.payout_mode AS mode
           FROM (public.stall_pack_runs() run(id, sales_from, sales_to, rent_month, posting_date)
             JOIN public.vendors vendor ON (vendor.active))
        ), own AS (
         SELECT run.id AS run_id,
            journal.id AS journal_id
           FROM (public.stall_pack_runs() run(id, sales_from, sales_to, rent_month, posting_date)
             JOIN public.journals journal ON (((journal.posting_key ~~ (('settlement:'::text || (run.id)::text) || ':%'::text)) OR (journal.posting_key ~~ (('disbursement:'::text || (run.id)::text) || ':%'::text)))))
        UNION
         SELECT link.run_id,
            link.journal_id
           FROM public.payout_run_journals link
        ), pay AS (
         SELECT params.run_id,
            params.vendor_id,
            COALESCE(sum((line.credit_minor - line.debit_minor)) FILTER (WHERE (journal.posting_date < params.sales_from)), (0)::numeric) AS brought_forward_minor,
            COALESCE(sum((line.credit_minor - line.debit_minor)) FILTER (WHERE (journal.posting_date >= params.sales_from)), (0)::numeric) AS movement_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE ((journal.source_type = 'item_return'::text) AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS return_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE ((journal.source_type = 'sale'::text) AND journal.is_reversal AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS void_minor,
            COALESCE(sum(line.debit_minor) FILTER (WHERE ((journal.source_type = 'sale'::text) AND (NOT journal.is_reversal) AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS draw_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE ((journal.source_type = ANY (ARRAY['check_disbursement'::text, 'cash_disbursement'::text, 'safe_disbursement'::text])) AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS check_paid_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE ((journal.source_type = 'settlement'::text) AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS settlement_applied_minor
           FROM (((params
             JOIN public.journal_lines line ON (((line.subledger_type = 'vendor'::text) AND (line.subledger_ref = (params.vendor_id)::text))))
             JOIN public.journals journal ON ((journal.id = line.journal_id)))
             JOIN public.chart_roles role ON (((role.account_id = line.account_id) AND (role.role = 'vendor_payable'::text))))
          WHERE (NOT (EXISTS ( SELECT 1
                   FROM own
                  WHERE ((own.run_id = params.run_id) AND (own.journal_id = journal.id)))))
          GROUP BY params.run_id, params.vendor_id
        ), sale AS (
         SELECT payout_pack_sales.run_id,
            payout_pack_sales.vendor_id,
            COALESCE(sum((payout_pack_sales.price_minor * payout_pack_sales.quantity)), (0)::numeric) AS sales_minor,
            COALESCE(sum(payout_pack_sales.discount_minor), (0)::numeric) AS discount_minor,
            COALESCE(sum(payout_pack_sales.commission_minor), (0)::numeric) AS commission_minor,
            COALESCE(sum(payout_pack_sales.tax_minor), (0)::numeric) AS tax_minor
           FROM public.payout_pack_sales
          GROUP BY payout_pack_sales.run_id, payout_pack_sales.vendor_id
        ), rent_posted AS (
         SELECT params.run_id,
            params.vendor_id,
            COALESCE(sum((line.debit_minor - line.credit_minor)), (0)::numeric) AS total_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE (journal.posting_date < params.sales_from)), (0)::numeric) AS before_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE ((journal.source_type = 'rent_charge'::text) AND (public.stall_rent_period_start(journal.posting_date) = params.rent_month))), (0)::numeric) AS month_minor,
            COALESCE(sum((line.credit_minor - line.debit_minor)) FILTER (WHERE ((journal.source_type = 'rent_payment'::text) AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS paid_minor,
            COALESCE(sum((line.credit_minor - line.debit_minor)) FILTER (WHERE ((journal.source_type = 'settlement'::text) AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS settlement_minor
           FROM (((params
             JOIN public.journal_lines line ON ((line.subledger_type = 'booth'::text)))
             JOIN public.journals journal ON ((journal.id = line.journal_id)))
             JOIN public.chart_roles role ON (((role.account_id = line.account_id) AND (role.role = 'booth_rent_receivable'::text))))
          WHERE ((EXISTS ( SELECT 1
                   FROM public.booth_assignments assignment
                  WHERE ((assignment.vendor_id = params.vendor_id) AND (assignment.booth_id = (line.subledger_ref)::uuid) AND (assignment.assignment_range @> journal.posting_date)))) AND (NOT (EXISTS ( SELECT 1
                   FROM own
                  WHERE ((own.run_id = params.run_id) AND (own.journal_id = journal.id))))))
          GROUP BY params.run_id, params.vendor_id
        ), missing_month AS (
         SELECT payout_missing_rent.run_id,
            payout_missing_rent.vendor_id,
            payout_missing_rent.month_start,
            payout_missing_rent.posting_date,
            payout_missing_rent.amount
           FROM public.payout_missing_rent
        ), missing AS (
         SELECT missing_month.run_id,
            missing_month.vendor_id,
            COALESCE(sum(missing_month.amount), (0)::numeric) AS total_minor,
            COALESCE(sum(missing_month.amount) FILTER (WHERE (missing_month.posting_date < params.sales_from)), (0)::numeric) AS before_minor,
            COALESCE(sum(missing_month.amount) FILTER (WHERE (missing_month.month_start = params.rent_month)), (0)::numeric) AS month_minor
           FROM (missing_month
             JOIN params ON (((params.run_id = missing_month.run_id) AND (params.vendor_id = missing_month.vendor_id))))
          GROUP BY missing_month.run_id, missing_month.vendor_id
        ), own_rent AS (
         SELECT params.run_id,
            params.vendor_id,
            COALESCE(sum((line.debit_minor - line.credit_minor)), (0)::numeric) AS total_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE (journal.posting_date < params.sales_from)), (0)::numeric) AS before_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE ((journal.source_type = 'rent_charge'::text) AND (public.stall_rent_period_start(journal.posting_date) = params.rent_month))), (0)::numeric) AS month_minor
           FROM ((((params
             JOIN public.payout_run_journals link ON (((link.run_id = params.run_id) AND (link.role = 'rent_charge'::text))))
             JOIN public.journals journal ON ((journal.id = link.journal_id)))
             JOIN public.journal_lines line ON (((line.journal_id = journal.id) AND (line.subledger_type = 'booth'::text))))
             JOIN public.chart_roles role ON (((role.account_id = line.account_id) AND (role.role = 'booth_rent_receivable'::text))))
          WHERE (EXISTS ( SELECT 1
                   FROM public.booth_assignments assignment
                  WHERE ((assignment.vendor_id = params.vendor_id) AND (assignment.booth_id = (line.subledger_ref)::uuid) AND (assignment.assignment_range @> journal.posting_date))))
          GROUP BY params.run_id, params.vendor_id
        ), figures AS (
         SELECT params.run_id,
            params.vendor_id,
            params.code,
            params.name,
            params.mode,
            params.posting_date,
            COALESCE(sale.sales_minor, (0)::numeric) AS sales_minor,
            COALESCE(sale.commission_minor, (0)::numeric) AS commission_minor,
            COALESCE(sale.tax_minor, (0)::numeric) AS tax_minor,
            COALESCE(sale.discount_minor, (0)::numeric) AS discount_minor,
            COALESCE(pay.return_minor, (0)::numeric) AS return_minor,
            COALESCE(pay.void_minor, (0)::numeric) AS void_minor,
            COALESCE(pay.draw_minor, (0)::numeric) AS draw_minor,
            COALESCE(pay.check_paid_minor, (0)::numeric) AS check_paid_minor,
            COALESCE(pay.settlement_applied_minor, (0)::numeric) AS settlement_applied_minor,
            COALESCE(pay.brought_forward_minor, (0)::numeric) AS brought_forward_minor,
            (COALESCE(pay.brought_forward_minor, (0)::numeric) + COALESCE(pay.movement_minor, (0)::numeric)) AS carried_payable_minor,
            ((COALESCE(rent_posted.before_minor, (0)::numeric) + COALESCE(missing.before_minor, (0)::numeric)) + COALESCE(own_rent.before_minor, (0)::numeric)) AS rent_brought_forward_minor,
            ((COALESCE(rent_posted.month_minor, (0)::numeric) + COALESCE(missing.month_minor, (0)::numeric)) + COALESCE(own_rent.month_minor, (0)::numeric)) AS rent_minor,
            COALESCE(rent_posted.paid_minor, (0)::numeric) AS rent_paid_since_minor,
            COALESCE(rent_posted.settlement_minor, (0)::numeric) AS rent_settlement_minor,
            ((COALESCE(rent_posted.total_minor, (0)::numeric) + COALESCE(missing.total_minor, (0)::numeric)) + COALESCE(own_rent.total_minor, (0)::numeric)) AS rent_total_minor
           FROM (((((params
             LEFT JOIN pay ON (((pay.run_id = params.run_id) AND (pay.vendor_id = params.vendor_id))))
             LEFT JOIN sale ON (((sale.run_id = params.run_id) AND (sale.vendor_id = params.vendor_id))))
             LEFT JOIN rent_posted ON (((rent_posted.run_id = params.run_id) AND (rent_posted.vendor_id = params.vendor_id))))
             LEFT JOIN missing ON (((missing.run_id = params.run_id) AND (missing.vendor_id = params.vendor_id))))
             LEFT JOIN own_rent ON (((own_rent.run_id = params.run_id) AND (own_rent.vendor_id = params.vendor_id))))
        )
 SELECT figures.run_id,
    figures.vendor_id,
    figures.code,
    figures.name,
    COALESCE(spot.booth_code, ''::text) AS booth,
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
    ((((figures.rent_total_minor - figures.rent_brought_forward_minor) - figures.rent_minor) + figures.rent_paid_since_minor) + figures.rent_settlement_minor) AS rent_charged_minor,
    figures.rent_brought_forward_minor,
    figures.rent_paid_since_minor,
    ((figures.carried_payable_minor - figures.brought_forward_minor) - (((((((figures.sales_minor - figures.discount_minor) - figures.commission_minor) - figures.return_minor) - figures.void_minor) - figures.draw_minor) - figures.check_paid_minor) - figures.settlement_applied_minor)) AS adjustment_minor,
    figures.brought_forward_minor,
    figures.carried_payable_minor,
    figures.rent_total_minor AS carried_rent_minor,
        CASE
            WHEN (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) THEN LEAST(GREATEST(figures.carried_payable_minor, (0)::numeric), GREATEST(figures.rent_total_minor, (0)::numeric))
            ELSE (0)::numeric
        END AS settled_minor,
        CASE
            WHEN (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) THEN GREATEST((figures.carried_payable_minor - figures.rent_total_minor), (0)::numeric)
            ELSE GREATEST(figures.carried_payable_minor, (0)::numeric)
        END AS check_minor,
        CASE
            WHEN (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) THEN GREATEST((figures.rent_total_minor - figures.carried_payable_minor), (0)::numeric)
            ELSE GREATEST(figures.rent_total_minor, (0)::numeric)
        END AS owes_minor,
        CASE
            WHEN (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) THEN (figures.carried_payable_minor - figures.rent_total_minor)
            ELSE figures.carried_payable_minor
        END AS net_minor,
    figures.rent_brought_forward_minor AS prior,
    (((figures.rent_total_minor - figures.rent_brought_forward_minor) + figures.rent_paid_since_minor) + figures.rent_settlement_minor) AS charged,
    (figures.rent_paid_since_minor + figures.rent_settlement_minor) AS paid,
    figures.carried_payable_minor AS payable,
    figures.rent_total_minor AS rent,
        CASE
            WHEN (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) THEN GREATEST((figures.carried_payable_minor - figures.rent_total_minor), (0)::numeric)
            ELSE GREATEST(figures.carried_payable_minor, (0)::numeric)
        END AS "check",
        CASE
            WHEN (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) THEN GREATEST((figures.rent_total_minor - figures.carried_payable_minor), (0)::numeric)
            ELSE GREATEST(figures.rent_total_minor, (0)::numeric)
        END AS owes,
    (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) AS rent_from_sales,
    spot.booth_id
   FROM ((figures
     CROSS JOIN public.mall mall_settings)
     LEFT JOIN LATERAL ( SELECT booth.id AS booth_id,
            booth.code AS booth_code
           FROM (public.booth_assignments assignment
             JOIN public.booths booth ON ((booth.id = assignment.booth_id)))
          WHERE ((assignment.vendor_id = figures.vendor_id) AND (assignment.assignment_range @> figures.posting_date))
          ORDER BY booth.code
         LIMIT 1) spot ON (true));

CREATE FUNCTION public.stall_payout_pack(target_run uuid, window_from date, window_to date, window_rent date, window_posting date) RETURNS SETOF public.payout_pack
    LANGUAGE plpgsql
    AS $$
DECLARE
    preview_id uuid := '00000000-0000-0000-0000-000000000000';
BEGIN
    PERFORM set_config('stall.pack_from', window_from::text, true);
    PERFORM set_config('stall.pack_to', window_to::text, true);
    PERFORM set_config('stall.pack_rent', COALESCE(window_rent::text, ''), true);
    PERFORM set_config('stall.pack_posting', window_posting::text, true);
    RETURN QUERY
        SELECT *
        FROM payout_pack
        WHERE run_id = COALESCE(target_run, preview_id);
    PERFORM set_config('stall.pack_from', '', true);
END;
$$;

CREATE FUNCTION public.stall_quantity_deal(item uuid, units integer, tag bigint) RETURNS TABLE(picked_offer uuid, vendor_discount bigint, store_discount bigint)
    LANGUAGE plpgsql STABLE
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    mall_day date;
    vendor uuid;
    best_savings bigint := 0;
    best_rank integer := 4;
    best_offer uuid;
    best_funded text;
    row_offer uuid;
    row_funded text;
    row_kind text;
    row_threshold integer;
    row_deal bigint;
    row_rank integer;
    savings bigint;
    groups integer;
BEGIN
    IF units < 1 OR tag < 1 THEN
        RETURN;
    END IF;

    SELECT (clock_timestamp() AT TIME ZONE mall.time_zone)::date
    INTO mall_day
    FROM public.mall;

    SELECT items.vendor_id INTO vendor
    FROM public.items AS items
    WHERE items.id = item;

    FOR row_offer, row_funded, row_kind, row_threshold, row_deal, row_rank IN
        SELECT offer.id, offer.funded_by, offer.kind, offer.threshold_qty, offer.deal_minor,
               CASE offer.scope WHEN 'item' THEN 1 WHEN 'vendor' THEN 2 ELSE 3 END
        FROM public.offers AS offer
        WHERE offer.starts_on <= mall_day
          AND (offer.ends_on IS NULL OR offer.ends_on >= mall_day)
          AND (
              (offer.scope = 'item' AND offer.scope_id = item)
              OR (offer.scope = 'vendor' AND offer.scope_id = vendor)
              OR offer.scope = 'store'
          )
    LOOP
        savings := 0;
        IF row_kind = 'bundle' THEN
            groups := units / row_threshold;
            IF groups >= 1 THEN
                savings := tag * units - (groups * row_deal + (units % row_threshold) * tag);
            END IF;
        ELSIF units >= row_threshold AND row_deal < tag THEN
            savings := units * (tag - row_deal);
        END IF;
        IF savings > best_savings
            OR (
                savings = best_savings
                AND savings > 0
                AND (row_rank < best_rank OR (row_rank = best_rank AND row_offer < best_offer))
            ) THEN
            best_savings := savings;
            best_rank := row_rank;
            best_offer := row_offer;
            best_funded := row_funded;
        END IF;
    END LOOP;

    IF best_savings < 1 OR best_offer IS NULL THEN
        RETURN;
    END IF;

    picked_offer := best_offer;
    vendor_discount := CASE WHEN best_funded = 'store' THEN 0 ELSE best_savings END;
    store_discount := CASE WHEN best_funded = 'store' THEN best_savings ELSE 0 END;
    RETURN NEXT;
END;
$$;

CREATE FUNCTION public.stall_refuse_ticket_discount(ticket uuid, kind text, value bigint) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    line_price bigint;
    line_item uuid;
    line_off bigint;
    offer_off bigint;
    store_off bigint;
    share bigint;
    items integer;
BEGIN
    IF kind IS NULL THEN
        RETURN;
    END IF;
    SELECT count(*)::integer INTO items FROM public.ticket_lines WHERE ticket_id = ticket;
    IF items = 0 THEN
        RAISE EXCEPTION 'a ticket needs an item'
            USING ERRCODE = '23514';
    END IF;
    FOR line_item, line_price, line_off, offer_off, store_off IN
        SELECT line.item_id,
               line.price_minor * line.quantity,
               line.line_discount_minor,
               line.offer_discount_minor,
               line.store_discount_minor
        FROM public.ticket_lines AS line
        WHERE line.ticket_id = ticket
        ORDER BY line.line_no
    LOOP
        share := public.stall_line_discount(ticket, line_item, kind, value);
        IF share < 0 OR line_off + offer_off + store_off + share > line_price THEN
            RAISE EXCEPTION 'the discount is more than the price'
                USING ERRCODE = '23514';
        END IF;
    END LOOP;
END;
$$;

CREATE FUNCTION public.stall_register_expected_cash(target_session uuid) RETURNS bigint
    LANGUAGE sql STABLE
    SET search_path TO 'pg_catalog'
    AS $$
    SELECT session.opening_cash_minor + COALESCE((
        SELECT sum(line.debit_minor - line.credit_minor)
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        JOIN public.register_session_posts AS post ON post.journal_id = line.journal_id
        JOIN public.journals AS journal ON journal.id = line.journal_id
        WHERE post.session_id = session.id
          AND role.role = 'cash'
          AND journal.source_type <> 'register_variance'
    ), 0)
    FROM public.register_sessions AS session
    WHERE session.id = target_session;
$$;

CREATE FUNCTION public.stall_reject_assignment_rent_rewrite() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM public.journal_lines AS line
        JOIN public.journals AS posted ON posted.id = line.journal_id
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        JOIN public.booth_assignments AS assignment ON assignment.id = OLD.assignment_id
        WHERE role.role = 'booth_rent_receivable'
          AND line.subledger_ref = assignment.booth_id::text
          AND assignment.assignment_range @> posted.posting_date
    ) THEN
        RAISE EXCEPTION 'an assignment rent cannot change after rent is posted'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'DELETE' THEN
        RETURN OLD;
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_reject_assignment_rewrite() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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

CREATE FUNCTION public.stall_reject_booth_place() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    default_width integer;
    default_depth integer;
    floor_width integer;
    floor_depth integer;
BEGIN
    IF NEW.x_ft IS NULL THEN
        NEW.floor_id := NULL;
    ELSIF NEW.floor_id IS NULL THEN
        SELECT id INTO NEW.floor_id
        FROM public.map_floors
        ORDER BY sort_order, lower(name)
        LIMIT 1;
        IF NEW.floor_id IS NULL THEN
            RAISE EXCEPTION 'the mall has no floor'
                USING ERRCODE = '23514';
        END IF;
    END IF;
    SELECT booth_width_ft, booth_depth_ft, floor_width_ft, floor_depth_ft
    INTO default_width, default_depth, floor_width, floor_depth
    FROM public.mall
    FOR UPDATE;
    PERFORM public.stall_assert_booth_on_floor(
        NEW.id, NEW.floor_id, NEW.x_ft, NEW.z_ft, NEW.width_ft, NEW.depth_ft,
        default_width, default_depth, floor_width, floor_depth
    );
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_reject_chart_account_change() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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

CREATE FUNCTION public.stall_reject_chart_account_delete() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF EXISTS (SELECT 1 FROM public.chart_roles WHERE account_id = OLD.id) THEN
        RAISE EXCEPTION 'a chart account cannot be deleted'
            USING ERRCODE = '23514';
    END IF;
    RETURN OLD;
END;
$$;

CREATE FUNCTION public.stall_reject_chart_role_change() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'a chart role cannot be changed or removed'
        USING ERRCODE = '23514';
END;
$$;

CREATE FUNCTION public.stall_reject_close_with_open_ticket() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF TG_OP <> 'UPDATE' OR OLD.closed_at IS NOT NULL OR NEW.closed_at IS NULL THEN
        RETURN NEW;
    END IF;

    DELETE FROM public.tickets AS ticket
    WHERE ticket.session_id = NEW.id
      AND ticket.status = 'open'
      AND NOT EXISTS (
          SELECT 1
          FROM public.ticket_lines AS line
          WHERE line.ticket_id = ticket.id
      );

    IF EXISTS (
        SELECT 1
        FROM public.tickets AS ticket
        WHERE ticket.session_id = NEW.id
          AND ticket.status = 'open'
    ) THEN
        RAISE EXCEPTION 'close is refused while a ticket is open'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_reject_closed_payout_window() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.payout_runs AS run
        WHERE run.kind = 'batch'
          AND run.reverses_run_id IS NULL
          AND run.status IN ('settled', 'paid')
          AND NEW.posting_date BETWEEN run.sales_from AND run.sales_to
    ) AND NOT EXISTS (
        SELECT 1
        FROM public.payout_runs AS open_run
        WHERE open_run.kind = 'batch'
          AND open_run.reverses_run_id IS NULL
          AND open_run.status = 'draft'
          AND NEW.posting_date BETWEEN open_run.sales_from AND open_run.sales_to
    ) THEN
        RAISE EXCEPTION 'a settled payout window refuses a journal'
            USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

CREATE FUNCTION public.stall_reject_control_source() RETURNS trigger
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

    IF chart_role IN ('vendor_payable', 'booth_rent_receivable') THEN
        RAISE EXCEPTION 'a control account line must come from a sale, rent charge, rent payment, or payout'
            USING ERRCODE = '23514';
    END IF;

    RAISE EXCEPTION 'a chart account line must come from a listed source type'
        USING ERRCODE = '23514';
END;
$$;

CREATE FUNCTION public.stall_reject_duplicate_disbursement_check() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    source text;
BEGIN
    SELECT journal.source_type INTO source
    FROM public.journals AS journal
    WHERE journal.id = NEW.journal_id;

    IF source = 'check_disbursement' THEN
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
    END IF;

    IF source IS DISTINCT FROM 'rent_payment' THEN
        RETURN NEW;
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.register_checks AS existing
        JOIN public.journals AS journal ON journal.id = existing.journal_id
        JOIN public.journal_lines AS line
          ON line.journal_id = journal.id
         AND line.subledger_type = 'booth'
        JOIN public.chart_roles AS role
          ON role.account_id = line.account_id
         AND role.role = 'booth_rent_receivable'
        JOIN public.booth_assignments AS assignment
          ON assignment.booth_id = line.subledger_ref::uuid
         AND assignment.assignment_range @> journal.posting_date
        WHERE existing.check_number = NEW.check_number
          AND existing.journal_id <> NEW.journal_id
          AND journal.source_type = 'rent_payment'
          AND NOT EXISTS (
              SELECT 1 FROM public.journals AS reversal
              WHERE reversal.reverses_journal_id = journal.id
          )
          AND assignment.vendor_id = (
              SELECT covering.vendor_id
              FROM public.journals AS receipt
              JOIN public.journal_lines AS credit
                ON credit.journal_id = receipt.id
               AND credit.subledger_type = 'booth'
              JOIN public.chart_roles AS credit_role
                ON credit_role.account_id = credit.account_id
               AND credit_role.role = 'booth_rent_receivable'
              JOIN public.booth_assignments AS covering
                ON covering.booth_id = credit.subledger_ref::uuid
               AND covering.assignment_range @> receipt.posting_date
              WHERE receipt.id = NEW.journal_id
              LIMIT 1
          )
    ) THEN
        RAISE EXCEPTION 'a received check number is unique for that vendor'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_reject_duplicate_opening() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    live_opening integer;
    other_journal integer;
BEGIN
    IF NEW.posting_key <> 'opening' THEN
        RETURN NEW;
    END IF;

    PERFORM pg_advisory_xact_lock(hashtextextended(NEW.book_id::text || chr(31) || NEW.posting_key, 0));

    SELECT count(*) INTO live_opening
    FROM public.journals AS prior
    WHERE prior.book_id = NEW.book_id
      AND prior.posting_key = 'opening'
      AND NOT EXISTS (
          SELECT 1
          FROM public.journals AS reversal
          WHERE reversal.reverses_journal_id = prior.id
      );

    IF live_opening > 0 THEN
        RAISE EXCEPTION 'duplicate key value violates unique constraint "journals_book_key"'
            USING ERRCODE = '23505';
    END IF;

    SELECT count(*) INTO other_journal
    FROM public.journals AS other
    WHERE other.book_id = NEW.book_id
      AND other.posting_key <> 'opening'
      AND NOT EXISTS (
          SELECT 1
          FROM public.journals AS opened
          WHERE opened.id = other.reverses_journal_id
            AND opened.book_id = NEW.book_id
            AND opened.posting_key = 'opening'
      );

    IF other_journal > 0 THEN
        RAISE EXCEPTION 'duplicate key value violates unique constraint "journals_book_key"'
            USING ERRCODE = '23505';
    END IF;

    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_reject_earlier_open_period(book uuid, period_start date) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    PERFORM 1
    FROM public.accounting_periods
    WHERE book_id = book
      AND starts_on < period_start
      AND status = 'open'
    ORDER BY starts_on
    FOR UPDATE;

    IF FOUND THEN
        RAISE EXCEPTION 'an earlier period is still open'
            USING ERRCODE = '23514';
    END IF;
END;
$$;

CREATE FUNCTION public.stall_reject_floor_change() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    placed record;
BEGIN
    PERFORM 1
    FROM public.mall
    FOR UPDATE;
    FOR placed IN
        SELECT id, floor_id, x_ft, z_ft, width_ft, depth_ft
        FROM public.booths
        WHERE x_ft IS NOT NULL
    LOOP
        PERFORM public.stall_assert_booth_on_floor(
            placed.id, placed.floor_id, placed.x_ft, placed.z_ft, placed.width_ft, placed.depth_ft,
            NEW.booth_width_ft, NEW.booth_depth_ft, NEW.floor_width_ft, NEW.floor_depth_ft
        );
    END LOOP;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_reject_inactive_with_open_rent() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF NEW.active OR NOT OLD.active THEN
        RETURN NEW;
    END IF;
    IF EXISTS (
        SELECT 1
        FROM public.journal_lines AS line
        JOIN public.journals AS posted ON posted.id = line.journal_id
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE role.role = 'booth_rent_receivable'
          AND line.subledger_type = 'booth'
          AND EXISTS (
              SELECT 1
              FROM public.booth_assignments AS assignment
              WHERE assignment.vendor_id = OLD.id
                AND assignment.booth_id = line.subledger_ref::uuid
                AND assignment.assignment_range @> posted.posting_date
          )
        GROUP BY line.subledger_ref
        HAVING sum(line.debit_minor - line.credit_minor) > 0
    ) THEN
        RAISE EXCEPTION 'a vendor with open rent cannot be made inactive'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_reject_last_register() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF (SELECT count(*) FROM public.registers) <= 1 THEN
        RAISE EXCEPTION 'the last register cannot be deleted'
            USING ERRCODE = '23514';
    END IF;
    RETURN OLD;
END;
$$;

CREATE FUNCTION public.stall_reject_live_posting_key() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF NEW.posting_key = 'opening' THEN
        RETURN NEW;
    END IF;

    PERFORM pg_advisory_xact_lock(hashtextextended(NEW.book_id::text || chr(31) || NEW.posting_key, 0));

    IF EXISTS (
        SELECT 1
        FROM public.journals AS prior
        WHERE prior.book_id = NEW.book_id
          AND prior.posting_key = NEW.posting_key
          AND NOT EXISTS (
              SELECT 1 FROM public.journals AS reversal
              WHERE reversal.reverses_journal_id = prior.id
          )
    ) THEN
        RAISE EXCEPTION 'duplicate key value violates unique constraint "journals_book_key"'
            USING ERRCODE = '23505';
    END IF;

    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_reject_map_floor_delete() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF (SELECT count(*) FROM public.map_floors) <= 1 THEN
        RAISE EXCEPTION 'the mall keeps at least one floor'
            USING ERRCODE = '25006';
    END IF;
    IF EXISTS (SELECT 1 FROM public.booths WHERE floor_id = OLD.id) THEN
        RAISE EXCEPTION 'take booths off this floor before removing it'
            USING ERRCODE = '23503';
    END IF;
    RETURN OLD;
END;
$$;

CREATE FUNCTION public.stall_reject_map_floor_insert() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF (SELECT count(*) FROM public.map_floors) >= 20 THEN
        RAISE EXCEPTION 'a mall keeps at most 20 floors'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_reject_payout_rent_month() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.rent_month IS NOT DISTINCT FROM OLD.rent_month THEN
        RETURN NEW;
    END IF;
    IF NEW.rent_month IS NOT NULL AND NEW.rent_month IS DISTINCT FROM public.stall_rent_period_start(NEW.rent_month) THEN
        RAISE EXCEPTION 'a payout rent month is the start of a rent period'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_reject_period_before_closed() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    PERFORM 1
    FROM public.ledger_books
    WHERE id = NEW.book_id
    FOR UPDATE;

    IF EXISTS (
        SELECT 1
        FROM public.accounting_periods
        WHERE book_id = NEW.book_id
          AND status = 'closed'
          AND starts_on > NEW.starts_on
    ) THEN
        RAISE EXCEPTION 'a period cannot start before a closed period'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_reject_posted_run_line() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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
    IF run_status = 'paid'
       AND OLD.check_number IS NULL
       AND NEW.check_number IS NOT NULL
       AND NEW.disbursement_journal_id IS NOT DISTINCT FROM OLD.disbursement_journal_id THEN
        RETURN NEW;
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

CREATE FUNCTION public.stall_reject_posted_run_sale() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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

CREATE FUNCTION public.stall_reject_referenced_party_delete() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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

CREATE FUNCTION public.stall_reject_register_check() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'a check number cannot be deleted'
            USING ERRCODE = '23514';
    END IF;
    RAISE EXCEPTION 'a check number cannot be changed'
        USING ERRCODE = '23514';
END;
$$;

CREATE FUNCTION public.stall_reject_rent_policy() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF NEW.rent_cycle_day IS DISTINCT FROM OLD.rent_cycle_day AND EXISTS (
        SELECT 1
        FROM public.journals AS posted
        WHERE posted.source_type = 'rent_charge'
          AND NOT posted.is_reversal
          AND NOT EXISTS (
              SELECT 1
              FROM public.journals AS reversal
              WHERE reversal.reverses_journal_id = posted.id
          )
    ) THEN
        RAISE EXCEPTION 'the rent cycle day cannot change after a rent charge is posted'
            USING ERRCODE = '23514';
    END IF;

    IF OLD.booth_rentals_enabled AND NOT NEW.booth_rentals_enabled AND EXISTS (
        SELECT 1
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE role.role = 'booth_rent_receivable'
          AND line.subledger_type = 'booth'
        GROUP BY line.subledger_ref
        HAVING sum(line.debit_minor - line.credit_minor) > 0
    ) THEN
        RAISE EXCEPTION 'booth rentals stay on while a booth still owes rent'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_reject_run_journal_change() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'a payout run journal cannot change'
        USING ERRCODE = '23514';
END;
$$;

CREATE FUNCTION public.stall_reject_run_status() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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

CREATE FUNCTION public.stall_reject_second_book() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'the mall has one book'
        USING ERRCODE = '23514';
END;
$$;

CREATE FUNCTION public.stall_reject_session_post() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    source text;
    open_session uuid;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'a register session post cannot be deleted'
            USING ERRCODE = '23514';
    END IF;
    IF TG_OP = 'UPDATE' THEN
        RAISE EXCEPTION 'a register session post cannot be changed'
            USING ERRCODE = '23514';
    END IF;

    SELECT source_type INTO source
    FROM public.journals
    WHERE id = NEW.journal_id;

    IF source IS NULL OR source NOT IN (
        'sale',
        'item_return',
        'rent_payment',
        'cash_disbursement',
        'gift_certificate_issue',
        'gift_card_issue',
        'store_credit_issue',
        'cash_drop',
        'register_variance',
        'layaway_deposit',
        'layaway_refund'
    ) THEN
        RAISE EXCEPTION 'a drawer journal needs an open register'
            USING ERRCODE = '23514';
    END IF;

    SELECT id INTO open_session
    FROM public.register_sessions
    WHERE id = NEW.session_id
      AND closed_at IS NULL;

    IF open_session IS NULL THEN
        RAISE EXCEPTION 'a drawer journal needs an open register'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_reject_session_rewrite() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $_$
DECLARE
    counts jsonb;
    expected text[] := ARRAY['10000', '5000', '2000', '1000', '500', '100', '25', '10', '5', '1'];
    bill text;
    qty bigint;
    total bigint;
    stored bigint;
    message text;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'a register session cannot be deleted'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'INSERT' AND (
        NEW.closed_at IS NOT NULL
        OR NEW.closing_counts IS NOT NULL
        OR NEW.counted_cash_minor IS NOT NULL
    ) THEN
        RAISE EXCEPTION 'a session opens before it closes'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF OLD.closed_at IS NOT NULL THEN
            RAISE EXCEPTION 'a closed session cannot be changed'
                USING ERRCODE = '23514';
        END IF;
        IF NEW.id IS DISTINCT FROM OLD.id
            OR NEW.register_id IS DISTINCT FROM OLD.register_id
            OR NEW.cashier IS DISTINCT FROM OLD.cashier
            OR NEW.opened_at IS DISTINCT FROM OLD.opened_at
            OR NEW.opening_counts IS DISTINCT FROM OLD.opening_counts
            OR NEW.opening_cash_minor IS DISTINCT FROM OLD.opening_cash_minor THEN
            RAISE EXCEPTION 'an opening count cannot be changed'
                USING ERRCODE = '23514';
        END IF;
    END IF;

    IF TG_OP = 'INSERT' THEN
        counts := NEW.opening_counts;
        stored := NEW.opening_cash_minor;
        message := 'opening cash is the bill and coin count';
    ELSE
        counts := NEW.closing_counts;
        stored := NEW.counted_cash_minor;
        message := 'counted cash is the bill and coin count';
    END IF;

    total := 0;
    IF counts IS NULL OR jsonb_typeof(counts) <> 'object' OR (
        SELECT count(*) FROM jsonb_object_keys(counts)
    ) <> 10 THEN
        RAISE EXCEPTION '%', message
            USING ERRCODE = '23514';
    END IF;
    FOREACH bill IN ARRAY expected LOOP
        IF NOT counts ? bill
            OR jsonb_typeof(counts -> bill) <> 'number'
            OR (counts ->> bill) !~ '^[0-9]+$' THEN
            RAISE EXCEPTION '%', message
                USING ERRCODE = '23514';
        END IF;
        qty := (counts ->> bill)::bigint;
        total := total + (qty * bill::bigint);
    END LOOP;
    IF stored IS DISTINCT FROM total THEN
        RAISE EXCEPTION '%', message
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$_$;

CREATE FUNCTION public.stall_reject_stored_value_change() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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

CREATE FUNCTION public.stall_reject_unknown_subledger() RETURNS trigger
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog'
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

CREATE FUNCTION public.stall_rent_cycle_day() RETURNS integer
    LANGUAGE sql STABLE
    AS $$
    SELECT rent_cycle_day FROM public.mall
$$;

CREATE FUNCTION public.stall_restore_on_hand() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    units integer;
    item uuid;
BEGIN
    IF NEW.is_reversal IS DISTINCT FROM true OR NEW.reverses_journal_id IS NULL THEN
        RETURN NEW;
    END IF;

    IF NEW.source_type = 'sale' THEN
        FOR item, units IN
            SELECT sale.item_id,
                   sale.quantity - COALESCE((
                       SELECT sum(returned.quantity)::integer
                       FROM public.item_returns AS returned
                       JOIN public.journals AS return_journal ON return_journal.id = returned.journal_id
                       WHERE returned.sale_journal_id = NEW.reverses_journal_id
                         AND returned.item_id = sale.item_id
                         AND return_journal.is_reversal = false
                         AND NOT EXISTS (
                             SELECT 1
                             FROM public.journals AS reversal
                             WHERE reversal.reverses_journal_id = return_journal.id
                               AND reversal.is_reversal = true
                         )
                   ), 0)
            FROM public.sale_lines AS sale
            WHERE sale.journal_id = NEW.reverses_journal_id
        LOOP
            IF units > 0 THEN
                UPDATE public.items
                SET quantity = quantity + units
                WHERE id = item;
            END IF;
        END LOOP;
    ELSIF NEW.source_type = 'item_return' THEN
        UPDATE public.items AS item
        SET quantity = item.quantity - returned.quantity
        FROM public.item_returns AS returned
        WHERE returned.journal_id = NEW.reverses_journal_id
          AND item.id = returned.item_id;
    END IF;

    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_reverse_payout_run(target uuid, entry_day date, actor text, reason text) RETURNS uuid
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    original public.payout_runs%ROWTYPE;
    reversal_id uuid;
    period uuid;
    composed text;
    item record;
    new_journal uuid;
    role_name text;
BEGIN
    IF actor IS NULL OR btrim(actor) = '' OR reason IS NULL OR btrim(reason) = '' THEN
        RAISE EXCEPTION 'a payout reversal needs the operator and a reason'
            USING ERRCODE = '23514';
    END IF;

    SELECT * INTO original FROM public.payout_runs WHERE id = target FOR UPDATE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'that payout run is not in this mall'
            USING ERRCODE = '23503';
    END IF;
    IF original.reverses_run_id IS NOT NULL OR original.status NOT IN ('settled', 'paid') THEN
        RAISE EXCEPTION 'a payout run status cannot skip'
            USING ERRCODE = '23514';
    END IF;

    composed := left('Reversal of run ' || original.run_no::text || ' — ' || btrim(reason), 2000);
    INSERT INTO public.payout_runs (
        book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date,
        note, reverses_run_id, reversed_by, reversal_reason
    ) VALUES (
        original.book_id, original.kind, original.cadence, original.sales_from, original.sales_to,
        original.rent_month, original.tender, 'settled', entry_day,
        composed, original.id, btrim(actor), btrim(reason)
    ) RETURNING id INTO reversal_id;

    SELECT id INTO period
    FROM public.accounting_periods
    WHERE book_id = original.book_id
      AND status = 'open'
      AND period_range @> entry_day;
    IF period IS NULL THEN
        RAISE EXCEPTION 'journal posting date must fall inside its period'
            USING ERRCODE = '23514';
    END IF;

    SET CONSTRAINTS ALL DEFERRED;

    FOREACH role_name IN ARRAY ARRAY['disbursement', 'settlement', 'rent_charge']
    LOOP
        FOR item IN
            SELECT linked.journal_id
            FROM public.payout_run_journals AS linked
            WHERE linked.run_id = original.id
              AND linked.role = role_name
            ORDER BY linked.journal_id
        LOOP
            new_journal := uuidv7();
            INSERT INTO public.journals (
                id, book_id, posting_key, posting_date, occurred_at, period_id,
                currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
                debit_total_minor, credit_total_minor
            )
            SELECT
                new_journal, posted.book_id, 'reversal:' || posted.id::text, entry_day,
                GREATEST(clock_timestamp(), entry_day::timestamp AT TIME ZONE 'UTC' + interval '1 second'), period,
                posted.currency, posted.source_type, posted.source_reference, 'Reversal', true, posted.id,
                posted.credit_total_minor, posted.debit_total_minor
            FROM public.journals AS posted
            WHERE posted.id = item.journal_id;

            INSERT INTO public.journal_lines (
                journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref, memo
            )
            SELECT
                new_journal, line.book_id, line.line_no, line.account_id, line.credit_minor, line.debit_minor,
                line.subledger_type, line.subledger_ref, line.memo
            FROM public.journal_lines AS line
            WHERE line.journal_id = item.journal_id;

            INSERT INTO public.payout_run_journals (run_id, journal_id, role)
            VALUES (reversal_id, new_journal, 'reversal');
        END LOOP;
    END LOOP;

    UPDATE public.payout_runs
    SET status = 'reversed', reversed_at = clock_timestamp()
    WHERE id = original.id;

    SET CONSTRAINTS ALL IMMEDIATE;
    SET CONSTRAINTS ALL DEFERRED;
    RETURN reversal_id;
END;
$$;

CREATE FUNCTION public.stall_seed_export_accounts() RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    INSERT INTO public.export_accounts (system, role, external_code)
    SELECT systems.system, role.role, account.code
    FROM public.chart_roles AS role
    JOIN public.accounts AS account ON account.id = role.account_id
    CROSS JOIN (VALUES ('quickbooks'), ('xero')) AS systems (system)
    WHERE NOT EXISTS (
        SELECT 1
        FROM public.export_accounts AS existing
        WHERE existing.system = systems.system
          AND existing.role = role.role
    );
END;
$$;

CREATE FUNCTION public.stall_sticker_deal(item uuid) RETURNS text
    LANGUAGE plpgsql STABLE
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    mall_day date;
    tag bigint;
    best_savings bigint := 0;
    best_name text := '';
    best_offer uuid;
    row_offer uuid;
    row_name text;
    row_kind text;
    row_threshold integer;
    row_deal bigint;
    savings bigint;
BEGIN
    SELECT item_row.price_minor INTO tag
    FROM public.items AS item_row
    WHERE item_row.id = item;
    IF tag IS NULL THEN
        RETURN '';
    END IF;

    SELECT (clock_timestamp() AT TIME ZONE mall.time_zone)::date
    INTO mall_day
    FROM public.mall;

    FOR row_offer, row_name, row_kind, row_threshold, row_deal IN
        SELECT offer.id, offer.name, offer.kind, offer.threshold_qty, offer.deal_minor
        FROM public.offers AS offer
        WHERE offer.scope = 'item'
          AND offer.scope_id = item
          AND offer.starts_on <= mall_day
          AND (offer.ends_on IS NULL OR offer.ends_on >= mall_day)
    LOOP
        savings := 0;
        IF row_kind = 'bundle' AND row_deal < tag * row_threshold THEN
            savings := tag * row_threshold - row_deal;
        ELSIF row_kind = 'unit_break' AND row_deal < tag THEN
            savings := row_threshold * (tag - row_deal);
        END IF;
        IF savings > best_savings OR (savings = best_savings AND savings > 0 AND row_offer < best_offer) THEN
            best_savings := savings;
            best_name := row_name;
            best_offer := row_offer;
        END IF;
    END LOOP;

    RETURN best_name;
END;
$$;

CREATE FUNCTION public.stall_tax_minor(net bigint, tax_bps bigint) RETURNS bigint
    LANGUAGE sql IMMUTABLE
    SET search_path TO 'pg_catalog'
    AS $$
    SELECT (net * tax_bps + 50000) / 100000;
$$;

CREATE TABLE public.accounts (
    id uuid DEFAULT uuidv7() NOT NULL,
    book_id uuid NOT NULL,
    code text NOT NULL,
    name text NOT NULL,
    account_type text NOT NULL,
    normal_balance text NOT NULL,
    is_postable boolean NOT NULL,
    is_control boolean DEFAULT false NOT NULL,
    subledger_type text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT accounts_code_chk CHECK (((code = btrim(code)) AND (code <> ''::text))),
    CONSTRAINT accounts_control_subledger_chk CHECK (((is_control AND (subledger_type IS NOT NULL) AND (subledger_type = btrim(subledger_type)) AND (subledger_type <> ''::text)) OR ((NOT is_control) AND (subledger_type IS NULL)))),
    CONSTRAINT accounts_name_chk CHECK (((name = btrim(name)) AND (name <> ''::text))),
    CONSTRAINT accounts_normal_chk CHECK ((normal_balance = ANY (ARRAY['debit'::text, 'credit'::text]))),
    CONSTRAINT accounts_type_chk CHECK ((account_type = ANY (ARRAY['asset'::text, 'liability'::text, 'equity'::text, 'revenue'::text, 'expense'::text])))
);

CREATE VIEW public.account_balances AS
 SELECT account.book_id,
    account.id AS account_id,
    account.code,
    account.account_type,
    account.normal_balance,
    (COALESCE(sum(line.debit_minor), (0)::numeric))::bigint AS debit_minor,
    (COALESCE(sum(line.credit_minor), (0)::numeric))::bigint AS credit_minor,
    (
        CASE account.normal_balance
            WHEN 'debit'::text THEN (COALESCE(sum(line.debit_minor), (0)::numeric) - COALESCE(sum(line.credit_minor), (0)::numeric))
            ELSE (COALESCE(sum(line.credit_minor), (0)::numeric) - COALESCE(sum(line.debit_minor), (0)::numeric))
        END)::bigint AS balance_minor
   FROM (public.accounts account
     LEFT JOIN public.journal_lines line ON (((line.account_id = account.id) AND (line.book_id = account.book_id))))
  GROUP BY account.book_id, account.id, account.code, account.account_type, account.normal_balance;

CREATE TABLE public.accounting_periods (
    id uuid DEFAULT uuidv7() NOT NULL,
    book_id uuid NOT NULL,
    starts_on date NOT NULL,
    ends_on date NOT NULL,
    status text NOT NULL,
    period_range daterange GENERATED ALWAYS AS (daterange(starts_on, ends_on, '[]'::text)) STORED,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT accounting_periods_range_chk CHECK ((starts_on <= ends_on)),
    CONSTRAINT accounting_periods_status_chk CHECK ((status = ANY (ARRAY['open'::text, 'closed'::text])))
);

CREATE TABLE public.book_journal_counters (
    book_id uuid NOT NULL,
    last_no bigint NOT NULL,
    CONSTRAINT book_journal_counters_last_no_chk CHECK ((last_no >= 0))
);

CREATE TABLE public.book_payout_run_counters (
    book_id uuid NOT NULL,
    last_no bigint NOT NULL,
    CONSTRAINT book_payout_run_counters_last_no_chk CHECK ((last_no >= 0))
);

CREATE TABLE public.booth_rent_rates (
    booth_id uuid NOT NULL,
    amount_minor bigint NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT booth_rent_rates_amount_chk CHECK ((amount_minor > 0))
);

CREATE TABLE public.brands (
    id uuid DEFAULT uuidv7() NOT NULL,
    name text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT brands_name_chk CHECK (((name = btrim(name)) AND (name <> ''::text)))
);

CREATE TABLE public.categories (
    id uuid DEFAULT uuidv7() NOT NULL,
    name text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT categories_name_chk CHECK (((name = btrim(name)) AND (name <> ''::text)))
);

CREATE TABLE public.chart_source_rules (
    role text NOT NULL,
    source_type text NOT NULL,
    CONSTRAINT chart_source_rules_source_chk CHECK (((source_type = btrim(source_type)) AND (source_type <> ''::text)))
);

CREATE TABLE public.colors (
    id uuid DEFAULT uuidv7() NOT NULL,
    name text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT colors_name_chk CHECK (((name = btrim(name)) AND (name <> ''::text)))
);

CREATE TABLE public.conditions (
    id uuid DEFAULT uuidv7() NOT NULL,
    name text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT conditions_name_chk CHECK (((name = btrim(name)) AND (name <> ''::text)))
);

CREATE TABLE public.customers (
    id uuid DEFAULT uuidv7() NOT NULL,
    name text NOT NULL,
    phone text,
    email text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT customers_email_chk CHECK (((email IS NULL) OR ((email = btrim(email)) AND (email ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'::text) AND (char_length(email) <= 200)))),
    CONSTRAINT customers_name_chk CHECK (((name = btrim(name)) AND (name <> ''::text) AND (char_length(name) <= 120))),
    CONSTRAINT customers_phone_chk CHECK (((phone IS NULL) OR ((phone = btrim(phone)) AND (phone <> ''::text) AND (char_length(phone) <= 40))))
);

CREATE TABLE public.departments (
    id uuid DEFAULT uuidv7() NOT NULL,
    name text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT departments_name_chk CHECK (((name = btrim(name)) AND (name <> ''::text)))
);

CREATE TABLE public.export_accounts (
    system text NOT NULL,
    role text NOT NULL,
    external_code text NOT NULL,
    CONSTRAINT export_accounts_code_chk CHECK (((external_code = btrim(external_code)) AND (external_code <> ''::text))),
    CONSTRAINT export_accounts_system_chk CHECK ((system = ANY (ARRAY['quickbooks'::text, 'xero'::text])))
);

CREATE VIEW public.export_lines AS
 SELECT journal.journal_no,
    journal.posting_date,
    journal.occurred_at,
    line.line_no,
    journal.posting_key,
    journal.source_type,
    journal.source_reference,
    account.code AS account_code,
    line.debit_minor,
    line.credit_minor,
    line.subledger_type,
    line.subledger_ref,
    journal.description,
    journal.currency
   FROM ((public.journal_lines line
     JOIN public.journals journal ON (((journal.id = line.journal_id) AND (journal.book_id = line.book_id))))
     JOIN public.accounts account ON (((account.id = line.account_id) AND (account.book_id = line.book_id))));

CREATE TABLE public.item_buys (
    id uuid DEFAULT uuidv7() NOT NULL,
    journal_id uuid NOT NULL,
    item_id uuid NOT NULL,
    vendor_id uuid NOT NULL,
    amount_minor bigint NOT NULL,
    CONSTRAINT item_buys_amount_chk CHECK ((amount_minor >= 0))
);

CREATE TABLE public.item_dispositions (
    id uuid DEFAULT uuidv7() NOT NULL,
    item_id uuid NOT NULL,
    kind text NOT NULL,
    posted_on date NOT NULL,
    CONSTRAINT item_dispositions_kind_chk CHECK ((kind = ANY (ARRAY['return'::text, 'donate'::text])))
);

CREATE TABLE public.item_photos (
    item_id uuid NOT NULL,
    photo bytea NOT NULL,
    CONSTRAINT item_photos_size_chk CHECK (((octet_length(photo) >= 1) AND (octet_length(photo) <= 2000000)))
);

CREATE TABLE public.item_price_events (
    id uuid DEFAULT uuidv7() NOT NULL,
    item_id uuid NOT NULL,
    step_id uuid NOT NULL,
    prior_minor bigint NOT NULL,
    price_minor bigint NOT NULL,
    applied_on date NOT NULL,
    CONSTRAINT item_price_events_prices_chk CHECK (((prior_minor > 0) AND (price_minor > 0) AND (price_minor < prior_minor)))
);

CREATE TABLE public.item_returns (
    journal_id uuid NOT NULL,
    sale_journal_id uuid NOT NULL,
    item_id uuid NOT NULL,
    price_minor bigint NOT NULL,
    commission_minor bigint NOT NULL,
    tax_minor bigint NOT NULL,
    discount_minor bigint DEFAULT 0 NOT NULL,
    quantity integer DEFAULT 1 NOT NULL,
    store_discount_minor bigint DEFAULT 0 NOT NULL,
    CONSTRAINT item_returns_commission_chk CHECK (((commission_minor >= 0) AND (commission_minor <= price_minor))),
    CONSTRAINT item_returns_discount_chk CHECK (((discount_minor >= 0) AND (discount_minor <= price_minor))),
    CONSTRAINT item_returns_price_chk CHECK ((price_minor > 0)),
    CONSTRAINT item_returns_quantity_chk CHECK ((quantity > 0)),
    CONSTRAINT item_returns_store_chk CHECK (((store_discount_minor >= 0) AND ((discount_minor + store_discount_minor) <= price_minor))),
    CONSTRAINT item_returns_tax_chk CHECK ((tax_minor >= 0))
);

CREATE TABLE public.journal_cash_tendered (
    journal_id uuid NOT NULL,
    tendered_minor bigint NOT NULL,
    CONSTRAINT journal_cash_tendered_amount_chk CHECK ((tendered_minor > 0))
);

CREATE TABLE public.ledger_books (
    id uuid DEFAULT uuidv7() NOT NULL,
    code text NOT NULL,
    name text NOT NULL,
    currency character(3) NOT NULL,
    currency_scale smallint NOT NULL,
    is_default boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT ledger_books_code_chk CHECK (((code = btrim(code)) AND (code <> ''::text))),
    CONSTRAINT ledger_books_currency_chk CHECK ((((currency)::text = upper((currency)::text)) AND (currency ~ '^[A-Z]{3}$'::text))),
    CONSTRAINT ledger_books_name_chk CHECK (((name = btrim(name)) AND (name <> ''::text))),
    CONSTRAINT ledger_books_scale_chk CHECK (((currency_scale >= 0) AND (currency_scale <= 4)))
);

CREATE TABLE public.map_floors (
    id uuid DEFAULT uuidv7() NOT NULL,
    name text NOT NULL,
    sort_order integer NOT NULL,
    CONSTRAINT map_floors_name_chk CHECK (((name = btrim(name)) AND ((char_length(name) >= 1) AND (char_length(name) <= 40)))),
    CONSTRAINT map_floors_sort_chk CHECK ((sort_order >= 0))
);

CREATE TABLE public.markdown_steps (
    id uuid DEFAULT uuidv7() NOT NULL,
    days_on_floor integer NOT NULL,
    percent integer NOT NULL,
    CONSTRAINT markdown_steps_days_chk CHECK (((days_on_floor >= 1) AND (days_on_floor <= 365))),
    CONSTRAINT markdown_steps_percent_chk CHECK (((percent >= 1) AND (percent <= 99)))
);

CREATE TABLE public.offers (
    id uuid DEFAULT uuidv7() NOT NULL,
    name text NOT NULL,
    scope text NOT NULL,
    scope_id uuid,
    kind text NOT NULL,
    threshold_qty integer NOT NULL,
    deal_minor bigint NOT NULL,
    funded_by text NOT NULL,
    starts_on date NOT NULL,
    ends_on date,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT offers_dates_chk CHECK (((ends_on IS NULL) OR (starts_on <= ends_on))),
    CONSTRAINT offers_deal_chk CHECK ((deal_minor > 0)),
    CONSTRAINT offers_funded_chk CHECK ((funded_by = ANY (ARRAY['vendor'::text, 'store'::text]))),
    CONSTRAINT offers_kind_chk CHECK ((kind = ANY (ARRAY['bundle'::text, 'unit_break'::text]))),
    CONSTRAINT offers_name_chk CHECK (((name = btrim(name)) AND (name <> ''::text) AND (char_length(name) <= 80))),
    CONSTRAINT offers_scope_chk CHECK ((((scope = 'store'::text) AND (scope_id IS NULL)) OR ((scope = ANY (ARRAY['item'::text, 'vendor'::text])) AND (scope_id IS NOT NULL)))),
    CONSTRAINT offers_threshold_chk CHECK ((threshold_qty >= 2))
);

CREATE TABLE public.payout_run_lines (
    id uuid DEFAULT uuidv7() NOT NULL,
    run_id uuid NOT NULL,
    vendor_id uuid NOT NULL,
    mode text NOT NULL,
    sales_minor bigint DEFAULT 0 NOT NULL,
    commission_minor bigint DEFAULT 0 NOT NULL,
    tax_minor bigint DEFAULT 0 NOT NULL,
    discount_minor bigint DEFAULT 0 NOT NULL,
    return_minor bigint DEFAULT 0 NOT NULL,
    void_minor bigint DEFAULT 0 NOT NULL,
    draw_minor bigint DEFAULT 0 NOT NULL,
    check_paid_minor bigint DEFAULT 0 NOT NULL,
    settlement_applied_minor bigint DEFAULT 0 NOT NULL,
    rent_minor bigint DEFAULT 0 NOT NULL,
    rent_charged_minor bigint DEFAULT 0 NOT NULL,
    rent_brought_forward_minor bigint DEFAULT 0 NOT NULL,
    rent_paid_since_minor bigint DEFAULT 0 NOT NULL,
    adjustment_minor bigint DEFAULT 0 NOT NULL,
    brought_forward_minor bigint DEFAULT 0 NOT NULL,
    carried_payable_minor bigint DEFAULT 0 NOT NULL,
    carried_rent_minor bigint DEFAULT 0 NOT NULL,
    settled_minor bigint DEFAULT 0 NOT NULL,
    check_minor bigint DEFAULT 0 NOT NULL,
    owes_minor bigint DEFAULT 0 NOT NULL,
    net_minor bigint DEFAULT 0 NOT NULL,
    settlement_journal_id uuid,
    disbursement_journal_id uuid,
    check_number text,
    CONSTRAINT payout_run_lines_check_number_chk CHECK (((check_number IS NULL) OR ((check_number = btrim(check_number)) AND (check_number <> ''::text)))),
    CONSTRAINT payout_run_lines_mode_chk CHECK ((mode = ANY (ARRAY['pay'::text, 'credit_only'::text, 'hold'::text])))
);

CREATE TABLE public.payout_run_sales (
    run_id uuid NOT NULL,
    vendor_id uuid NOT NULL,
    journal_id uuid NOT NULL,
    line_no integer NOT NULL,
    item_id uuid NOT NULL,
    sold_on date NOT NULL,
    item_code text NOT NULL,
    description text NOT NULL,
    price_minor bigint NOT NULL,
    commission_minor bigint NOT NULL,
    tax_minor bigint NOT NULL,
    discount_minor bigint DEFAULT 0 NOT NULL,
    quantity integer DEFAULT 1 NOT NULL
);

CREATE TABLE public.payout_runs (
    id uuid DEFAULT uuidv7() NOT NULL,
    book_id uuid NOT NULL,
    run_no bigint NOT NULL,
    kind text NOT NULL,
    cadence text NOT NULL,
    sales_from date NOT NULL,
    sales_to date NOT NULL,
    rent_month date,
    tender text NOT NULL,
    status text DEFAULT 'draft'::text NOT NULL,
    posting_date date NOT NULL,
    note text,
    reversed_by text,
    reversal_reason text,
    reviewed_checksum text,
    reviewed_at timestamp with time zone,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    settled_at timestamp with time zone,
    paid_at timestamp with time zone,
    reversed_at timestamp with time zone,
    reverses_run_id uuid,
    CONSTRAINT payout_runs_cadence_chk CHECK ((cadence = ANY (ARRAY['weekly'::text, 'biweekly'::text, 'monthly'::text, 'ad_hoc'::text]))),
    CONSTRAINT payout_runs_kind_chk CHECK ((kind = ANY (ARRAY['batch'::text, 'single'::text]))),
    CONSTRAINT payout_runs_note_chk CHECK (((note IS NULL) OR ((note = btrim(note)) AND (note <> ''::text) AND (char_length(note) <= 2000)))),
    CONSTRAINT payout_runs_reversal_audit_chk CHECK ((((reverses_run_id IS NULL) AND (reversed_by IS NULL) AND (reversal_reason IS NULL)) OR ((reverses_run_id IS NOT NULL) AND (reversed_by = btrim(reversed_by)) AND (reversed_by <> ''::text) AND (reversal_reason = btrim(reversal_reason)) AND (reversal_reason <> ''::text) AND (char_length(reversal_reason) <= 2000)))),
    CONSTRAINT payout_runs_reversal_chk CHECK (((reverses_run_id IS NULL) OR ((status = 'settled'::text) AND (reverses_run_id <> id)))),
    CONSTRAINT payout_runs_status_chk CHECK ((status = ANY (ARRAY['draft'::text, 'settled'::text, 'paid'::text, 'reversed'::text]))),
    CONSTRAINT payout_runs_tender_chk CHECK ((tender = ANY (ARRAY['drawer_cash'::text, 'safe_cash'::text, 'check'::text]))),
    CONSTRAINT payout_runs_window_chk CHECK ((sales_from <= sales_to))
);

CREATE TABLE public.register_checks (
    journal_id uuid NOT NULL,
    check_number text NOT NULL,
    CONSTRAINT register_checks_number_chk CHECK (((check_number = btrim(check_number)) AND (check_number <> ''::text) AND (char_length(check_number) <= 40)))
);

CREATE TABLE public.register_session_posts (
    journal_id uuid NOT NULL,
    session_id uuid NOT NULL
);

CREATE TABLE public.register_sessions (
    id uuid DEFAULT uuidv7() NOT NULL,
    register_id uuid NOT NULL,
    cashier text NOT NULL,
    opened_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    closed_at timestamp with time zone,
    opening_counts jsonb NOT NULL,
    opening_cash_minor bigint NOT NULL,
    closing_counts jsonb,
    counted_cash_minor bigint,
    counted_card_minor bigint,
    counted_checks_minor bigint,
    CONSTRAINT register_sessions_cashier_chk CHECK (((cashier = btrim(cashier)) AND (cashier <> ''::text) AND (char_length(cashier) <= 80))),
    CONSTRAINT register_sessions_close_shape_chk CHECK ((((closed_at IS NULL) AND (closing_counts IS NULL) AND (counted_cash_minor IS NULL) AND (counted_card_minor IS NULL) AND (counted_checks_minor IS NULL)) OR ((closed_at IS NOT NULL) AND (closing_counts IS NOT NULL) AND (counted_cash_minor IS NOT NULL) AND (counted_card_minor IS NOT NULL) AND (counted_card_minor >= 0) AND (counted_checks_minor IS NOT NULL) AND (counted_checks_minor >= 0)))),
    CONSTRAINT register_sessions_counted_chk CHECK (((counted_cash_minor IS NULL) OR (counted_cash_minor >= 0))),
    CONSTRAINT register_sessions_open_cash_chk CHECK ((opening_cash_minor >= 0))
);

CREATE TABLE public.registers (
    id uuid DEFAULT uuidv7() NOT NULL,
    name text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT registers_name_chk CHECK (((name = btrim(name)) AND (name <> ''::text) AND (char_length(name) <= 40)))
);

CREATE TABLE public.schema_migrations (
    filename text NOT NULL,
    checksum text NOT NULL,
    applied_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT schema_migrations_checksum_chk CHECK ((checksum ~ '^[0-9a-f]{64}$'::text)),
    CONSTRAINT schema_migrations_filename_chk CHECK ((filename ~ '^[0-9]{3}_[a-z0-9_]+\.sql$'::text))
);

CREATE TABLE public.sizes (
    id uuid DEFAULT uuidv7() NOT NULL,
    name text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT sizes_name_chk CHECK (((name = btrim(name)) AND (name <> ''::text)))
);

CREATE TABLE public.staff (
    id uuid DEFAULT uuidv7() NOT NULL,
    username text NOT NULL,
    display_name text NOT NULL,
    password_hash text NOT NULL,
    role text NOT NULL,
    vendor_id uuid,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    email text,
    credentials_version integer DEFAULT 1 NOT NULL,
    CONSTRAINT staff_credentials_version_chk CHECK ((credentials_version >= 1)),
    CONSTRAINT staff_display_name_chk CHECK (((display_name = btrim(display_name)) AND (display_name <> ''::text) AND (char_length(display_name) <= 80))),
    CONSTRAINT staff_email_chk CHECK (((email IS NULL) OR ((email = btrim(email)) AND (char_length(email) <= 200) AND (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text)))),
    CONSTRAINT staff_password_chk CHECK ((char_length(password_hash) >= 20)),
    CONSTRAINT staff_role_chk CHECK ((role = ANY (ARRAY['cashier'::text, 'vendor'::text, 'books'::text]))),
    CONSTRAINT staff_username_chk CHECK (((username = btrim(username)) AND (username ~ '^[a-z][a-z0-9-]{0,62}$'::text))),
    CONSTRAINT staff_vendor_chk CHECK ((((role = 'vendor'::text) AND (vendor_id IS NOT NULL)) OR ((role = ANY (ARRAY['cashier'::text, 'books'::text])) AND (vendor_id IS NULL))))
);

CREATE TABLE public.stored_values (
    id uuid DEFAULT uuidv7() NOT NULL,
    kind text NOT NULL,
    number text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT stored_values_kind_chk CHECK ((kind = ANY (ARRAY['gift_certificate'::text, 'gift_card'::text, 'store_credit'::text]))),
    CONSTRAINT stored_values_number_chk CHECK (((number = btrim(number)) AND (number <> ''::text) AND (char_length(number) <= 40)))
);

CREATE TABLE public.ticket_lines (
    ticket_id uuid NOT NULL,
    line_no integer NOT NULL,
    item_id uuid NOT NULL,
    price_minor bigint NOT NULL,
    quantity integer DEFAULT 1 NOT NULL,
    line_discount_kind text,
    line_discount_value bigint,
    line_discount_reason text,
    line_discount_minor bigint DEFAULT 0 NOT NULL,
    offer_id uuid,
    offer_discount_minor bigint DEFAULT 0 NOT NULL,
    store_discount_minor bigint DEFAULT 0 NOT NULL,
    CONSTRAINT ticket_lines_line_discount_chk CHECK (((line_discount_minor >= 0) AND (((line_discount_kind IS NULL) AND (line_discount_value IS NULL) AND (line_discount_reason IS NULL) AND (line_discount_minor = 0)) OR ((line_discount_kind = 'percent'::text) AND (line_discount_value > 0) AND (line_discount_value <= 10000) AND (line_discount_reason = ANY (ARRAY['manager approval'::text, 'damaged'::text, 'loyalty'::text, 'promotion'::text, 'other'::text])) AND (line_discount_minor > 0)) OR ((line_discount_kind = 'amount'::text) AND (line_discount_value > 0) AND (line_discount_reason = ANY (ARRAY['manager approval'::text, 'damaged'::text, 'loyalty'::text, 'promotion'::text, 'other'::text])) AND (line_discount_minor = line_discount_value))))),
    CONSTRAINT ticket_lines_no_chk CHECK ((line_no > 0)),
    CONSTRAINT ticket_lines_offer_chk CHECK (((offer_discount_minor >= 0) AND (store_discount_minor >= 0) AND (((offer_id IS NULL) AND (offer_discount_minor = 0) AND (store_discount_minor = 0)) OR ((offer_id IS NOT NULL) AND (((offer_discount_minor > 0) AND (store_discount_minor = 0)) OR ((store_discount_minor > 0) AND (offer_discount_minor = 0))))))),
    CONSTRAINT ticket_lines_price_chk CHECK ((price_minor > 0)),
    CONSTRAINT ticket_lines_quantity_chk CHECK ((quantity > 0))
);

CREATE TABLE public.ticket_tenders (
    ticket_id uuid NOT NULL,
    role text NOT NULL,
    amount_minor bigint NOT NULL,
    reference text,
    CONSTRAINT ticket_tenders_amount_chk CHECK ((amount_minor > 0)),
    CONSTRAINT ticket_tenders_reference_chk CHECK (((reference IS NULL) OR ((reference = btrim(reference)) AND (char_length(reference) <= 40)))),
    CONSTRAINT ticket_tenders_role_chk CHECK ((role = ANY (ARRAY['cash'::text, 'card'::text, 'checks'::text, 'gift_certificate'::text, 'gift_card'::text, 'store_credit'::text, 'vendor_draw'::text])))
);

CREATE TABLE public.tickets (
    id uuid NOT NULL,
    session_id uuid NOT NULL,
    status text DEFAULT 'open'::text NOT NULL,
    posted_journal_id uuid,
    discount_kind text,
    discount_value bigint,
    discount_reason text,
    tax_waiver_reason text,
    buyer_id uuid,
    customer_id uuid,
    park_label text,
    CONSTRAINT tickets_discount_chk CHECK ((((discount_kind IS NULL) AND (discount_value IS NULL) AND (discount_reason IS NULL)) OR ((discount_kind = 'percent'::text) AND (discount_value > 0) AND (discount_value <= 10000) AND (discount_reason = ANY (ARRAY['manager approval'::text, 'damaged'::text, 'loyalty'::text, 'promotion'::text, 'other'::text]))) OR ((discount_kind = 'amount'::text) AND (discount_value > 0) AND (discount_reason = ANY (ARRAY['manager approval'::text, 'damaged'::text, 'loyalty'::text, 'promotion'::text, 'other'::text]))))),
    CONSTRAINT tickets_park_label_chk CHECK (((park_label IS NULL) OR ((park_label = btrim(park_label)) AND ((char_length(park_label) >= 1) AND (char_length(park_label) <= 40))))),
    CONSTRAINT tickets_posted_chk CHECK ((((status = ANY (ARRAY['open'::text, 'parked'::text, 'layaway'::text])) AND (posted_journal_id IS NULL)) OR ((status = 'posted'::text) AND (posted_journal_id IS NOT NULL)))),
    CONSTRAINT tickets_status_chk CHECK ((status = ANY (ARRAY['open'::text, 'posted'::text, 'parked'::text, 'layaway'::text]))),
    CONSTRAINT tickets_waiver_chk CHECK (((tax_waiver_reason IS NULL) OR (tax_waiver_reason = ANY (ARRAY['resale'::text, 'charity'::text, 'government or school'::text, 'other'::text]))))
);

CREATE VIEW public.vendor_statement AS
 SELECT
        CASE
            WHEN (role.role = 'vendor_payable'::text) THEN (line.subledger_ref)::uuid
            ELSE assignment.vendor_id
        END AS vendor_id,
    journal.posting_date,
    journal.occurred_at,
    journal.journal_no,
    line.line_no,
    journal.id AS journal_id,
    journal.source_type,
    journal.posting_key,
    role.role,
    line.subledger_type,
    line.subledger_ref,
    line.debit_minor,
    line.credit_minor
   FROM (((public.journal_lines line
     JOIN public.journals journal ON (((journal.id = line.journal_id) AND (journal.book_id = line.book_id))))
     JOIN public.chart_roles role ON ((role.account_id = line.account_id)))
     LEFT JOIN public.booth_assignments assignment ON (((role.role = 'booth_rent_receivable'::text) AND (assignment.booth_id = (line.subledger_ref)::uuid) AND (assignment.assignment_range @> journal.posting_date))))
  WHERE (role.role = ANY (ARRAY['vendor_payable'::text, 'booth_rent_receivable'::text]));

CREATE TABLE public.wait_list (
    id uuid DEFAULT uuidv7() NOT NULL,
    first_name text NOT NULL,
    last_name text NOT NULL,
    email text NOT NULL,
    phone text NOT NULL,
    width_ft integer,
    depth_ft integer,
    status text DEFAULT 'waiting'::text NOT NULL,
    notes text,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT wait_list_email_chk CHECK (((email = btrim(email)) AND (email ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'::text) AND (char_length(email) <= 200))),
    CONSTRAINT wait_list_first_name_chk CHECK (((first_name = btrim(first_name)) AND (first_name <> ''::text))),
    CONSTRAINT wait_list_last_name_chk CHECK (((last_name = btrim(last_name)) AND (last_name <> ''::text))),
    CONSTRAINT wait_list_notes_chk CHECK (((notes IS NULL) OR ((notes = btrim(notes)) AND (notes <> ''::text) AND (char_length(notes) <= 2000)))),
    CONSTRAINT wait_list_phone_chk CHECK (((phone = btrim(phone)) AND (phone <> ''::text) AND (char_length(phone) <= 40))),
    CONSTRAINT wait_list_size_chk CHECK ((((width_ft IS NULL) = (depth_ft IS NULL)) AND ((width_ft IS NULL) OR ((width_ft > 0) AND (depth_ft > 0))))),
    CONSTRAINT wait_list_status_chk CHECK ((status = ANY (ARRAY['waiting'::text, 'contacted'::text, 'placed'::text, 'declined'::text])))
);

ALTER TABLE ONLY public.accounting_periods
    ADD CONSTRAINT accounting_periods_book_id_key UNIQUE (book_id, id);

ALTER TABLE ONLY public.accounting_periods
    ADD CONSTRAINT accounting_periods_book_start_key UNIQUE (book_id, starts_on);

ALTER TABLE ONLY public.accounting_periods
    ADD CONSTRAINT accounting_periods_no_overlap EXCLUDE USING gist (book_id WITH =, period_range WITH &&);

ALTER TABLE ONLY public.accounting_periods
    ADD CONSTRAINT accounting_periods_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT accounts_book_code_key UNIQUE (book_id, code);

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT accounts_book_id_key UNIQUE (book_id, id);

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT accounts_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.assignment_rent
    ADD CONSTRAINT assignment_rent_pkey PRIMARY KEY (assignment_id);

ALTER TABLE ONLY public.book_journal_counters
    ADD CONSTRAINT book_journal_counters_pkey PRIMARY KEY (book_id);

ALTER TABLE ONLY public.book_payout_run_counters
    ADD CONSTRAINT book_payout_run_counters_pkey PRIMARY KEY (book_id);

ALTER TABLE ONLY public.booth_assignments
    ADD CONSTRAINT booth_assignments_no_overlap EXCLUDE USING gist (booth_id WITH =, assignment_range WITH &&);

ALTER TABLE ONLY public.booth_assignments
    ADD CONSTRAINT booth_assignments_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.booth_rent_rates
    ADD CONSTRAINT booth_rent_rates_pkey PRIMARY KEY (booth_id);

ALTER TABLE ONLY public.booths
    ADD CONSTRAINT booths_code_key UNIQUE (code);

ALTER TABLE ONLY public.booths
    ADD CONSTRAINT booths_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.brands
    ADD CONSTRAINT brands_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.categories
    ADD CONSTRAINT categories_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.chart_roles
    ADD CONSTRAINT chart_roles_account_id_key UNIQUE (account_id);

ALTER TABLE ONLY public.chart_roles
    ADD CONSTRAINT chart_roles_pkey PRIMARY KEY (role);

ALTER TABLE ONLY public.chart_source_rules
    ADD CONSTRAINT chart_source_rules_pkey PRIMARY KEY (role, source_type);

ALTER TABLE ONLY public.colors
    ADD CONSTRAINT colors_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.conditions
    ADD CONSTRAINT conditions_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.customers
    ADD CONSTRAINT customers_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.departments
    ADD CONSTRAINT departments_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.export_accounts
    ADD CONSTRAINT export_accounts_pkey PRIMARY KEY (system, role);

ALTER TABLE ONLY public.item_buys
    ADD CONSTRAINT item_buys_item_id_key UNIQUE (item_id);

ALTER TABLE ONLY public.item_buys
    ADD CONSTRAINT item_buys_journal_id_key UNIQUE (journal_id);

ALTER TABLE ONLY public.item_buys
    ADD CONSTRAINT item_buys_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.item_dispositions
    ADD CONSTRAINT item_dispositions_item_id_key UNIQUE (item_id);

ALTER TABLE ONLY public.item_dispositions
    ADD CONSTRAINT item_dispositions_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.item_photos
    ADD CONSTRAINT item_photos_pkey PRIMARY KEY (item_id);

ALTER TABLE ONLY public.item_price_events
    ADD CONSTRAINT item_price_events_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.item_price_events
    ADD CONSTRAINT item_price_events_step_key UNIQUE (item_id, step_id);

ALTER TABLE ONLY public.item_returns
    ADD CONSTRAINT item_returns_pkey PRIMARY KEY (journal_id);

ALTER TABLE ONLY public.items
    ADD CONSTRAINT items_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.journal_cash_tendered
    ADD CONSTRAINT journal_cash_tendered_pkey PRIMARY KEY (journal_id);

ALTER TABLE ONLY public.journal_lines
    ADD CONSTRAINT journal_lines_journal_line_key UNIQUE (journal_id, line_no);

ALTER TABLE ONLY public.journal_lines
    ADD CONSTRAINT journal_lines_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.journals
    ADD CONSTRAINT journals_book_no_key UNIQUE (book_id, journal_no);

ALTER TABLE ONLY public.journals
    ADD CONSTRAINT journals_id_book_key UNIQUE (id, book_id);

ALTER TABLE ONLY public.journals
    ADD CONSTRAINT journals_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.ledger_books
    ADD CONSTRAINT ledger_books_code_key UNIQUE (code);

ALTER TABLE ONLY public.ledger_books
    ADD CONSTRAINT ledger_books_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.mall
    ADD CONSTRAINT mall_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.map_floors
    ADD CONSTRAINT map_floors_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.markdown_steps
    ADD CONSTRAINT markdown_steps_days_key UNIQUE (days_on_floor);

ALTER TABLE ONLY public.markdown_steps
    ADD CONSTRAINT markdown_steps_percent_key UNIQUE (percent);

ALTER TABLE ONLY public.markdown_steps
    ADD CONSTRAINT markdown_steps_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.offers
    ADD CONSTRAINT offers_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.payout_run_journals
    ADD CONSTRAINT payout_run_journals_pkey PRIMARY KEY (run_id, journal_id);

ALTER TABLE ONLY public.payout_run_lines
    ADD CONSTRAINT payout_run_lines_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.payout_run_lines
    ADD CONSTRAINT payout_run_lines_run_vendor_key UNIQUE (run_id, vendor_id);

ALTER TABLE ONLY public.payout_run_sales
    ADD CONSTRAINT payout_run_sales_pkey PRIMARY KEY (run_id, journal_id, line_no);

ALTER TABLE ONLY public.payout_runs
    ADD CONSTRAINT payout_runs_book_id_key UNIQUE (book_id, id);

ALTER TABLE ONLY public.payout_runs
    ADD CONSTRAINT payout_runs_book_no_key UNIQUE (book_id, run_no);

ALTER TABLE ONLY public.payout_runs
    ADD CONSTRAINT payout_runs_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.register_checks
    ADD CONSTRAINT register_checks_pkey PRIMARY KEY (journal_id);

ALTER TABLE ONLY public.register_session_posts
    ADD CONSTRAINT register_session_posts_pkey PRIMARY KEY (journal_id);

ALTER TABLE ONLY public.register_sessions
    ADD CONSTRAINT register_sessions_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.registers
    ADD CONSTRAINT registers_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.sale_lines
    ADD CONSTRAINT sale_lines_item_key UNIQUE (journal_id, item_id);

ALTER TABLE ONLY public.sale_lines
    ADD CONSTRAINT sale_lines_pkey PRIMARY KEY (journal_id, line_no);

ALTER TABLE ONLY public.schema_migrations
    ADD CONSTRAINT schema_migrations_pkey PRIMARY KEY (filename);

ALTER TABLE ONLY public.sizes
    ADD CONSTRAINT sizes_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.staff
    ADD CONSTRAINT staff_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.stored_values
    ADD CONSTRAINT stored_values_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.ticket_lines
    ADD CONSTRAINT ticket_lines_item_key UNIQUE (ticket_id, item_id);

ALTER TABLE ONLY public.ticket_lines
    ADD CONSTRAINT ticket_lines_pkey PRIMARY KEY (ticket_id, line_no);

ALTER TABLE ONLY public.ticket_tenders
    ADD CONSTRAINT ticket_tenders_pkey PRIMARY KEY (ticket_id, role);

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.vendors
    ADD CONSTRAINT vendors_code_key UNIQUE (code);

ALTER TABLE ONLY public.vendors
    ADD CONSTRAINT vendors_pkey PRIMARY KEY (id);

ALTER TABLE ONLY public.wait_list
    ADD CONSTRAINT wait_list_pkey PRIMARY KEY (id);

CREATE UNIQUE INDEX brands_name_key ON public.brands USING btree (lower(name));

CREATE UNIQUE INDEX categories_name_key ON public.categories USING btree (lower(name));

CREATE UNIQUE INDEX colors_name_key ON public.colors USING btree (lower(name));

CREATE UNIQUE INDEX conditions_name_key ON public.conditions USING btree (lower(name));

CREATE UNIQUE INDEX departments_name_key ON public.departments USING btree (lower(name));

CREATE UNIQUE INDEX items_barcode_key ON public.items USING btree (lower(barcode));

CREATE UNIQUE INDEX items_code_key ON public.items USING btree (lower(code));

CREATE INDEX journal_lines_account_subledger_idx ON public.journal_lines USING btree (account_id, subledger_type, subledger_ref);

CREATE INDEX journals_book_key_lookup ON public.journals USING btree (book_id, posting_key);

CREATE UNIQUE INDEX journals_one_reversal ON public.journals USING btree (reverses_journal_id) WHERE (reverses_journal_id IS NOT NULL);

CREATE INDEX journals_period_idx ON public.journals USING btree (book_id, period_id);

CREATE INDEX journals_posting_date_idx ON public.journals USING btree (book_id, posting_date);

CREATE INDEX journals_source_idx ON public.journals USING btree (source_type, source_reference);

CREATE UNIQUE INDEX ledger_books_one_default ON public.ledger_books USING btree (is_default) WHERE is_default;

CREATE UNIQUE INDEX map_floors_name_key ON public.map_floors USING btree (lower(name));

CREATE UNIQUE INDEX payout_runs_one_draft_window ON public.payout_runs USING btree (book_id, sales_from, sales_to, COALESCE(rent_month, '0001-01-01'::date)) WHERE ((status = 'draft'::text) AND (reverses_run_id IS NULL) AND (kind = 'batch'::text));

CREATE UNIQUE INDEX register_sessions_one_open ON public.register_sessions USING btree (register_id) WHERE (closed_at IS NULL);

CREATE UNIQUE INDEX registers_name_key ON public.registers USING btree (lower(name));

CREATE UNIQUE INDEX sizes_name_key ON public.sizes USING btree (lower(name));

CREATE UNIQUE INDEX staff_username_key ON public.staff USING btree (lower(username));

CREATE UNIQUE INDEX stored_values_number_key ON public.stored_values USING btree (kind, lower(number));

CREATE UNIQUE INDEX tickets_one_open ON public.tickets USING btree (session_id) WHERE (status = 'open'::text);

CREATE UNIQUE INDEX wait_list_open_email_key ON public.wait_list USING btree (lower(email)) WHERE (status = ANY (ARRAY['waiting'::text, 'contacted'::text]));

CREATE TRIGGER accounting_periods_before_closed BEFORE INSERT ON public.accounting_periods FOR EACH ROW EXECUTE FUNCTION public.stall_reject_period_before_closed();

CREATE TRIGGER accounting_periods_frozen BEFORE DELETE OR UPDATE ON public.accounting_periods FOR EACH ROW EXECUTE FUNCTION public.ledger_reject_period_change();

CREATE TRIGGER accounting_periods_no_truncate BEFORE TRUNCATE ON public.accounting_periods FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.accounting_periods ENABLE ALWAYS TRIGGER accounting_periods_no_truncate;

CREATE TRIGGER accounts_chart_frozen BEFORE UPDATE OF book_id, code, account_type, normal_balance, is_postable, is_control, subledger_type ON public.accounts FOR EACH ROW EXECUTE FUNCTION public.stall_reject_chart_account_change();

CREATE TRIGGER accounts_chart_no_delete BEFORE DELETE ON public.accounts FOR EACH ROW EXECUTE FUNCTION public.stall_reject_chart_account_delete();

ALTER TABLE public.accounts ENABLE ALWAYS TRIGGER accounts_chart_no_delete;

CREATE TRIGGER accounts_no_truncate BEFORE TRUNCATE ON public.accounts FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.accounts ENABLE ALWAYS TRIGGER accounts_no_truncate;

CREATE TRIGGER accounts_posted_frozen BEFORE UPDATE OF book_id, is_postable, is_control, subledger_type, account_type, normal_balance, code ON public.accounts FOR EACH ROW EXECUTE FUNCTION public.ledger_reject_account_change();

CREATE TRIGGER assignment_rent_frozen_after_charge BEFORE DELETE OR UPDATE ON public.assignment_rent FOR EACH ROW EXECUTE FUNCTION public.stall_reject_assignment_rent_rewrite();

CREATE TRIGGER assignment_rent_no_truncate BEFORE TRUNCATE ON public.assignment_rent FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.assignment_rent ENABLE ALWAYS TRIGGER assignment_rent_no_truncate;

CREATE TRIGGER booth_assignments_append_only BEFORE DELETE OR UPDATE ON public.booth_assignments FOR EACH ROW EXECUTE FUNCTION public.stall_reject_assignment_rewrite();

ALTER TABLE public.booth_assignments ENABLE ALWAYS TRIGGER booth_assignments_append_only;

CREATE TRIGGER booth_assignments_no_truncate BEFORE TRUNCATE ON public.booth_assignments FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.booth_assignments ENABLE ALWAYS TRIGGER booth_assignments_no_truncate;

CREATE TRIGGER booth_rent_rates_no_truncate BEFORE TRUNCATE ON public.booth_rent_rates FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.booth_rent_rates ENABLE ALWAYS TRIGGER booth_rent_rates_no_truncate;

CREATE TRIGGER booths_floor_id BEFORE UPDATE OF floor_id ON public.booths FOR EACH ROW EXECUTE FUNCTION public.stall_reject_booth_place();

ALTER TABLE public.booths ENABLE ALWAYS TRIGGER booths_floor_id;

CREATE TRIGGER booths_no_truncate BEFORE TRUNCATE ON public.booths FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.booths ENABLE ALWAYS TRIGGER booths_no_truncate;

CREATE TRIGGER booths_on_floor BEFORE INSERT OR UPDATE OF x_ft, z_ft, width_ft, depth_ft ON public.booths FOR EACH ROW EXECUTE FUNCTION public.stall_reject_booth_place();

ALTER TABLE public.booths ENABLE ALWAYS TRIGGER booths_on_floor;

CREATE TRIGGER booths_referenced_no_delete BEFORE DELETE ON public.booths FOR EACH ROW EXECUTE FUNCTION public.stall_reject_referenced_party_delete();

ALTER TABLE public.booths ENABLE ALWAYS TRIGGER booths_referenced_no_delete;

CREATE TRIGGER brands_no_truncate BEFORE TRUNCATE ON public.brands FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.brands ENABLE ALWAYS TRIGGER brands_no_truncate;

CREATE TRIGGER categories_no_truncate BEFORE TRUNCATE ON public.categories FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.categories ENABLE ALWAYS TRIGGER categories_no_truncate;

CREATE TRIGGER chart_roles_frozen BEFORE DELETE OR UPDATE ON public.chart_roles FOR EACH ROW EXECUTE FUNCTION public.stall_reject_chart_role_change();

CREATE TRIGGER chart_roles_no_truncate BEFORE TRUNCATE ON public.chart_roles FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.chart_roles ENABLE ALWAYS TRIGGER chart_roles_no_truncate;

CREATE TRIGGER chart_source_rules_no_truncate BEFORE TRUNCATE ON public.chart_source_rules FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.chart_source_rules ENABLE ALWAYS TRIGGER chart_source_rules_no_truncate;

CREATE TRIGGER colors_no_truncate BEFORE TRUNCATE ON public.colors FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.colors ENABLE ALWAYS TRIGGER colors_no_truncate;

CREATE TRIGGER conditions_no_truncate BEFORE TRUNCATE ON public.conditions FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.conditions ENABLE ALWAYS TRIGGER conditions_no_truncate;

CREATE TRIGGER customers_no_truncate BEFORE TRUNCATE ON public.customers FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.customers ENABLE ALWAYS TRIGGER customers_no_truncate;

CREATE TRIGGER departments_no_truncate BEFORE TRUNCATE ON public.departments FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.departments ENABLE ALWAYS TRIGGER departments_no_truncate;

CREATE TRIGGER item_buys_apply BEFORE INSERT ON public.item_buys FOR EACH ROW EXECUTE FUNCTION public.stall_apply_item_buy();

CREATE TRIGGER item_buys_no_truncate BEFORE TRUNCATE ON public.item_buys FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.item_buys ENABLE ALWAYS TRIGGER item_buys_no_truncate;

CREATE TRIGGER item_dispositions_apply BEFORE INSERT ON public.item_dispositions FOR EACH ROW EXECUTE FUNCTION public.stall_apply_disposition();

CREATE TRIGGER item_dispositions_no_truncate BEFORE TRUNCATE ON public.item_dispositions FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.item_dispositions ENABLE ALWAYS TRIGGER item_dispositions_no_truncate;

CREATE TRIGGER item_photos_no_truncate BEFORE TRUNCATE ON public.item_photos FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.item_photos ENABLE ALWAYS TRIGGER item_photos_no_truncate;

CREATE TRIGGER item_price_events_apply BEFORE INSERT ON public.item_price_events FOR EACH ROW EXECUTE FUNCTION public.stall_apply_markdown();

CREATE TRIGGER item_price_events_no_truncate BEFORE TRUNCATE ON public.item_price_events FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.item_price_events ENABLE ALWAYS TRIGGER item_price_events_no_truncate;

CREATE TRIGGER item_returns_no_delete BEFORE INSERT OR DELETE OR UPDATE ON public.item_returns FOR EACH ROW EXECUTE FUNCTION public.stall_assert_item_return();

ALTER TABLE public.item_returns ENABLE ALWAYS TRIGGER item_returns_no_delete;

CREATE TRIGGER item_returns_no_truncate BEFORE TRUNCATE ON public.item_returns FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.item_returns ENABLE ALWAYS TRIGGER item_returns_no_truncate;

CREATE TRIGGER items_disabled_attribute BEFORE INSERT OR UPDATE OF color_id, category_id, size_id, condition_id, department_id ON public.items FOR EACH ROW EXECUTE FUNCTION public.items_reject_disabled_attribute();

CREATE TRIGGER items_disabled_brand BEFORE INSERT OR UPDATE OF brand_id ON public.items FOR EACH ROW EXECUTE FUNCTION public.items_reject_disabled_brand();

CREATE TRIGGER items_no_truncate BEFORE TRUNCATE ON public.items FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.items ENABLE ALWAYS TRIGGER items_no_truncate;

CREATE TRIGGER items_placement BEFORE INSERT OR UPDATE OF placement, vendor_id, commission_bps, expires_on, cost_minor ON public.items FOR EACH ROW EXECUTE FUNCTION public.stall_guard_item_placement();

CREATE TRIGGER journal_lines_account BEFORE INSERT ON public.journal_lines FOR EACH ROW EXECUTE FUNCTION public.ledger_reject_line_account();

CREATE TRIGGER journal_lines_append_only BEFORE DELETE OR UPDATE ON public.journal_lines FOR EACH ROW EXECUTE FUNCTION public.ledger_reject_mutation();

ALTER TABLE public.journal_lines ENABLE ALWAYS TRIGGER journal_lines_append_only;

CREATE TRIGGER journal_lines_control_source BEFORE INSERT ON public.journal_lines FOR EACH ROW EXECUTE FUNCTION public.stall_reject_control_source();

CREATE TRIGGER journal_lines_no_truncate BEFORE TRUNCATE ON public.journal_lines FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.journal_lines ENABLE ALWAYS TRIGGER journal_lines_no_truncate;

CREATE TRIGGER journal_lines_seal BEFORE INSERT ON public.journal_lines FOR EACH ROW EXECUTE FUNCTION public.ledger_reject_line_after_post();

CREATE TRIGGER journal_lines_subledger_identity BEFORE INSERT ON public.journal_lines FOR EACH ROW EXECUTE FUNCTION public.stall_reject_unknown_subledger();

CREATE CONSTRAINT TRIGGER journals_adjustment AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.stall_assert_adjustment();

CREATE TRIGGER journals_append_only BEFORE DELETE OR UPDATE ON public.journals FOR EACH ROW EXECUTE FUNCTION public.ledger_reject_mutation();

ALTER TABLE public.journals ENABLE ALWAYS TRIGGER journals_append_only;

CREATE CONSTRAINT TRIGGER journals_balanced AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.ledger_assert_journal_balance();

CREATE CONSTRAINT TRIGGER journals_cash_drop_no_delete AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.stall_assert_cash_drop();

ALTER TABLE public.journals ENABLE ALWAYS TRIGGER journals_cash_drop_no_delete;

CREATE TRIGGER journals_closed_payout_window AFTER INSERT ON public.journals FOR EACH ROW EXECUTE FUNCTION public.stall_reject_closed_payout_window();

ALTER TABLE public.journals ENABLE ALWAYS TRIGGER journals_closed_payout_window;

CREATE TRIGGER journals_context BEFORE INSERT ON public.journals FOR EACH ROW EXECUTE FUNCTION public.ledger_reject_journal_context();

CREATE CONSTRAINT TRIGGER journals_disbursement AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.stall_assert_disbursement();

ALTER TABLE public.journals ENABLE ALWAYS TRIGGER journals_disbursement;

CREATE CONSTRAINT TRIGGER journals_item_return AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.stall_assert_item_return();

ALTER TABLE public.journals ENABLE ALWAYS TRIGGER journals_item_return;

CREATE CONSTRAINT TRIGGER journals_layaway_no_delete AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.stall_assert_layaway();

ALTER TABLE public.journals ENABLE ALWAYS TRIGGER journals_layaway_no_delete;

CREATE TRIGGER journals_live_key BEFORE INSERT ON public.journals FOR EACH ROW EXECUTE FUNCTION public.stall_reject_live_posting_key();

ALTER TABLE public.journals ENABLE ALWAYS TRIGGER journals_live_key;

CREATE TRIGGER journals_no_truncate BEFORE TRUNCATE ON public.journals FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.journals ENABLE ALWAYS TRIGGER journals_no_truncate;

CREATE CONSTRAINT TRIGGER journals_opening AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.stall_assert_opening();

CREATE TRIGGER journals_opening_key BEFORE INSERT ON public.journals FOR EACH ROW EXECUTE FUNCTION public.stall_reject_duplicate_opening();

ALTER TABLE public.journals ENABLE ALWAYS TRIGGER journals_opening_key;

CREATE CONSTRAINT TRIGGER journals_payout AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.stall_assert_payout();

CREATE CONSTRAINT TRIGGER journals_period_close AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.stall_assert_period_close();

CREATE CONSTRAINT TRIGGER journals_register_session AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.stall_assert_register_session();

CREATE CONSTRAINT TRIGGER journals_register_variance_no_delete AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.stall_assert_register_variance();

ALTER TABLE public.journals ENABLE ALWAYS TRIGGER journals_register_variance_no_delete;

CREATE CONSTRAINT TRIGGER journals_rent_charge AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.stall_assert_rent_charge();

CREATE CONSTRAINT TRIGGER journals_rent_payment AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.stall_assert_rent_payment();

CREATE TRIGGER journals_restore_on_hand AFTER INSERT ON public.journals FOR EACH ROW EXECUTE FUNCTION public.stall_restore_on_hand();

ALTER TABLE public.journals ENABLE ALWAYS TRIGGER journals_restore_on_hand;

CREATE CONSTRAINT TRIGGER journals_reversal_shape AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.ledger_assert_reversal();

CREATE CONSTRAINT TRIGGER journals_sale_shape AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.stall_assert_sale_shape();

CREATE CONSTRAINT TRIGGER journals_settlement AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.stall_assert_settlement();

ALTER TABLE public.journals ENABLE ALWAYS TRIGGER journals_settlement;

CREATE CONSTRAINT TRIGGER journals_store_purchase AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.stall_assert_store_purchase();

ALTER TABLE public.journals ENABLE ALWAYS TRIGGER journals_store_purchase;

CREATE CONSTRAINT TRIGGER journals_stored_value_issue AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.stall_assert_stored_value_issue();

CREATE CONSTRAINT TRIGGER journals_subledger_non_negative AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.stall_assert_subledger_non_negative();

CREATE CONSTRAINT TRIGGER journals_tax_payment AFTER INSERT ON public.journals DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.stall_assert_tax_payment();

CREATE TRIGGER ledger_books_currency_frozen BEFORE UPDATE OF currency, currency_scale ON public.ledger_books FOR EACH ROW EXECUTE FUNCTION public.ledger_reject_book_currency();

CREATE TRIGGER ledger_books_journal_counter AFTER INSERT ON public.ledger_books FOR EACH ROW EXECUTE FUNCTION public.ledger_seed_journal_counter();

INSERT INTO public.ledger_books (id, code, name, currency, currency_scale, is_default, created_at) VALUES ('01900000-0000-7000-8000-000000000001', 'mall', 'Mall', 'USD', 2, true, '2026-10-21 00:42:50.440076+00');

CREATE TRIGGER ledger_books_no_truncate BEFORE TRUNCATE ON public.ledger_books FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.ledger_books ENABLE ALWAYS TRIGGER ledger_books_no_truncate;

CREATE TRIGGER ledger_books_one_book BEFORE INSERT ON public.ledger_books FOR EACH ROW EXECUTE FUNCTION public.stall_reject_second_book();

CREATE TRIGGER mall_floor BEFORE UPDATE OF booth_width_ft, booth_depth_ft, floor_width_ft, floor_depth_ft ON public.mall FOR EACH ROW EXECUTE FUNCTION public.stall_reject_floor_change();

ALTER TABLE public.mall ENABLE ALWAYS TRIGGER mall_floor;

CREATE TRIGGER mall_no_delete BEFORE DELETE ON public.mall FOR EACH ROW EXECUTE FUNCTION public.mall_reject_delete();

ALTER TABLE public.mall ENABLE ALWAYS TRIGGER mall_no_delete;

CREATE TRIGGER mall_no_truncate BEFORE TRUNCATE ON public.mall FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.mall ENABLE ALWAYS TRIGGER mall_no_truncate;

CREATE TRIGGER mall_rent_policy BEFORE UPDATE OF rent_cycle_day, booth_rentals_enabled ON public.mall FOR EACH ROW EXECUTE FUNCTION public.stall_reject_rent_policy();

CREATE TRIGGER mall_shop_capabilities BEFORE INSERT OR UPDATE OF floor_days, consignment_enabled, booth_rentals_enabled, store_stock_enabled ON public.mall FOR EACH ROW EXECUTE FUNCTION public.stall_guard_shop_capabilities();

CREATE TRIGGER mall_stock_on_hand BEFORE UPDATE OF consignment_enabled, store_stock_enabled ON public.mall FOR EACH ROW EXECUTE FUNCTION public.stall_guard_capabilities_stock();

CREATE TRIGGER mall_zone BEFORE INSERT OR UPDATE OF time_zone ON public.mall FOR EACH ROW EXECUTE FUNCTION public.mall_reject_zone();

CREATE TRIGGER map_floors_limit BEFORE INSERT ON public.map_floors FOR EACH ROW EXECUTE FUNCTION public.stall_reject_map_floor_insert();

ALTER TABLE public.map_floors ENABLE ALWAYS TRIGGER map_floors_limit;

CREATE TRIGGER map_floors_no_delete BEFORE DELETE ON public.map_floors FOR EACH ROW EXECUTE FUNCTION public.stall_reject_map_floor_delete();

ALTER TABLE public.map_floors ENABLE ALWAYS TRIGGER map_floors_no_delete;

CREATE TRIGGER markdown_steps_no_truncate BEFORE TRUNCATE ON public.markdown_steps FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.markdown_steps ENABLE ALWAYS TRIGGER markdown_steps_no_truncate;

CREATE TRIGGER markdown_steps_order BEFORE INSERT OR UPDATE ON public.markdown_steps FOR EACH ROW EXECUTE FUNCTION public.stall_guard_markdown_step();

CREATE TRIGGER offers_guard BEFORE INSERT OR DELETE OR UPDATE ON public.offers FOR EACH ROW EXECUTE FUNCTION public.stall_assert_offer();

ALTER TABLE public.offers ENABLE ALWAYS TRIGGER offers_guard;

CREATE TRIGGER offers_no_truncate BEFORE TRUNCATE ON public.offers FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.offers ENABLE ALWAYS TRIGGER offers_no_truncate;

CREATE TRIGGER payout_run_journals_append_only BEFORE DELETE OR UPDATE ON public.payout_run_journals FOR EACH ROW EXECUTE FUNCTION public.stall_reject_run_journal_change();

ALTER TABLE public.payout_run_journals ENABLE ALWAYS TRIGGER payout_run_journals_append_only;

CREATE TRIGGER payout_run_journals_no_truncate BEFORE TRUNCATE ON public.payout_run_journals FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.payout_run_journals ENABLE ALWAYS TRIGGER payout_run_journals_no_truncate;

CREATE TRIGGER payout_run_lines_frozen BEFORE DELETE OR UPDATE ON public.payout_run_lines FOR EACH ROW EXECUTE FUNCTION public.stall_reject_posted_run_line();

ALTER TABLE public.payout_run_lines ENABLE ALWAYS TRIGGER payout_run_lines_frozen;

CREATE TRIGGER payout_run_lines_no_truncate BEFORE TRUNCATE ON public.payout_run_lines FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.payout_run_lines ENABLE ALWAYS TRIGGER payout_run_lines_no_truncate;

CREATE TRIGGER payout_run_sales_frozen BEFORE DELETE OR UPDATE ON public.payout_run_sales FOR EACH ROW EXECUTE FUNCTION public.stall_reject_posted_run_sale();

ALTER TABLE public.payout_run_sales ENABLE ALWAYS TRIGGER payout_run_sales_frozen;

CREATE TRIGGER payout_run_sales_no_truncate BEFORE TRUNCATE ON public.payout_run_sales FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.payout_run_sales ENABLE ALWAYS TRIGGER payout_run_sales_no_truncate;

CREATE TRIGGER payout_runs_assign_no BEFORE INSERT ON public.payout_runs FOR EACH ROW EXECUTE FUNCTION public.stall_assign_payout_run_no();

ALTER TABLE public.payout_runs ENABLE ALWAYS TRIGGER payout_runs_assign_no;

CREATE TRIGGER payout_runs_no_truncate BEFORE TRUNCATE ON public.payout_runs FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.payout_runs ENABLE ALWAYS TRIGGER payout_runs_no_truncate;

CREATE TRIGGER payout_runs_rent_period BEFORE INSERT OR UPDATE OF rent_month ON public.payout_runs FOR EACH ROW EXECUTE FUNCTION public.stall_reject_payout_rent_month();

CREATE TRIGGER payout_runs_status BEFORE DELETE OR UPDATE ON public.payout_runs FOR EACH ROW EXECUTE FUNCTION public.stall_reject_run_status();

ALTER TABLE public.payout_runs ENABLE ALWAYS TRIGGER payout_runs_status;

CREATE TRIGGER register_checks_append_only BEFORE UPDATE ON public.register_checks FOR EACH ROW EXECUTE FUNCTION public.stall_reject_register_check();

ALTER TABLE public.register_checks ENABLE ALWAYS TRIGGER register_checks_append_only;

CREATE TRIGGER register_checks_disbursement_number BEFORE INSERT ON public.register_checks FOR EACH ROW EXECUTE FUNCTION public.stall_reject_duplicate_disbursement_check();

ALTER TABLE public.register_checks ENABLE ALWAYS TRIGGER register_checks_disbursement_number;

CREATE TRIGGER register_checks_no_delete BEFORE DELETE ON public.register_checks FOR EACH ROW EXECUTE FUNCTION public.stall_reject_register_check();

ALTER TABLE public.register_checks ENABLE ALWAYS TRIGGER register_checks_no_delete;

CREATE TRIGGER register_checks_no_truncate BEFORE TRUNCATE ON public.register_checks FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.register_checks ENABLE ALWAYS TRIGGER register_checks_no_truncate;

CREATE TRIGGER register_session_posts_append_only BEFORE INSERT OR UPDATE ON public.register_session_posts FOR EACH ROW EXECUTE FUNCTION public.stall_reject_session_post();

ALTER TABLE public.register_session_posts ENABLE ALWAYS TRIGGER register_session_posts_append_only;

CREATE TRIGGER register_session_posts_no_delete BEFORE DELETE ON public.register_session_posts FOR EACH ROW EXECUTE FUNCTION public.stall_reject_session_post();

ALTER TABLE public.register_session_posts ENABLE ALWAYS TRIGGER register_session_posts_no_delete;

CREATE TRIGGER register_session_posts_no_truncate BEFORE TRUNCATE ON public.register_session_posts FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.register_session_posts ENABLE ALWAYS TRIGGER register_session_posts_no_truncate;

CREATE TRIGGER register_sessions_append_only BEFORE INSERT OR UPDATE ON public.register_sessions FOR EACH ROW EXECUTE FUNCTION public.stall_reject_session_rewrite();

ALTER TABLE public.register_sessions ENABLE ALWAYS TRIGGER register_sessions_append_only;

CREATE TRIGGER register_sessions_close_no_delete BEFORE UPDATE ON public.register_sessions FOR EACH ROW EXECUTE FUNCTION public.stall_assert_register_close();

ALTER TABLE public.register_sessions ENABLE ALWAYS TRIGGER register_sessions_close_no_delete;

CREATE TRIGGER register_sessions_no_delete BEFORE DELETE ON public.register_sessions FOR EACH ROW EXECUTE FUNCTION public.stall_reject_session_rewrite();

ALTER TABLE public.register_sessions ENABLE ALWAYS TRIGGER register_sessions_no_delete;

CREATE TRIGGER register_sessions_no_truncate BEFORE TRUNCATE ON public.register_sessions FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.register_sessions ENABLE ALWAYS TRIGGER register_sessions_no_truncate;

CREATE TRIGGER register_sessions_open_ticket_no_delete BEFORE UPDATE ON public.register_sessions FOR EACH ROW EXECUTE FUNCTION public.stall_reject_close_with_open_ticket();

ALTER TABLE public.register_sessions ENABLE ALWAYS TRIGGER register_sessions_open_ticket_no_delete;

CREATE TRIGGER registers_last_no_delete BEFORE DELETE ON public.registers FOR EACH ROW EXECUTE FUNCTION public.stall_reject_last_register();

ALTER TABLE public.registers ENABLE ALWAYS TRIGGER registers_last_no_delete;

CREATE TRIGGER registers_no_truncate BEFORE TRUNCATE ON public.registers FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.registers ENABLE ALWAYS TRIGGER registers_no_truncate;

CREATE TRIGGER sale_lines_append_only BEFORE INSERT OR DELETE OR UPDATE ON public.sale_lines FOR EACH ROW EXECUTE FUNCTION public.stall_assert_sale_shape();

ALTER TABLE public.sale_lines ENABLE ALWAYS TRIGGER sale_lines_append_only;

CREATE TRIGGER sale_lines_no_truncate BEFORE TRUNCATE ON public.sale_lines FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.sale_lines ENABLE ALWAYS TRIGGER sale_lines_no_truncate;

CREATE CONSTRAINT TRIGGER sale_lines_open_ticket_no_delete AFTER INSERT ON public.sale_lines DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION public.stall_assert_sale_not_on_ticket();

ALTER TABLE public.sale_lines ENABLE ALWAYS TRIGGER sale_lines_open_ticket_no_delete;

CREATE TRIGGER sizes_no_truncate BEFORE TRUNCATE ON public.sizes FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.sizes ENABLE ALWAYS TRIGGER sizes_no_truncate;

CREATE TRIGGER staff_no_truncate BEFORE TRUNCATE ON public.staff FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.staff ENABLE ALWAYS TRIGGER staff_no_truncate;

CREATE TRIGGER stored_values_append_only BEFORE UPDATE ON public.stored_values FOR EACH ROW EXECUTE FUNCTION public.stall_reject_stored_value_change();

ALTER TABLE public.stored_values ENABLE ALWAYS TRIGGER stored_values_append_only;

CREATE TRIGGER stored_values_no_delete BEFORE DELETE ON public.stored_values FOR EACH ROW EXECUTE FUNCTION public.stall_reject_stored_value_change();

ALTER TABLE public.stored_values ENABLE ALWAYS TRIGGER stored_values_no_delete;

CREATE TRIGGER stored_values_no_truncate BEFORE TRUNCATE ON public.stored_values FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.stored_values ENABLE ALWAYS TRIGGER stored_values_no_truncate;

CREATE TRIGGER ticket_lines_no_delete BEFORE INSERT OR DELETE OR UPDATE ON public.ticket_lines FOR EACH ROW EXECUTE FUNCTION public.stall_assert_ticket_line();

ALTER TABLE public.ticket_lines ENABLE ALWAYS TRIGGER ticket_lines_no_delete;

CREATE TRIGGER ticket_lines_no_truncate BEFORE TRUNCATE ON public.ticket_lines FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.ticket_lines ENABLE ALWAYS TRIGGER ticket_lines_no_truncate;

CREATE TRIGGER tickets_no_delete BEFORE INSERT OR DELETE OR UPDATE ON public.tickets FOR EACH ROW EXECUTE FUNCTION public.stall_assert_ticket();

ALTER TABLE public.tickets ENABLE ALWAYS TRIGGER tickets_no_delete;

CREATE TRIGGER tickets_no_truncate BEFORE TRUNCATE ON public.tickets FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.tickets ENABLE ALWAYS TRIGGER tickets_no_truncate;

CREATE TRIGGER vendors_no_truncate BEFORE TRUNCATE ON public.vendors FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.vendors ENABLE ALWAYS TRIGGER vendors_no_truncate;

CREATE TRIGGER vendors_open_rent BEFORE UPDATE OF active ON public.vendors FOR EACH ROW EXECUTE FUNCTION public.stall_reject_inactive_with_open_rent();

ALTER TABLE public.vendors ENABLE ALWAYS TRIGGER vendors_open_rent;

CREATE TRIGGER vendors_referenced_no_delete BEFORE DELETE ON public.vendors FOR EACH ROW EXECUTE FUNCTION public.stall_reject_referenced_party_delete();

ALTER TABLE public.vendors ENABLE ALWAYS TRIGGER vendors_referenced_no_delete;

CREATE TRIGGER wait_list_no_truncate BEFORE TRUNCATE ON public.wait_list FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.wait_list ENABLE ALWAYS TRIGGER wait_list_no_truncate;

ALTER TABLE ONLY public.accounting_periods
    ADD CONSTRAINT accounting_periods_book_id_fkey FOREIGN KEY (book_id) REFERENCES public.ledger_books(id);

ALTER TABLE ONLY public.accounts
    ADD CONSTRAINT accounts_book_id_fkey FOREIGN KEY (book_id) REFERENCES public.ledger_books(id);

ALTER TABLE ONLY public.assignment_rent
    ADD CONSTRAINT assignment_rent_assignment_id_fkey FOREIGN KEY (assignment_id) REFERENCES public.booth_assignments(id);

ALTER TABLE ONLY public.book_journal_counters
    ADD CONSTRAINT book_journal_counters_book_id_fkey FOREIGN KEY (book_id) REFERENCES public.ledger_books(id);

ALTER TABLE ONLY public.book_payout_run_counters
    ADD CONSTRAINT book_payout_run_counters_book_id_fkey FOREIGN KEY (book_id) REFERENCES public.ledger_books(id);

ALTER TABLE ONLY public.booth_assignments
    ADD CONSTRAINT booth_assignments_booth_id_fkey FOREIGN KEY (booth_id) REFERENCES public.booths(id);

ALTER TABLE ONLY public.booth_assignments
    ADD CONSTRAINT booth_assignments_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendors(id);

ALTER TABLE ONLY public.booth_rent_rates
    ADD CONSTRAINT booth_rent_rates_booth_id_fkey FOREIGN KEY (booth_id) REFERENCES public.booths(id);

ALTER TABLE ONLY public.booths
    ADD CONSTRAINT booths_floor_id_fkey FOREIGN KEY (floor_id) REFERENCES public.map_floors(id);

ALTER TABLE ONLY public.chart_roles
    ADD CONSTRAINT chart_roles_account_id_fkey FOREIGN KEY (account_id) REFERENCES public.accounts(id);

ALTER TABLE ONLY public.chart_source_rules
    ADD CONSTRAINT chart_source_rules_role_fkey FOREIGN KEY (role) REFERENCES public.chart_roles(role);

ALTER TABLE ONLY public.export_accounts
    ADD CONSTRAINT export_accounts_role_fkey FOREIGN KEY (role) REFERENCES public.chart_roles(role);

ALTER TABLE ONLY public.item_buys
    ADD CONSTRAINT item_buys_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.items(id);

ALTER TABLE ONLY public.item_buys
    ADD CONSTRAINT item_buys_journal_id_fkey FOREIGN KEY (journal_id) REFERENCES public.journals(id);

ALTER TABLE ONLY public.item_buys
    ADD CONSTRAINT item_buys_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendors(id);

ALTER TABLE ONLY public.item_dispositions
    ADD CONSTRAINT item_dispositions_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.items(id);

ALTER TABLE ONLY public.item_photos
    ADD CONSTRAINT item_photos_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.items(id) ON DELETE CASCADE;

ALTER TABLE ONLY public.item_price_events
    ADD CONSTRAINT item_price_events_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.items(id);

ALTER TABLE ONLY public.item_price_events
    ADD CONSTRAINT item_price_events_step_id_fkey FOREIGN KEY (step_id) REFERENCES public.markdown_steps(id);

ALTER TABLE ONLY public.item_returns
    ADD CONSTRAINT item_returns_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.items(id);

ALTER TABLE ONLY public.item_returns
    ADD CONSTRAINT item_returns_journal_id_fkey FOREIGN KEY (journal_id) REFERENCES public.journals(id);

ALTER TABLE ONLY public.item_returns
    ADD CONSTRAINT item_returns_sale_journal_id_fkey FOREIGN KEY (sale_journal_id) REFERENCES public.journals(id);

ALTER TABLE ONLY public.items
    ADD CONSTRAINT items_brand_id_fkey FOREIGN KEY (brand_id) REFERENCES public.brands(id);

ALTER TABLE ONLY public.items
    ADD CONSTRAINT items_category_id_fkey FOREIGN KEY (category_id) REFERENCES public.categories(id);

ALTER TABLE ONLY public.items
    ADD CONSTRAINT items_color_id_fkey FOREIGN KEY (color_id) REFERENCES public.colors(id);

ALTER TABLE ONLY public.items
    ADD CONSTRAINT items_condition_id_fkey FOREIGN KEY (condition_id) REFERENCES public.conditions(id);

ALTER TABLE ONLY public.items
    ADD CONSTRAINT items_department_id_fkey FOREIGN KEY (department_id) REFERENCES public.departments(id);

ALTER TABLE ONLY public.items
    ADD CONSTRAINT items_size_id_fkey FOREIGN KEY (size_id) REFERENCES public.sizes(id);

ALTER TABLE ONLY public.items
    ADD CONSTRAINT items_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendors(id);

ALTER TABLE ONLY public.journal_cash_tendered
    ADD CONSTRAINT journal_cash_tendered_journal_id_fkey FOREIGN KEY (journal_id) REFERENCES public.journals(id);

ALTER TABLE ONLY public.journal_lines
    ADD CONSTRAINT journal_lines_account_book_fkey FOREIGN KEY (account_id, book_id) REFERENCES public.accounts(id, book_id);

ALTER TABLE ONLY public.journal_lines
    ADD CONSTRAINT journal_lines_journal_book_fkey FOREIGN KEY (journal_id, book_id) REFERENCES public.journals(id, book_id);

ALTER TABLE ONLY public.journals
    ADD CONSTRAINT journals_book_id_fkey FOREIGN KEY (book_id) REFERENCES public.ledger_books(id);

ALTER TABLE ONLY public.journals
    ADD CONSTRAINT journals_period_book_fkey FOREIGN KEY (book_id, period_id) REFERENCES public.accounting_periods(book_id, id);

ALTER TABLE ONLY public.journals
    ADD CONSTRAINT journals_reverses_journal_id_fkey FOREIGN KEY (reverses_journal_id) REFERENCES public.journals(id);

ALTER TABLE ONLY public.payout_run_journals
    ADD CONSTRAINT payout_run_journals_journal_id_fkey FOREIGN KEY (journal_id) REFERENCES public.journals(id);

ALTER TABLE ONLY public.payout_run_journals
    ADD CONSTRAINT payout_run_journals_run_id_fkey FOREIGN KEY (run_id) REFERENCES public.payout_runs(id);

ALTER TABLE ONLY public.payout_run_lines
    ADD CONSTRAINT payout_run_lines_disbursement_journal_id_fkey FOREIGN KEY (disbursement_journal_id) REFERENCES public.journals(id);

ALTER TABLE ONLY public.payout_run_lines
    ADD CONSTRAINT payout_run_lines_run_id_fkey FOREIGN KEY (run_id) REFERENCES public.payout_runs(id);

ALTER TABLE ONLY public.payout_run_lines
    ADD CONSTRAINT payout_run_lines_settlement_journal_id_fkey FOREIGN KEY (settlement_journal_id) REFERENCES public.journals(id);

ALTER TABLE ONLY public.payout_run_lines
    ADD CONSTRAINT payout_run_lines_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendors(id);

ALTER TABLE ONLY public.payout_run_sales
    ADD CONSTRAINT payout_run_sales_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.items(id);

ALTER TABLE ONLY public.payout_run_sales
    ADD CONSTRAINT payout_run_sales_journal_id_fkey FOREIGN KEY (journal_id) REFERENCES public.journals(id);

ALTER TABLE ONLY public.payout_run_sales
    ADD CONSTRAINT payout_run_sales_run_id_fkey FOREIGN KEY (run_id) REFERENCES public.payout_runs(id);

ALTER TABLE ONLY public.payout_run_sales
    ADD CONSTRAINT payout_run_sales_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendors(id);

ALTER TABLE ONLY public.payout_runs
    ADD CONSTRAINT payout_runs_book_id_fkey FOREIGN KEY (book_id) REFERENCES public.ledger_books(id);

ALTER TABLE ONLY public.payout_runs
    ADD CONSTRAINT payout_runs_reverses_run_id_fkey FOREIGN KEY (reverses_run_id) REFERENCES public.payout_runs(id);

ALTER TABLE ONLY public.register_checks
    ADD CONSTRAINT register_checks_journal_id_fkey FOREIGN KEY (journal_id) REFERENCES public.journals(id);

ALTER TABLE ONLY public.register_session_posts
    ADD CONSTRAINT register_session_posts_journal_id_fkey FOREIGN KEY (journal_id) REFERENCES public.journals(id);

ALTER TABLE ONLY public.register_session_posts
    ADD CONSTRAINT register_session_posts_session_id_fkey FOREIGN KEY (session_id) REFERENCES public.register_sessions(id);

ALTER TABLE ONLY public.register_sessions
    ADD CONSTRAINT register_sessions_register_id_fkey FOREIGN KEY (register_id) REFERENCES public.registers(id);

ALTER TABLE ONLY public.sale_lines
    ADD CONSTRAINT sale_lines_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.items(id);

ALTER TABLE ONLY public.sale_lines
    ADD CONSTRAINT sale_lines_journal_id_fkey FOREIGN KEY (journal_id) REFERENCES public.journals(id);

ALTER TABLE ONLY public.sale_lines
    ADD CONSTRAINT sale_lines_offer_id_fkey FOREIGN KEY (offer_id) REFERENCES public.offers(id);

ALTER TABLE ONLY public.staff
    ADD CONSTRAINT staff_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendors(id);

ALTER TABLE ONLY public.ticket_lines
    ADD CONSTRAINT ticket_lines_item_id_fkey FOREIGN KEY (item_id) REFERENCES public.items(id);

ALTER TABLE ONLY public.ticket_lines
    ADD CONSTRAINT ticket_lines_offer_id_fkey FOREIGN KEY (offer_id) REFERENCES public.offers(id);

ALTER TABLE ONLY public.ticket_lines
    ADD CONSTRAINT ticket_lines_ticket_id_fkey FOREIGN KEY (ticket_id) REFERENCES public.tickets(id);

ALTER TABLE ONLY public.ticket_tenders
    ADD CONSTRAINT ticket_tenders_ticket_id_fkey FOREIGN KEY (ticket_id) REFERENCES public.tickets(id) ON DELETE CASCADE;

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_buyer_id_fkey FOREIGN KEY (buyer_id) REFERENCES public.vendors(id);

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_customer_id_fkey FOREIGN KEY (customer_id) REFERENCES public.customers(id);

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_posted_journal_id_fkey FOREIGN KEY (posted_journal_id) REFERENCES public.journals(id);

ALTER TABLE ONLY public.tickets
    ADD CONSTRAINT tickets_session_id_fkey FOREIGN KEY (session_id) REFERENCES public.register_sessions(id);

GRANT USAGE ON SCHEMA public TO stall_poster;

REVOKE ALL ON FUNCTION public.stall_close_quiet_period(closed_period uuid) FROM PUBLIC;

GRANT ALL ON FUNCTION public.stall_close_quiet_period(closed_period uuid) TO stall_poster;

REVOKE ALL ON FUNCTION public.stall_csv_field(value text) FROM PUBLIC;

GRANT ALL ON FUNCTION public.stall_csv_field(value text) TO stall_poster;

REVOKE ALL ON FUNCTION public.stall_export_journal_csv(system text, from_date date, to_date date, tax_rate text) FROM PUBLIC;

GRANT ALL ON FUNCTION public.stall_export_journal_csv(system text, from_date date, to_date date, tax_rate text) TO stall_poster;

GRANT ALL ON FUNCTION public.stall_pack_runs() TO stall_poster;

GRANT ALL ON FUNCTION public.stall_pack_runs() TO stall_backup;

GRANT ALL ON FUNCTION public.stall_rent_charge_suffix(period_start date) TO stall_poster;

GRANT ALL ON FUNCTION public.stall_rent_charge_suffix(period_start date) TO stall_backup;

GRANT ALL ON FUNCTION public.stall_rent_period_end(period_start date) TO stall_poster;

GRANT ALL ON FUNCTION public.stall_rent_period_end(period_start date) TO stall_backup;

GRANT ALL ON FUNCTION public.stall_rent_period_start(on_date date) TO stall_poster;

GRANT ALL ON FUNCTION public.stall_rent_period_start(on_date date) TO stall_backup;

GRANT SELECT,INSERT,UPDATE ON TABLE public.assignment_rent TO stall_poster;

GRANT SELECT,INSERT,UPDATE ON TABLE public.booth_assignments TO stall_poster;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.booths TO stall_poster;

GRANT SELECT ON TABLE public.chart_roles TO stall_poster;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.items TO stall_poster;

GRANT SELECT,INSERT ON TABLE public.journal_lines TO stall_poster;

GRANT SELECT,INSERT ON TABLE public.journals TO stall_poster;

GRANT SELECT,UPDATE ON TABLE public.mall TO stall_poster;

GRANT SELECT,INSERT,UPDATE ON TABLE public.vendors TO stall_poster;

GRANT SELECT ON TABLE public.payout_missing_rent TO stall_poster;

GRANT SELECT ON TABLE public.payout_missing_rent TO stall_backup;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.payout_run_journals TO stall_poster;

GRANT SELECT ON TABLE public.payout_run_journals TO stall_backup;

GRANT SELECT,INSERT ON TABLE public.sale_lines TO stall_poster;

GRANT SELECT ON TABLE public.sale_lines TO stall_backup;

GRANT SELECT ON TABLE public.payout_pack_sales TO stall_poster;

GRANT SELECT ON TABLE public.payout_pack_sales TO stall_backup;

GRANT SELECT ON TABLE public.payout_pack TO stall_poster;

GRANT SELECT ON TABLE public.payout_pack TO stall_backup;

GRANT ALL ON FUNCTION public.stall_payout_pack(target_run uuid, window_from date, window_to date, window_rent date, window_posting date) TO stall_poster;

GRANT ALL ON FUNCTION public.stall_payout_pack(target_run uuid, window_from date, window_to date, window_rent date, window_posting date) TO stall_backup;

GRANT ALL ON FUNCTION public.stall_rent_cycle_day() TO stall_poster;

GRANT ALL ON FUNCTION public.stall_rent_cycle_day() TO stall_backup;

GRANT SELECT ON TABLE public.accounts TO stall_poster;

GRANT SELECT ON TABLE public.account_balances TO stall_poster;

GRANT SELECT,INSERT ON TABLE public.accounting_periods TO stall_poster;

GRANT SELECT ON TABLE public.book_journal_counters TO stall_poster;

GRANT SELECT,UPDATE ON TABLE public.book_payout_run_counters TO stall_poster;

GRANT SELECT ON TABLE public.book_payout_run_counters TO stall_backup;

GRANT SELECT,INSERT,UPDATE ON TABLE public.booth_rent_rates TO stall_poster;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.brands TO stall_poster;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.categories TO stall_poster;

GRANT SELECT ON TABLE public.chart_source_rules TO stall_poster;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.colors TO stall_poster;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.conditions TO stall_poster;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.customers TO stall_poster;

GRANT SELECT ON TABLE public.customers TO stall_backup;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.departments TO stall_poster;

GRANT SELECT ON TABLE public.export_accounts TO stall_poster;

GRANT SELECT ON TABLE public.export_lines TO stall_poster;

GRANT SELECT,INSERT ON TABLE public.item_buys TO stall_poster;

GRANT SELECT,INSERT ON TABLE public.item_dispositions TO stall_poster;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.item_photos TO stall_poster;

GRANT SELECT ON TABLE public.item_photos TO stall_backup;

GRANT SELECT,INSERT ON TABLE public.item_price_events TO stall_poster;

GRANT SELECT,INSERT ON TABLE public.item_returns TO stall_poster;

GRANT SELECT ON TABLE public.item_returns TO stall_backup;

GRANT SELECT,INSERT ON TABLE public.journal_cash_tendered TO stall_poster;

GRANT SELECT ON TABLE public.journal_cash_tendered TO stall_backup;

GRANT SELECT ON TABLE public.ledger_books TO stall_poster;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.map_floors TO stall_poster;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.markdown_steps TO stall_poster;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.offers TO stall_poster;

GRANT SELECT ON TABLE public.offers TO stall_backup;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.payout_run_lines TO stall_poster;

GRANT SELECT ON TABLE public.payout_run_lines TO stall_backup;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.payout_run_sales TO stall_poster;

GRANT SELECT ON TABLE public.payout_run_sales TO stall_backup;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.payout_runs TO stall_poster;

GRANT SELECT ON TABLE public.payout_runs TO stall_backup;

GRANT SELECT,INSERT ON TABLE public.register_checks TO stall_poster;

GRANT SELECT ON TABLE public.register_checks TO stall_backup;

GRANT SELECT,INSERT ON TABLE public.register_session_posts TO stall_poster;

GRANT SELECT ON TABLE public.register_session_posts TO stall_backup;

GRANT SELECT,INSERT,UPDATE ON TABLE public.register_sessions TO stall_poster;

GRANT SELECT ON TABLE public.register_sessions TO stall_backup;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.registers TO stall_poster;

GRANT SELECT ON TABLE public.registers TO stall_backup;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.sizes TO stall_poster;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.staff TO stall_poster;

GRANT SELECT ON TABLE public.staff TO stall_app;

GRANT SELECT ON TABLE public.staff TO stall_backup;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.stored_values TO stall_poster;

GRANT SELECT ON TABLE public.stored_values TO stall_backup;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.ticket_lines TO stall_poster;

GRANT SELECT ON TABLE public.ticket_lines TO stall_backup;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.ticket_tenders TO stall_poster;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.tickets TO stall_poster;

GRANT SELECT ON TABLE public.tickets TO stall_backup;

GRANT SELECT ON TABLE public.vendor_statement TO stall_poster;

GRANT SELECT,INSERT,DELETE,UPDATE ON TABLE public.wait_list TO stall_poster;

GRANT SELECT ON TABLE public.wait_list TO stall_backup;

INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-000000000010', '01900000-0000-7000-8000-000000000001', '1000', 'Cash', 'asset', 'debit', true, false, NULL, '2026-10-21 00:42:50.443136+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-000000000011', '01900000-0000-7000-8000-000000000001', '1010', 'Bank', 'asset', 'debit', true, false, NULL, '2026-10-21 00:42:50.443673+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-000000000012', '01900000-0000-7000-8000-000000000001', '4000', 'Commission revenue', 'revenue', 'credit', true, false, NULL, '2026-10-21 00:42:50.443696+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-000000000013', '01900000-0000-7000-8000-000000000001', '2100', 'Sales tax payable', 'liability', 'credit', true, false, NULL, '2026-10-21 00:42:50.443701+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-000000000014', '01900000-0000-7000-8000-000000000001', '2000', 'Vendor payable', 'liability', 'credit', true, true, 'vendor', '2026-10-21 00:42:50.443707+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-000000000015', '01900000-0000-7000-8000-000000000001', '1200', 'Booth rent receivable', 'asset', 'debit', true, true, 'booth', '2026-10-21 00:42:50.443713+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-000000000016', '01900000-0000-7000-8000-000000000001', '1020', 'Card', 'asset', 'debit', true, false, NULL, '2026-10-21 00:42:50.521647+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-000000000017', '01900000-0000-7000-8000-000000000001', '4100', 'Rent revenue', 'revenue', 'credit', true, false, NULL, '2026-10-21 00:42:50.538444+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-000000000018', '01900000-0000-7000-8000-000000000001', '6000', 'Operating expense', 'expense', 'debit', true, false, NULL, '2026-10-21 00:42:50.610894+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-000000000019', '01900000-0000-7000-8000-000000000001', '3000', 'Retained earnings', 'equity', 'credit', true, false, NULL, '2026-10-21 00:42:50.686443+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-00000000001c', '01900000-0000-7000-8000-000000000001', '2200', 'Gift certificates', 'liability', 'credit', true, true, 'gift_certificate', '2026-10-21 00:42:50.925665+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-00000000001f', '01900000-0000-7000-8000-000000000001', '6150', 'Cash over/short', 'expense', 'debit', true, false, NULL, '2026-10-21 00:42:50.926074+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-00000000001e', '01900000-0000-7000-8000-000000000001', '2220', 'Store credit', 'liability', 'credit', true, true, 'store_credit', '2026-10-21 00:42:50.926087+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-00000000001b', '01900000-0000-7000-8000-000000000001', '1100', 'Checks', 'asset', 'debit', true, false, NULL, '2026-10-21 00:42:50.926101+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-00000000001a', '01900000-0000-7000-8000-000000000001', '1015', 'Cash in safe', 'asset', 'debit', true, false, NULL, '2026-10-21 00:42:50.926109+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-00000000001d', '01900000-0000-7000-8000-000000000001', '2210', 'Gift cards', 'liability', 'credit', true, true, 'gift_card', '2026-10-21 00:42:50.926116+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-000000000020', '01900000-0000-7000-8000-000000000001', '6160', 'Cash rounding', 'expense', 'debit', true, false, NULL, '2026-10-21 00:42:51.066886+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-000000000021', '01900000-0000-7000-8000-000000000001', '2230', 'Layaway deposits', 'liability', 'credit', true, true, 'ticket', '2026-10-21 00:42:51.140466+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-000000000022', '01900000-0000-7000-8000-000000000001', '6100', 'Store purchases', 'expense', 'debit', true, false, NULL, '2026-10-21 00:42:51.168172+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-000000000023', '01900000-0000-7000-8000-000000000001', '4110', 'Late fee income', 'revenue', 'credit', true, false, NULL, '2026-10-21 00:42:51.443162+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-000000000024', '01900000-0000-7000-8000-000000000001', '6200', 'Promotion expense', 'expense', 'debit', true, false, NULL, '2026-10-21 00:42:51.504616+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-000000000027', '01900000-0000-7000-8000-000000000001', '5000', 'Cost of goods', 'expense', 'debit', true, false, NULL, '2026-10-21 00:42:51.784291+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-000000000026', '01900000-0000-7000-8000-000000000001', '1300', 'Inventory', 'asset', 'debit', true, false, NULL, '2026-10-21 00:42:51.784366+00');
INSERT INTO public.accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type, created_at) VALUES ('01900000-0000-7000-8000-000000000025', '01900000-0000-7000-8000-000000000001', '4120', 'Store sales', 'revenue', 'credit', true, false, NULL, '2026-10-21 00:42:51.784373+00');
INSERT INTO public.book_payout_run_counters (book_id, last_no) VALUES ('01900000-0000-7000-8000-000000000001', 0);
INSERT INTO public.chart_roles (role, account_id) VALUES ('cash', '01900000-0000-7000-8000-000000000010');
INSERT INTO public.chart_roles (role, account_id) VALUES ('bank', '01900000-0000-7000-8000-000000000011');
INSERT INTO public.chart_roles (role, account_id) VALUES ('commission_revenue', '01900000-0000-7000-8000-000000000012');
INSERT INTO public.chart_roles (role, account_id) VALUES ('sales_tax_payable', '01900000-0000-7000-8000-000000000013');
INSERT INTO public.chart_roles (role, account_id) VALUES ('vendor_payable', '01900000-0000-7000-8000-000000000014');
INSERT INTO public.chart_roles (role, account_id) VALUES ('booth_rent_receivable', '01900000-0000-7000-8000-000000000015');
INSERT INTO public.chart_roles (role, account_id) VALUES ('card', '01900000-0000-7000-8000-000000000016');
INSERT INTO public.chart_roles (role, account_id) VALUES ('rent_revenue', '01900000-0000-7000-8000-000000000017');
INSERT INTO public.chart_roles (role, account_id) VALUES ('operating_expense', '01900000-0000-7000-8000-000000000018');
INSERT INTO public.chart_roles (role, account_id) VALUES ('retained_earnings', '01900000-0000-7000-8000-000000000019');
INSERT INTO public.chart_roles (role, account_id) VALUES ('checks', '01900000-0000-7000-8000-00000000001b');
INSERT INTO public.chart_roles (role, account_id) VALUES ('gift_certificate', '01900000-0000-7000-8000-00000000001c');
INSERT INTO public.chart_roles (role, account_id) VALUES ('store_credit', '01900000-0000-7000-8000-00000000001e');
INSERT INTO public.chart_roles (role, account_id) VALUES ('cash_over_short', '01900000-0000-7000-8000-00000000001f');
INSERT INTO public.chart_roles (role, account_id) VALUES ('gift_card', '01900000-0000-7000-8000-00000000001d');
INSERT INTO public.chart_roles (role, account_id) VALUES ('cash_in_safe', '01900000-0000-7000-8000-00000000001a');
INSERT INTO public.chart_roles (role, account_id) VALUES ('cash_rounding', '01900000-0000-7000-8000-000000000020');
INSERT INTO public.chart_roles (role, account_id) VALUES ('layaway_deposit', '01900000-0000-7000-8000-000000000021');
INSERT INTO public.chart_roles (role, account_id) VALUES ('store_purchases', '01900000-0000-7000-8000-000000000022');
INSERT INTO public.chart_roles (role, account_id) VALUES ('late_fee_income', '01900000-0000-7000-8000-000000000023');
INSERT INTO public.chart_roles (role, account_id) VALUES ('promotion_expense', '01900000-0000-7000-8000-000000000024');
INSERT INTO public.chart_roles (role, account_id) VALUES ('store_sales', '01900000-0000-7000-8000-000000000025');
INSERT INTO public.chart_roles (role, account_id) VALUES ('inventory', '01900000-0000-7000-8000-000000000026');
INSERT INTO public.chart_roles (role, account_id) VALUES ('cost_of_goods', '01900000-0000-7000-8000-000000000027');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('sales_tax_payable', 'sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('vendor_payable', 'rent_charge');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('bank', 'sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('booth_rent_receivable', 'sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('commission_revenue', 'rent_payment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cash', 'rent_charge');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('booth_rent_receivable', 'rent_payment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('bank', 'tax_payment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('commission_revenue', 'sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cash', 'adjustment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('sales_tax_payable', 'tax_payment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('bank', 'rent_payment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('sales_tax_payable', 'rent_payment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('sales_tax_payable', 'rent_charge');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('bank', 'rent_charge');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('vendor_payable', 'sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cash', 'tax_payment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('sales_tax_payable', 'adjustment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('bank', 'adjustment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('booth_rent_receivable', 'rent_charge');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cash', 'rent_payment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('commission_revenue', 'rent_charge');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('commission_revenue', 'adjustment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cash', 'sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('vendor_payable', 'rent_payment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('card', 'sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('card', 'rent_charge');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('card', 'rent_payment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('card', 'adjustment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('rent_revenue', 'sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('rent_revenue', 'rent_charge');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('rent_revenue', 'rent_payment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('rent_revenue', 'adjustment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('operating_expense', 'sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('operating_expense', 'rent_charge');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('operating_expense', 'rent_payment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('operating_expense', 'adjustment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('commission_revenue', 'period_close');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('rent_revenue', 'period_close');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('operating_expense', 'period_close');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('retained_earnings', 'period_close');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('gift_card', 'gift_card_issue');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cash', 'gift_card_issue');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('gift_certificate', 'gift_certificate_issue');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('checks', 'sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cash', 'gift_certificate_issue');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('card', 'gift_certificate_issue');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cash', 'cash_drop');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cash_in_safe', 'cash_drop');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('checks', 'store_credit_issue');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('card', 'gift_card_issue');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cash', 'store_credit_issue');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cash_over_short', 'register_variance');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('store_credit', 'sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('gift_certificate', 'sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('checks', 'gift_card_issue');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('card', 'store_credit_issue');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('checks', 'gift_certificate_issue');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('store_credit', 'store_credit_issue');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cash', 'register_variance');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('gift_card', 'sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cash_rounding', 'sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('checks', 'item_return');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('commission_revenue', 'item_return');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('vendor_payable', 'item_return');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('gift_certificate', 'item_return');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cash', 'item_return');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('sales_tax_payable', 'item_return');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('store_credit', 'item_return');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('card', 'item_return');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('gift_card', 'item_return');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('card', 'layaway_deposit');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cash', 'layaway_refund');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cash', 'layaway_deposit');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('card', 'layaway_refund');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('layaway_deposit', 'sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('layaway_deposit', 'layaway_refund');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('checks', 'layaway_deposit');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('checks', 'layaway_refund');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('layaway_deposit', 'layaway_deposit');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('vendor_payable', 'safe_disbursement');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('vendor_payable', 'cash_disbursement');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('bank', 'check_disbursement');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cash', 'cash_disbursement');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('store_purchases', 'store_purchase');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cash_in_safe', 'safe_disbursement');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('vendor_payable', 'settlement');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('vendor_payable', 'store_purchase');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('vendor_payable', 'check_disbursement');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('booth_rent_receivable', 'settlement');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('checks', 'rent_payment');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('booth_rent_receivable', 'late_fee');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('late_fee_income', 'late_fee');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('vendor_payable', 'booth_correction');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('promotion_expense', 'sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('promotion_expense', 'item_return');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cost_of_goods', 'sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('inventory', 'sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('store_sales', 'sale');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('inventory', 'item_return');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('store_sales', 'item_return');
INSERT INTO public.chart_source_rules (role, source_type) VALUES ('cost_of_goods', 'item_return');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'vendor_payable', '2000');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'cash', '1000');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'bank', '1010');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'booth_rent_receivable', '1200');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'sales_tax_payable', '2100');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'operating_expense', '6000');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'commission_revenue', '4000');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'card', '1020');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'rent_revenue', '4100');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'vendor_payable', '2000');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'cash', '1000');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'bank', '1010');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'booth_rent_receivable', '1200');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'sales_tax_payable', '2100');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'operating_expense', '6000');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'commission_revenue', '4000');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'card', '1020');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'rent_revenue', '4100');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'retained_earnings', '3000');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'retained_earnings', '3000');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'gift_certificate', '2200');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'checks', '1100');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'store_credit', '2220');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'cash_over_short', '6150');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'gift_card', '2210');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'cash_in_safe', '1015');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'gift_certificate', '2200');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'checks', '1100');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'store_credit', '2220');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'cash_over_short', '6150');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'gift_card', '2210');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'cash_in_safe', '1015');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'cash_rounding', '6160');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'cash_rounding', '6160');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'layaway_deposit', '2230');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'layaway_deposit', '2230');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'store_purchases', '6100');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'store_purchases', '6100');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'late_fee_income', '4110');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'late_fee_income', '4110');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'promotion_expense', '6200');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'promotion_expense', '6200');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'inventory', '1300');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'store_sales', '4120');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'cost_of_goods', '5000');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'store_sales', '4120');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('quickbooks', 'inventory', '1300');
INSERT INTO public.export_accounts (system, role, external_code) VALUES ('xero', 'cost_of_goods', '5000');
INSERT INTO public.mall (id, time_zone, booth_width_ft, booth_depth_ft, floor_width_ft, floor_depth_ft, commission_bps, floor_days, tax_bps, cash_round_nickel, operating_mode, payout_cadence, rent_prepaid_from_prior_sales, use_color, use_category, use_size, use_condition, use_department, label_stock, use_brand, booth_rentals_enabled, rent_cycle_day, street, street2, city, state, postal, phone, email, late_fees_enabled, late_fee_grace_days, late_fee_first_minor, late_fee_next_minor, receipt_footer, consignment_enabled, store_stock_enabled, receipt_mail_via, gmail_address, gmail_app_password) VALUES (true, 'America/Los_Angeles', 10, 10, 120, 90, 0, NULL, 0, false, 'both', 'monthly', false, true, true, true, true, true, 'avery_8160', true, true, 1, NULL, NULL, NULL, NULL, NULL, NULL, NULL, false, 0, 0, 0, 'Thank you', true, false, 'server', NULL, NULL);
INSERT INTO public.map_floors (id, name, sort_order) VALUES ('01a15be9-2e20-73a5-85ed-43ebc7a867f3', 'Main Floor', 0);
INSERT INTO public.registers (id, name, created_at) VALUES ('01900000-0000-7000-8000-000000000d10', 'Register', '2026-10-21 00:42:50.890252+00');
