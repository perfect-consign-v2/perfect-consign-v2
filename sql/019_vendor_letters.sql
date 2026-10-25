-- An owner letter and one copy per consignor. The letter cannot change.
-- A copy may record sent_at once.

CREATE FUNCTION public.stall_reject_vendor_letter() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'a letter cannot be changed'
        USING ERRCODE = '25006';
END;
$$;

CREATE FUNCTION public.stall_apply_vendor_letter_copy() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    standing boolean;
    address text;
    bounced boolean;
BEGIN
    SELECT vendor.active, vendor.email, vendor.email_bounced
    INTO standing, address, bounced
    FROM public.vendors AS vendor
    WHERE vendor.id = NEW.vendor_id;
    IF standing IS NULL OR standing = false THEN
        RAISE EXCEPTION 'an inactive consignor is left out'
            USING ERRCODE = '23514';
    END IF;
    IF bounced THEN
        RAISE EXCEPTION 'a bounced address is left out'
            USING ERRCODE = '23514';
    END IF;
    IF address IS NULL OR lower(address) IS DISTINCT FROM lower(NEW.email) THEN
        RAISE EXCEPTION 'the letter address is not the consignor email'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE FUNCTION public.stall_guard_vendor_letter_copy() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'a letter copy cannot be removed'
            USING ERRCODE = '25006';
    END IF;
    IF OLD.sent_at IS NOT NULL THEN
        RAISE EXCEPTION 'a letter copy was already sent'
            USING ERRCODE = '25006';
    END IF;
    IF NEW.sent_at IS NULL
        OR NEW.letter_id IS DISTINCT FROM OLD.letter_id
        OR NEW.vendor_id IS DISTINCT FROM OLD.vendor_id
        OR NEW.email IS DISTINCT FROM OLD.email THEN
        RAISE EXCEPTION 'a letter copy cannot be rewritten'
            USING ERRCODE = '25006';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TABLE public.vendor_letters (
    id uuid DEFAULT uuidv7() NOT NULL,
    subject text NOT NULL,
    body text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT vendor_letters_pkey PRIMARY KEY (id),
    CONSTRAINT vendor_letters_subject_chk CHECK ((
        (subject = btrim(subject))
        AND (subject <> ''::text)
        AND (char_length(subject) <= 150)
        AND (subject !~ E'[\\r\\n]')
    )),
    CONSTRAINT vendor_letters_body_chk CHECK ((
        (body = btrim(body))
        AND (body <> ''::text)
        AND (char_length(body) <= 4000)
    ))
);

CREATE TABLE public.vendor_letter_copies (
    letter_id uuid NOT NULL,
    vendor_id uuid NOT NULL,
    email text NOT NULL,
    sent_at timestamp with time zone,
    CONSTRAINT vendor_letter_copies_pkey PRIMARY KEY (letter_id, vendor_id),
    CONSTRAINT vendor_letter_copies_email_chk CHECK ((
        (email = btrim(email))
        AND (email ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'::text)
        AND (char_length(email) <= 200)
    ))
);

CREATE TRIGGER vendor_letters_no_delete
    BEFORE UPDATE OR DELETE ON public.vendor_letters
    FOR EACH ROW EXECUTE FUNCTION public.stall_reject_vendor_letter();

CREATE TRIGGER vendor_letters_no_truncate
    BEFORE TRUNCATE ON public.vendor_letters
    FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

CREATE TRIGGER vendor_letter_copies_apply_no_delete
    BEFORE INSERT ON public.vendor_letter_copies
    FOR EACH ROW EXECUTE FUNCTION public.stall_apply_vendor_letter_copy();

CREATE TRIGGER vendor_letter_copies_no_delete
    BEFORE UPDATE OR DELETE ON public.vendor_letter_copies
    FOR EACH ROW EXECUTE FUNCTION public.stall_guard_vendor_letter_copy();

CREATE TRIGGER vendor_letter_copies_no_truncate
    BEFORE TRUNCATE ON public.vendor_letter_copies
    FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();

ALTER TABLE public.vendor_letters
    ENABLE ALWAYS TRIGGER vendor_letters_no_delete,
    ENABLE ALWAYS TRIGGER vendor_letters_no_truncate;

ALTER TABLE public.vendor_letter_copies
    ENABLE ALWAYS TRIGGER vendor_letter_copies_apply_no_delete,
    ENABLE ALWAYS TRIGGER vendor_letter_copies_no_delete,
    ENABLE ALWAYS TRIGGER vendor_letter_copies_no_truncate;

ALTER TABLE ONLY public.vendor_letter_copies
    ADD CONSTRAINT vendor_letter_copies_letter_id_fkey FOREIGN KEY (letter_id) REFERENCES public.vendor_letters(id);
ALTER TABLE ONLY public.vendor_letter_copies
    ADD CONSTRAINT vendor_letter_copies_vendor_id_fkey FOREIGN KEY (vendor_id) REFERENCES public.vendors(id);

GRANT SELECT, INSERT ON TABLE public.vendor_letters TO stall_poster;
GRANT SELECT, INSERT ON TABLE public.vendor_letter_copies TO stall_poster;
GRANT UPDATE (sent_at) ON TABLE public.vendor_letter_copies TO stall_poster;
