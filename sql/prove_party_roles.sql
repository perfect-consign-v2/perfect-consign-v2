-- Party roles. One transaction, then ROLLBACK.
-- A person can hold vendor and customer. An organization cannot be a vendor.
-- A ticket customer is a party with the customer role.

BEGIN;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'wait_list'
          AND column_name IN ('id', 'first_name', 'last_name', 'email', 'phone', 'address')
    ) THEN
        RAISE EXCEPTION 'wait list still stores identity';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'persons'
          AND column_name IN ('email', 'phone', 'address', 'city', 'state', 'postal')
    ) THEN
        RAISE EXCEPTION 'persons still stores contact';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM information_schema.columns
        WHERE table_schema = current_schema()
          AND table_name = 'organizations'
          AND column_name IN ('email', 'phone', 'address')
    ) THEN
        RAISE EXCEPTION 'organizations still stores contact';
    END IF;
END $$;

DO $$
DECLARE
    party uuid;
    attached uuid;
BEGIN
    party := stall_seed_vendor(
        '01900000-0000-7000-8000-00000000a101',
        'dual',
        'Ada',
        'Lane'
    );
    attached := stall_seed_customer_person('Ada', 'Lane', party);
    IF attached IS DISTINCT FROM party THEN
        RAISE EXCEPTION 'customer role was not attached to the vendor party';
    END IF;
    IF (
        SELECT count(*)
        FROM party_roles
        WHERE party_id = party
          AND role_type IN ('vendor', 'customer')
          AND status = 'active'
    ) <> 2 THEN
        RAISE EXCEPTION 'a person did not hold vendor and customer roles';
    END IF;
END $$;

DO $$
DECLARE
    party uuid;
BEGIN
    party := stall_seed_vendor(
        '01900000-0000-7000-8000-00000000a102',
        'twice',
        'Bea',
        'Lane'
    );
    INSERT INTO party_roles (party_id, role_type) VALUES (party, 'vendor');
    RAISE EXCEPTION 'a duplicate role was accepted';
EXCEPTION
    WHEN SQLSTATE '23505' THEN
        NULL;
END $$;

DO $$
DECLARE
    party uuid;
BEGIN
    INSERT INTO parties DEFAULT VALUES RETURNING id INTO party;
    INSERT INTO party_roles (party_id, role_type) VALUES (party, 'vendor');
    RAISE EXCEPTION 'a vendor role without a person was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a vendor role needs a person%' THEN
            RAISE;
        END IF;
END $$;

DO $$
DECLARE
    party uuid;
BEGIN
    INSERT INTO parties DEFAULT VALUES RETURNING id INTO party;
    INSERT INTO organizations (party_id, legal_name) VALUES (party, 'Lane Goods');
    INSERT INTO party_roles (party_id, role_type) VALUES (party, 'vendor');
    RAISE EXCEPTION 'an organization vendor role was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM NOT LIKE '%a vendor role needs a person%' THEN
            RAISE;
        END IF;
END $$;

DO $$
DECLARE
    party uuid;
BEGIN
    INSERT INTO parties DEFAULT VALUES RETURNING id INTO party;
    INSERT INTO organizations (party_id, legal_name) VALUES (party, 'Lane Mail');
    INSERT INTO party_contacts (party_id, method, label, value, is_primary)
    VALUES
        (party, 'phone', 'primary', '555-0200', true),
        (party, 'email', 'primary', 'org@example.com', true);
    INSERT INTO party_addresses (party_id, kind, street, city, state, postal, is_primary)
    VALUES
        (party, 'physical', '1 Store St', 'Austin', 'TX', '78701', true),
        (party, 'mailing', 'PO Box 9', 'Austin', 'TX', '78702', true);
    IF stall_party_primary_email(party) IS DISTINCT FROM 'org@example.com' THEN
        RAISE EXCEPTION 'organization primary email was not stored on the party';
    END IF;
    IF (
        SELECT count(*) FROM party_addresses WHERE party_id = party
    ) <> 2 THEN
        RAISE EXCEPTION 'organization physical and mailing addresses were not stored';
    END IF;
END $$;

DO $$
DECLARE
    party uuid;
    session_id uuid := '01900000-0000-7000-8000-00000000aa22';
    ticket_id uuid := '01900000-0000-7000-8000-00000000aa72';
BEGIN
    party := stall_seed_vendor(
        '01900000-0000-7000-8000-00000000aa11',
        'buyer',
        'Bea',
        'Buyer'
    );
    INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
    VALUES (
        session_id,
        '01900000-0000-7000-8000-000000000d10',
        'Ada',
        '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb,
        0
    );
    INSERT INTO tickets (id, session_id) VALUES (ticket_id, session_id);
    BEGIN
        UPDATE tickets SET customer_party_id = party WHERE id = ticket_id;
        RAISE EXCEPTION 'a ticket customer without the customer role was accepted';
    EXCEPTION
        WHEN SQLSTATE '23514' THEN
            IF SQLERRM NOT LIKE '%Choose a customer to hold a layaway.%' THEN
                RAISE;
            END IF;
    END;
    PERFORM stall_seed_customer_person('Bea', 'Buyer', party);
    UPDATE tickets SET customer_party_id = party WHERE id = ticket_id;
    IF (SELECT customer_party_id FROM tickets WHERE id = ticket_id) IS DISTINCT FROM party THEN
        RAISE EXCEPTION 'a customer role did not hold the layaway';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO commission_rules (vendor_id, min_minor, bps, starts_on)
    VALUES ('01900000-0000-7000-8000-00000000ab01', 0, 0, DATE '2026-01-01');
    RAISE EXCEPTION 'a commission rule for a missing vendor was accepted';
EXCEPTION
    WHEN SQLSTATE '23503' THEN
        NULL;
END $$;

DO $$
BEGIN
    INSERT INTO vendor_entry_tokens (vendor_id, token_hash, expires_at)
    VALUES (
        '01900000-0000-7000-8000-00000000ab02',
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        clock_timestamp() + interval '30 minutes'
    );
    RAISE EXCEPTION 'a vendor entry token for a missing vendor was accepted';
EXCEPTION
    WHEN SQLSTATE '23503' THEN
        NULL;
END $$;

ROLLBACK;
