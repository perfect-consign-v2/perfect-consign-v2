-- Undo one payout run. The reversal is its own run, with the operator and the reason.

CREATE OR REPLACE FUNCTION stall_reverse_payout_run(
    target uuid,
    entry_day date,
    actor text,
    reason text
) RETURNS uuid
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    original public.payout_runs%ROWTYPE;
    reversal_id uuid;
    period uuid;
    composed text;
    item record;
    new_journal uuid;
    role_name text;
BEGIN
    IF actor IS NULL OR btrim(actor) = '' OR reason IS NULL OR btrim(reason) = '' THEN
        RAISE EXCEPTION 'a payout reversal needs the operator and a reason'
            USING ERRCODE = '23514';
    END IF;

    SELECT * INTO original FROM public.payout_runs WHERE id = target FOR UPDATE;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'that payout run is not in this mall'
            USING ERRCODE = '23503';
    END IF;
    IF original.reverses_run_id IS NOT NULL OR original.status NOT IN ('settled', 'paid') THEN
        RAISE EXCEPTION 'a payout run status cannot skip'
            USING ERRCODE = '23514';
    END IF;

    composed := left('Reversal of run ' || original.run_no::text || ' — ' || btrim(reason), 2000);
    INSERT INTO public.payout_runs (
        book_id, kind, cadence, sales_from, sales_to, rent_month, tender, status, posting_date,
        note, reverses_run_id, reversed_by, reversal_reason
    ) VALUES (
        original.book_id, original.kind, original.cadence, original.sales_from, original.sales_to,
        original.rent_month, original.tender, 'settled', entry_day,
        composed, original.id, btrim(actor), btrim(reason)
    ) RETURNING id INTO reversal_id;

    SELECT id INTO period
    FROM public.accounting_periods
    WHERE book_id = original.book_id
      AND status = 'open'
      AND period_range @> entry_day;
    IF period IS NULL THEN
        RAISE EXCEPTION 'journal posting date must fall inside its period'
            USING ERRCODE = '23514';
    END IF;

    SET CONSTRAINTS ALL DEFERRED;

    FOREACH role_name IN ARRAY ARRAY['disbursement', 'settlement', 'rent_charge']
    LOOP
        FOR item IN
            SELECT linked.journal_id
            FROM public.payout_run_journals AS linked
            WHERE linked.run_id = original.id
              AND linked.role = role_name
            ORDER BY linked.journal_id
        LOOP
            new_journal := uuidv7();
            INSERT INTO public.journals (
                id, book_id, posting_key, posting_date, occurred_at, period_id,
                currency, source_type, source_reference, description, is_reversal, reverses_journal_id,
                debit_total_minor, credit_total_minor
            )
            SELECT
                new_journal, posted.book_id, 'reversal:' || posted.id::text, entry_day,
                GREATEST(clock_timestamp(), entry_day::timestamp AT TIME ZONE 'UTC' + interval '1 second'), period,
                posted.currency, posted.source_type, posted.source_reference, 'Reversal', true, posted.id,
                posted.credit_total_minor, posted.debit_total_minor
            FROM public.journals AS posted
            WHERE posted.id = item.journal_id;

            INSERT INTO public.journal_lines (
                journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref, memo
            )
            SELECT
                new_journal, line.book_id, line.line_no, line.account_id, line.credit_minor, line.debit_minor,
                line.subledger_type, line.subledger_ref, line.memo
            FROM public.journal_lines AS line
            WHERE line.journal_id = item.journal_id;

            INSERT INTO public.payout_run_journals (run_id, journal_id, role)
            VALUES (reversal_id, new_journal, 'reversal');
        END LOOP;
    END LOOP;

    UPDATE public.payout_runs
    SET status = 'reversed', reversed_at = clock_timestamp()
    WHERE id = original.id;

    SET CONSTRAINTS ALL IMMEDIATE;
    RETURN reversal_id;
END;
$$;
