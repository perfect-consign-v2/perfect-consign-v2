-- Booth deposit liability is a booth control account.
-- A release that would take that booth below zero is refused.
-- The cash line cannot carry a booth: cash is not a booth control account.

INSERT INTO public.accounts (
    id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type
) VALUES (
    '01900000-0000-7000-8000-00000000002d',
    '01900000-0000-7000-8000-000000000001',
    '2151',
    'Booth deposits held',
    'liability',
    'credit',
    true,
    true,
    'booth'
);

INSERT INTO public.chart_roles (role, account_id) VALUES
    ('booth_deposit_held', '01900000-0000-7000-8000-00000000002d');

INSERT INTO public.chart_source_rules (role, source_type) VALUES
    ('booth_deposit_held', 'booth_deposit');

SELECT public.stall_seed_export_accounts();

CREATE FUNCTION public.stall_refuse_booth_deposit_overdraw() RETURNS trigger
    LANGUAGE plpgsql
    SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    held bigint;
    booth_account uuid;
BEGIN
    SELECT role.account_id INTO booth_account
    FROM public.chart_roles AS role
    WHERE role.role = 'booth_deposit_held';
    IF NEW.account_id IS DISTINCT FROM booth_account THEN
        RETURN NEW;
    END IF;
    IF NEW.subledger_type IS DISTINCT FROM 'booth' OR NEW.subledger_ref IS NULL THEN
        RAISE EXCEPTION 'a booth deposit line names its booth'
            USING ERRCODE = '23514';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM public.booths WHERE id = NEW.subledger_ref::uuid) THEN
        RAISE EXCEPTION 'that booth does not exist'
            USING ERRCODE = '23514';
    END IF;
    SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0) INTO held
    FROM public.journal_lines AS line
    WHERE line.account_id = booth_account
      AND line.subledger_ref = NEW.subledger_ref;
    IF held < 0 THEN
        RAISE EXCEPTION 'a release is larger than the deposit held'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER journal_lines_booth_deposit_append_only
    AFTER INSERT ON public.journal_lines
    FOR EACH ROW
    EXECUTE FUNCTION public.stall_refuse_booth_deposit_overdraw();

ALTER TABLE public.journal_lines
    ENABLE ALWAYS TRIGGER journal_lines_booth_deposit_append_only;
