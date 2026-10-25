-- Inside SECURITY DEFINER, current_user is the owner. The poster is current_setting('role').

CREATE OR REPLACE FUNCTION platform.enqueue_vendor_letter(letter_date date, letter_id uuid) RETURNS void
    LANGUAGE plpgsql
    SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $$
DECLARE
    poster text;
    tenant_slug text;
BEGIN
    poster := nullif(current_setting('role', true), 'none');
    IF poster IS NULL OR poster = '' THEN
        RAISE EXCEPTION 'a poster role must enqueue the letter'
            USING ERRCODE = '42501';
    END IF;
    IF poster = 'stall_poster' THEN
        tenant_slug := 'mall';
    ELSIF poster ~ '^poster_[0-9a-f]{32}$' THEN
        SELECT slug INTO tenant_slug
        FROM platform.tenants
        WHERE schema_name = 'tenant_' || substr(poster, 8);
    ELSE
        RAISE EXCEPTION 'a poster role must enqueue the letter'
            USING ERRCODE = '42501';
    END IF;
    IF tenant_slug IS NULL THEN
        RAISE EXCEPTION 'the mall is not a mall'
            USING ERRCODE = '23514';
    END IF;
    INSERT INTO platform.jobs (tenant, kind, payload, run_at)
    VALUES (
        tenant_slug,
        'vendor-letter',
        jsonb_build_object('date', letter_date::text, 'letter', letter_id::text),
        clock_timestamp()
    )
    ON CONFLICT (tenant, kind, (payload ->> 'letter')) WHERE ((payload ->> 'letter') IS NOT NULL) DO NOTHING;
END;
$$;
