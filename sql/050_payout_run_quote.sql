-- One-vendor read of an open draft pack. Vendor pages must not rebuild every row.

CREATE OR REPLACE FUNCTION public.stall_pack_run_id() RETURNS uuid
    LANGUAGE sql
    STABLE
    SET search_path TO 'pg_catalog'
    AS $$
    SELECT CASE
        WHEN current_setting('stall.pack_run', true) ~ '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$'
        THEN current_setting('stall.pack_run', true)::uuid
        ELSE NULL
    END
$$;

CREATE OR REPLACE FUNCTION public.stall_pack_runs() RETURNS TABLE(id uuid, sales_from date, sales_to date, rent_month date, posting_date date)
    LANGUAGE sql
    AS $_$
    SELECT run.id, run.sales_from, run.sales_to, run.rent_month, run.posting_date
    FROM public.payout_runs AS run
    WHERE public.stall_pack_run_id() IS NULL
       OR run.id = public.stall_pack_run_id()
    UNION ALL
    SELECT
        '00000000-0000-0000-0000-000000000000'::uuid,
        CASE
            WHEN current_setting('stall.pack_from', true) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
            THEN current_setting('stall.pack_from', true)::date
        END,
        CASE
            WHEN current_setting('stall.pack_to', true) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
            THEN current_setting('stall.pack_to', true)::date
        END,
        CASE
            WHEN current_setting('stall.pack_rent', true) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
            THEN current_setting('stall.pack_rent', true)::date
        END,
        CASE
            WHEN current_setting('stall.pack_posting', true) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
            THEN current_setting('stall.pack_posting', true)::date
        END
    WHERE current_setting('stall.pack_from', true) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
      AND current_setting('stall.pack_to', true) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
      AND current_setting('stall.pack_posting', true) ~ '^[0-9]{4}-[0-9]{2}-[0-9]{2}$'
      AND public.stall_pack_run_id() IS NULL;
$_$;

CREATE OR REPLACE FUNCTION public.stall_payout_run_quote(target_run uuid, target_vendor uuid) RETURNS SETOF public.payout_pack
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF target_run IS NULL OR target_vendor IS NULL THEN
        RAISE EXCEPTION 'a payout run quote needs a run and a vendor'
            USING ERRCODE = '23514';
    END IF;
    PERFORM set_config('stall.pack_run', target_run::text, true);
    PERFORM set_config('stall.pack_vendor', target_vendor::text, true);
    RETURN QUERY
        SELECT *
        FROM payout_pack
        WHERE run_id = target_run
          AND vendor_id = target_vendor;
    PERFORM set_config('stall.pack_run', '', true);
    PERFORM set_config('stall.pack_vendor', '', true);
END;
$$;

CREATE OR REPLACE FUNCTION public.stall_payout_pack(target_run uuid, window_from date, window_to date, window_rent date, window_posting date) RETURNS SETOF public.payout_pack
    LANGUAGE plpgsql
    AS $$
DECLARE
    preview_id uuid := '00000000-0000-0000-0000-000000000000';
BEGIN
    PERFORM set_config('stall.pack_run', '', true);
    PERFORM set_config('stall.pack_vendor', '', true);
    PERFORM set_config('stall.pack_from', window_from::text, true);
    PERFORM set_config('stall.pack_to', window_to::text, true);
    PERFORM set_config('stall.pack_rent', COALESCE(window_rent::text, ''), true);
    PERFORM set_config('stall.pack_posting', window_posting::text, true);
    RETURN QUERY
        SELECT *
        FROM payout_pack
        WHERE run_id = COALESCE(target_run, preview_id);
    PERFORM set_config('stall.pack_from', '', true);
    PERFORM set_config('stall.pack_to', '', true);
    PERFORM set_config('stall.pack_rent', '', true);
    PERFORM set_config('stall.pack_posting', '', true);
    PERFORM set_config('stall.pack_vendor', '', true);
    PERFORM set_config('stall.pack_run', '', true);
END;
$$;

CREATE OR REPLACE FUNCTION public.stall_payout_quote(target_vendor uuid, window_from date, window_to date, window_rent date, window_posting date) RETURNS SETOF public.payout_pack
    LANGUAGE plpgsql
    AS $$
DECLARE
    preview_id uuid := '00000000-0000-0000-0000-000000000000';
BEGIN
    IF target_vendor IS NULL THEN
        RAISE EXCEPTION 'a payout quote needs a vendor'
            USING ERRCODE = '23514';
    END IF;
    PERFORM set_config('stall.pack_run', '', true);
    PERFORM set_config('stall.pack_vendor', target_vendor::text, true);
    PERFORM set_config('stall.pack_from', window_from::text, true);
    PERFORM set_config('stall.pack_to', window_to::text, true);
    PERFORM set_config('stall.pack_rent', COALESCE(window_rent::text, ''), true);
    PERFORM set_config('stall.pack_posting', window_posting::text, true);
    RETURN QUERY
        SELECT *
        FROM payout_pack
        WHERE run_id = preview_id
          AND vendor_id = target_vendor;
    PERFORM set_config('stall.pack_from', '', true);
    PERFORM set_config('stall.pack_to', '', true);
    PERFORM set_config('stall.pack_rent', '', true);
    PERFORM set_config('stall.pack_posting', '', true);
    PERFORM set_config('stall.pack_vendor', '', true);
    PERFORM set_config('stall.pack_run', '', true);
END;
$$;

REVOKE ALL ON FUNCTION public.stall_pack_run_id() FROM PUBLIC;
REVOKE ALL ON FUNCTION public.stall_payout_run_quote(uuid, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.stall_pack_run_id() TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_pack_run_id() TO stall_backup;
GRANT EXECUTE ON FUNCTION public.stall_payout_run_quote(uuid, uuid) TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_payout_run_quote(uuid, uuid) TO stall_backup;
