-- A layaway deposit is cash, card, or a check held for this ticket. The piece stays unsold until the deposits cover the amount due.
-- Apply after 039. The provisioner rewrites stall_poster.

INSERT INTO accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type)
SELECT '01900000-0000-7000-8000-000000000021'::uuid, '01900000-0000-7000-8000-000000000001'::uuid, '2230', 'Layaway deposits', 'liability', 'credit', true, true, 'ticket'
WHERE NOT EXISTS (
    SELECT 1 FROM accounts WHERE id = '01900000-0000-7000-8000-000000000021'::uuid
);

INSERT INTO chart_roles (role, account_id)
SELECT 'layaway_deposit', '01900000-0000-7000-8000-000000000021'::uuid
WHERE NOT EXISTS (
    SELECT 1 FROM chart_roles WHERE role = 'layaway_deposit'
);

INSERT INTO chart_source_rules (role, source_type)
SELECT seed.role, seed.source_type
FROM (
    VALUES
        ('cash', 'layaway_deposit'),
        ('card', 'layaway_deposit'),
        ('checks', 'layaway_deposit'),
        ('cash', 'layaway_refund'),
        ('card', 'layaway_refund'),
        ('checks', 'layaway_refund'),
        ('layaway_deposit', 'layaway_deposit'),
        ('layaway_deposit', 'layaway_refund'),
        ('layaway_deposit', 'sale')
) AS seed(role, source_type)
WHERE NOT EXISTS (
    SELECT 1
    FROM chart_source_rules AS existing
    WHERE existing.role = seed.role
      AND existing.source_type = seed.source_type
);

SELECT stall_seed_export_accounts();





CREATE OR REPLACE FUNCTION stall_assert_layaway()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    lines integer;
    layaway_credit bigint;
    tender_debit bigint;
    held_ticket text;
BEGIN
    IF NEW.source_type NOT IN ('layaway_deposit', 'layaway_refund') THEN
        RETURN NULL;
    END IF;

    SELECT count(*)::integer INTO lines FROM public.journal_lines WHERE journal_id = NEW.id;
    IF lines <> 2 THEN
        RAISE EXCEPTION 'a layaway deposit is the tender and the deposit'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.source_type = 'layaway_deposit' THEN
        SELECT COALESCE(sum(line.debit_minor), 0) INTO tender_debit
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role IN ('cash', 'card', 'checks')
          AND line.subledger_type IS NULL;
        SELECT COALESCE(sum(line.credit_minor), 0), min(line.subledger_ref) INTO layaway_credit, held_ticket
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role = 'layaway_deposit'
          AND line.subledger_type = 'ticket';
    ELSE
        SELECT COALESCE(sum(line.credit_minor), 0) INTO tender_debit
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role IN ('cash', 'card', 'checks')
          AND line.subledger_type IS NULL;
        SELECT COALESCE(sum(line.debit_minor), 0), min(line.subledger_ref) INTO layaway_credit, held_ticket
        FROM public.journal_lines AS line
        JOIN public.chart_roles AS role ON role.account_id = line.account_id
        WHERE line.journal_id = NEW.id
          AND role.role = 'layaway_deposit'
          AND line.subledger_type = 'ticket';
    END IF;

    IF tender_debit < 1 OR tender_debit IS DISTINCT FROM layaway_credit THEN
        RAISE EXCEPTION 'a layaway deposit is the tender and the deposit'
            USING ERRCODE = '23514';
    END IF;

    IF held_ticket IS NULL THEN
        RAISE EXCEPTION 'a layaway deposit needs its ticket'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.source_type = 'layaway_deposit' AND NOT EXISTS (
        SELECT 1 FROM public.tickets WHERE id = held_ticket::uuid AND status IN ('open', 'layaway')
    ) THEN
        RAISE EXCEPTION 'a layaway deposit needs its ticket'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.source_type = 'layaway_deposit' AND tender_debit > COALESCE((
        SELECT sum(line.price_minor * line.quantity - line.line_discount_minor)
        FROM public.ticket_lines AS line
        WHERE line.ticket_id = held_ticket::uuid
    ), 0) - COALESCE((
        SELECT sum(prior.credit_minor - prior.debit_minor)
        FROM public.journal_lines AS prior
        JOIN public.chart_roles AS role ON role.account_id = prior.account_id
        WHERE role.role = 'layaway_deposit'
          AND prior.subledger_ref = held_ticket
          AND prior.journal_id <> NEW.id
    ), 0) THEN
        RAISE EXCEPTION 'a layaway deposit cannot pass the amount due'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.source_type = 'layaway_refund' AND tender_debit > COALESCE((
        SELECT sum(prior.credit_minor - prior.debit_minor)
        FROM public.journal_lines AS prior
        JOIN public.chart_roles AS role ON role.account_id = prior.account_id
        WHERE role.role = 'layaway_deposit'
          AND prior.subledger_ref = held_ticket
          AND prior.journal_id <> NEW.id
    ), 0) THEN
        RAISE EXCEPTION 'a layaway refund cannot pass what was paid in'
            USING ERRCODE = '23514';
    END IF;

    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS journals_layaway ON journals;
DROP TRIGGER IF EXISTS journals_layaway_no_delete ON journals;
CREATE CONSTRAINT TRIGGER journals_layaway_no_delete
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION stall_assert_layaway();

ALTER TABLE journals ENABLE ALWAYS TRIGGER journals_layaway_no_delete;




