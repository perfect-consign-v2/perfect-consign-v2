-- Wait list. A person who wants a booth when none are open. Not a vendor. Not a journal.
-- Apply inside each tenant schema, after 023. The provisioner rewrites stall_poster.

CREATE TABLE wait_list (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    first_name text NOT NULL,
    last_name text NOT NULL,
    email text NOT NULL,
    phone text NOT NULL,
    width_ft integer,
    depth_ft integer,
    status text NOT NULL DEFAULT 'waiting',
    notes text,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT wait_list_first_name_chk CHECK (first_name = btrim(first_name) AND first_name <> ''),
    CONSTRAINT wait_list_last_name_chk CHECK (last_name = btrim(last_name) AND last_name <> ''),
    CONSTRAINT wait_list_email_chk CHECK (
        email = btrim(email)
        AND email ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'
        AND char_length(email) <= 200
    ),
    CONSTRAINT wait_list_phone_chk CHECK (
        phone = btrim(phone) AND phone <> '' AND char_length(phone) <= 40
    ),
    CONSTRAINT wait_list_notes_chk CHECK (
        notes IS NULL OR (notes = btrim(notes) AND notes <> '' AND char_length(notes) <= 2000)
    ),
    CONSTRAINT wait_list_status_chk CHECK (status IN ('waiting', 'contacted', 'placed', 'declined')),
    CONSTRAINT wait_list_size_chk CHECK (
        (width_ft IS NULL) = (depth_ft IS NULL)
        AND (width_ft IS NULL OR (width_ft > 0 AND depth_ft > 0))
    )
);

CREATE UNIQUE INDEX wait_list_open_email_key
    ON wait_list (lower(email))
    WHERE status IN ('waiting', 'contacted');

GRANT SELECT, INSERT, UPDATE, DELETE ON wait_list TO stall_poster;
REVOKE TRUNCATE, TRIGGER ON wait_list FROM stall_poster;
GRANT SELECT ON wait_list TO stall_backup;

CREATE TRIGGER wait_list_no_truncate
    BEFORE TRUNCATE ON wait_list
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE wait_list ENABLE ALWAYS TRIGGER wait_list_no_truncate;
