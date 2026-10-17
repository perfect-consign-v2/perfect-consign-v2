-- The line printed at the bottom of a sale receipt and a rent receipt.
-- Apply inside each tenant schema, after 063. The provisioner rewrites stall_poster.
-- Re-running this file is safe.

ALTER TABLE mall ADD COLUMN IF NOT EXISTS receipt_footer text NOT NULL DEFAULT 'Thank you';

ALTER TABLE mall DROP CONSTRAINT IF EXISTS mall_receipt_footer_chk;

ALTER TABLE mall ADD CONSTRAINT mall_receipt_footer_chk CHECK (
    receipt_footer = btrim(receipt_footer, E' \t\n\r')
    AND char_length(receipt_footer) <= 400
);
