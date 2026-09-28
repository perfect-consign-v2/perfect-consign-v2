-- One open session per register. Opening cash is the bill and coin count.
-- Apply after 026. The provisioner rewrites stall_poster.

CREATE TABLE register_sessions (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    register_id uuid NOT NULL REFERENCES registers (id),
    cashier text NOT NULL,
    opened_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    closed_at timestamptz,
    opening_counts jsonb NOT NULL,
    opening_cash_minor bigint NOT NULL,
    closing_counts jsonb,
    counted_cash_minor bigint,
    CONSTRAINT register_sessions_cashier_chk CHECK (
        cashier = btrim(cashier) AND cashier <> '' AND char_length(cashier) <= 80
    ),
    CONSTRAINT register_sessions_open_cash_chk CHECK (opening_cash_minor >= 0),
    CONSTRAINT register_sessions_counted_chk CHECK (
        counted_cash_minor IS NULL OR counted_cash_minor >= 0
    ),
    CONSTRAINT register_sessions_close_shape_chk CHECK (
        (
            closed_at IS NULL
            AND closing_counts IS NULL
            AND counted_cash_minor IS NULL
        )
        OR (
            closed_at IS NOT NULL
            AND closing_counts IS NOT NULL
            AND counted_cash_minor IS NOT NULL
        )
    )
);

CREATE UNIQUE INDEX register_sessions_one_open
    ON register_sessions (register_id)
    WHERE closed_at IS NULL;

GRANT SELECT, INSERT, UPDATE ON register_sessions TO stall_poster;
REVOKE DELETE, TRUNCATE, TRIGGER ON register_sessions FROM stall_poster;
GRANT SELECT ON register_sessions TO stall_backup;

CREATE OR REPLACE FUNCTION stall_reject_session_rewrite()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    counts jsonb;
    expected text[] := ARRAY['10000', '5000', '2000', '1000', '500', '100', '25', '10', '5', '1'];
    bill text;
    qty bigint;
    total bigint;
    stored bigint;
    message text;
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'a register session cannot be deleted'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'INSERT' AND (
        NEW.closed_at IS NOT NULL
        OR NEW.closing_counts IS NOT NULL
        OR NEW.counted_cash_minor IS NOT NULL
    ) THEN
        RAISE EXCEPTION 'a session opens before it closes'
            USING ERRCODE = '23514';
    END IF;

    IF TG_OP = 'UPDATE' THEN
        IF OLD.closed_at IS NOT NULL THEN
            RAISE EXCEPTION 'a closed session cannot be changed'
                USING ERRCODE = '23514';
        END IF;
        IF NEW.id IS DISTINCT FROM OLD.id
            OR NEW.register_id IS DISTINCT FROM OLD.register_id
            OR NEW.cashier IS DISTINCT FROM OLD.cashier
            OR NEW.opened_at IS DISTINCT FROM OLD.opened_at
            OR NEW.opening_counts IS DISTINCT FROM OLD.opening_counts
            OR NEW.opening_cash_minor IS DISTINCT FROM OLD.opening_cash_minor THEN
            RAISE EXCEPTION 'an opening count cannot be changed'
                USING ERRCODE = '23514';
        END IF;
    END IF;

    IF TG_OP = 'INSERT' THEN
        counts := NEW.opening_counts;
        stored := NEW.opening_cash_minor;
        message := 'opening cash is the bill and coin count';
    ELSE
        counts := NEW.closing_counts;
        stored := NEW.counted_cash_minor;
        message := 'counted cash is the bill and coin count';
    END IF;

    total := 0;
    IF counts IS NULL OR jsonb_typeof(counts) <> 'object' OR (
        SELECT count(*) FROM jsonb_object_keys(counts)
    ) <> 10 THEN
        RAISE EXCEPTION '%', message
            USING ERRCODE = '23514';
    END IF;
    FOREACH bill IN ARRAY expected LOOP
        IF NOT counts ? bill
            OR jsonb_typeof(counts -> bill) <> 'number'
            OR (counts ->> bill) !~ '^[0-9]+$' THEN
            RAISE EXCEPTION '%', message
                USING ERRCODE = '23514';
        END IF;
        qty := (counts ->> bill)::bigint;
        total := total + (qty * bill::bigint);
    END LOOP;
    IF stored IS DISTINCT FROM total THEN
        RAISE EXCEPTION '%', message
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER register_sessions_append_only
    BEFORE INSERT OR UPDATE ON register_sessions
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_session_rewrite();

CREATE TRIGGER register_sessions_no_delete
    BEFORE DELETE ON register_sessions
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_session_rewrite();

CREATE TRIGGER register_sessions_no_truncate
    BEFORE TRUNCATE ON register_sessions
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE register_sessions
    ENABLE ALWAYS TRIGGER register_sessions_append_only,
    ENABLE ALWAYS TRIGGER register_sessions_no_delete,
    ENABLE ALWAYS TRIGGER register_sessions_no_truncate;
