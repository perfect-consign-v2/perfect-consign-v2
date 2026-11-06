-- Inactive vendors cannot redeem entry links. Entry request rows record client address for spray caps.

ALTER TABLE vendor_entry_requests
    ADD COLUMN IF NOT EXISTS client_address text NOT NULL DEFAULT '';

CREATE OR REPLACE FUNCTION public.stall_vendor_open(entry_hash text) RETURNS uuid
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'public'
    AS $$
DECLARE
    vendor uuid;
    token_id uuid;
    is_active boolean;
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

    SELECT vendor_row.active
    INTO is_active
    FROM public.vendors AS vendor_row
    WHERE vendor_row.id = vendor;

    IF is_active IS DISTINCT FROM true THEN
        UPDATE public.vendor_entry_tokens
        SET used_at = clock_timestamp()
        WHERE id = token_id;
        RETURN NULL;
    END IF;

    UPDATE public.vendor_entry_tokens
    SET used_at = clock_timestamp()
    WHERE id = token_id;

    RETURN vendor;
END;
$$;
