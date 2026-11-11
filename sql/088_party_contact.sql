-- Party-owned contacts and addresses. Subtypes keep names only.

CREATE TABLE IF NOT EXISTS public.party_contacts (
    id uuid DEFAULT uuidv7() NOT NULL,
    party_id uuid NOT NULL,
    method text NOT NULL,
    label text DEFAULT 'primary'::text NOT NULL,
    value text NOT NULL,
    is_primary boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT party_contacts_pkey PRIMARY KEY (id),
    CONSTRAINT party_contacts_party_fkey FOREIGN KEY (party_id) REFERENCES public.parties (id) ON DELETE RESTRICT,
    CONSTRAINT party_contacts_method_chk CHECK ((method = ANY (ARRAY['phone'::text, 'email'::text]))),
    CONSTRAINT party_contacts_label_chk CHECK ((label = ANY (ARRAY['primary'::text, 'mobile'::text, 'home'::text, 'work'::text, 'other'::text]))),
    CONSTRAINT party_contacts_value_chk CHECK (((value = btrim(value)) AND (value <> ''::text))),
    CONSTRAINT party_contacts_phone_chk CHECK (((method <> 'phone'::text) OR (char_length(value) <= 40))),
    CONSTRAINT party_contacts_email_chk CHECK (((method <> 'email'::text) OR ((char_length(value) <= 200) AND (value ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'::text))))
);

CREATE UNIQUE INDEX IF NOT EXISTS party_contacts_primary_method_uidx
    ON public.party_contacts (party_id, method)
    WHERE (is_primary = true);

CREATE INDEX IF NOT EXISTS party_contacts_party_idx ON public.party_contacts USING btree (party_id);
CREATE INDEX IF NOT EXISTS party_contacts_email_lower_idx
    ON public.party_contacts USING btree (lower(value))
    WHERE (method = 'email'::text);

CREATE TABLE IF NOT EXISTS public.party_addresses (
    id uuid DEFAULT uuidv7() NOT NULL,
    party_id uuid NOT NULL,
    kind text NOT NULL,
    street text,
    street2 text,
    city text,
    state text,
    postal text,
    is_primary boolean DEFAULT false NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT party_addresses_pkey PRIMARY KEY (id),
    CONSTRAINT party_addresses_party_fkey FOREIGN KEY (party_id) REFERENCES public.parties (id) ON DELETE RESTRICT,
    CONSTRAINT party_addresses_kind_chk CHECK ((kind = ANY (ARRAY['physical'::text, 'mailing'::text]))),
    CONSTRAINT party_addresses_kind_uidx UNIQUE (party_id, kind),
    CONSTRAINT party_addresses_street_chk CHECK (((street IS NULL) OR ((street = btrim(street)) AND (street <> ''::text) AND (char_length(street) <= 200)))),
    CONSTRAINT party_addresses_street2_chk CHECK (((street2 IS NULL) OR ((street2 = btrim(street2)) AND (street2 <> ''::text) AND (char_length(street2) <= 200)))),
    CONSTRAINT party_addresses_city_chk CHECK (((city IS NULL) OR ((city = btrim(city)) AND (city <> ''::text) AND (char_length(city) <= 80)))),
    CONSTRAINT party_addresses_state_chk CHECK (((state IS NULL) OR ((state = btrim(state)) AND (state <> ''::text) AND (char_length(state) <= 40)))),
    CONSTRAINT party_addresses_postal_chk CHECK (((postal IS NULL) OR ((postal = btrim(postal)) AND (postal <> ''::text) AND (char_length(postal) <= 20)))),
    CONSTRAINT party_addresses_has_line_chk CHECK (
        (street IS NOT NULL) OR (street2 IS NOT NULL) OR (city IS NOT NULL) OR (state IS NOT NULL) OR (postal IS NOT NULL)
    )
);

CREATE INDEX IF NOT EXISTS party_addresses_party_idx ON public.party_addresses USING btree (party_id);

CREATE OR REPLACE FUNCTION public.stall_party_primary_email(target_party uuid) RETURNS text
    LANGUAGE sql STABLE
    SET search_path TO 'pg_catalog', 'public'
    AS $$
    SELECT contact.value
    FROM public.party_contacts AS contact
    WHERE contact.party_id = target_party
      AND contact.method = 'email'
      AND contact.is_primary
    LIMIT 1;
$$;

CREATE OR REPLACE FUNCTION public.stall_party_primary_phone(target_party uuid) RETURNS text
    LANGUAGE sql STABLE
    SET search_path TO 'pg_catalog', 'public'
    AS $$
    SELECT contact.value
    FROM public.party_contacts AS contact
    WHERE contact.party_id = target_party
      AND contact.method = 'phone'
      AND contact.is_primary
    LIMIT 1;
$$;

-- Backfill from subtypes before drop.
INSERT INTO public.party_contacts (party_id, method, label, value, is_primary)
SELECT person.party_id, 'phone', 'primary', person.phone, true
FROM public.persons AS person
WHERE person.phone IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM public.party_contacts AS contact
      WHERE contact.party_id = person.party_id AND contact.method = 'phone' AND contact.is_primary
  );

INSERT INTO public.party_contacts (party_id, method, label, value, is_primary)
SELECT person.party_id, 'email', 'primary', person.email, true
FROM public.persons AS person
WHERE person.email IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM public.party_contacts AS contact
      WHERE contact.party_id = person.party_id AND contact.method = 'email' AND contact.is_primary
  );

INSERT INTO public.party_contacts (party_id, method, label, value, is_primary)
SELECT org.party_id, 'phone', 'primary', org.phone, true
FROM public.organizations AS org
WHERE org.phone IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM public.party_contacts AS contact
      WHERE contact.party_id = org.party_id AND contact.method = 'phone' AND contact.is_primary
  );

INSERT INTO public.party_contacts (party_id, method, label, value, is_primary)
SELECT org.party_id, 'email', 'primary', org.email, true
FROM public.organizations AS org
WHERE org.email IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM public.party_contacts AS contact
      WHERE contact.party_id = org.party_id AND contact.method = 'email' AND contact.is_primary
  );

INSERT INTO public.party_addresses (party_id, kind, street, street2, city, state, postal, is_primary)
SELECT person.party_id, 'physical', person.address, NULL, person.city, person.state, person.postal, true
FROM public.persons AS person
WHERE person.address IS NOT NULL OR person.city IS NOT NULL OR person.state IS NOT NULL OR person.postal IS NOT NULL
ON CONFLICT (party_id, kind) DO NOTHING;

INSERT INTO public.party_addresses (party_id, kind, street, street2, city, state, postal, is_primary)
SELECT org.party_id, 'physical', org.address, NULL, NULL, NULL, NULL, true
FROM public.organizations AS org
WHERE org.address IS NOT NULL
ON CONFLICT (party_id, kind) DO NOTHING;

CREATE OR REPLACE FUNCTION public.stall_sync_vendor_display_name() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
DECLARE
    party uuid;
    display text;
    mail text;
BEGIN
    IF TG_TABLE_NAME = 'vendors' THEN
        SELECT public.stall_person_display_name(person.first_name, person.last_name, person.display_name),
               public.stall_party_primary_email(NEW.party_id)
        INTO NEW.name, NEW.email
        FROM public.persons AS person
        WHERE person.party_id = NEW.party_id;
        IF NEW.name IS NULL OR NEW.name = '' THEN
            RAISE EXCEPTION 'a vendor role needs a person'
                USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
    END IF;
    IF TG_TABLE_NAME = 'persons' THEN
        UPDATE public.vendors
        SET name = public.stall_person_display_name(NEW.first_name, NEW.last_name, NEW.display_name)
        WHERE party_id = NEW.party_id;
        RETURN NEW;
    END IF;
    IF TG_TABLE_NAME = 'party_contacts' THEN
        party := COALESCE(NEW.party_id, OLD.party_id);
        IF TG_OP = 'DELETE' OR (NEW.method = 'email') OR (OLD.method = 'email') THEN
            mail := public.stall_party_primary_email(party);
            UPDATE public.vendors
            SET email = mail
            WHERE party_id = party;
        END IF;
        RETURN COALESCE(NEW, OLD);
    END IF;
    RETURN COALESCE(NEW, OLD);
END;
$$;

-- Keep persons_sync_vendor_display_name (tenant DROP TRIGGER is refused).
-- Dropping persons.email later removes that column from its UPDATE OF list.
CREATE TRIGGER party_contacts_sync_vendor_email
    AFTER INSERT OR UPDATE OR DELETE ON public.party_contacts
    FOR EACH ROW EXECUTE FUNCTION public.stall_sync_vendor_display_name();

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

DROP VIEW IF EXISTS public.vendor_display;
CREATE VIEW public.vendor_display AS
 SELECT vendor.id AS vendor_id,
    vendor.party_id,
    vendor.code,
    vendor.name,
    person.first_name,
    person.last_name,
    person.display_name AS business_name,
    public.stall_party_primary_phone(vendor.party_id) AS phone,
    public.stall_party_primary_email(vendor.party_id) AS email,
    (
        SELECT addr.street
        FROM public.party_addresses AS addr
        WHERE addr.party_id = vendor.party_id AND addr.kind = 'physical'
        LIMIT 1
    ) AS address,
    (
        SELECT addr.city
        FROM public.party_addresses AS addr
        WHERE addr.party_id = vendor.party_id AND addr.kind = 'physical'
        LIMIT 1
    ) AS city,
    (
        SELECT addr.state
        FROM public.party_addresses AS addr
        WHERE addr.party_id = vendor.party_id AND addr.kind = 'physical'
        LIMIT 1
    ) AS state,
    (
        SELECT addr.postal
        FROM public.party_addresses AS addr
        WHERE addr.party_id = vendor.party_id AND addr.kind = 'physical'
        LIMIT 1
    ) AS postal,
    vendor.active,
    vendor.email_bounced,
    vendor.commission_bps,
    vendor.agreement_starts_on,
    vendor.agreement_ends_on,
    vendor.tax_id,
    vendor.notes,
    vendor.tax_exempt,
    vendor.tax_exempt_number,
    vendor.payout_mode,
    vendor.discount_by,
    vendor.keep_days,
    vendor.expiry_action
   FROM (public.vendors vendor
     JOIN public.persons person ON ((person.party_id = vendor.party_id)));

GRANT SELECT ON TABLE public.vendor_display TO stall_poster;
GRANT SELECT ON TABLE public.vendor_display TO stall_backup;

CREATE OR REPLACE FUNCTION public.stall_seed_vendor(
    vendor_id uuid,
    code text,
    first text,
    last text,
    commission_bps integer DEFAULT NULL,
    tax_exempt boolean DEFAULT false,
    tax_exempt_number text DEFAULT NULL,
    email text DEFAULT NULL,
    active boolean DEFAULT true,
    email_bounced boolean DEFAULT false,
    payout_mode text DEFAULT 'pay',
    phone text DEFAULT NULL,
    address text DEFAULT NULL,
    agreement_starts_on date DEFAULT NULL,
    keep_days integer DEFAULT NULL,
    discount_by text DEFAULT 'staff',
    expiry_action text DEFAULT 'return',
    display_name text DEFAULT NULL
) RETURNS uuid
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
DECLARE
    party uuid;
BEGIN
    INSERT INTO public.parties DEFAULT VALUES RETURNING parties.id INTO party;
    INSERT INTO public.persons (party_id, first_name, last_name, display_name)
    VALUES (party, first, last, display_name);
    IF phone IS NOT NULL AND btrim(phone) <> '' THEN
        INSERT INTO public.party_contacts (party_id, method, label, value, is_primary)
        VALUES (party, 'phone', 'primary', btrim(phone), true);
    END IF;
    IF email IS NOT NULL AND btrim(email) <> '' THEN
        INSERT INTO public.party_contacts (party_id, method, label, value, is_primary)
        VALUES (party, 'email', 'primary', btrim(email), true);
    END IF;
    IF address IS NOT NULL AND btrim(address) <> '' THEN
        INSERT INTO public.party_addresses (party_id, kind, street, is_primary)
        VALUES (party, 'physical', btrim(address), true);
    END IF;
    INSERT INTO public.party_roles (party_id, role_type, status)
    VALUES (party, 'vendor', 'active');
    INSERT INTO public.vendors (
        id,
        party_id,
        code,
        active,
        commission_bps,
        tax_exempt,
        tax_exempt_number,
        email_bounced,
        payout_mode,
        agreement_starts_on,
        keep_days,
        discount_by,
        expiry_action
    ) VALUES (
        vendor_id,
        party,
        code,
        active,
        commission_bps,
        tax_exempt,
        tax_exempt_number,
        email_bounced,
        payout_mode,
        agreement_starts_on,
        keep_days,
        discount_by,
        expiry_action
    );
    RETURN party;
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

CREATE OR REPLACE FUNCTION public.stall_seed_customer_person(
    first text,
    last text,
    target_party uuid DEFAULT NULL,
    email text DEFAULT NULL,
    phone text DEFAULT NULL,
    address text DEFAULT NULL,
    display_name text DEFAULT NULL
) RETURNS uuid
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
DECLARE
    chosen uuid := target_party;
BEGIN
    IF chosen IS NULL THEN
        INSERT INTO public.parties DEFAULT VALUES RETURNING parties.id INTO chosen;
    ELSIF NOT EXISTS (SELECT 1 FROM public.parties WHERE parties.id = chosen) THEN
        INSERT INTO public.parties (id) VALUES (chosen);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM public.persons AS person WHERE person.party_id = chosen)
       AND NOT EXISTS (SELECT 1 FROM public.organizations AS org WHERE org.party_id = chosen) THEN
        INSERT INTO public.persons (party_id, first_name, last_name, display_name)
        VALUES (chosen, first, last, display_name);
        IF phone IS NOT NULL AND btrim(phone) <> '' THEN
            INSERT INTO public.party_contacts (party_id, method, label, value, is_primary)
            VALUES (chosen, 'phone', 'primary', btrim(phone), true);
        END IF;
        IF email IS NOT NULL AND btrim(email) <> '' THEN
            INSERT INTO public.party_contacts (party_id, method, label, value, is_primary)
            VALUES (chosen, 'email', 'primary', btrim(email), true);
        END IF;
        IF address IS NOT NULL AND btrim(address) <> '' THEN
            INSERT INTO public.party_addresses (party_id, kind, street, is_primary)
            VALUES (chosen, 'physical', btrim(address), true);
        END IF;
    END IF;
    INSERT INTO public.party_roles (party_id, role_type, status)
    VALUES (chosen, 'customer', 'active')
    ON CONFLICT (party_id, role_type) DO NOTHING;
    RETURN chosen;
END;
$$;

CREATE TRIGGER party_contacts_no_truncate BEFORE TRUNCATE ON public.party_contacts
    FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();
ALTER TABLE public.party_contacts ENABLE ALWAYS TRIGGER party_contacts_no_truncate;

CREATE TRIGGER party_addresses_no_truncate BEFORE TRUNCATE ON public.party_addresses
    FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();
ALTER TABLE public.party_addresses ENABLE ALWAYS TRIGGER party_addresses_no_truncate;

REVOKE SELECT (party_id, email) ON TABLE public.persons FROM stall_vendor_entry;
GRANT SELECT (party_id, method, value, is_primary) ON TABLE public.party_contacts TO stall_vendor_entry;

GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.party_contacts TO stall_poster;
GRANT SELECT ON TABLE public.party_contacts TO stall_backup;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.party_addresses TO stall_poster;
GRANT SELECT ON TABLE public.party_addresses TO stall_backup;
GRANT EXECUTE ON FUNCTION public.stall_party_primary_email(uuid) TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_party_primary_email(uuid) TO stall_vendor_entry;
GRANT EXECUTE ON FUNCTION public.stall_party_primary_phone(uuid) TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_seed_vendor(uuid, text, text, text, integer, boolean, text, text, boolean, boolean, text, text, text, date, integer, text, text, text) TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_seed_wait_person(text, text, text, text, uuid, numeric, numeric, text, text) TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_seed_customer_person(text, text, uuid, text, text, text, text) TO stall_poster;

ALTER TABLE public.persons DROP COLUMN IF EXISTS phone;
-- CASCADE removes persons_sync_vendor_display_name (UPDATE OF email); recreate without email.
ALTER TABLE public.persons DROP COLUMN IF EXISTS email CASCADE;
CREATE TRIGGER persons_sync_vendor_display_name
    AFTER INSERT OR UPDATE OF first_name, last_name, display_name ON public.persons
    FOR EACH ROW EXECUTE FUNCTION public.stall_sync_vendor_display_name();
ALTER TABLE public.persons DROP COLUMN IF EXISTS address;
ALTER TABLE public.persons DROP COLUMN IF EXISTS city;
ALTER TABLE public.persons DROP COLUMN IF EXISTS state;
ALTER TABLE public.persons DROP COLUMN IF EXISTS postal;

ALTER TABLE public.organizations DROP COLUMN IF EXISTS phone;
ALTER TABLE public.organizations DROP COLUMN IF EXISTS email;
ALTER TABLE public.organizations DROP COLUMN IF EXISTS address;
