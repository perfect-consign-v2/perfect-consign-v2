-- stall_seed_vendor parameter was named id, which clashes with RETURNING id on parties.
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
