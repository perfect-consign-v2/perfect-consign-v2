-- Repair: wait-list BEFORE INSERT must call required assert (row not visible yet).
-- Contact trigger keeps calling stall_assert_wait_list_party (open-row gated).

CREATE OR REPLACE FUNCTION public.stall_assert_wait_list_party_required(p_party_id uuid) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
DECLARE
    wait_email text;
    wait_phone text;
BEGIN
    wait_phone := public.stall_party_primary_phone(p_party_id);
    IF wait_phone IS NULL OR btrim(wait_phone) = '' THEN
        RAISE EXCEPTION 'Enter a phone for the wait list.'
            USING ERRCODE = '23514';
    END IF;
    wait_email := public.stall_party_primary_email(p_party_id);
    IF wait_email IS NULL OR btrim(wait_email) = '' THEN
        RAISE EXCEPTION 'Enter an email for the wait list.'
            USING ERRCODE = '23514';
    END IF;
    PERFORM pg_advisory_xact_lock(hashtext('wait-list-email'), hashtext(lower(wait_email)));
    IF EXISTS (
        SELECT 1
        FROM public.wait_list AS other
        WHERE other.party_id IS DISTINCT FROM p_party_id
          AND other.status = ANY (ARRAY['waiting'::text, 'contacted'::text])
          AND lower(public.stall_party_primary_email(other.party_id)) = lower(wait_email)
    ) THEN
        RAISE EXCEPTION 'That email is already on the wait list.'
            USING ERRCODE = '23505';
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION public.stall_assert_wait_list_party(p_party_id uuid) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM public.wait_list AS entry
        WHERE entry.party_id = p_party_id
          AND entry.status = ANY (ARRAY['waiting'::text, 'contacted'::text])
    ) THEN
        RETURN;
    END IF;
    PERFORM public.stall_assert_wait_list_party_required(p_party_id);
END;
$$;

CREATE OR REPLACE FUNCTION public.stall_assert_wait_list_open_email() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF NEW.status NOT IN ('waiting', 'contacted') THEN
        RETURN NEW;
    END IF;
    PERFORM public.stall_assert_wait_list_party_required(NEW.party_id);
    RETURN NEW;
END;
$$;
