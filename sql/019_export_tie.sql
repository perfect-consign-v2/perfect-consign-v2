-- A period closes only when the QuickBooks and Xero files tie to the books.
-- The file is the existing journal CSV. A missing export account refuses the close.
-- Accounts whose whole history is inside the period must match account_balances.

CREATE FUNCTION public.stall_export_tie_delta(system text, from_date date, to_date date) RETURNS bigint
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    ledger_debit bigint;
    ledger_credit bigint;
    file_debit bigint;
    file_credit bigint;
    inside_debit bigint;
    inside_credit bigint;
    balance_debit bigint;
    balance_credit bigint;
BEGIN
    IF system IS NULL OR system NOT IN ('quickbooks', 'xero') THEN
        RAISE EXCEPTION 'the export system must be quickbooks or xero'
            USING ERRCODE = '23514';
    END IF;
    IF from_date IS NULL OR to_date IS NULL OR from_date > to_date THEN
        RAISE EXCEPTION 'the export range ends before it starts'
            USING ERRCODE = '23514';
    END IF;
    IF NOT EXISTS (
        SELECT 1
        FROM public.export_lines AS line
        WHERE line.posting_date >= from_date
          AND line.posting_date <= to_date
    ) THEN
        RETURN 0;
    END IF;

    PERFORM line
    FROM public.stall_export_journal_csv(system, from_date, to_date) AS line;

    SELECT COALESCE(sum(line.debit_minor), 0), COALESCE(sum(line.credit_minor), 0)
    INTO ledger_debit, ledger_credit
    FROM public.export_lines AS line
    WHERE line.posting_date >= from_date
      AND line.posting_date <= to_date;

    IF system = 'quickbooks' THEN
        SELECT
            COALESCE(sum(
                CASE
                    WHEN line.debit_minor > 0 THEN round(
                        to_char(
                            line.debit_minor::numeric / (10::numeric ^ book.currency_scale),
                            'FM999999999999990' || CASE
                                WHEN book.currency_scale > 0 THEN '.' || repeat('0', book.currency_scale)
                                ELSE ''
                            END
                        )::numeric * (10::numeric ^ book.currency_scale)
                    )::bigint
                    ELSE 0
                END
            ), 0),
            COALESCE(sum(
                CASE
                    WHEN line.credit_minor > 0 THEN round(
                        to_char(
                            line.credit_minor::numeric / (10::numeric ^ book.currency_scale),
                            'FM999999999999990' || CASE
                                WHEN book.currency_scale > 0 THEN '.' || repeat('0', book.currency_scale)
                                ELSE ''
                            END
                        )::numeric * (10::numeric ^ book.currency_scale)
                    )::bigint
                    ELSE 0
                END
            ), 0)
        INTO file_debit, file_credit
        FROM public.export_lines AS line
        JOIN public.journals AS journal
            ON journal.journal_no = line.journal_no
           AND journal.posting_key = line.posting_key
        JOIN public.ledger_books AS book
            ON book.id = journal.book_id
        WHERE line.posting_date >= from_date
          AND line.posting_date <= to_date;
    ELSE
        SELECT
            COALESCE(sum(
                CASE
                    WHEN signed > 0 THEN signed
                    ELSE 0
                END
            ), 0),
            COALESCE(sum(
                CASE
                    WHEN signed < 0 THEN -signed
                    ELSE 0
                END
            ), 0)
        INTO file_debit, file_credit
        FROM (
            SELECT round(
                to_char(
                    CASE
                        WHEN line.debit_minor > 0 THEN line.debit_minor::numeric
                        ELSE -line.credit_minor::numeric
                    END / (10::numeric ^ book.currency_scale),
                    'FM999999999999990' || CASE
                        WHEN book.currency_scale > 0 THEN '.' || repeat('0', book.currency_scale)
                        ELSE ''
                    END
                )::numeric * (10::numeric ^ book.currency_scale)
            )::bigint AS signed
            FROM public.export_lines AS line
            JOIN public.journals AS journal
                ON journal.journal_no = line.journal_no
               AND journal.posting_key = line.posting_key
            JOIN public.ledger_books AS book
                ON book.id = journal.book_id
            WHERE line.posting_date >= from_date
              AND line.posting_date <= to_date
        ) AS filed;
    END IF;

    SELECT
        COALESCE(sum(inside.debit_minor), 0),
        COALESCE(sum(inside.credit_minor), 0),
        COALESCE(sum(balance.debit_minor), 0),
        COALESCE(sum(balance.credit_minor), 0)
    INTO inside_debit, inside_credit, balance_debit, balance_credit
    FROM (
        SELECT
            account.id AS account_id,
            COALESCE(sum(line.debit_minor), 0) AS debit_minor,
            COALESCE(sum(line.credit_minor), 0) AS credit_minor
        FROM public.journal_lines AS line
        JOIN public.journals AS journal ON journal.id = line.journal_id
        JOIN public.accounts AS account ON account.id = line.account_id
        JOIN public.chart_roles AS role ON role.account_id = account.id
        JOIN public.export_accounts AS mapped
            ON mapped.role = role.role
           AND mapped.system = stall_export_tie_delta.system
        WHERE journal.posting_date >= from_date
          AND journal.posting_date <= to_date
          AND NOT EXISTS (
              SELECT 1
              FROM public.journal_lines AS other
              JOIN public.journals AS other_journal ON other_journal.id = other.journal_id
              WHERE other.account_id = account.id
                AND (
                    other_journal.posting_date < from_date
                    OR other_journal.posting_date > to_date
                )
          )
        GROUP BY account.id
    ) AS inside
    JOIN public.account_balances AS balance ON balance.account_id = inside.account_id;

    RETURN abs(ledger_debit - file_debit)
        + abs(ledger_credit - file_credit)
        + abs(inside_debit - balance_debit)
        + abs(inside_credit - balance_credit);
END;
$$;

CREATE FUNCTION public.stall_refuse_export_tie(from_date date, to_date date) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    export_system text;
    delta bigint;
BEGIN
    FOREACH export_system IN ARRAY ARRAY['quickbooks', 'xero'] LOOP
        delta := public.stall_export_tie_delta(export_system, from_date, to_date);
        IF delta <> 0 THEN
            RAISE EXCEPTION 'the export does not tie to the books'
                USING ERRCODE = '23514';
        END IF;
    END LOOP;
END;
$$;

CREATE FUNCTION public.stall_export_file_hash(system text, from_date date, to_date date) RETURNS text
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    row text;
    body text := '';
BEGIN
    FOR row IN
        SELECT line
        FROM public.stall_export_journal_csv(system, from_date, to_date) AS line
    LOOP
        body := body || row || E'\n';
    END LOOP;

    RETURN md5(body);
END;
$$;

CREATE FUNCTION public.stall_guard_period_export() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF NEW.status IS DISTINCT FROM 'closed' OR OLD.status IS NOT DISTINCT FROM 'closed' THEN
        RETURN NEW;
    END IF;
    PERFORM public.stall_refuse_export_tie(NEW.starts_on, NEW.ends_on);

    RETURN NEW;
END;
$$;

CREATE TRIGGER accounting_periods_export_tie
    BEFORE UPDATE OF status ON public.accounting_periods
    FOR EACH ROW
    EXECUTE FUNCTION public.stall_guard_period_export();

GRANT ALL ON FUNCTION public.stall_export_tie_delta(system text, from_date date, to_date date) TO stall_poster;
GRANT ALL ON FUNCTION public.stall_export_tie_delta(system text, from_date date, to_date date) TO stall_backup;
GRANT ALL ON FUNCTION public.stall_refuse_export_tie(from_date date, to_date date) TO stall_poster;
GRANT ALL ON FUNCTION public.stall_refuse_export_tie(from_date date, to_date date) TO stall_backup;
GRANT ALL ON FUNCTION public.stall_export_file_hash(system text, from_date date, to_date date) TO stall_poster;
GRANT ALL ON FUNCTION public.stall_export_file_hash(system text, from_date date, to_date date) TO stall_backup;
GRANT ALL ON FUNCTION public.stall_guard_period_export() TO stall_poster;
GRANT ALL ON FUNCTION public.stall_guard_period_export() TO stall_backup;
