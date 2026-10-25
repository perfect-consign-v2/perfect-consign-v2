-- A poster may only enqueue a vendor letter for its own mall.

CREATE OR REPLACE FUNCTION platform.enqueue_vendor_letter(letter_date date, letter_id uuid) RETURNS void
    LANGUAGE plpgsql
    SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $$
DECLARE
    tenant_slug text;
BEGIN
    IF current_user = 'stall_poster' THEN
        tenant_slug := 'mall';
    ELSIF current_user ~ '^poster_[0-9a-f]{32}$' THEN
        SELECT slug INTO tenant_slug
        FROM platform.tenants
        WHERE schema_name = 'tenant_' || substr(current_user, 8);
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

REVOKE ALL ON FUNCTION platform.enqueue_vendor_letter(date, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION platform.enqueue_vendor_letter(date, uuid) TO stall_app;
GRANT EXECUTE ON FUNCTION platform.enqueue_vendor_letter(date, uuid) TO stall_poster;

CREATE OR REPLACE FUNCTION platform.grant_poster_jobs(poster text) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF poster IS NULL OR poster !~ '^[a-z_][a-z0-9_]{0,62}$' THEN
        RAISE EXCEPTION 'a poster role name is required'
            USING ERRCODE = '23514';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = poster) THEN
        RAISE EXCEPTION 'poster role % is missing', poster
            USING ERRCODE = '42704';
    END IF;
    IF to_regclass('platform.jobs') IS NULL THEN
        RAISE EXCEPTION 'platform.jobs is missing'
            USING ERRCODE = '42P01';
    END IF;
    EXECUTE format('REVOKE INSERT ON TABLE platform.jobs FROM %I', poster);
    EXECUTE format('GRANT SELECT ON TABLE platform.jobs TO %I', poster);
    EXECUTE format('GRANT EXECUTE ON FUNCTION platform.enqueue_vendor_letter(date, uuid) TO %I', poster);
END;
$$;

DO $$
DECLARE
    mall record;
    poster text;
BEGIN
    FOR mall IN SELECT slug, schema_name FROM platform.tenants LOOP
        IF mall.slug = 'mall' THEN
            poster := 'stall_poster';
        ELSE
            poster := 'poster_' || substr(mall.schema_name, 8);
        END IF;
        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = poster) THEN
            PERFORM platform.grant_poster_jobs(poster);
        END IF;
    END LOOP;
    REVOKE INSERT ON TABLE platform.jobs FROM stall_poster;
    GRANT SELECT ON TABLE platform.jobs TO stall_poster;
END $$;
