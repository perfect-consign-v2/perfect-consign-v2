-- Evidence tables: backup is SELECT-only. Token open/prefer and lease end are DEFINER.

REVOKE ALL ON TABLE public.vendor_entry_requests FROM PUBLIC;
REVOKE ALL ON TABLE public.vendor_entry_requests FROM stall_poster;
REVOKE ALL ON TABLE public.vendor_entry_requests FROM stall_backup;
GRANT SELECT, INSERT ON TABLE public.vendor_entry_requests TO stall_poster;
GRANT SELECT ON TABLE public.vendor_entry_requests TO stall_backup;

REVOKE ALL ON TABLE public.vendor_entry_tokens FROM PUBLIC;
REVOKE ALL ON TABLE public.vendor_entry_tokens FROM stall_poster;
REVOKE ALL ON TABLE public.vendor_entry_tokens FROM stall_backup;
GRANT SELECT, INSERT ON TABLE public.vendor_entry_tokens TO stall_poster;
GRANT SELECT ON TABLE public.vendor_entry_tokens TO stall_backup;

CREATE OR REPLACE FUNCTION public.stall_vendor_open(entry_hash text) RETURNS uuid
    LANGUAGE plpgsql
    SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'public'
    AS $$
DECLARE
    vendor uuid;
    token_id uuid;
BEGIN
    SELECT link.id, link.vendor_id
    INTO token_id, vendor
    FROM public.vendor_entry_tokens AS link
    WHERE link.token_hash = entry_hash
      AND link.used_at IS NULL
      AND link.expires_at > clock_timestamp()
    FOR UPDATE;

    IF token_id IS NULL THEN
        RETURN NULL;
    END IF;

    UPDATE public.vendor_entry_tokens
    SET used_at = clock_timestamp()
    WHERE id = token_id;

    RETURN vendor;
END;
$$;

CREATE OR REPLACE FUNCTION public.stall_vendor_prefer(mode text) RETURNS void
    LANGUAGE plpgsql
    SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'public'
    AS $$
DECLARE
    vendor uuid;
BEGIN
    IF mode IS NULL OR mode NOT IN ('pay', 'hold') THEN
        RAISE EXCEPTION 'a payout preference is check or hold'
            USING ERRCODE = '23514';
    END IF;
    BEGIN
        vendor := NULLIF(current_setting('stall.vendor_id', true), '')::uuid;
    EXCEPTION WHEN invalid_text_representation THEN
        vendor := NULL;
    END;
    IF vendor IS NULL THEN
        RAISE EXCEPTION 'a vendor setting is required'
            USING ERRCODE = '23514';
    END IF;
    UPDATE public.vendors
    SET payout_mode = mode
    WHERE id = vendor;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'a vendor setting is required'
            USING ERRCODE = '23514';
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION public.stall_end_lease(lease uuid, ended_on date) RETURNS void
    LANGUAGE plpgsql
    SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    UPDATE public.booth_leases
    SET status = 'ended', ends_on = ended_on
    WHERE id = lease
      AND status = 'active'
      AND starts_on <= ended_on;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'that lease is not active'
            USING ERRCODE = '23514';
    END IF;
END;
$$;

ALTER FUNCTION public.stall_propose_markdowns(date) SECURITY DEFINER;
ALTER FUNCTION public.stall_confirm_markdown(uuid, text, boolean) SECURITY DEFINER;
ALTER FUNCTION public.stall_propose_markdowns(date) SET search_path TO 'pg_catalog', 'public';
ALTER FUNCTION public.stall_confirm_markdown(uuid, text, boolean) SET search_path TO 'pg_catalog', 'public';

REVOKE ALL ON FUNCTION public.stall_vendor_open(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.stall_vendor_prefer(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.stall_end_lease(uuid, date) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.stall_vendor_open(text) TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_vendor_prefer(text) TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_vendor_prefer(text) TO stall_vendor;
GRANT EXECUTE ON FUNCTION public.stall_end_lease(uuid, date) TO stall_poster;

REVOKE ALL ON TABLE public.booth_leases FROM stall_backup;
GRANT SELECT ON TABLE public.booth_leases TO stall_backup;

REVOKE ALL ON TABLE public.markdown_proposals FROM stall_backup;
GRANT SELECT ON TABLE public.markdown_proposals TO stall_backup;

REVOKE ALL ON TABLE public.item_floors FROM stall_backup;
GRANT SELECT ON TABLE public.item_floors TO stall_backup;

REVOKE ALL ON TABLE public.commission_rules FROM stall_backup;
GRANT SELECT ON TABLE public.commission_rules TO stall_backup;
