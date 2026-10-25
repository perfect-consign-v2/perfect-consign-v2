-- Override failures stay inside the mall. The poster cannot DELETE the table.

CREATE TABLE public.override_sign_in_failures (
    id uuid DEFAULT uuidv7() NOT NULL,
    username text NOT NULL,
    failed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT override_sign_in_failures_pkey PRIMARY KEY (id),
    CONSTRAINT override_sign_in_failures_username_chk CHECK (((username = btrim(username)) AND (username ~ '^[a-z][a-z0-9-]{0,62}$'::text)))
);

CREATE INDEX override_sign_in_failures_window
    ON public.override_sign_in_failures USING btree (username, failed_at);

DO $$
BEGIN
    IF to_regclass('platform.override_sign_in_failures') IS NOT NULL THEN
        INSERT INTO public.override_sign_in_failures (id, username, failed_at)
        SELECT platform_row.id, platform_row.username, platform_row.failed_at
        FROM platform.override_sign_in_failures AS platform_row
        JOIN platform.tenants AS mall ON mall.id = platform_row.tenant_id
        WHERE mall.schema_name = current_schema()
        ON CONFLICT (id) DO NOTHING;
    END IF;
END $$;

CREATE FUNCTION public.stall_override_attempts(who text) RETURNS integer
    LANGUAGE plpgsql
    SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF who IS NULL OR who !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RETURN 0;
    END IF;
    RETURN (
        SELECT count(*)::integer
        FROM public.override_sign_in_failures
        WHERE username = who
          AND failed_at > clock_timestamp() - interval '15 minutes'
    );
END;
$$;

CREATE FUNCTION public.stall_override_record(who text) RETURNS void
    LANGUAGE plpgsql
    SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF who IS NULL OR who !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RETURN;
    END IF;
    DELETE FROM public.override_sign_in_failures
    WHERE failed_at <= clock_timestamp() - interval '15 minutes';
    INSERT INTO public.override_sign_in_failures (username) VALUES (who);
END;
$$;

CREATE FUNCTION public.stall_override_clear(who text) RETURNS void
    LANGUAGE plpgsql
    SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF who IS NULL OR who !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RETURN;
    END IF;
    DELETE FROM public.override_sign_in_failures WHERE username = who;
END;
$$;

REVOKE ALL ON TABLE public.override_sign_in_failures FROM PUBLIC;
REVOKE ALL ON FUNCTION public.stall_override_attempts(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.stall_override_record(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.stall_override_clear(text) FROM PUBLIC;

GRANT EXECUTE ON FUNCTION public.stall_override_attempts(text) TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_override_record(text) TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_override_clear(text) TO stall_poster;
GRANT SELECT ON TABLE public.override_sign_in_failures TO stall_backup;
