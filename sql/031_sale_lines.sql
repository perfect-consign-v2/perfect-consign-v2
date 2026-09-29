-- A sale is one or more pieces. Tax is on top of the sold price.
-- Commission is the store take of that price. Apply after 030. The provisioner rewrites stall_poster.

ALTER TABLE mall ADD COLUMN IF NOT EXISTS tax_bps integer NOT NULL DEFAULT 0;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'mall_tax_bps_chk'
          AND conrelid = 'mall'::regclass
    ) THEN
        ALTER TABLE mall DROP CONSTRAINT mall_tax_bps_chk;
        UPDATE mall SET tax_bps = tax_bps * 10;
    END IF;
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'mall_tax_millis_chk'
          AND conrelid = 'mall'::regclass
    ) THEN
        ALTER TABLE mall ADD CONSTRAINT mall_tax_millis_chk CHECK (tax_bps >= 0 AND tax_bps <= 100000);
    END IF;
END $$;

CREATE TABLE IF NOT EXISTS sale_lines (
    journal_id uuid NOT NULL REFERENCES journals (id),
    line_no integer NOT NULL,
    item_id uuid NOT NULL REFERENCES items (id),
    price_minor bigint NOT NULL,
    commission_minor bigint NOT NULL,
    tax_minor bigint NOT NULL,
    CONSTRAINT sale_lines_pkey PRIMARY KEY (journal_id, line_no),
    CONSTRAINT sale_lines_item_key UNIQUE (journal_id, item_id),
    CONSTRAINT sale_lines_no_chk CHECK (line_no > 0),
    CONSTRAINT sale_lines_price_chk CHECK (price_minor > 0),
    CONSTRAINT sale_lines_commission_chk CHECK (commission_minor >= 0 AND commission_minor <= price_minor),
    CONSTRAINT sale_lines_tax_chk CHECK (tax_minor >= 0)
);

GRANT SELECT, INSERT ON sale_lines TO stall_poster;
REVOKE UPDATE, DELETE, TRUNCATE, TRIGGER ON sale_lines FROM stall_poster;
GRANT SELECT ON sale_lines TO stall_backup;

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

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'sale_lines'::regclass
          AND tgname = 'sale_lines_append_only'
          AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER sale_lines_append_only
            BEFORE INSERT OR UPDATE OR DELETE ON sale_lines
            FOR EACH ROW
            EXECUTE FUNCTION stall_assert_sale_shape();
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_trigger
        WHERE tgrelid = 'sale_lines'::regclass
          AND tgname = 'sale_lines_no_truncate'
          AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER sale_lines_no_truncate
            BEFORE TRUNCATE ON sale_lines
            FOR EACH STATEMENT
            EXECUTE FUNCTION ledger_reject_truncate();
    END IF;
END $$;

ALTER TABLE sale_lines
    ENABLE ALWAYS TRIGGER sale_lines_append_only,
    ENABLE ALWAYS TRIGGER sale_lines_no_truncate;
