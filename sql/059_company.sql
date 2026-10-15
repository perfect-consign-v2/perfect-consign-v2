-- The address, phone, and email printed under the store name on a receipt.
-- Apply inside each tenant schema, after 058. The provisioner rewrites stall_poster.
-- Re-running this file is safe: a tenant stamp rebuild executes it again.

DO $company$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'mall' AND column_name = 'street'
    ) THEN
        ALTER TABLE mall ADD COLUMN street text;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'mall' AND column_name = 'street2'
    ) THEN
        ALTER TABLE mall ADD COLUMN street2 text;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'mall' AND column_name = 'city'
    ) THEN
        ALTER TABLE mall ADD COLUMN city text;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'mall' AND column_name = 'state'
    ) THEN
        ALTER TABLE mall ADD COLUMN state text;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'mall' AND column_name = 'postal'
    ) THEN
        ALTER TABLE mall ADD COLUMN postal text;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'mall' AND column_name = 'phone'
    ) THEN
        ALTER TABLE mall ADD COLUMN phone text;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = current_schema() AND table_name = 'mall' AND column_name = 'email'
    ) THEN
        ALTER TABLE mall ADD COLUMN email text;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'mall_street_chk' AND connamespace = current_schema()::regnamespace
    ) THEN
        ALTER TABLE mall ADD CONSTRAINT mall_street_chk CHECK (
            street IS NULL OR (street = btrim(street) AND street <> '' AND char_length(street) <= 200)
        );
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'mall_street2_chk' AND connamespace = current_schema()::regnamespace
    ) THEN
        ALTER TABLE mall ADD CONSTRAINT mall_street2_chk CHECK (
            street2 IS NULL OR (street2 = btrim(street2) AND street2 <> '' AND char_length(street2) <= 200)
        );
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'mall_city_chk' AND connamespace = current_schema()::regnamespace
    ) THEN
        ALTER TABLE mall ADD CONSTRAINT mall_city_chk CHECK (
            city IS NULL OR (city = btrim(city) AND city <> '' AND char_length(city) <= 80)
        );
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'mall_state_chk' AND connamespace = current_schema()::regnamespace
    ) THEN
        ALTER TABLE mall ADD CONSTRAINT mall_state_chk CHECK (
            state IS NULL OR (state = btrim(state) AND state <> '' AND char_length(state) <= 40)
        );
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'mall_postal_chk' AND connamespace = current_schema()::regnamespace
    ) THEN
        ALTER TABLE mall ADD CONSTRAINT mall_postal_chk CHECK (
            postal IS NULL OR (postal = btrim(postal) AND postal <> '' AND char_length(postal) <= 20)
        );
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'mall_phone_chk' AND connamespace = current_schema()::regnamespace
    ) THEN
        ALTER TABLE mall ADD CONSTRAINT mall_phone_chk CHECK (
            phone IS NULL OR (phone = btrim(phone) AND phone <> '' AND char_length(phone) <= 40)
        );
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'mall_email_chk' AND connamespace = current_schema()::regnamespace
    ) THEN
        ALTER TABLE mall ADD CONSTRAINT mall_email_chk CHECK (
            email IS NULL OR (
                email = btrim(email)
                AND char_length(email) <= 200
                AND email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'
            )
        );
    END IF;
END
$company$;
