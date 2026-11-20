-- A cash booth deposit and its return name the open shift.
-- register_session_posts_append_only binds stall_reject_session_post, so a
-- poster cannot replace that function. This file turns event triggers off
-- only while those two functions are replaced, then turns them back on.
-- A later statement in the same transaction stays guarded.

SET LOCAL event_triggers = false;

CREATE OR REPLACE FUNCTION public.stall_reject_session_post() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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
        'item_return',
        'rent_payment',
        'cash_disbursement',
        'gift_certificate_issue',
        'gift_card_issue',
        'store_credit_issue',
        'cash_drop',
        'register_variance',
        'layaway_deposit',
        'layaway_refund',
        'booth_deposit'
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

CREATE OR REPLACE FUNCTION public.stall_assert_register_session() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    checks_count integer;
    check_row integer;
BEGIN
    IF NEW.source_type IN (
        'sale',
        'item_return',
        'rent_payment',
        'cash_disbursement',
        'gift_certificate_issue',
        'gift_card_issue',
        'store_credit_issue',
        'cash_drop',
        'register_variance',
        'layaway_deposit',
        'layaway_refund',
        'booth_deposit'
    ) AND NOT EXISTS (
        SELECT 1
        FROM public.register_session_posts
        WHERE journal_id = NEW.id
    ) THEN
        RAISE EXCEPTION 'a drawer journal needs an open register'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.source_type IN ('sale', 'item_return', 'layaway_deposit', 'layaway_refund') AND NOT NEW.is_reversal THEN
        SELECT count(*) INTO checks_count
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role = 'checks'
          AND (
              (NEW.source_type IN ('sale', 'layaway_deposit') AND line.debit_minor > 0)
              OR (NEW.source_type IN ('item_return', 'layaway_refund') AND line.credit_minor > 0)
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

SET LOCAL event_triggers = true;
