-- Folded wait-list contact assert (was 082/083/089–091). Idempotent for stamped 088 malls.

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

CREATE OR REPLACE FUNCTION public.stall_assert_wait_list_party_contacts() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    PERFORM public.stall_assert_wait_list_party(COALESCE(NEW.party_id, OLD.party_id));
    RETURN NULL;
END;
$$;

CREATE OR REPLACE FUNCTION public.stall_seed_wait_person(
    first text,
    last text,
    email text,
    phone text,
    party_id uuid DEFAULT NULL,
    width_ft numeric DEFAULT NULL,
    depth_ft numeric DEFAULT NULL,
    status text DEFAULT 'waiting',
    notes text DEFAULT NULL
) RETURNS uuid
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF phone IS NULL OR phone = '' THEN
        RAISE EXCEPTION 'Enter a phone for the wait list.'
            USING ERRCODE = '23514';
    END IF;
    IF email IS NULL OR email = '' THEN
        RAISE EXCEPTION 'Enter an email for the wait list.'
            USING ERRCODE = '23514';
    END IF;
    IF party_id IS NULL THEN
        INSERT INTO public.parties DEFAULT VALUES RETURNING parties.id INTO party_id;
    ELSE
        INSERT INTO public.parties (id) VALUES (party_id);
    END IF;
    INSERT INTO public.persons (party_id, first_name, last_name)
    VALUES (party_id, first, last);
    INSERT INTO public.party_contacts (party_id, method, label, value, is_primary)
    VALUES (party_id, 'phone', 'primary', phone, true);
    INSERT INTO public.party_contacts (party_id, method, label, value, is_primary)
    VALUES (party_id, 'email', 'primary', email, true);
    INSERT INTO public.party_roles (party_id, role_type, status)
    VALUES (party_id, 'wait_list', 'active');
    INSERT INTO public.wait_list (party_id, width_ft, depth_ft, status, notes)
    VALUES (party_id, width_ft, depth_ft, status, notes);
    RETURN party_id;
END;
$$;

GRANT EXECUTE ON FUNCTION public.stall_seed_wait_person(text, text, text, text, uuid, numeric, numeric, text, text) TO stall_poster;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_trigger AS trig
        JOIN pg_class AS rel ON rel.oid = trig.tgrelid
        JOIN pg_namespace AS nsp ON nsp.oid = rel.relnamespace
        WHERE nsp.nspname = current_schema()
          AND rel.relname = 'party_contacts'
          AND trig.tgname = 'party_contacts_wait_list_assert'
    ) THEN
        CREATE CONSTRAINT TRIGGER party_contacts_wait_list_assert
            AFTER INSERT OR UPDATE OR DELETE ON public.party_contacts
            DEFERRABLE INITIALLY DEFERRED
            FOR EACH ROW
            EXECUTE FUNCTION public.stall_assert_wait_list_party_contacts();
        ALTER TABLE public.party_contacts ENABLE ALWAYS TRIGGER party_contacts_wait_list_assert;
    END IF;
END;
$$;
