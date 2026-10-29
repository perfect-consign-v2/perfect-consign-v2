-- Platform store signup settings, email verification tokens, and audit actions.
-- One transaction, then ROLLBACK.

BEGIN;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM platform.settings WHERE key = 'store_signup_enabled'
    ) THEN
        RAISE EXCEPTION 'store_signup_enabled setting is missing';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM platform.settings WHERE key = 'store_signup_require_email_verification'
    ) THEN
        RAISE EXCEPTION 'store_signup_require_email_verification setting is missing';
    END IF;
END $$;

UPDATE platform.settings
SET value = 'true'::jsonb
WHERE key = 'store_signup_enabled';

UPDATE platform.settings
SET value = 'false'::jsonb
WHERE key = 'store_signup_require_email_verification';

DO $$
BEGIN
    IF (SELECT value::text FROM platform.settings WHERE key = 'store_signup_enabled')
        NOT IN ('true', 'true'::text) THEN
        RAISE EXCEPTION 'store signup could not be enabled';
    END IF;
    IF (SELECT value::text FROM platform.settings WHERE key = 'store_signup_require_email_verification')
        NOT IN ('false', 'false'::text) THEN
        RAISE EXCEPTION 'store signup require-verification could not be turned off';
    END IF;
END $$;

INSERT INTO platform.signup_attempts (client_address)
VALUES ('203.0.113.9'), ('203.0.113.9'), ('203.0.113.9'), ('203.0.113.9'), ('203.0.113.9');

DO $$
BEGIN
    IF (
        SELECT COUNT(*)
        FROM platform.signup_attempts
        WHERE client_address = '203.0.113.9'
          AND attempted_at > clock_timestamp() - interval '1 hour'
    ) < 5 THEN
        RAISE EXCEPTION 'signup attempts were not stored';
    END IF;
END $$;

DO $$
BEGIN
    INSERT INTO platform.settings (key, value) VALUES ('', 'true'::jsonb);
    RAISE EXCEPTION 'empty settings key was accepted';
EXCEPTION
    WHEN check_violation THEN
        NULL;
END $$;

INSERT INTO platform.admin_audit (
    admin_id, admin_email, action, outcome, detail, request_id, client_address
) VALUES (
    NULL, 'owner@example.test', 'store.signup', 'ok', '{"display_name":"Proof Mall"}'::jsonb, 'proof-signup', ''
);

INSERT INTO platform.admin_audit (
    admin_id, admin_email, action, outcome, detail, request_id, client_address
) VALUES (
    NULL, 'owner@example.test', 'store.email_verified', 'ok', '{}'::jsonb, 'proof-signup-verify', ''
);

DO $$
DECLARE
    admin_row record;
    mall_id uuid;
    owner_id uuid;
    token_id uuid := '01900000-0000-7000-8000-00000000f001';
BEGIN
    SELECT id, email INTO admin_row FROM platform.admins ORDER BY created_at LIMIT 1;
    IF admin_row.id IS NOT NULL THEN
        INSERT INTO platform.admin_audit (
            admin_id, admin_email, action, outcome, detail, request_id, client_address
        ) VALUES (
            admin_row.id, admin_row.email, 'store.signup_toggle', 'ok',
            '{"enabled":true,"require_email_verification":true}'::jsonb,
            'proof-signup-toggle', ''
        );
        INSERT INTO platform.admin_audit (
            admin_id, admin_email, action, outcome, detail, request_id, client_address
        ) VALUES (
            admin_row.id, admin_row.email, 'store.admin_create', 'ok',
            '{"display_name":"Admin Mall"}'::jsonb,
            'proof-admin-create', ''
        );
    END IF;

    SELECT id INTO mall_id FROM platform.tenants WHERE schema_name = current_schema();
    IF mall_id IS NULL THEN
        RAISE EXCEPTION 'the tenant for this schema is missing';
    END IF;

    INSERT INTO platform.owners (id, tenant_id, username, password_hash, email, email_verified_at)
    VALUES (
        '01900000-0000-7000-8000-00000000f020',
        mall_id,
        'signupowner',
        'hash',
        'signup-owner@example.test',
        NULL
    )
    ON CONFLICT (tenant_id) DO UPDATE
        SET email = EXCLUDED.email,
            email_verified_at = NULL
    RETURNING id INTO owner_id;

    IF owner_id IS NULL THEN
        SELECT id INTO owner_id FROM platform.owners WHERE tenant_id = mall_id;
    END IF;

    IF (SELECT email_verified_at FROM platform.owners WHERE id = owner_id) IS NOT NULL THEN
        RAISE EXCEPTION 'unverified owner was marked verified';
    END IF;

    UPDATE platform.owners
    SET email_verified_at = clock_timestamp()
    WHERE id = owner_id;

    IF (SELECT email_verified_at FROM platform.owners WHERE id = owner_id) IS NULL THEN
        RAISE EXCEPTION 'owner email_verified_at was not set';
    END IF;

    INSERT INTO platform.owner_email_verifications (
        id, owner_id, token_hash, expires_at
    ) VALUES (
        token_id,
        owner_id,
        repeat('ab', 32),
        clock_timestamp() + interval '30 minutes'
    );

    BEGIN
        UPDATE platform.owner_email_verifications SET token_hash = repeat('cd', 32) WHERE id = token_id;
        RAISE EXCEPTION 'an email verification body was rewritten';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%an email verification cannot be rewritten%' THEN
                RAISE;
            END IF;
    END;

    UPDATE platform.owner_email_verifications SET used_at = clock_timestamp() WHERE id = token_id;
    IF (SELECT used_at FROM platform.owner_email_verifications WHERE id = token_id) IS NULL THEN
        RAISE EXCEPTION 'an email verification was not marked used';
    END IF;

    BEGIN
        UPDATE platform.owner_email_verifications SET used_at = clock_timestamp() WHERE id = token_id;
        RAISE EXCEPTION 'a used email verification was reused';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%an email verification was already used%' THEN
                RAISE;
            END IF;
    END;

    BEGIN
        DELETE FROM platform.owner_email_verifications WHERE id = token_id;
        RAISE EXCEPTION 'an email verification was deleted';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%an email verification cannot be removed%' THEN
                RAISE;
            END IF;
    END;

    BEGIN
        TRUNCATE platform.owner_email_verifications;
        RAISE EXCEPTION 'email verifications were truncated';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%email verifications cannot be truncated%' THEN
                RAISE;
            END IF;
    END;

    BEGIN
        ALTER TABLE platform.owner_email_verifications DISABLE TRIGGER owner_email_verifications_append_only;
        RAISE EXCEPTION 'email verification append-only trigger was disabled';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%append-only triggers cannot be disabled%' THEN
                RAISE;
            END IF;
    END;

    BEGIN
        DROP TRIGGER owner_email_verifications_append_only ON platform.owner_email_verifications;
        RAISE EXCEPTION 'email verification append-only trigger was dropped';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%append-only triggers cannot be dropped%' THEN
                RAISE;
            END IF;
    END;

    BEGIN
        CREATE OR REPLACE FUNCTION platform.reject_owner_email_verification_mutation() RETURNS trigger
            LANGUAGE plpgsql AS $fn$ BEGIN RETURN NEW; END; $fn$;
        RAISE EXCEPTION 'email verification guard function was replaced';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
                RAISE;
            END IF;
    END;

    BEGIN
        PERFORM set_config('session_replication_role', 'replica', true);
        UPDATE platform.owner_email_verifications
        SET token_hash = repeat('ef', 32)
        WHERE id = token_id;
        PERFORM set_config('session_replication_role', 'origin', true);
        RAISE EXCEPTION 'replica session rewrote an email verification';
    EXCEPTION
        WHEN SQLSTATE '25006' THEN
            PERFORM set_config('session_replication_role', 'origin', true);
            IF SQLERRM NOT LIKE '%an email verification%' AND SQLERRM NOT LIKE '%already used%' THEN
                RAISE;
            END IF;
        WHEN insufficient_privilege THEN
            PERFORM set_config('session_replication_role', 'origin', true);
    END;
END $$;

ROLLBACK;
