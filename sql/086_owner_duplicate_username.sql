-- Duplicate co-owner username / sign-in email: plain English (ERRCODE 23505).
CREATE OR REPLACE FUNCTION platform.put_sign_in_directory(
    p_email text,
    p_tenant_id uuid,
    p_kind text,
    p_account_id uuid,
    p_username text
) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $_$
DECLARE
    normalized text;
BEGIN
    IF p_email IS NULL OR btrim(p_email) = '' THEN
        DELETE FROM platform.sign_in_directory
        WHERE account_kind = p_kind AND account_id = p_account_id;
        RETURN;
    END IF;
    normalized := lower(btrim(p_email));
    IF char_length(normalized) > 200
        OR normalized !~ '^[^@\s]+@[^@\s]+\.[^@\s]+$' THEN
        RAISE EXCEPTION 'sign-in email is not usable'
            USING ERRCODE = 'check_violation';
    END IF;
    IF p_kind IS DISTINCT FROM 'owner' AND p_kind IS DISTINCT FROM 'staff' THEN
        RAISE EXCEPTION 'sign-in account kind is not usable'
            USING ERRCODE = 'check_violation';
    END IF;
    IF p_username IS NULL OR p_username !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RAISE EXCEPTION 'sign-in username is not usable'
            USING ERRCODE = 'check_violation';
    END IF;
    IF EXISTS (
        SELECT 1 FROM platform.sign_in_directory AS other
        WHERE lower(other.email) = normalized
          AND (other.account_kind, other.account_id) IS DISTINCT FROM (p_kind, p_account_id)
    ) THEN
        RAISE EXCEPTION 'That email is already used on another sign-in.'
            USING ERRCODE = '23505';
    END IF;
    INSERT INTO platform.sign_in_directory (email, tenant_id, account_kind, account_id, username)
    VALUES (normalized, p_tenant_id, p_kind, p_account_id, p_username)
    ON CONFLICT (account_kind, account_id) DO UPDATE
        SET email = EXCLUDED.email,
            tenant_id = EXCLUDED.tenant_id,
            username = EXCLUDED.username;
END;
$_$;

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
    IF EXISTS (
        SELECT 1 FROM platform.owners
        WHERE tenant_id = mall_id AND username = cleaned_username
    ) THEN
        RAISE EXCEPTION 'That sign-in name is already used.'
            USING ERRCODE = '23505';
    END IF;
    INSERT INTO platform.owners (id, tenant_id, username, password_hash, email, email_verified_at)
    VALUES (uuidv7(), mall_id, cleaned_username, owner_password_hash, cleaned_email, clock_timestamp())
    RETURNING id INTO new_id;
    RETURN new_id;
END;
$_$;
