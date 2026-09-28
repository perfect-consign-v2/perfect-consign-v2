-- Expected drawer cash, a cash drop, and the confirmed over/short.
-- Apply after 029. The provisioner rewrites stall_poster.

ALTER TABLE register_sessions
    ADD COLUMN counted_card_minor bigint,
    ADD COLUMN counted_checks_minor bigint;

ALTER TABLE register_sessions DROP CONSTRAINT register_sessions_close_shape_chk;

ALTER TABLE register_sessions ADD CONSTRAINT register_sessions_close_shape_chk CHECK (
    (
        closed_at IS NULL
        AND closing_counts IS NULL
        AND counted_cash_minor IS NULL
        AND counted_card_minor IS NULL
        AND counted_checks_minor IS NULL
    )
    OR (
        closed_at IS NOT NULL
        AND closing_counts IS NOT NULL
        AND counted_cash_minor IS NOT NULL
        AND counted_card_minor IS NOT NULL
        AND counted_card_minor >= 0
        AND counted_checks_minor IS NOT NULL
        AND counted_checks_minor >= 0
    )
);

CREATE OR REPLACE FUNCTION stall_register_expected_cash(target_session uuid)
RETURNS bigint
LANGUAGE sql
STABLE
SET search_path = pg_catalog
AS $$
    SELECT session.opening_cash_minor + COALESCE((
        SELECT sum(line.debit_minor - line.credit_minor)
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        JOIN public.register_session_posts AS post ON post.journal_id = line.journal_id
        JOIN public.journals AS journal ON journal.id = line.journal_id
        WHERE post.session_id = session.id
          AND role.role = 'cash'
          AND journal.source_type <> 'register_variance'
    ), 0)
    FROM public.register_sessions AS session
    WHERE session.id = target_session;
$$;

CREATE OR REPLACE FUNCTION stall_assert_cash_drop()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    line_count integer;
    safe_ok integer;
    cash_ok integer;
BEGIN
    IF NEW.source_type <> 'cash_drop' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    SELECT count(*) INTO line_count
    FROM public.journal_lines
    WHERE journal_id = NEW.id;

    SELECT count(*) INTO safe_ok
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'cash_in_safe'
      AND line.debit_minor = NEW.debit_total_minor
      AND line.credit_minor = 0;

    SELECT count(*) INTO cash_ok
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'cash'
      AND line.credit_minor = NEW.credit_total_minor
      AND line.debit_minor = 0;

    IF NEW.debit_total_minor < 1 OR line_count <> 2 OR safe_ok <> 1 OR cash_ok <> 1 THEN
        RAISE EXCEPTION 'a cash drop moves drawer cash into the safe'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER journals_cash_drop_no_delete
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_cash_drop();

ALTER TABLE journals ENABLE ALWAYS TRIGGER journals_cash_drop_no_delete;

CREATE OR REPLACE FUNCTION stall_assert_register_variance()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    line_count integer;
    cash_line integer;
    short_line integer;
BEGIN
    IF NEW.source_type <> 'register_variance' OR NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    SELECT count(*) INTO line_count
    FROM public.journal_lines
    WHERE journal_id = NEW.id;

    SELECT count(*) INTO cash_line
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'cash'
      AND line.subledger_type IS NULL
      AND (
          (line.debit_minor = NEW.debit_total_minor AND line.credit_minor = 0)
          OR (line.credit_minor = NEW.credit_total_minor AND line.debit_minor = 0)
      );

    SELECT count(*) INTO short_line
    FROM public.journal_lines AS line
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE line.journal_id = NEW.id
      AND role.role = 'cash_over_short'
      AND line.subledger_type IS NULL
      AND (
          (line.debit_minor = NEW.debit_total_minor AND line.credit_minor = 0)
          OR (line.credit_minor = NEW.credit_total_minor AND line.debit_minor = 0)
      );

    IF NEW.debit_total_minor < 1 OR line_count <> 2 OR cash_line <> 1 OR short_line <> 1 THEN
        RAISE EXCEPTION 'register variance moves the difference between cash and over/short'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER journals_register_variance_no_delete
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_register_variance();

ALTER TABLE journals ENABLE ALWAYS TRIGGER journals_register_variance_no_delete;

CREATE OR REPLACE FUNCTION stall_assert_register_close()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    expected bigint;
    difference bigint;
    variance_count integer;
    variance_cash bigint;
BEGIN
    IF TG_OP <> 'UPDATE' OR OLD.closed_at IS NOT NULL OR NEW.closed_at IS NULL THEN
        RETURN NEW;
    END IF;

    SELECT session.opening_cash_minor + COALESCE((
        SELECT sum(line.debit_minor - line.credit_minor)
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        JOIN public.register_session_posts AS post ON post.journal_id = line.journal_id
        JOIN public.journals AS journal ON journal.id = line.journal_id
        WHERE post.session_id = session.id
          AND role.role = 'cash'
          AND journal.source_type <> 'register_variance'
    ), 0)
    INTO expected
    FROM public.register_sessions AS session
    WHERE session.id = NEW.id;

    difference := NEW.counted_cash_minor - expected;

    SELECT count(*), COALESCE(sum(line.debit_minor - line.credit_minor), 0)
    INTO variance_count, variance_cash
    FROM public.register_session_posts AS post
    JOIN public.journals AS journal ON journal.id = post.journal_id
    JOIN public.journal_lines AS line ON line.journal_id = journal.id
    JOIN public.chart_roles AS role ON role.account_id = line.account_id
    WHERE post.session_id = NEW.id
      AND journal.source_type = 'register_variance'
      AND role.role = 'cash';

    IF difference = 0 AND variance_count = 0 THEN
        RETURN NEW;
    END IF;

    IF difference <> 0 AND variance_count = 1 AND variance_cash = difference THEN
        RETURN NEW;
    END IF;

    RAISE EXCEPTION 'counted cash does not match the expected drawer'
        USING ERRCODE = '23514';
END;
$$;

CREATE TRIGGER register_sessions_close_no_delete
    BEFORE UPDATE ON register_sessions
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_register_close();

ALTER TABLE register_sessions ENABLE ALWAYS TRIGGER register_sessions_close_no_delete;
