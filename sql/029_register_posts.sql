-- A drawer journal names one open register session.
-- Apply after 028. The sale shape matches sql/004_sale.sql. The provisioner rewrites stall_poster.

CREATE TABLE register_session_posts (
    journal_id uuid PRIMARY KEY REFERENCES journals (id),
    session_id uuid NOT NULL REFERENCES register_sessions (id)
);

CREATE TABLE register_checks (
    journal_id uuid PRIMARY KEY REFERENCES journals (id),
    check_number text NOT NULL,
    CONSTRAINT register_checks_number_chk CHECK (
        check_number = btrim(check_number)
        AND check_number <> ''
        AND char_length(check_number) <= 40
    )
);

GRANT SELECT, INSERT ON register_session_posts, register_checks TO stall_poster;
REVOKE UPDATE, DELETE, TRUNCATE, TRIGGER ON register_session_posts, register_checks FROM stall_poster;
GRANT SELECT ON register_session_posts, register_checks TO stall_backup;

CREATE OR REPLACE FUNCTION stall_assert_sale_shape()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    sale_source text;
    sale_reversal boolean;
    tax_exempt boolean;
    commission_bps integer;
    tax_bps integer;
    expected_commission bigint;
    expected_tax bigint;
    tender_lines integer;
    tender_roles integer;
    tender_sum bigint;
    prices bigint;
    commissions bigint;
    taxes bigint;
    commission_lines integer;
    commission_credit bigint;
    tax_lines integer;
    tax_credit bigint;
    payable_gaps integer;
    foreign_lines integer;
    round_debit bigint;
    round_credit bigint;
    round_lines integer;
    cash_debit bigint;
    exact_cash bigint;
    round_delta integer;
    round_cash boolean;
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

        SELECT vendor.tax_exempt,
               COALESCE(item.commission_bps, vendor.commission_bps, mall.commission_bps),
               mall.tax_bps
        INTO tax_exempt, commission_bps, tax_bps
        FROM public.items AS item
        JOIN public.vendors AS vendor ON vendor.id = item.vendor_id
        CROSS JOIN public.mall AS mall
        WHERE item.id = NEW.item_id
        FOR UPDATE OF item;

        IF tax_exempt IS NULL THEN
            RAISE EXCEPTION 'a sale line needs a piece'
                USING ERRCODE = '23514';
        END IF;

        expected_commission := (NEW.price_minor * commission_bps + 5000) / 10000;
        IF tax_exempt THEN
            expected_tax := 0;
        ELSE
            expected_tax := (NEW.price_minor * tax_bps + 5000) / 10000;
        END IF;

        IF NEW.commission_minor IS DISTINCT FROM expected_commission THEN
            RAISE EXCEPTION 'a sale commission does not match the store take'
                USING ERRCODE = '23514';
        END IF;

        IF NEW.tax_minor IS DISTINCT FROM expected_tax THEN
            RAISE EXCEPTION 'a sale tax does not match the mall rate'
                USING ERRCODE = '23514';
        END IF;

        IF EXISTS (
            SELECT 1
            FROM public.sale_lines AS other
            JOIN public.journals AS journal ON journal.id = other.journal_id
            WHERE other.item_id = NEW.item_id
              AND other.journal_id IS DISTINCT FROM NEW.journal_id
              AND journal.source_type = 'sale'
              AND journal.is_reversal = false
              AND NOT EXISTS (
                  SELECT 1
                  FROM public.journals AS reversal
                  WHERE reversal.reverses_journal_id = journal.id
                    AND reversal.is_reversal = true
              )
              AND NOT EXISTS (
                  SELECT 1
                  FROM public.piece_returns AS returned
                  JOIN public.journals AS return_journal ON return_journal.id = returned.journal_id
                  WHERE returned.sale_journal_id = journal.id
                    AND returned.item_id = NEW.item_id
                    AND return_journal.is_reversal = false
                    AND NOT EXISTS (
                        SELECT 1
                        FROM public.journals AS reversal
                        WHERE reversal.reverses_journal_id = return_journal.id
                          AND reversal.is_reversal = true
                    )
              )
        ) THEN
            RAISE EXCEPTION 'a piece is already sold'
                USING ERRCODE = '23514';
        END IF;

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
      AND role.role IN ('cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit');

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
        RAISE EXCEPTION 'a sale needs a piece'
            USING ERRCODE = '23514';
    END IF;

    SELECT COALESCE(sum(price_minor), 0),
           COALESCE(sum(commission_minor), 0),
           COALESCE(sum(tax_minor), 0)
    INTO prices, commissions, taxes
    FROM public.sale_lines
    WHERE journal_id = NEW.id;

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

    exact_cash := prices + taxes - (tender_sum - cash_debit);
    round_delta := CASE exact_cash % 5
        WHEN 1 THEN -1
        WHEN 2 THEN -2
        WHEN 3 THEN 2
        WHEN 4 THEN 1
        ELSE 0
    END;

    IF cash_debit = 0 OR round_cash IS DISTINCT FROM true THEN
        IF round_lines <> 0 OR tender_sum <> prices + taxes THEN
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

    IF tender_sum + round_debit <> NEW.debit_total_minor
        OR tender_sum <> prices + taxes + round_credit - round_debit THEN
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

    IF commissions = 0 THEN
        IF commission_lines <> 0 THEN
            RAISE EXCEPTION 'a sale commission does not match the store take'
                USING ERRCODE = '23514';
        END IF;
    ELSIF commission_lines <> 1 OR commission_credit <> commissions THEN
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
        SELECT item.vendor_id, sum(sale.price_minor - sale.commission_minor) AS owed
        FROM public.sale_lines AS sale
        JOIN public.items AS item ON item.id = sale.item_id
        WHERE sale.journal_id = NEW.id
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

    SELECT count(*) INTO foreign_lines
    FROM public.journal_lines AS line
    WHERE line.journal_id = NEW.id
      AND NOT EXISTS (
          SELECT 1
          FROM public.chart_roles AS role
          WHERE role.account_id = line.account_id
            AND (
                (role.role IN ('cash', 'card', 'checks', 'gift_certificate', 'gift_card', 'store_credit') AND line.debit_minor > 0)
                OR (role.role = 'commission_revenue' AND line.credit_minor > 0)
                OR (role.role = 'sales_tax_payable' AND line.credit_minor > 0)
                OR (role.role = 'vendor_payable' AND line.credit_minor > 0)
                OR (role.role = 'cash_rounding' AND (line.debit_minor > 0 OR line.credit_minor > 0))
            )
      );

    IF foreign_lines > 0 THEN
        RAISE EXCEPTION 'a sale only credits commission, sales tax, and vendor payable'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE OR REPLACE FUNCTION stall_reject_session_post()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
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
        'piece_return',
        'rent_payment',
        'payout',
        'gift_certificate_issue',
        'gift_card_issue',
        'store_credit_issue',
        'cash_drop',
        'register_variance'
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

CREATE TRIGGER register_session_posts_append_only
    BEFORE INSERT OR UPDATE ON register_session_posts
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_session_post();

CREATE TRIGGER register_session_posts_no_delete
    BEFORE DELETE ON register_session_posts
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_session_post();

CREATE TRIGGER register_session_posts_no_truncate
    BEFORE TRUNCATE ON register_session_posts
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE register_session_posts
    ENABLE ALWAYS TRIGGER register_session_posts_append_only,
    ENABLE ALWAYS TRIGGER register_session_posts_no_delete,
    ENABLE ALWAYS TRIGGER register_session_posts_no_truncate;

CREATE OR REPLACE FUNCTION stall_reject_register_check()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
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

CREATE TRIGGER register_checks_append_only
    BEFORE UPDATE ON register_checks
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_register_check();

CREATE TRIGGER register_checks_no_delete
    BEFORE DELETE ON register_checks
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_register_check();

CREATE TRIGGER register_checks_no_truncate
    BEFORE TRUNCATE ON register_checks
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE register_checks
    ENABLE ALWAYS TRIGGER register_checks_append_only,
    ENABLE ALWAYS TRIGGER register_checks_no_delete,
    ENABLE ALWAYS TRIGGER register_checks_no_truncate;

CREATE OR REPLACE FUNCTION stall_assert_register_session()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    checks_debit integer;
    check_row integer;
BEGIN
    IF NEW.source_type IN (
        'sale',
        'rent_payment',
        'payout',
        'gift_certificate_issue',
        'gift_card_issue',
        'store_credit_issue',
        'cash_drop',
        'register_variance'
    ) AND NOT EXISTS (
        SELECT 1
        FROM public.register_session_posts
        WHERE journal_id = NEW.id
    ) THEN
        RAISE EXCEPTION 'a drawer journal needs an open register'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.source_type = 'sale' AND NOT NEW.is_reversal THEN
        SELECT count(*) INTO checks_debit
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role = 'checks'
          AND line.debit_minor > 0;

        SELECT count(*) INTO check_row
        FROM public.register_checks
        WHERE journal_id = NEW.id;

        IF checks_debit <> check_row THEN
            RAISE EXCEPTION 'a check sale needs a check number'
                USING ERRCODE = '23514';
        END IF;
    END IF;

    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER journals_register_session
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_register_session();
