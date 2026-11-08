-- Open wait-list rows need a person email. Avoid PL/pgSQL names that clash with persons.address.
CREATE OR REPLACE FUNCTION public.stall_assert_wait_list_open_email() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
DECLARE
    wait_email text;
BEGIN
    IF NEW.status NOT IN ('waiting', 'contacted') THEN
        RETURN NEW;
    END IF;
    SELECT person.email INTO wait_email FROM public.persons AS person WHERE person.party_id = NEW.party_id;
    IF wait_email IS NULL OR btrim(wait_email) = '' THEN
        RAISE EXCEPTION 'Enter an email for the wait list.'
            USING ERRCODE = '23514';
    END IF;
    PERFORM pg_advisory_xact_lock(hashtext('wait-list-email'), hashtext(lower(wait_email)));
    IF EXISTS (
        SELECT 1
        FROM public.wait_list AS other
        JOIN public.persons AS person ON person.party_id = other.party_id
        WHERE other.party_id IS DISTINCT FROM NEW.party_id
          AND other.status = ANY (ARRAY['waiting'::text, 'contacted'::text])
          AND lower(person.email) = lower(wait_email)
    ) THEN
        RAISE EXCEPTION 'That email is already on the wait list.'
            USING ERRCODE = '23505';
    END IF;
    RETURN NEW;
END;
$$;
