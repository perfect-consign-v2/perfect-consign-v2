-- A return is its own journal. It gives one piece back and leaves the rest of the sale sold.
-- Apply after 033. The provisioner rewrites stall_poster.
-- sql/029_register_posts.sql accepts piece_return on the session post. This file replaces that function for a mall that already ran 029.





CREATE TABLE IF NOT EXISTS piece_returns (
    journal_id uuid PRIMARY KEY REFERENCES journals (id),
    sale_journal_id uuid NOT NULL REFERENCES journals (id),
    item_id uuid NOT NULL REFERENCES items (id),
    price_minor bigint NOT NULL,
    commission_minor bigint NOT NULL,
    tax_minor bigint NOT NULL,
    discount_minor bigint NOT NULL DEFAULT 0,
    quantity integer NOT NULL DEFAULT 1,
    CONSTRAINT piece_returns_price_chk CHECK (price_minor > 0),
    CONSTRAINT piece_returns_commission_chk CHECK (
        commission_minor >= 0 AND commission_minor <= price_minor
    ),
    CONSTRAINT piece_returns_tax_chk CHECK (tax_minor >= 0),
    CONSTRAINT piece_returns_discount_chk CHECK (
        discount_minor >= 0 AND discount_minor <= price_minor
    ),
    CONSTRAINT piece_returns_quantity_chk CHECK (quantity > 0)
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
    sale_discount bigint;
    sale_quantity integer;
    prior_quantity integer;
    prior_price bigint;
    prior_commission bigint;
    prior_tax bigint;
    prior_discount bigint;
    expected_price bigint;
    expected_commission bigint;
    expected_tax bigint;
    expected_discount bigint;
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
        SELECT sale.price_minor, sale.commission_minor, sale.tax_minor, sale.discount_minor, sale.quantity
        INTO sale_price, sale_commission, sale_tax, sale_discount, sale_quantity
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
               COALESCE(sum(returned.discount_minor), 0)
        INTO prior_quantity, prior_price, prior_commission, prior_tax, prior_discount
        FROM public.piece_returns AS returned
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
        ELSE
            expected_price := (sale_price * sale_quantity * NEW.quantity) / sale_quantity;
            expected_commission := (sale_commission * NEW.quantity) / sale_quantity;
            expected_tax := (sale_tax * NEW.quantity) / sale_quantity;
            expected_discount := (sale_discount * NEW.quantity) / sale_quantity;
        END IF;
        IF NEW.price_minor <> expected_price
            OR NEW.commission_minor <> expected_commission
            OR NEW.tax_minor <> expected_tax
            OR NEW.discount_minor <> expected_discount THEN
            RAISE EXCEPTION 'a return reverses that piece'
                USING ERRCODE = '23514';
        END IF;

        UPDATE public.items
        SET quantity = quantity + NEW.quantity
        WHERE id = NEW.item_id;

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

    SELECT price_minor, commission_minor, tax_minor, discount_minor
    INTO sale_price, sale_commission, sale_tax, sale_discount
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

    IF tender_lines <> 1
        OR tender_credit <> sale_price - sale_discount + sale_tax
        OR commission_debit <> sale_commission
        OR tax_debit <> sale_tax
        OR payable_debit <> sale_price - sale_discount - sale_commission THEN
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


