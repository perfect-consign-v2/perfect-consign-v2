-- Registers. One named till. The mall starts with one. Apply after 025.
-- The provisioner rewrites stall_poster.

CREATE TABLE registers (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    name text NOT NULL,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT registers_name_chk CHECK (
        name = btrim(name) AND name <> '' AND char_length(name) <= 40
    )
);

CREATE UNIQUE INDEX registers_name_key ON registers (lower(name));

INSERT INTO registers (id, name)
SELECT '01900000-0000-7000-8000-000000000d10', 'Register'
WHERE NOT EXISTS (
    SELECT 1 FROM registers WHERE lower(name) = 'register'
);

GRANT SELECT, INSERT, UPDATE, DELETE ON registers TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON registers FROM stall_poster;
GRANT SELECT ON registers TO stall_backup;

CREATE OR REPLACE FUNCTION stall_reject_last_register()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF (SELECT count(*) FROM public.registers) <= 1 THEN
        RAISE EXCEPTION 'the last register cannot be deleted'
            USING ERRCODE = '23514';
    END IF;
    RETURN OLD;
END;
$$;

CREATE TRIGGER registers_last_no_delete
    BEFORE DELETE ON registers
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_last_register();

CREATE TRIGGER registers_no_truncate
    BEFORE TRUNCATE ON registers
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE registers
    ENABLE ALWAYS TRIGGER registers_last_no_delete,
    ENABLE ALWAYS TRIGGER registers_no_truncate;
