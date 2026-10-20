-- How a sale receipt is emailed: the server mailbox, or this mall's Gmail.
-- Apply inside each tenant schema, after 065. Re-running this file is safe.

DO $mail$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'mall' AND column_name = 'receipt_mail_via'
    ) THEN
        ALTER TABLE mall ADD COLUMN receipt_mail_via text NOT NULL DEFAULT 'server';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'mall' AND column_name = 'gmail_address'
    ) THEN
        ALTER TABLE mall ADD COLUMN gmail_address text;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'mall' AND column_name = 'gmail_app_password'
    ) THEN
        ALTER TABLE mall ADD COLUMN gmail_app_password text;
    END IF;
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint
        JOIN pg_class ON pg_class.oid = pg_constraint.conrelid
        JOIN pg_namespace ON pg_namespace.oid = pg_class.relnamespace
        WHERE pg_namespace.nspname = current_schema()
          AND pg_class.relname = 'mall'
          AND pg_constraint.conname = 'mall_receipt_mail_chk'
    ) THEN
        ALTER TABLE mall ADD CONSTRAINT mall_receipt_mail_chk CHECK (
            receipt_mail_via IN ('server', 'gmail')
            AND (
                gmail_address IS NULL
                OR (
                    gmail_address = btrim(gmail_address)
                    AND char_length(gmail_address) <= 200
                    AND gmail_address ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'
                )
            )
            AND (
                gmail_app_password IS NULL
                OR (
                    gmail_app_password = btrim(gmail_app_password)
                    AND char_length(gmail_app_password) BETWEEN 16 AND 64
                    AND gmail_app_password ~ '^[A-Za-z0-9]+$'
                )
            )
            AND (
                receipt_mail_via = 'server'
                OR (gmail_address IS NOT NULL AND gmail_app_password IS NOT NULL)
            )
        );
    END IF;
END
$mail$;
