-- A paid payout may fill a blank check number once. Money stays frozen.
-- Apply after 053. The provisioner rewrites public.

CREATE OR REPLACE FUNCTION stall_reject_posted_run_line()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    run_status text;
BEGIN
    SELECT status INTO run_status FROM public.payout_runs
    WHERE id = COALESCE(NEW.run_id, OLD.run_id);
    IF TG_OP = 'DELETE' THEN
        IF run_status <> 'draft' THEN
            RAISE EXCEPTION 'a settled payout run report cannot change'
                USING ERRCODE = '23514';
        END IF;
        RETURN OLD;
    END IF;
    IF run_status = 'draft' OR run_status IS NULL THEN
        RETURN NEW;
    END IF;
    IF (NEW.sales_minor, NEW.discount_minor, NEW.commission_minor, NEW.tax_minor,
        NEW.return_minor, NEW.void_minor, NEW.draw_minor, NEW.check_paid_minor,
        NEW.settlement_applied_minor, NEW.rent_minor, NEW.rent_charged_minor,
        NEW.rent_brought_forward_minor, NEW.rent_paid_since_minor,
        NEW.adjustment_minor, NEW.brought_forward_minor, NEW.carried_payable_minor,
        NEW.carried_rent_minor, NEW.settled_minor, NEW.check_minor, NEW.owes_minor,
        NEW.net_minor, NEW.settlement_journal_id, NEW.mode, NEW.vendor_id, NEW.run_id)
       IS DISTINCT FROM
       (OLD.sales_minor, OLD.discount_minor, OLD.commission_minor, OLD.tax_minor,
        OLD.return_minor, OLD.void_minor, OLD.draw_minor, OLD.check_paid_minor,
        OLD.settlement_applied_minor, OLD.rent_minor, OLD.rent_charged_minor,
        OLD.rent_brought_forward_minor, OLD.rent_paid_since_minor,
        OLD.adjustment_minor, OLD.brought_forward_minor, OLD.carried_payable_minor,
        OLD.carried_rent_minor, OLD.settled_minor, OLD.check_minor, OLD.owes_minor,
        OLD.net_minor, OLD.settlement_journal_id, OLD.mode, OLD.vendor_id, OLD.run_id)
    THEN
        RAISE EXCEPTION 'a settled payout run money line cannot change'
            USING ERRCODE = '23514';
    END IF;
    IF OLD.check_number IS NOT NULL AND NEW.check_number IS DISTINCT FROM OLD.check_number THEN
        RAISE EXCEPTION 'a check number is written once'
            USING ERRCODE = '23514';
    END IF;
    IF OLD.disbursement_journal_id IS NOT NULL
       AND NEW.disbursement_journal_id IS DISTINCT FROM OLD.disbursement_journal_id THEN
        RAISE EXCEPTION 'a disbursement journal is written once'
            USING ERRCODE = '23514';
    END IF;
    IF run_status = 'paid'
       AND OLD.check_number IS NULL
       AND NEW.check_number IS NOT NULL
       AND NEW.disbursement_journal_id IS NOT DISTINCT FROM OLD.disbursement_journal_id THEN
        RETURN NEW;
    END IF;
    IF run_status <> 'settled'
       AND (NEW.check_number IS DISTINCT FROM OLD.check_number
            OR NEW.disbursement_journal_id IS DISTINCT FROM OLD.disbursement_journal_id) THEN
        RAISE EXCEPTION 'a check is written only while the run is settled'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;
