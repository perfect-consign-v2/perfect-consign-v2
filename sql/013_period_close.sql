-- One closing journal zeros a period's income and expense into retained earnings.
-- Safe to run again.

INSERT INTO accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type)
SELECT
    '01900000-0000-7000-8000-000000000019',
    '01900000-0000-7000-8000-000000000001',
    '3000',
    'Retained earnings',
    'equity',
    'credit',
    true,
    false,
    NULL
WHERE NOT EXISTS (
    SELECT 1 FROM accounts WHERE id = '01900000-0000-7000-8000-000000000019'
);

INSERT INTO chart_roles (role, account_id)
SELECT 'retained_earnings', '01900000-0000-7000-8000-000000000019'
WHERE NOT EXISTS (
    SELECT 1 FROM chart_roles WHERE role = 'retained_earnings'
);

SELECT stall_seed_export_accounts();

INSERT INTO chart_source_rules (role, source_type)
SELECT seed.role, seed.source_type
FROM (
    VALUES
        ('commission_revenue', 'period_close'),
        ('rent_revenue', 'period_close'),
        ('operating_expense', 'period_close'),
        ('retained_earnings', 'period_close')
) AS seed(role, source_type)
WHERE NOT EXISTS (
    SELECT 1
    FROM chart_source_rules AS existing
    WHERE existing.role = seed.role
      AND existing.source_type = seed.source_type
);

CREATE OR REPLACE FUNCTION stall_assert_period_close()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog
AS $$
DECLARE
    retained_id uuid;
    period_start date;
BEGIN
    IF NEW.is_reversal THEN
        IF EXISTS (
            SELECT 1
            FROM public.journals AS original
            WHERE original.id = NEW.reverses_journal_id
              AND original.source_type = 'period_close'
        ) THEN
            RAISE EXCEPTION 'a period close is not reversible'
                USING ERRCODE = '23514';
        END IF;
        RETURN NULL;
    END IF;

    IF NEW.source_type <> 'period_close' THEN
        RETURN NULL;
    END IF;

    IF NEW.posting_key <> 'period-close:' || NEW.period_id::text THEN
        RAISE EXCEPTION 'a period close posting key is its period'
            USING ERRCODE = '23514';
    END IF;

    SELECT starts_on INTO period_start
    FROM public.accounting_periods
    WHERE id = NEW.period_id;
    PERFORM public.stall_reject_earlier_open_period(NEW.book_id, period_start);

    SELECT account_id INTO retained_id
    FROM public.chart_roles
    WHERE role = 'retained_earnings';
    IF retained_id IS NULL THEN
        RAISE EXCEPTION 'a period close requires retained earnings'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        WITH movement AS (
            SELECT
                account.id AS account_id,
                sum(line.credit_minor - line.debit_minor) AS net_minor
            FROM public.journal_lines AS line
            JOIN public.journals AS posted ON posted.id = line.journal_id
            JOIN public.accounts AS account ON account.id = line.account_id
            WHERE posted.period_id = NEW.period_id
              AND posted.id <> NEW.id
              AND account.account_type IN ('revenue', 'expense')
            GROUP BY account.id
            HAVING sum(line.credit_minor - line.debit_minor) <> 0
        ),
        expected AS (
            SELECT
                movement.account_id,
                CASE WHEN movement.net_minor > 0 THEN movement.net_minor ELSE 0 END AS debit_minor,
                CASE WHEN movement.net_minor < 0 THEN -movement.net_minor ELSE 0 END AS credit_minor,
                NULL::text AS subledger_type,
                NULL::text AS subledger_ref
            FROM movement
            UNION ALL
            SELECT
                retained_id,
                CASE WHEN income.net_minor < 0 THEN -income.net_minor ELSE 0 END,
                CASE WHEN income.net_minor > 0 THEN income.net_minor ELSE 0 END,
                NULL::text,
                NULL::text
            FROM (
                SELECT COALESCE(sum(movement.net_minor), 0) AS net_minor
                FROM movement
            ) AS income
            WHERE income.net_minor <> 0
        ),
        actual AS (
            SELECT account_id, debit_minor, credit_minor, subledger_type, subledger_ref
            FROM public.journal_lines
            WHERE journal_id = NEW.id
        )
        SELECT account_id, debit_minor, credit_minor, subledger_type, subledger_ref FROM expected
        EXCEPT ALL
        SELECT account_id, debit_minor, credit_minor, subledger_type, subledger_ref FROM actual
        UNION ALL
        (
            SELECT account_id, debit_minor, credit_minor, subledger_type, subledger_ref FROM actual
            EXCEPT ALL
            SELECT account_id, debit_minor, credit_minor, subledger_type, subledger_ref FROM expected
        )
    ) THEN
        RAISE EXCEPTION 'a period close zeros that period income and expense into retained earnings'
            USING ERRCODE = '23514';
    END IF;

    UPDATE public.accounting_periods
    SET status = 'closed'
    WHERE id = NEW.period_id
      AND status = 'open';

    RETURN NULL;
END;
$$;

ALTER FUNCTION stall_assert_period_close() SECURITY DEFINER;

DROP TRIGGER IF EXISTS journals_period_close ON journals;
CREATE CONSTRAINT TRIGGER journals_period_close
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_period_close();

CREATE OR REPLACE FUNCTION stall_reject_earlier_open_period(book uuid, period_start date)
RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog
AS $$
BEGIN
    PERFORM 1
    FROM public.accounting_periods
    WHERE book_id = book
      AND starts_on < period_start
      AND status = 'open'
    ORDER BY starts_on
    FOR UPDATE;

    IF FOUND THEN
        RAISE EXCEPTION 'an earlier period is still open'
            USING ERRCODE = '23514';
    END IF;
END;
$$;

ALTER FUNCTION stall_reject_earlier_open_period(uuid, date) SECURITY DEFINER;

CREATE OR REPLACE FUNCTION stall_reject_period_before_closed()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog
AS $$
BEGIN
    PERFORM 1
    FROM public.ledger_books
    WHERE id = NEW.book_id
    FOR UPDATE;

    IF EXISTS (
        SELECT 1
        FROM public.accounting_periods
        WHERE book_id = NEW.book_id
          AND status = 'closed'
          AND starts_on > NEW.starts_on
    ) THEN
        RAISE EXCEPTION 'a period cannot start before a closed period'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

ALTER FUNCTION stall_reject_period_before_closed() SECURITY DEFINER;

DROP TRIGGER IF EXISTS accounting_periods_before_closed ON accounting_periods;
CREATE TRIGGER accounting_periods_before_closed
    BEFORE INSERT ON accounting_periods
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_period_before_closed();

CREATE OR REPLACE FUNCTION stall_close_quiet_period(closed_period uuid)
RETURNS void
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog
AS $$
DECLARE
    period_book uuid;
    period_start date;
    period_status text;
BEGIN
    SELECT book_id INTO period_book
    FROM public.accounting_periods
    WHERE id = closed_period;
    IF period_book IS NULL THEN
        RAISE EXCEPTION 'journal period does not exist'
            USING ERRCODE = '23503';
    END IF;

    PERFORM 1
    FROM public.ledger_books
    WHERE id = period_book
    FOR UPDATE;

    SELECT starts_on, status
    INTO period_start, period_status
    FROM public.accounting_periods
    WHERE id = closed_period
    FOR UPDATE;
    IF period_status IS NULL THEN
        RAISE EXCEPTION 'journal period does not exist'
            USING ERRCODE = '23503';
    END IF;
    IF period_status <> 'open' THEN
        RAISE EXCEPTION 'a closed period refuses a new journal'
            USING ERRCODE = '23514';
    END IF;

    PERFORM public.stall_reject_earlier_open_period(period_book, period_start);

    IF EXISTS (
        SELECT 1
        FROM public.journal_lines AS line
        JOIN public.journals AS posted ON posted.id = line.journal_id
        JOIN public.accounts AS account ON account.id = line.account_id
        WHERE posted.period_id = closed_period
          AND account.account_type IN ('revenue', 'expense')
        GROUP BY account.id
        HAVING sum(line.credit_minor - line.debit_minor) <> 0
    ) THEN
        RAISE EXCEPTION 'a period with income or expense closes by a journal'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.journals AS posted
        WHERE posted.period_id = closed_period
          AND EXISTS (
              SELECT 1
              FROM public.journal_lines AS line
              JOIN public.accounts AS account ON account.id = line.account_id
              WHERE line.journal_id = posted.id
                AND account.account_type IN ('revenue', 'expense')
          )
          AND NOT (
              (
                  posted.is_reversal
                  AND EXISTS (
                      SELECT 1
                      FROM public.journals AS original
                      WHERE original.id = posted.reverses_journal_id
                        AND original.period_id = closed_period
                  )
              )
              OR (
                  NOT posted.is_reversal
                  AND EXISTS (
                      SELECT 1
                      FROM public.journals AS reversal
                      WHERE reversal.reverses_journal_id = posted.id
                        AND reversal.period_id = closed_period
                  )
              )
          )
    ) THEN
        RAISE EXCEPTION 'a period with income or expense closes by a journal'
            USING ERRCODE = '23514';
    END IF;

    UPDATE public.accounting_periods
    SET status = 'closed'
    WHERE id = closed_period
      AND status = 'open';
END;
$$;

ALTER FUNCTION stall_close_quiet_period(uuid) SECURITY DEFINER;

REVOKE ALL ON FUNCTION stall_close_quiet_period(uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION stall_close_quiet_period(uuid) TO stall_poster;
