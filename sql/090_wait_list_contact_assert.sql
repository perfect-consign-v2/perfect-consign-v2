-- Open wait-list phone/email/uniqueness when party_contacts change (deferred).

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
    -- BEFORE INSERT the new row is not visible; require contacts for NEW.party_id.
    PERFORM public.stall_assert_wait_list_party_required(NEW.party_id);
    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION public.stall_assert_wait_list_party_contacts() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    PERFORM public.stall_assert_wait_list_party(COALESCE(NEW.party_id, OLD.party_id));
    RETURN NULL;
END;
$$;

DROP TRIGGER IF EXISTS party_contacts_wait_list_assert_no_delete ON public.party_contacts;
DROP TRIGGER IF EXISTS party_contacts_wait_list_assert ON public.party_contacts;
CREATE CONSTRAINT TRIGGER party_contacts_wait_list_assert
    AFTER INSERT OR UPDATE OR DELETE ON public.party_contacts
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION public.stall_assert_wait_list_party_contacts();

ALTER TABLE public.party_contacts ENABLE ALWAYS TRIGGER party_contacts_wait_list_assert;
