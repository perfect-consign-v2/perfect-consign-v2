-- A return is its own journal. It gives one piece back and leaves the rest of the sale sold.
-- Apply after 033. The provisioner rewrites stall_poster.
-- sql/029_register_posts.sql accepts piece_return on the session post. This file replaces that function for a mall that already ran 029.

SET event_triggers = false;

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

SET event_triggers = true;

CREATE TABLE IF NOT EXISTS piece_returns (
    journal_id uuid PRIMARY KEY REFERENCES journals (id),
    sale_journal_id uuid NOT NULL REFERENCES journals (id),
    item_id uuid NOT NULL REFERENCES items (id),
    price_minor bigint NOT NULL,
    commission_minor bigint NOT NULL,
    tax_minor bigint NOT NULL,
    CONSTRAINT piece_returns_sale_item_key UNIQUE (sale_journal_id, item_id),
    CONSTRAINT piece_returns_price_chk CHECK (price_minor > 0),
    CONSTRAINT piece_returns_commission_chk CHECK (commission_minor >= 0 AND commission_minor <= price_minor),
    CONSTRAINT piece_returns_tax_chk CHECK (tax_minor >= 0)
);

GRANT SELECT, INSERT ON piece_returns TO stall_poster;
REVOKE UPDATE, DELETE, TRUNCATE, TRIGGER ON piece_returns FROM stall_poster;
GRANT SELECT ON piece_returns TO stall_backup;

INSERT INTO chart_source_rules (role, source_type)
SELECT seed.role, 'piece_return'
FROM (
    VALUES
        ('cash'),
        ('card'),
        ('checks'),
        ('gift_certificate'),
        ('gift_card'),
        ('store_credit'),
        ('commission_revenue'),
        ('sales_tax_payable'),
        ('vendor_payable')
) AS seed(role)
WHERE NOT EXISTS (
    SELECT 1
    FROM chart_source_rules AS existing
    WHERE existing.role = seed.role
      AND existing.source_type = 'piece_return'
);

CREATE OR REPLACE FUNCTION stall_assert_piece_return()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    sale_price bigint;
    sale_commission bigint;
    sale_tax bigint;
    tender_credit bigint;
    tender_lines integer;
    commission_debit bigint;
    tax_debit bigint;
    payable_debit bigint;
BEGIN
    IF TG_TABLE_NAME = 'piece_returns' THEN
        IF TG_OP <> 'INSERT' THEN
            RAISE EXCEPTION 'a return cannot be changed'
                USING ERRCODE = '23514';
        END IF;
        IF NOT EXISTS (
            SELECT 1
            FROM public.journals AS journal
            WHERE journal.id = NEW.journal_id
              AND journal.source_type = 'piece_return'
              AND journal.is_reversal = false
        ) THEN
            RAISE EXCEPTION 'a return belongs on a return'
                USING ERRCODE = '23514';
        END IF;
        SELECT sale.price_minor, sale.commission_minor, sale.tax_minor
        INTO sale_price, sale_commission, sale_tax
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
        IF sale_price IS NULL
            OR NEW.price_minor <> sale_price
            OR NEW.commission_minor <> sale_commission
            OR NEW.tax_minor <> sale_tax THEN
            RAISE EXCEPTION 'a return reverses that piece'
                USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
    END IF;

    IF NEW.source_type <> 'piece_return' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM public.piece_returns WHERE journal_id = NEW.id
    ) THEN
        RAISE EXCEPTION 'a return needs a piece'
            USING ERRCODE = '23514';
    END IF;

    SELECT price_minor, commission_minor, tax_minor
    INTO sale_price, sale_commission, sale_tax
    FROM public.piece_returns
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

    IF tender_lines <> 1 OR tender_credit <> sale_price + sale_tax
        OR commission_debit <> sale_commission
        OR tax_debit <> sale_tax
        OR payable_debit <> sale_price - sale_commission THEN
        RAISE EXCEPTION 'a return reverses that piece'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = 'piece_returns'::regclass AND tgname = 'piece_returns_no_delete' AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER piece_returns_no_delete
            BEFORE INSERT OR UPDATE OR DELETE ON piece_returns
            FOR EACH ROW
            EXECUTE FUNCTION stall_assert_piece_return();
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = 'piece_returns'::regclass AND tgname = 'piece_returns_no_truncate' AND NOT tgisinternal
    ) THEN
        CREATE TRIGGER piece_returns_no_truncate
            BEFORE TRUNCATE ON piece_returns
            FOR EACH STATEMENT
            EXECUTE FUNCTION ledger_reject_truncate();
    END IF;
END $$;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgrelid = 'journals'::regclass AND tgname = 'journals_piece_return' AND NOT tgisinternal
    ) THEN
        CREATE CONSTRAINT TRIGGER journals_piece_return
            AFTER INSERT ON journals
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION stall_assert_piece_return();
    END IF;
END $$;

ALTER TABLE piece_returns
    ENABLE ALWAYS TRIGGER piece_returns_no_delete,
    ENABLE ALWAYS TRIGGER piece_returns_no_truncate;

ALTER TABLE journals ENABLE ALWAYS TRIGGER journals_piece_return;

CREATE OR REPLACE FUNCTION stall_assert_register_session()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    checks_count integer;
    check_row integer;
BEGIN
    IF NEW.source_type IN (
        'sale',
        'piece_return',
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

    IF NEW.source_type IN ('sale', 'piece_return') AND NOT NEW.is_reversal THEN
        SELECT count(*) INTO checks_count
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role = 'checks'
          AND (
              (NEW.source_type = 'sale' AND line.debit_minor > 0)
              OR (NEW.source_type = 'piece_return' AND line.credit_minor > 0)
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
