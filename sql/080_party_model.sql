-- Party model: identity on persons/organizations; vendor/customer/wait_list are roles.
-- Existing tenant data is not preserved (wipe/reseed). Safe on empty or demo tenants.

CREATE TABLE IF NOT EXISTS public.parties (
    id uuid DEFAULT uuidv7() NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT parties_pkey PRIMARY KEY (id)
);

CREATE TABLE IF NOT EXISTS public.persons (
    party_id uuid NOT NULL,
    first_name text NOT NULL,
    last_name text NOT NULL,
    display_name text,
    phone text,
    email text,
    address text,
    CONSTRAINT persons_pkey PRIMARY KEY (party_id),
    CONSTRAINT persons_party_fkey FOREIGN KEY (party_id) REFERENCES public.parties (id) ON DELETE RESTRICT,
    CONSTRAINT persons_first_name_chk CHECK (((first_name = btrim(first_name)) AND (first_name <> ''::text))),
    CONSTRAINT persons_last_name_chk CHECK (((last_name = btrim(last_name)) AND (last_name <> ''::text))),
    CONSTRAINT persons_display_name_chk CHECK (((display_name IS NULL) OR ((display_name = btrim(display_name)) AND (display_name <> ''::text) AND (char_length(display_name) <= 120)))),
    CONSTRAINT persons_phone_chk CHECK (((phone IS NULL) OR ((phone = btrim(phone)) AND (phone <> ''::text) AND (char_length(phone) <= 40)))),
    CONSTRAINT persons_email_chk CHECK (((email IS NULL) OR ((email = btrim(email)) AND (email ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'::text) AND (char_length(email) <= 200)))),
    CONSTRAINT persons_address_chk CHECK (((address IS NULL) OR ((address = btrim(address)) AND (address <> ''::text) AND (char_length(address) <= 400))))
);

CREATE TABLE IF NOT EXISTS public.organizations (
    party_id uuid NOT NULL,
    legal_name text NOT NULL,
    dba_name text,
    phone text,
    email text,
    address text,
    CONSTRAINT organizations_pkey PRIMARY KEY (party_id),
    CONSTRAINT organizations_party_fkey FOREIGN KEY (party_id) REFERENCES public.parties (id) ON DELETE RESTRICT,
    CONSTRAINT organizations_legal_name_chk CHECK (((legal_name = btrim(legal_name)) AND (legal_name <> ''::text) AND (char_length(legal_name) <= 120))),
    CONSTRAINT organizations_dba_name_chk CHECK (((dba_name IS NULL) OR ((dba_name = btrim(dba_name)) AND (dba_name <> ''::text) AND (char_length(dba_name) <= 120)))),
    CONSTRAINT organizations_phone_chk CHECK (((phone IS NULL) OR ((phone = btrim(phone)) AND (phone <> ''::text) AND (char_length(phone) <= 40)))),
    CONSTRAINT organizations_email_chk CHECK (((email IS NULL) OR ((email = btrim(email)) AND (email ~ '^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$'::text) AND (char_length(email) <= 200)))),
    CONSTRAINT organizations_address_chk CHECK (((address IS NULL) OR ((address = btrim(address)) AND (address <> ''::text) AND (char_length(address) <= 400))))
);

CREATE TABLE IF NOT EXISTS public.party_roles (
    id uuid DEFAULT uuidv7() NOT NULL,
    party_id uuid NOT NULL,
    role_type text NOT NULL,
    status text DEFAULT 'active'::text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT party_roles_pkey PRIMARY KEY (id),
    CONSTRAINT party_roles_party_fkey FOREIGN KEY (party_id) REFERENCES public.parties (id) ON DELETE RESTRICT,
    CONSTRAINT party_roles_party_type_key UNIQUE (party_id, role_type),
    CONSTRAINT party_roles_type_chk CHECK ((role_type = ANY (ARRAY['vendor'::text, 'customer'::text, 'wait_list'::text]))),
    CONSTRAINT party_roles_status_chk CHECK ((status = ANY (ARRAY['active'::text, 'inactive'::text])))
);

CREATE INDEX IF NOT EXISTS party_roles_type_status_idx ON public.party_roles USING btree (role_type, status);

CREATE OR REPLACE FUNCTION public.stall_person_display_name(first_name text, last_name text, display_name text DEFAULT NULL::text) RETURNS text
    LANGUAGE sql IMMUTABLE PARALLEL SAFE
    SET search_path TO 'pg_catalog', 'public'
    AS $$
    SELECT CASE
        WHEN display_name IS NOT NULL AND btrim(display_name) <> '' THEN btrim(display_name)
        WHEN first_name = last_name THEN first_name
        ELSE btrim(first_name || ' ' || last_name)
    END;
$$;

CREATE OR REPLACE FUNCTION public.stall_organization_display_name(legal_name text, dba_name text DEFAULT NULL::text) RETURNS text
    LANGUAGE sql IMMUTABLE PARALLEL SAFE
    SET search_path TO 'pg_catalog', 'public'
    AS $$
    SELECT COALESCE(NULLIF(btrim(dba_name), ''), btrim(legal_name));
$$;

CREATE OR REPLACE FUNCTION public.stall_party_display_name(target_party uuid) RETURNS text
    LANGUAGE sql STABLE
    SET search_path TO 'pg_catalog', 'public'
    AS $$
    SELECT COALESCE(
        (SELECT public.stall_person_display_name(p.first_name, p.last_name, p.display_name)
         FROM public.persons p WHERE p.party_id = target_party),
        (SELECT public.stall_organization_display_name(o.legal_name, o.dba_name)
         FROM public.organizations o WHERE o.party_id = target_party),
        ''
    );
$$;

CREATE OR REPLACE FUNCTION public.stall_assert_party_subtype() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF TG_TABLE_NAME = 'persons' AND EXISTS (SELECT 1 FROM public.organizations WHERE party_id = NEW.party_id) THEN
        RAISE EXCEPTION 'a party is a person or an organization, not both'
            USING ERRCODE = '23514';
    END IF;
    IF TG_TABLE_NAME = 'organizations' AND EXISTS (SELECT 1 FROM public.persons WHERE party_id = NEW.party_id) THEN
        RAISE EXCEPTION 'a party is a person or an organization, not both'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS persons_one_subtype ON public.persons;
CREATE TRIGGER persons_one_subtype BEFORE INSERT OR UPDATE ON public.persons
    FOR EACH ROW EXECUTE FUNCTION public.stall_assert_party_subtype();

DROP TRIGGER IF EXISTS organizations_one_subtype ON public.organizations;
CREATE TRIGGER organizations_one_subtype BEFORE INSERT OR UPDATE ON public.organizations
    FOR EACH ROW EXECUTE FUNCTION public.stall_assert_party_subtype();

CREATE OR REPLACE FUNCTION public.stall_assert_vendor_role_person() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF NEW.role_type = 'vendor' AND NEW.status = 'active' THEN
        IF NOT EXISTS (SELECT 1 FROM public.persons WHERE party_id = NEW.party_id) THEN
            RAISE EXCEPTION 'a vendor role needs a person'
                USING ERRCODE = '23514';
        END IF;
        IF EXISTS (SELECT 1 FROM public.organizations WHERE party_id = NEW.party_id) THEN
            RAISE EXCEPTION 'a vendor role needs a person'
                USING ERRCODE = '23514';
        END IF;
    END IF;
    IF NEW.role_type = 'wait_list' AND NEW.status = 'active' THEN
        IF NOT EXISTS (SELECT 1 FROM public.persons WHERE party_id = NEW.party_id) THEN
            RAISE EXCEPTION 'a wait list role needs a person'
                USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS party_roles_vendor_person ON public.party_roles;
CREATE TRIGGER party_roles_vendor_person BEFORE INSERT OR UPDATE ON public.party_roles
    FOR EACH ROW EXECUTE FUNCTION public.stall_assert_vendor_role_person();

-- Backfill vendors that still hold identity columns into parties/persons/roles.
DO $$
DECLARE
    row record;
    party uuid;
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'vendors' AND column_name = 'first_name'
    ) AND NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'vendors' AND column_name = 'party_id'
    ) THEN
        ALTER TABLE public.vendors ADD COLUMN party_id uuid;
        FOR row IN SELECT * FROM public.vendors LOOP
            INSERT INTO public.parties DEFAULT VALUES RETURNING id INTO party;
            INSERT INTO public.persons (party_id, first_name, last_name, display_name, phone, email, address)
            VALUES (
                party,
                row.first_name,
                row.last_name,
                row.business_name,
                row.phone,
                row.email,
                row.address
            );
            INSERT INTO public.party_roles (party_id, role_type, status)
            VALUES (party, 'vendor', CASE WHEN row.active THEN 'active' ELSE 'inactive' END);
            UPDATE public.vendors SET party_id = party WHERE id = row.id;
        END LOOP;
        ALTER TABLE public.vendors ALTER COLUMN party_id SET NOT NULL;
        ALTER TABLE public.vendors ADD CONSTRAINT vendors_party_id_key UNIQUE (party_id);
        ALTER TABLE public.vendors
            ADD CONSTRAINT vendors_party_fkey FOREIGN KEY (party_id) REFERENCES public.parties (id) ON DELETE RESTRICT;
        -- Replace generated name with denormalized display synced from persons (reports keep vendor.name).
        DROP FUNCTION IF EXISTS public.stall_payout_run_quote(uuid, uuid);
        DROP FUNCTION IF EXISTS public.stall_payout_quote(uuid, date, date, date, date);
        DROP FUNCTION IF EXISTS public.stall_payout_pack(uuid, date, date, date, date);
        DROP VIEW IF EXISTS public.payout_pack;
        -- email stays on vendors as a synced copy: stall_apply_vendor_letter_copy is bound to
        -- vendor_letter_copies_apply_no_delete and cannot be CREATE OR REPLACE'd to join persons.
        ALTER TABLE public.vendors DROP COLUMN IF EXISTS name;
        ALTER TABLE public.vendors DROP COLUMN IF EXISTS first_name;
        ALTER TABLE public.vendors DROP COLUMN IF EXISTS last_name;
        ALTER TABLE public.vendors DROP COLUMN IF EXISTS business_name;
        ALTER TABLE public.vendors DROP COLUMN IF EXISTS phone;
        ALTER TABLE public.vendors DROP COLUMN IF EXISTS address;
        ALTER TABLE public.vendors ADD COLUMN name text NOT NULL DEFAULT '';
        UPDATE public.vendors AS vendor
        SET name = public.stall_person_display_name(person.first_name, person.last_name, person.display_name),
            email = person.email
        FROM public.persons AS person
        WHERE person.party_id = vendor.party_id;
        ALTER TABLE public.vendors ALTER COLUMN name DROP DEFAULT;
    ELSIF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'vendors' AND column_name = 'party_id'
    ) THEN
        ALTER TABLE public.vendors ADD COLUMN party_id uuid NOT NULL;
        ALTER TABLE public.vendors ADD CONSTRAINT vendors_party_id_key UNIQUE (party_id);
        ALTER TABLE public.vendors
            ADD CONSTRAINT vendors_party_fkey FOREIGN KEY (party_id) REFERENCES public.parties (id) ON DELETE RESTRICT;
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION public.stall_refuse_duplicate_vendor_code() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM public.vendors AS other
        WHERE other.code = NEW.code AND other.id IS DISTINCT FROM NEW.id
    ) THEN
        RAISE EXCEPTION 'That code is already used.'
            USING ERRCODE = '23505';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS vendors_refuse_duplicate_code ON public.vendors;
CREATE TRIGGER vendors_refuse_duplicate_code BEFORE INSERT OR UPDATE OF code ON public.vendors
    FOR EACH ROW EXECUTE FUNCTION public.stall_refuse_duplicate_vendor_code();

CREATE OR REPLACE FUNCTION public.stall_assert_vendor_extension_role() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM public.party_roles
        WHERE party_id = NEW.party_id AND role_type = 'vendor' AND status = 'active'
    ) THEN
        RAISE EXCEPTION 'a vendor row needs an active vendor role'
            USING ERRCODE = '23514';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM public.persons WHERE party_id = NEW.party_id) THEN
        RAISE EXCEPTION 'a vendor role needs a person'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS vendors_require_role ON public.vendors;
CREATE TRIGGER vendors_require_role BEFORE INSERT OR UPDATE OF party_id ON public.vendors
    FOR EACH ROW EXECUTE FUNCTION public.stall_assert_vendor_extension_role();

CREATE OR REPLACE FUNCTION public.stall_sync_vendor_display_name() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF TG_TABLE_NAME = 'vendors' THEN
        SELECT public.stall_person_display_name(person.first_name, person.last_name, person.display_name),
               person.email
        INTO NEW.name, NEW.email
        FROM public.persons AS person
        WHERE person.party_id = NEW.party_id;
        IF NEW.name IS NULL OR NEW.name = '' THEN
            RAISE EXCEPTION 'a vendor role needs a person'
                USING ERRCODE = '23514';
        END IF;
        RETURN NEW;
    END IF;
    UPDATE public.vendors
    SET name = public.stall_person_display_name(NEW.first_name, NEW.last_name, NEW.display_name),
        email = NEW.email
    WHERE party_id = NEW.party_id;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS vendors_sync_display_name ON public.vendors;
CREATE TRIGGER vendors_sync_display_name BEFORE INSERT OR UPDATE OF party_id ON public.vendors
    FOR EACH ROW EXECUTE FUNCTION public.stall_sync_vendor_display_name();

DROP TRIGGER IF EXISTS persons_sync_vendor_display_name ON public.persons;
CREATE TRIGGER persons_sync_vendor_display_name
    AFTER INSERT OR UPDATE OF first_name, last_name, display_name, email ON public.persons
    FOR EACH ROW EXECUTE FUNCTION public.stall_sync_vendor_display_name();

-- Tickets: customer is a party with customer role.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'tickets' AND column_name = 'customer_id'
    ) THEN
        IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'tickets_customer_id_fkey') THEN
            ALTER TABLE public.tickets DROP CONSTRAINT tickets_customer_id_fkey;
        END IF;
        ALTER TABLE public.tickets DROP COLUMN customer_id;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'tickets' AND column_name = 'customer_party_id'
    ) THEN
        ALTER TABLE public.tickets ADD COLUMN customer_party_id uuid;
        ALTER TABLE public.tickets
            ADD CONSTRAINT tickets_customer_party_id_fkey
            FOREIGN KEY (customer_party_id) REFERENCES public.parties (id) ON DELETE RESTRICT;
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION public.stall_assert_ticket_customer_role() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF NEW.customer_party_id IS NULL THEN
        RETURN NEW;
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM public.party_roles
        WHERE party_id = NEW.customer_party_id AND role_type = 'customer' AND status = 'active'
    ) THEN
        RAISE EXCEPTION 'Choose a customer to hold a layaway.'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS tickets_customer_role ON public.tickets;
CREATE TRIGGER tickets_customer_role BEFORE INSERT OR UPDATE OF customer_party_id ON public.tickets
    FOR EACH ROW EXECUTE FUNCTION public.stall_assert_ticket_customer_role();

DROP TABLE IF EXISTS public.customers CASCADE;

-- Wait list: extension on party_id (person + wait_list role).
-- Append-only truncate triggers cannot be dropped; reshape the empty table in place.
DO $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'wait_list' AND column_name = 'first_name'
    ) THEN
        IF EXISTS (SELECT 1 FROM public.wait_list) THEN
            RAISE EXCEPTION 'wait_list still has rows; wipe the tenant before party cutover';
        END IF;
        IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'wait_list_pkey') THEN
            ALTER TABLE public.wait_list DROP CONSTRAINT wait_list_pkey;
        END IF;
        IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'wait_list_first_name_chk') THEN
            ALTER TABLE public.wait_list DROP CONSTRAINT wait_list_first_name_chk;
        END IF;
        IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'wait_list_last_name_chk') THEN
            ALTER TABLE public.wait_list DROP CONSTRAINT wait_list_last_name_chk;
        END IF;
        IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'wait_list_email_chk') THEN
            ALTER TABLE public.wait_list DROP CONSTRAINT wait_list_email_chk;
        END IF;
        IF EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'wait_list_phone_chk') THEN
            ALTER TABLE public.wait_list DROP CONSTRAINT wait_list_phone_chk;
        END IF;
        DROP INDEX IF EXISTS wait_list_open_email_key;
        ALTER TABLE public.wait_list ADD COLUMN party_id uuid;
        ALTER TABLE public.wait_list DROP COLUMN IF EXISTS id;
        ALTER TABLE public.wait_list DROP COLUMN IF EXISTS first_name;
        ALTER TABLE public.wait_list DROP COLUMN IF EXISTS last_name;
        ALTER TABLE public.wait_list DROP COLUMN IF EXISTS email;
        ALTER TABLE public.wait_list DROP COLUMN IF EXISTS phone;
        ALTER TABLE public.wait_list ALTER COLUMN party_id SET NOT NULL;
        ALTER TABLE public.wait_list ADD CONSTRAINT wait_list_pkey PRIMARY KEY (party_id);
        ALTER TABLE public.wait_list
            ADD CONSTRAINT wait_list_party_fkey FOREIGN KEY (party_id) REFERENCES public.parties (id) ON DELETE RESTRICT;
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION public.stall_assert_wait_list_role() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM public.party_roles
        WHERE party_id = NEW.party_id AND role_type = 'wait_list' AND status = 'active'
    ) THEN
        RAISE EXCEPTION 'a wait list row needs an active wait list role'
            USING ERRCODE = '23514';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM public.persons WHERE party_id = NEW.party_id) THEN
        RAISE EXCEPTION 'a wait list role needs a person'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS wait_list_require_role ON public.wait_list;
CREATE TRIGGER wait_list_require_role BEFORE INSERT OR UPDATE OF party_id ON public.wait_list
    FOR EACH ROW EXECUTE FUNCTION public.stall_assert_wait_list_role();

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
    -- Same open address waits on the other session, then one of them is refused.
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

DROP TRIGGER IF EXISTS wait_list_open_email ON public.wait_list;
CREATE TRIGGER wait_list_open_email BEFORE INSERT OR UPDATE ON public.wait_list
    FOR EACH ROW EXECUTE FUNCTION public.stall_assert_wait_list_open_email();

DROP INDEX IF EXISTS wait_list_open_email_key;

-- Missing FKs (conname checks must be schema-scoped; leftover tenants share names).
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint AS constraint_row
        JOIN pg_class AS relation ON relation.oid = constraint_row.conrelid
        JOIN pg_namespace AS namespace ON namespace.oid = relation.relnamespace
        WHERE constraint_row.conname = 'commission_rules_vendor_id_fkey'
          AND namespace.nspname = current_schema()
    ) THEN
        ALTER TABLE public.commission_rules
            ADD CONSTRAINT commission_rules_vendor_id_fkey
            FOREIGN KEY (vendor_id) REFERENCES public.vendors (id) ON DELETE RESTRICT;
    END IF;
    IF NOT EXISTS (
        SELECT 1
        FROM pg_constraint AS constraint_row
        JOIN pg_class AS relation ON relation.oid = constraint_row.conrelid
        JOIN pg_namespace AS namespace ON namespace.oid = relation.relnamespace
        WHERE constraint_row.conname = 'vendor_entry_tokens_vendor_id_fkey'
          AND namespace.nspname = current_schema()
    ) THEN
        ALTER TABLE public.vendor_entry_tokens
            ADD CONSTRAINT vendor_entry_tokens_vendor_id_fkey
            FOREIGN KEY (vendor_id) REFERENCES public.vendors (id) ON DELETE RESTRICT;
    END IF;
END;
$$;

CREATE OR REPLACE VIEW public.vendor_display AS
 SELECT vendor.id AS vendor_id,
    vendor.party_id,
    vendor.code,
    vendor.name,
    person.first_name,
    person.last_name,
    person.display_name AS business_name,
    person.phone,
    person.email,
    person.address,
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

-- stall_apply_vendor_letter_copy is bound to vendor_letter_copies_apply_no_delete;
-- CREATE OR REPLACE is refused. Party-aware body lives in 001_tenant.sql.

CREATE OR REPLACE VIEW public.payout_pack AS
WITH params AS (
         SELECT run.id AS run_id,
            run.sales_from,
            run.sales_to,
            run.rent_month,
            run.posting_date,
            vendor.id AS vendor_id,
            vendor.code,
            vendor.name AS name,
            vendor.payout_mode AS mode
           FROM (public.stall_pack_runs() run(id, sales_from, sales_to, rent_month, posting_date)
             JOIN public.vendors vendor ON ((vendor.active AND ((public.stall_pack_vendor_id() IS NULL) OR (vendor.id = public.stall_pack_vendor_id())))))
        ), own AS (
         SELECT run.id AS run_id,
            journal.id AS journal_id
           FROM (public.stall_pack_runs() run(id, sales_from, sales_to, rent_month, posting_date)
             JOIN public.journals journal ON (((journal.posting_key ~~ (('settlement:'::text || (run.id)::text) || ':%'::text)) OR (journal.posting_key ~~ (('disbursement:'::text || (run.id)::text) || ':%'::text)))))
        UNION
         SELECT link.run_id,
            link.journal_id
           FROM public.payout_run_journals link
        ), pay AS (
         SELECT params.run_id,
            params.vendor_id,
            COALESCE(sum((line.credit_minor - line.debit_minor)) FILTER (WHERE (journal.posting_date < params.sales_from)), (0)::numeric) AS brought_forward_minor,
            COALESCE(sum((line.credit_minor - line.debit_minor)) FILTER (WHERE (journal.posting_date >= params.sales_from)), (0)::numeric) AS movement_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE ((journal.source_type = 'item_return'::text) AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS return_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE ((journal.source_type = 'sale'::text) AND journal.is_reversal AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS void_minor,
            COALESCE(sum(line.debit_minor) FILTER (WHERE ((journal.source_type = 'sale'::text) AND (NOT journal.is_reversal) AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS draw_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE ((journal.source_type = ANY (ARRAY['check_disbursement'::text, 'cash_disbursement'::text, 'safe_disbursement'::text])) AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS check_paid_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE ((journal.source_type = 'settlement'::text) AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS settlement_applied_minor
           FROM (((params
             JOIN public.journal_lines line ON (((line.subledger_type = 'vendor'::text) AND (line.subledger_ref = (params.vendor_id)::text))))
             JOIN public.journals journal ON ((journal.id = line.journal_id)))
             JOIN public.chart_roles role ON (((role.account_id = line.account_id) AND (role.role = 'vendor_payable'::text))))
          WHERE (NOT (EXISTS ( SELECT 1
                   FROM own
                  WHERE ((own.run_id = params.run_id) AND (own.journal_id = journal.id)))))
          GROUP BY params.run_id, params.vendor_id
        ), sale AS (
         SELECT payout_pack_sales.run_id,
            payout_pack_sales.vendor_id,
            COALESCE(sum((payout_pack_sales.price_minor * payout_pack_sales.quantity)), (0)::numeric) AS sales_minor,
            COALESCE(sum(payout_pack_sales.discount_minor), (0)::numeric) AS discount_minor,
            COALESCE(sum(payout_pack_sales.commission_minor), (0)::numeric) AS commission_minor,
            COALESCE(sum(payout_pack_sales.tax_minor), (0)::numeric) AS tax_minor
           FROM public.payout_pack_sales
          GROUP BY payout_pack_sales.run_id, payout_pack_sales.vendor_id
        ), rent_posted AS (
         SELECT params.run_id,
            params.vendor_id,
            COALESCE(sum((line.debit_minor - line.credit_minor)), (0)::numeric) AS total_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE (journal.posting_date < params.sales_from)), (0)::numeric) AS before_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE ((journal.source_type = 'rent_charge'::text) AND (public.stall_rent_period_start(journal.posting_date) = params.rent_month))), (0)::numeric) AS month_minor,
            COALESCE(sum((line.credit_minor - line.debit_minor)) FILTER (WHERE ((journal.source_type = 'rent_payment'::text) AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS paid_minor,
            COALESCE(sum((line.credit_minor - line.debit_minor)) FILTER (WHERE ((journal.source_type = 'settlement'::text) AND ((journal.posting_date >= params.sales_from) AND (journal.posting_date <= params.sales_to)))), (0)::numeric) AS settlement_minor
           FROM (((params
             JOIN public.journal_lines line ON ((line.subledger_type = 'booth'::text)))
             JOIN public.journals journal ON ((journal.id = line.journal_id)))
             JOIN public.chart_roles role ON (((role.account_id = line.account_id) AND (role.role = 'booth_rent_receivable'::text))))
          WHERE ((EXISTS ( SELECT 1
                   FROM public.booth_assignments assignment
                  WHERE ((assignment.vendor_id = params.vendor_id) AND (assignment.booth_id = (line.subledger_ref)::uuid) AND (assignment.assignment_range @> journal.posting_date)))) AND (NOT (EXISTS ( SELECT 1
                   FROM own
                  WHERE ((own.run_id = params.run_id) AND (own.journal_id = journal.id))))))
          GROUP BY params.run_id, params.vendor_id
        ), missing_month AS (
         SELECT payout_missing_rent.run_id,
            payout_missing_rent.vendor_id,
            payout_missing_rent.month_start,
            payout_missing_rent.posting_date,
            payout_missing_rent.amount
           FROM public.payout_missing_rent
        ), missing AS (
         SELECT missing_month.run_id,
            missing_month.vendor_id,
            COALESCE(sum(missing_month.amount), (0)::numeric) AS total_minor,
            COALESCE(sum(missing_month.amount) FILTER (WHERE (missing_month.posting_date < params.sales_from)), (0)::numeric) AS before_minor,
            COALESCE(sum(missing_month.amount) FILTER (WHERE (missing_month.month_start = params.rent_month)), (0)::numeric) AS month_minor
           FROM (missing_month
             JOIN params ON (((params.run_id = missing_month.run_id) AND (params.vendor_id = missing_month.vendor_id))))
          GROUP BY missing_month.run_id, missing_month.vendor_id
        ), own_rent AS (
         SELECT params.run_id,
            params.vendor_id,
            COALESCE(sum((line.debit_minor - line.credit_minor)), (0)::numeric) AS total_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE (journal.posting_date < params.sales_from)), (0)::numeric) AS before_minor,
            COALESCE(sum((line.debit_minor - line.credit_minor)) FILTER (WHERE ((journal.source_type = 'rent_charge'::text) AND (public.stall_rent_period_start(journal.posting_date) = params.rent_month))), (0)::numeric) AS month_minor
           FROM ((((params
             JOIN public.payout_run_journals link ON (((link.run_id = params.run_id) AND (link.role = 'rent_charge'::text))))
             JOIN public.journals journal ON ((journal.id = link.journal_id)))
             JOIN public.journal_lines line ON (((line.journal_id = journal.id) AND (line.subledger_type = 'booth'::text))))
             JOIN public.chart_roles role ON (((role.account_id = line.account_id) AND (role.role = 'booth_rent_receivable'::text))))
          WHERE (EXISTS ( SELECT 1
                   FROM public.booth_assignments assignment
                  WHERE ((assignment.vendor_id = params.vendor_id) AND (assignment.booth_id = (line.subledger_ref)::uuid) AND (assignment.assignment_range @> journal.posting_date))))
          GROUP BY params.run_id, params.vendor_id
        ), figures AS (
         SELECT params.run_id,
            params.vendor_id,
            params.code,
            params.name,
            params.mode,
            params.posting_date,
            COALESCE(sale.sales_minor, (0)::numeric) AS sales_minor,
            COALESCE(sale.commission_minor, (0)::numeric) AS commission_minor,
            COALESCE(sale.tax_minor, (0)::numeric) AS tax_minor,
            COALESCE(sale.discount_minor, (0)::numeric) AS discount_minor,
            COALESCE(pay.return_minor, (0)::numeric) AS return_minor,
            COALESCE(pay.void_minor, (0)::numeric) AS void_minor,
            COALESCE(pay.draw_minor, (0)::numeric) AS draw_minor,
            COALESCE(pay.check_paid_minor, (0)::numeric) AS check_paid_minor,
            COALESCE(pay.settlement_applied_minor, (0)::numeric) AS settlement_applied_minor,
            COALESCE(pay.brought_forward_minor, (0)::numeric) AS brought_forward_minor,
            (COALESCE(pay.brought_forward_minor, (0)::numeric) + COALESCE(pay.movement_minor, (0)::numeric)) AS carried_payable_minor,
            ((COALESCE(rent_posted.before_minor, (0)::numeric) + COALESCE(missing.before_minor, (0)::numeric)) + COALESCE(own_rent.before_minor, (0)::numeric)) AS rent_brought_forward_minor,
            ((COALESCE(rent_posted.month_minor, (0)::numeric) + COALESCE(missing.month_minor, (0)::numeric)) + COALESCE(own_rent.month_minor, (0)::numeric)) AS rent_minor,
            COALESCE(rent_posted.paid_minor, (0)::numeric) AS rent_paid_since_minor,
            COALESCE(rent_posted.settlement_minor, (0)::numeric) AS rent_settlement_minor,
            ((COALESCE(rent_posted.total_minor, (0)::numeric) + COALESCE(missing.total_minor, (0)::numeric)) + COALESCE(own_rent.total_minor, (0)::numeric)) AS rent_total_minor
           FROM (((((params
             LEFT JOIN pay ON (((pay.run_id = params.run_id) AND (pay.vendor_id = params.vendor_id))))
             LEFT JOIN sale ON (((sale.run_id = params.run_id) AND (sale.vendor_id = params.vendor_id))))
             LEFT JOIN rent_posted ON (((rent_posted.run_id = params.run_id) AND (rent_posted.vendor_id = params.vendor_id))))
             LEFT JOIN missing ON (((missing.run_id = params.run_id) AND (missing.vendor_id = params.vendor_id))))
             LEFT JOIN own_rent ON (((own_rent.run_id = params.run_id) AND (own_rent.vendor_id = params.vendor_id))))
        )
 SELECT figures.run_id,
    figures.vendor_id,
    figures.code,
    figures.name,
    COALESCE(spot.booth_code, ''::text) AS booth,
    figures.mode,
    figures.sales_minor,
    figures.commission_minor,
    figures.tax_minor,
    figures.discount_minor,
    figures.return_minor,
    figures.void_minor,
    figures.draw_minor,
    figures.check_paid_minor,
    figures.settlement_applied_minor,
    figures.rent_minor,
    ((((figures.rent_total_minor - figures.rent_brought_forward_minor) - figures.rent_minor) + figures.rent_paid_since_minor) + figures.rent_settlement_minor) AS rent_charged_minor,
    figures.rent_brought_forward_minor,
    figures.rent_paid_since_minor,
    ((figures.carried_payable_minor - figures.brought_forward_minor) - (((((((figures.sales_minor - figures.discount_minor) - figures.commission_minor) - figures.return_minor) - figures.void_minor) - figures.draw_minor) - figures.check_paid_minor) - figures.settlement_applied_minor)) AS adjustment_minor,
    figures.brought_forward_minor,
    figures.carried_payable_minor,
    figures.rent_total_minor AS carried_rent_minor,
        CASE
            WHEN (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) THEN LEAST(GREATEST(figures.carried_payable_minor, (0)::numeric), GREATEST(figures.rent_total_minor, (0)::numeric))
            ELSE (0)::numeric
        END AS settled_minor,
        CASE
            WHEN (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) THEN GREATEST((figures.carried_payable_minor - figures.rent_total_minor), (0)::numeric)
            ELSE GREATEST(figures.carried_payable_minor, (0)::numeric)
        END AS check_minor,
        CASE
            WHEN (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) THEN GREATEST((figures.rent_total_minor - figures.carried_payable_minor), (0)::numeric)
            ELSE GREATEST(figures.rent_total_minor, (0)::numeric)
        END AS owes_minor,
        CASE
            WHEN (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) THEN (figures.carried_payable_minor - figures.rent_total_minor)
            ELSE figures.carried_payable_minor
        END AS net_minor,
    figures.rent_brought_forward_minor AS prior,
    (((figures.rent_total_minor - figures.rent_brought_forward_minor) + figures.rent_paid_since_minor) + figures.rent_settlement_minor) AS charged,
    (figures.rent_paid_since_minor + figures.rent_settlement_minor) AS paid,
    figures.carried_payable_minor AS payable,
    figures.rent_total_minor AS rent,
        CASE
            WHEN (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) THEN GREATEST((figures.carried_payable_minor - figures.rent_total_minor), (0)::numeric)
            ELSE GREATEST(figures.carried_payable_minor, (0)::numeric)
        END AS "check",
        CASE
            WHEN (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) THEN GREATEST((figures.rent_total_minor - figures.carried_payable_minor), (0)::numeric)
            ELSE GREATEST(figures.rent_total_minor, (0)::numeric)
        END AS owes,
    (mall_settings.booth_rentals_enabled AND mall_settings.rent_prepaid_from_prior_sales) AS rent_from_sales,
    spot.booth_id
   FROM ((figures
     CROSS JOIN public.mall mall_settings)
     LEFT JOIN LATERAL ( SELECT booth.id AS booth_id,
            booth.code AS booth_code
           FROM (public.booth_assignments assignment
             JOIN public.booths booth ON ((booth.id = assignment.booth_id)))
          WHERE ((assignment.vendor_id = figures.vendor_id) AND (assignment.assignment_range @> figures.posting_date))
          ORDER BY booth.code
         LIMIT 1) spot ON (true));

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

CREATE OR REPLACE FUNCTION public.stall_propose_markdowns(on_date date) RETURNS integer
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'public'
    AS $$
DECLARE
    filed integer := 0;
    piece record;
    next_step uuid;
    original bigint;
    proposed bigint;
BEGIN
    IF on_date IS NULL THEN
        RAISE EXCEPTION 'a markdown needs a day'
            USING ERRCODE = '23514';
    END IF;

    FOR piece IN
        SELECT item.id
        FROM public.items AS item
        WHERE item.placement = 'consigned'
          AND item.quantity > 0
          AND item.held IS DISTINCT FROM true
    LOOP
        SELECT step.id
        INTO next_step
        FROM public.markdown_steps AS step
        WHERE NOT EXISTS (
            SELECT 1
            FROM public.item_price_events AS prior
            WHERE prior.item_id = piece.id
              AND prior.step_id = step.id
        )
        AND NOT EXISTS (
            SELECT 1
            FROM public.markdown_proposals AS open_row
            WHERE open_row.item_id = piece.id
              AND open_row.step_id = step.id
              AND open_row.status = 'proposed'
        )
        ORDER BY step.days_on_floor
        LIMIT 1;

        IF next_step IS NULL THEN
            CONTINUE;
        END IF;

        IF EXISTS (
            SELECT 1
            FROM public.items AS aged
            JOIN public.markdown_steps AS step ON step.id = next_step
            WHERE aged.id = piece.id
              AND aged.intake_on + step.days_on_floor > on_date
        ) THEN
            CONTINUE;
        END IF;

        SELECT event.prior_minor INTO original
        FROM public.item_price_events AS event
        WHERE event.item_id = piece.id
        ORDER BY event.applied_on, event.id
        LIMIT 1;
        IF original IS NULL THEN
            SELECT price_minor INTO original FROM public.items WHERE id = piece.id;
        END IF;

        SELECT public.stall_half_up(original, 10000 - step.percent * 100)
        INTO proposed
        FROM public.markdown_steps AS step
        WHERE step.id = next_step;

        INSERT INTO public.markdown_proposals (item_id, step_id, proposed_minor, proposed_on)
        VALUES (piece.id, next_step, proposed, on_date);
        filed := filed + 1;
    END LOOP;

    RETURN filed;
END;
$$;

CREATE OR REPLACE FUNCTION public.stall_quantity_deal(item uuid, units integer, tag bigint) RETURNS TABLE(picked_offer uuid, vendor_discount bigint, store_discount bigint)
    LANGUAGE plpgsql STABLE
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    mall_day date;
    vendor uuid;
    best_savings bigint := 0;
    best_rank integer := 4;
    best_offer uuid;
    best_funded text;
    row_offer uuid;
    row_funded text;
    row_kind text;
    row_threshold integer;
    row_deal bigint;
    row_rank integer;
    savings bigint;
    groups integer;
BEGIN
    IF units < 1 OR tag < 1 THEN
        RETURN;
    END IF;

    SELECT (clock_timestamp() AT TIME ZONE mall.time_zone)::date
    INTO mall_day
    FROM public.mall;

    SELECT items.vendor_id INTO vendor
    FROM public.items AS items
    WHERE items.id = item;

    FOR row_offer, row_funded, row_kind, row_threshold, row_deal, row_rank IN
        SELECT offer.id, offer.funded_by, offer.kind, offer.threshold_qty, offer.deal_minor,
               CASE offer.scope WHEN 'item' THEN 1 WHEN 'vendor' THEN 2 ELSE 3 END
        FROM public.offers AS offer
        WHERE offer.starts_on <= mall_day
          AND (offer.ends_on IS NULL OR offer.ends_on >= mall_day)
          AND (
              (offer.scope = 'item' AND offer.scope_id = item)
              OR (offer.scope = 'vendor' AND offer.scope_id = vendor)
              OR offer.scope = 'store'
          )
    LOOP
        savings := 0;
        IF row_kind = 'bundle' THEN
            groups := units / row_threshold;
            IF groups >= 1 THEN
                savings := tag * units - (groups * row_deal + (units % row_threshold) * tag);
            END IF;
        ELSIF units >= row_threshold AND row_deal < tag THEN
            savings := units * (tag - row_deal);
        END IF;
        IF savings > best_savings
            OR (
                savings = best_savings
                AND savings > 0
                AND (row_rank < best_rank OR (row_rank = best_rank AND row_offer < best_offer))
            ) THEN
            best_savings := savings;
            best_rank := row_rank;
            best_offer := row_offer;
            best_funded := row_funded;
        END IF;
    END LOOP;

    IF best_savings < 1 OR best_offer IS NULL THEN
        RETURN;
    END IF;

    picked_offer := best_offer;
    vendor_discount := CASE WHEN best_funded = 'store' THEN 0 ELSE best_savings END;
    store_discount := CASE WHEN best_funded = 'store' THEN best_savings ELSE 0 END;
    RETURN NEXT;
END;
$$;

-- stall_refuse_booth_deposit_overdraw is bound to journal_lines_booth_deposit_append_only;
-- CREATE OR REPLACE is refused. Body is unchanged in 001_tenant.sql.

CREATE OR REPLACE FUNCTION public.stall_refuse_export_tie(from_date date, to_date date) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    export_system text;
    delta bigint;
BEGIN
    FOREACH export_system IN ARRAY ARRAY['quickbooks', 'xero'] LOOP
        delta := public.stall_export_tie_delta(export_system, from_date, to_date);
        IF delta <> 0 THEN
            RAISE EXCEPTION 'the export does not tie to the books'
                USING ERRCODE = '23514';
        END IF;
    END LOOP;
END;
$$;

CREATE OR REPLACE FUNCTION public.stall_refuse_report_drift(report_minor bigint, books_minor bigint) RETURNS void
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF report_minor IS DISTINCT FROM books_minor THEN
        RAISE EXCEPTION 'a report disagrees with the books'
            USING ERRCODE = '23514';
    END IF;
END;
$$;

CREATE TRIGGER parties_no_truncate BEFORE TRUNCATE ON public.parties
    FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();
ALTER TABLE public.parties ENABLE ALWAYS TRIGGER parties_no_truncate;

CREATE TRIGGER persons_no_truncate BEFORE TRUNCATE ON public.persons
    FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();
ALTER TABLE public.persons ENABLE ALWAYS TRIGGER persons_no_truncate;

CREATE TRIGGER organizations_no_truncate BEFORE TRUNCATE ON public.organizations
    FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();
ALTER TABLE public.organizations ENABLE ALWAYS TRIGGER organizations_no_truncate;

CREATE TRIGGER party_roles_no_truncate BEFORE TRUNCATE ON public.party_roles
    FOR EACH STATEMENT EXECUTE FUNCTION public.ledger_reject_truncate();
ALTER TABLE public.party_roles ENABLE ALWAYS TRIGGER party_roles_no_truncate;

GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.parties TO stall_poster;
GRANT SELECT ON TABLE public.parties TO stall_backup;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.persons TO stall_poster;
GRANT SELECT ON TABLE public.persons TO stall_backup;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.organizations TO stall_poster;
GRANT SELECT ON TABLE public.organizations TO stall_backup;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.party_roles TO stall_poster;
GRANT SELECT ON TABLE public.party_roles TO stall_backup;
GRANT SELECT ON TABLE public.vendor_display TO stall_poster;
GRANT SELECT ON TABLE public.vendor_display TO stall_backup;
GRANT SELECT ON TABLE public.payout_pack TO stall_poster;
GRANT SELECT ON TABLE public.payout_pack TO stall_backup;
GRANT SELECT (id, active, party_id) ON TABLE public.vendors TO stall_vendor_entry;
GRANT SELECT (party_id, email) ON TABLE public.persons TO stall_vendor_entry;
GRANT SELECT, INSERT, DELETE ON TABLE public.vendor_entry_requests TO stall_vendor_entry;
GRANT INSERT ON TABLE public.vendor_entry_tokens TO stall_vendor_entry;
GRANT SELECT, INSERT, DELETE, UPDATE ON TABLE public.wait_list TO stall_poster;
GRANT SELECT ON TABLE public.wait_list TO stall_backup;

GRANT EXECUTE ON FUNCTION public.stall_person_display_name(text, text, text) TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_organization_display_name(text, text) TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_party_display_name(uuid) TO stall_poster;
GRANT ALL ON FUNCTION public.stall_payout_pack(uuid, date, date, date, date) TO stall_poster;
GRANT ALL ON FUNCTION public.stall_payout_pack(uuid, date, date, date, date) TO stall_backup;
GRANT EXECUTE ON FUNCTION public.stall_payout_quote(uuid, date, date, date, date) TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_payout_quote(uuid, date, date, date, date) TO stall_backup;
GRANT EXECUTE ON FUNCTION public.stall_payout_run_quote(uuid, uuid) TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_payout_run_quote(uuid, uuid) TO stall_backup;

-- Cashier-facing unique/check refusals: plain English, not constraint names.
CREATE OR REPLACE FUNCTION public.stall_refuse_duplicate_item_code() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM public.items AS other
        WHERE lower(other.code) = lower(NEW.code) AND other.id IS DISTINCT FROM NEW.id
    ) THEN
        RAISE EXCEPTION 'That tag is already used.'
            USING ERRCODE = '23505';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS items_refuse_duplicate_code ON public.items;
CREATE TRIGGER items_refuse_duplicate_code BEFORE INSERT OR UPDATE OF code ON public.items
    FOR EACH ROW EXECUTE FUNCTION public.stall_refuse_duplicate_item_code();

CREATE OR REPLACE FUNCTION public.stall_refuse_duplicate_item_barcode() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF NEW.barcode IS NULL OR btrim(NEW.barcode) = '' THEN
        RETURN NEW;
    END IF;
    IF EXISTS (
        SELECT 1 FROM public.items AS other
        WHERE lower(other.barcode) = lower(NEW.barcode) AND other.id IS DISTINCT FROM NEW.id
    ) THEN
        RAISE EXCEPTION 'That barcode is already used.'
            USING ERRCODE = '23505';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS items_refuse_duplicate_barcode ON public.items;
CREATE TRIGGER items_refuse_duplicate_barcode BEFORE INSERT OR UPDATE OF barcode ON public.items
    FOR EACH ROW EXECUTE FUNCTION public.stall_refuse_duplicate_item_barcode();

CREATE OR REPLACE FUNCTION public.stall_refuse_blank_list_name() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF NEW.name IS NULL OR btrim(NEW.name) = '' THEN
        IF TG_TABLE_NAME = 'brands' THEN
            RAISE EXCEPTION 'Enter a brand name.'
                USING ERRCODE = '23514';
        END IF;
        IF TG_TABLE_NAME = 'colors' THEN
            RAISE EXCEPTION 'Enter a color name.'
                USING ERRCODE = '23514';
        END IF;
        RAISE EXCEPTION 'Enter a name.'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS brands_refuse_blank_name ON public.brands;
CREATE TRIGGER brands_refuse_blank_name BEFORE INSERT OR UPDATE OF name ON public.brands
    FOR EACH ROW EXECUTE FUNCTION public.stall_refuse_blank_list_name();

CREATE OR REPLACE FUNCTION public.stall_refuse_duplicate_brand_name() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM public.brands AS other
        WHERE lower(other.name) = lower(NEW.name) AND other.id IS DISTINCT FROM NEW.id
    ) THEN
        RAISE EXCEPTION 'That name is already used.'
            USING ERRCODE = '23505';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS brands_refuse_duplicate_name ON public.brands;
CREATE TRIGGER brands_refuse_duplicate_name BEFORE INSERT OR UPDATE OF name ON public.brands
    FOR EACH ROW EXECUTE FUNCTION public.stall_refuse_duplicate_brand_name();

DROP TRIGGER IF EXISTS colors_refuse_blank_name ON public.colors;
CREATE TRIGGER colors_refuse_blank_name BEFORE INSERT OR UPDATE OF name ON public.colors
    FOR EACH ROW EXECUTE FUNCTION public.stall_refuse_blank_list_name();

CREATE OR REPLACE FUNCTION public.stall_refuse_booth_overlap() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM public.booth_assignments AS other
        WHERE other.booth_id = NEW.booth_id
          AND other.id IS DISTINCT FROM NEW.id
          AND daterange(other.starts_on, COALESCE(other.ends_on, 'infinity'::date), '[]')
              && daterange(NEW.starts_on, COALESCE(NEW.ends_on, 'infinity'::date), '[]')
    ) THEN
        RAISE EXCEPTION 'That booth is already taken on those dates.'
            USING ERRCODE = '23P01';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS booth_assignments_refuse_overlap ON public.booth_assignments;
CREATE TRIGGER booth_assignments_refuse_overlap BEFORE INSERT OR UPDATE ON public.booth_assignments
    FOR EACH ROW EXECUTE FUNCTION public.stall_refuse_booth_overlap();

-- Proof seeds. Identity lives on the person. The vendor, wait list, and customer rows are roles.
-- display_name is the old vendors.business_name. A vendor role stays active so the extension row can exist
-- while vendors.active records standing.

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
    INSERT INTO public.persons (party_id, first_name, last_name, display_name, phone, email, address)
    VALUES (party, first, last, display_name, phone, email, address);
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
    IF party_id IS NULL THEN
        INSERT INTO public.parties DEFAULT VALUES RETURNING parties.id INTO party_id;
    ELSE
        INSERT INTO public.parties (id) VALUES (party_id);
    END IF;
    INSERT INTO public.persons (party_id, first_name, last_name, phone, email)
    VALUES (party_id, first, last, phone, email);
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
        INSERT INTO public.persons (party_id, first_name, last_name, display_name, phone, email, address)
        VALUES (chosen, first, last, display_name, phone, email, address);
    END IF;
    INSERT INTO public.party_roles (party_id, role_type, status)
    VALUES (chosen, 'customer', 'active')
    ON CONFLICT (party_id, role_type) DO NOTHING;
    RETURN chosen;
END;
$$;

GRANT EXECUTE ON FUNCTION public.stall_seed_vendor(uuid, text, text, text, integer, boolean, text, text, boolean, boolean, text, text, text, date, integer, text, text, text) TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_seed_wait_person(text, text, text, text, uuid, numeric, numeric, text, text) TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_seed_customer_person(text, text, uuid, text, text, text, text) TO stall_poster;
