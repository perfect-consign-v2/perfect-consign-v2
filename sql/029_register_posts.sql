-- A drawer journal names one open register session.
-- Apply after 028. The sale shape matches sql/004_sale.sql. The provisioner rewrites stall_poster.

CREATE TABLE register_session_posts (
    journal_id uuid PRIMARY KEY REFERENCES journals (id),
    session_id uuid NOT NULL REFERENCES register_sessions (id)
);

CREATE TABLE register_checks (
    journal_id uuid PRIMARY KEY REFERENCES journals (id),
    check_number text NOT NULL,
    CONSTRAINT register_checks_number_chk CHECK (
        check_number = btrim(check_number)
        AND check_number <> ''
        AND char_length(check_number) <= 40
    )
);

GRANT SELECT, INSERT ON register_session_posts, register_checks TO stall_poster;
REVOKE UPDATE, DELETE, TRUNCATE, TRIGGER ON register_session_posts, register_checks FROM stall_poster;
GRANT SELECT ON register_session_posts, register_checks TO stall_backup;



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
        'cash_disbursement',
        'gift_certificate_issue',
        'gift_card_issue',
        'store_credit_issue',
        'cash_drop',
        'register_variance',
        'layaway_deposit',
        'layaway_refund'
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

CREATE TRIGGER register_session_posts_append_only
    BEFORE INSERT OR UPDATE ON register_session_posts
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_session_post();

CREATE TRIGGER register_session_posts_no_delete
    BEFORE DELETE ON register_session_posts
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_session_post();

CREATE TRIGGER register_session_posts_no_truncate
    BEFORE TRUNCATE ON register_session_posts
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE register_session_posts
    ENABLE ALWAYS TRIGGER register_session_posts_append_only,
    ENABLE ALWAYS TRIGGER register_session_posts_no_delete,
    ENABLE ALWAYS TRIGGER register_session_posts_no_truncate;

CREATE OR REPLACE FUNCTION stall_reject_register_check()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'a check number cannot be deleted'
            USING ERRCODE = '23514';
    END IF;
    RAISE EXCEPTION 'a check number cannot be changed'
        USING ERRCODE = '23514';
END;
$$;

CREATE TRIGGER register_checks_append_only
    BEFORE UPDATE ON register_checks
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_register_check();

CREATE TRIGGER register_checks_no_delete
    BEFORE DELETE ON register_checks
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_register_check();

CREATE TRIGGER register_checks_no_truncate
    BEFORE TRUNCATE ON register_checks
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE register_checks
    ENABLE ALWAYS TRIGGER register_checks_append_only,
    ENABLE ALWAYS TRIGGER register_checks_no_delete,
    ENABLE ALWAYS TRIGGER register_checks_no_truncate;

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
        'cash_disbursement',
        'gift_certificate_issue',
        'gift_card_issue',
        'store_credit_issue',
        'cash_drop',
        'register_variance',
        'layaway_deposit',
        'layaway_refund'
    ) AND NOT EXISTS (
        SELECT 1
        FROM public.register_session_posts
        WHERE journal_id = NEW.id
    ) THEN
        RAISE EXCEPTION 'a drawer journal needs an open register'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.source_type IN ('sale', 'piece_return', 'layaway_deposit', 'layaway_refund') AND NOT NEW.is_reversal THEN
        SELECT count(*) INTO checks_count
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role = 'checks'
          AND (
              (NEW.source_type IN ('sale', 'layaway_deposit') AND line.debit_minor > 0)
              OR (NEW.source_type IN ('piece_return', 'layaway_refund') AND line.credit_minor > 0)
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

CREATE CONSTRAINT TRIGGER journals_register_session
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_register_session();
