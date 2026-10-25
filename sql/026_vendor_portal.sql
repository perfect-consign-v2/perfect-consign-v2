-- A consignor sees only the rows for stall.vendor_id. A missing setting returns nothing.
-- The link token is stored as a hash. Check and hold are the payout choices.

CREATE TABLE public.vendor_entry_tokens (
    id uuid DEFAULT uuidv7() NOT NULL,
    vendor_id uuid NOT NULL,
    token_hash text NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    used_at timestamp with time zone,
    CONSTRAINT vendor_entry_tokens_pkey PRIMARY KEY (id),
    CONSTRAINT vendor_entry_tokens_hash_chk CHECK ((token_hash ~ '^[0-9a-f]{64}$')),
    CONSTRAINT vendor_entry_tokens_hash_key UNIQUE (token_hash)
);

CREATE TABLE public.vendor_entry_requests (
    id uuid DEFAULT uuidv7() NOT NULL,
    email text NOT NULL,
    requested_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT vendor_entry_requests_pkey PRIMARY KEY (id),
    CONSTRAINT vendor_entry_requests_email_chk CHECK (((email = btrim(email)) AND (email <> ''::text)))
);

CREATE FUNCTION public.stall_vendor_open(entry_hash text) RETURNS uuid
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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

CREATE FUNCTION public.stall_vendor_prefer(mode text) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
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

CREATE VIEW public.stall_vendor_items WITH (security_barrier = true) AS
SELECT item.id, item.code, item.description, item.price_minor, item.quantity, item.expires_on
FROM public.items AS item
WHERE item.vendor_id = NULLIF(current_setting('stall.vendor_id', true), '')::uuid;

CREATE VIEW public.stall_vendor_sales WITH (security_barrier = true) AS
SELECT sale.journal_id, sale.price_minor, sale.quantity, sale.commission_minor, journal.posting_date
FROM public.sale_lines AS sale
JOIN public.journals AS journal ON journal.id = sale.journal_id
JOIN public.items AS item ON item.id = sale.item_id
WHERE item.vendor_id = NULLIF(current_setting('stall.vendor_id', true), '')::uuid;

CREATE VIEW public.stall_vendor_payable WITH (security_barrier = true) AS
SELECT COALESCE(sum(line.credit_minor - line.debit_minor), 0)::bigint AS open_minor
FROM public.vendor_statement AS line
WHERE line.vendor_id = NULLIF(current_setting('stall.vendor_id', true), '')::uuid
  AND line.role = 'vendor_payable';

CREATE VIEW public.stall_vendor_expiring WITH (security_barrier = true) AS
SELECT item.id, item.code, item.description, item.expires_on
FROM public.items AS item
CROSS JOIN public.mall AS mall
WHERE item.vendor_id = NULLIF(current_setting('stall.vendor_id', true), '')::uuid
  AND item.quantity > 0
  AND item.expires_on IS NOT NULL
  AND item.expires_on <= ((clock_timestamp() AT TIME ZONE mall.time_zone)::date + 14);

CREATE VIEW public.stall_vendor_agreement WITH (security_barrier = true) AS
SELECT vendor.agreement_starts_on, vendor.agreement_ends_on, vendor.payout_mode
FROM public.vendors AS vendor
WHERE vendor.id = NULLIF(current_setting('stall.vendor_id', true), '')::uuid;

GRANT USAGE ON SCHEMA public TO stall_vendor;
GRANT SELECT ON public.stall_vendor_items TO stall_vendor;
GRANT SELECT ON public.stall_vendor_sales TO stall_vendor;
GRANT SELECT ON public.stall_vendor_payable TO stall_vendor;
GRANT SELECT ON public.stall_vendor_expiring TO stall_vendor;
GRANT SELECT ON public.stall_vendor_agreement TO stall_vendor;
GRANT ALL ON TABLE public.vendor_entry_tokens TO stall_poster;
GRANT ALL ON TABLE public.vendor_entry_tokens TO stall_backup;
GRANT ALL ON TABLE public.vendor_entry_requests TO stall_poster;
GRANT ALL ON TABLE public.vendor_entry_requests TO stall_backup;
GRANT ALL ON FUNCTION public.stall_vendor_open(entry_hash text) TO stall_poster;
GRANT ALL ON FUNCTION public.stall_vendor_open(entry_hash text) TO stall_backup;
GRANT ALL ON FUNCTION public.stall_vendor_prefer(mode text) TO stall_poster;
GRANT ALL ON FUNCTION public.stall_vendor_prefer(mode text) TO stall_backup;
GRANT SELECT ON public.stall_vendor_items TO stall_poster;
GRANT SELECT ON public.stall_vendor_sales TO stall_poster;
GRANT SELECT ON public.stall_vendor_payable TO stall_poster;
GRANT SELECT ON public.stall_vendor_expiring TO stall_poster;
GRANT SELECT ON public.stall_vendor_agreement TO stall_poster;
