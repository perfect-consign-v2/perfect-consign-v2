-- A sale is one or more items. Tax is on top of the sold price.
-- Commission is the store take of that price. Apply after 030. The provisioner rewrites stall_poster.

CREATE TABLE IF NOT EXISTS sale_lines (
    journal_id uuid NOT NULL REFERENCES journals (id),
    line_no integer NOT NULL,
    item_id uuid NOT NULL REFERENCES items (id),
    price_minor bigint NOT NULL,
    commission_minor bigint NOT NULL,
    tax_minor bigint NOT NULL,
    discount_minor bigint NOT NULL DEFAULT 0,
    quantity integer NOT NULL DEFAULT 1,
    CONSTRAINT sale_lines_pkey PRIMARY KEY (journal_id, line_no),
    CONSTRAINT sale_lines_item_key UNIQUE (journal_id, item_id),
    CONSTRAINT sale_lines_no_chk CHECK (line_no > 0),
    CONSTRAINT sale_lines_price_chk CHECK (price_minor > 0),
    CONSTRAINT sale_lines_commission_chk CHECK (
        commission_minor >= 0 AND commission_minor <= price_minor * quantity
    ),
    CONSTRAINT sale_lines_tax_chk CHECK (tax_minor >= 0),
    CONSTRAINT sale_lines_discount_chk CHECK (
        discount_minor >= 0 AND discount_minor <= price_minor * quantity
    ),
    CONSTRAINT sale_lines_quantity_chk CHECK (quantity > 0)
);

GRANT SELECT, INSERT ON sale_lines TO stall_poster;
REVOKE UPDATE, DELETE, TRUNCATE, TRIGGER ON sale_lines FROM stall_poster;
GRANT SELECT ON sale_lines TO stall_backup;



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
    ENABLE ALWAYS TRIGGER sale_lines_no_truncate;
