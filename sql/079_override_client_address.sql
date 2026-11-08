-- Override lockout is per username + client address (same shape as email sign-in).

ALTER TABLE override_sign_in_failures
    ADD COLUMN client_address text NOT NULL DEFAULT '';

DROP FUNCTION IF EXISTS public.stall_override_attempts(text);
DROP FUNCTION IF EXISTS public.stall_override_record(text);

CREATE OR REPLACE FUNCTION public.stall_override_attempts(who text, addr text DEFAULT ''::text) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'public'
    AS $_$
BEGIN
    IF who IS NULL OR who !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RETURN 0;
    END IF;
    RETURN (
        SELECT count(*)::integer
        FROM public.override_sign_in_failures
        WHERE username = who
          AND client_address = COALESCE(addr, '')
          AND failed_at > clock_timestamp() - interval '15 minutes'
    );
END;
$_$;

CREATE OR REPLACE FUNCTION public.stall_override_record(who text, addr text DEFAULT ''::text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'public'
    AS $_$
BEGIN
    IF who IS NULL OR who !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RETURN;
    END IF;
    DELETE FROM public.override_sign_in_failures
    WHERE failed_at <= clock_timestamp() - interval '15 minutes';
    INSERT INTO public.override_sign_in_failures (username, client_address)
    VALUES (who, COALESCE(addr, ''));
END;
$_$;

GRANT EXECUTE ON FUNCTION public.stall_override_attempts(text, text) TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_override_record(text, text) TO stall_poster;
