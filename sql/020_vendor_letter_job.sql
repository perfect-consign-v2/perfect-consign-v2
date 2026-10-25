-- A filed letter is its own job. Dated jobs stay one per day.
-- A letter job is one per letter, so two letters on the same day both stay.

ALTER TABLE platform.jobs DROP CONSTRAINT jobs_kind_chk;

ALTER TABLE platform.jobs ADD CONSTRAINT jobs_kind_chk CHECK (kind = ANY (ARRAY['assess-late-fees'::text, 'vendor-digest'::text, 'rent-reminder'::text, 'reconcile-reports'::text, 'sale-notice'::text, 'vendor-letter'::text]));

DROP INDEX platform.jobs_tenant_kind_day_key;

CREATE UNIQUE INDEX IF NOT EXISTS jobs_tenant_kind_day_key
    ON platform.jobs (tenant, kind, (payload ->> 'date'::text))
    WHERE ((payload ->> 'letter'::text) IS NULL);

CREATE UNIQUE INDEX IF NOT EXISTS jobs_tenant_kind_letter_key
    ON platform.jobs (tenant, kind, (payload ->> 'letter'::text))
    WHERE ((payload ->> 'letter'::text) IS NOT NULL);

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
    EXECUTE format('GRANT SELECT, INSERT ON TABLE platform.jobs TO %I', poster);
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
END $$;
