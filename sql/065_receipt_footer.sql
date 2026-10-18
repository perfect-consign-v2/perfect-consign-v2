-- The line printed at the bottom of a sale receipt and a rent receipt.
-- Apply inside each tenant schema, after 063. The provisioner rewrites stall_poster.
-- Re-running this file is safe: a tenant stamp rebuild executes it again.

DO $footer$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'mall' AND column_name = 'receipt_footer'
    ) THEN
        ALTER TABLE mall ADD COLUMN receipt_footer text NOT NULL DEFAULT 'Thank you';
    END IF;
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        JOIN pg_class ON pg_class.oid = pg_constraint.conrelid
        JOIN pg_namespace ON pg_namespace.oid = pg_class.relnamespace
        WHERE pg_namespace.nspname = current_schema()
          AND pg_class.relname = 'mall'
          AND pg_constraint.conname = 'mall_receipt_footer_chk'
    ) THEN
        ALTER TABLE mall ADD CONSTRAINT mall_receipt_footer_chk CHECK (
            receipt_footer = btrim(receipt_footer, E' \t\n\r')
            AND char_length(receipt_footer) <= 400
        );
    END IF;
END
$footer$;
