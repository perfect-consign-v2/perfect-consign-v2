-- Co-owners: many owners per mall. Username unique per mall.
-- stall_app adds/removes only through SECURITY DEFINER helpers.

ALTER TABLE platform.owners
    DROP CONSTRAINT IF EXISTS owners_tenant_id_key;

ALTER TABLE platform.owners
    DROP CONSTRAINT IF EXISTS owners_tenant_username_key;

ALTER TABLE platform.owners
    ADD CONSTRAINT owners_tenant_username_key UNIQUE (tenant_id, username);

CREATE OR REPLACE FUNCTION platform.add_mall_owner(
    mall_id uuid,
    owner_username text,
    owner_password_hash text,
    owner_email text
) RETURNS uuid
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $_$
DECLARE
    new_id uuid;
    cleaned_username text;
    cleaned_email text;
BEGIN
    IF mall_id IS NULL THEN
        RAISE EXCEPTION 'a mall is required'
            USING ERRCODE = '23514';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM platform.tenants WHERE id = mall_id) THEN
        RAISE EXCEPTION 'that mall was not found'
            USING ERRCODE = 'P0002';
    END IF;
    cleaned_username := lower(btrim(owner_username));
    IF cleaned_username IS NULL OR cleaned_username !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RAISE EXCEPTION 'Use a sign-in name of letters, numbers, and hyphens.'
            USING ERRCODE = '23514';
    END IF;
    IF owner_password_hash IS NULL OR owner_password_hash = '' THEN
        RAISE EXCEPTION 'a password is required'
            USING ERRCODE = '23514';
    END IF;
    cleaned_email := lower(btrim(owner_email));
    IF cleaned_email IS NULL
        OR char_length(cleaned_email) > 200
        OR cleaned_email !~ '^[^@\s]+@[^@\s]+\.[^@\s]+$' THEN
        RAISE EXCEPTION 'Enter a usable email.'
            USING ERRCODE = '23514';
    END IF;
    INSERT INTO platform.owners (id, tenant_id, username, password_hash, email, email_verified_at)
    VALUES (uuidv7(), mall_id, cleaned_username, owner_password_hash, cleaned_email, clock_timestamp())
    RETURNING id INTO new_id;
    RETURN new_id;
END;
$_$;

CREATE OR REPLACE FUNCTION platform.remove_mall_owner(
    mall_id uuid,
    owner_id uuid
) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $_$
DECLARE
    owner_count integer;
BEGIN
    IF mall_id IS NULL OR owner_id IS NULL THEN
        RAISE EXCEPTION 'Choose an owner.'
            USING ERRCODE = '23514';
    END IF;
    SELECT count(*)::integer INTO owner_count
    FROM platform.owners
    WHERE tenant_id = mall_id;
    IF owner_count < 2 THEN
        RAISE EXCEPTION 'Keep at least one owner on the mall.'
            USING ERRCODE = 'P0001';
    END IF;
    DELETE FROM platform.owners
    WHERE id = owner_id AND tenant_id = mall_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'Choose an owner.'
            USING ERRCODE = 'P0002';
    END IF;
END;
$_$;

REVOKE ALL ON FUNCTION platform.add_mall_owner(uuid, text, text, text) FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.remove_mall_owner(uuid, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION platform.add_mall_owner(uuid, text, text, text) TO stall_app;
GRANT EXECUTE ON FUNCTION platform.remove_mall_owner(uuid, uuid) TO stall_app;
