-- Public store signup settings, owner email verification, and rate-limit storage.

INSERT INTO platform.settings (key, value)
VALUES
    ('store_signup_enabled', 'false'::jsonb),
    ('store_signup_require_email_verification', 'true'::jsonb)
ON CONFLICT (key) DO NOTHING;

ALTER TABLE platform.owners
    ADD COLUMN IF NOT EXISTS email_verified_at timestamp with time zone;

UPDATE platform.owners
SET email_verified_at = created_at
WHERE email_verified_at IS NULL;

CREATE TABLE IF NOT EXISTS platform.owner_email_verifications (
    id uuid DEFAULT uuidv7() NOT NULL,
    owner_id uuid NOT NULL,
    token_hash text NOT NULL,
    expires_at timestamp with time zone NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    used_at timestamp with time zone,
    CONSTRAINT owner_email_verifications_pkey PRIMARY KEY (id),
    CONSTRAINT owner_email_verifications_hash_key UNIQUE (token_hash),
    CONSTRAINT owner_email_verifications_hash_chk CHECK (token_hash ~ '^[0-9a-f]{64}$'::text),
    CONSTRAINT owner_email_verifications_owner_id_fkey
        FOREIGN KEY (owner_id) REFERENCES platform.owners (id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS owner_email_verifications_owner
    ON platform.owner_email_verifications USING btree (owner_id);

CREATE OR REPLACE FUNCTION platform.reject_owner_email_verification_mutation() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'an email verification cannot be removed'
            USING ERRCODE = '25006';
    END IF;
    IF OLD.used_at IS NOT NULL THEN
        RAISE EXCEPTION 'an email verification was already used'
            USING ERRCODE = '25006';
    END IF;
    IF NEW.used_at IS NULL
        OR NEW.id IS DISTINCT FROM OLD.id
        OR NEW.owner_id IS DISTINCT FROM OLD.owner_id
        OR NEW.token_hash IS DISTINCT FROM OLD.token_hash
        OR NEW.expires_at IS DISTINCT FROM OLD.expires_at
        OR NEW.created_at IS DISTINCT FROM OLD.created_at THEN
        RAISE EXCEPTION 'an email verification cannot be rewritten'
            USING ERRCODE = '25006';
    END IF;

    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION platform.reject_owner_email_verification_truncate() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'email verifications cannot be truncated'
        USING ERRCODE = '25006';
END;
$$;

CREATE OR REPLACE FUNCTION platform.reject_owner_email_verification_guard() RETURNS event_trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $_$
DECLARE
    command record;
    dropped record;
BEGIN
    IF TG_EVENT = 'sql_drop' THEN
        FOR dropped IN
            SELECT object_type, object_identity
            FROM pg_event_trigger_dropped_objects()
        LOOP
            IF dropped.object_type = 'trigger'
                AND right(dropped.object_identity, length(' on platform.owner_email_verifications'))
                    = ' on platform.owner_email_verifications' THEN
                RAISE EXCEPTION 'append-only triggers cannot be dropped'
                    USING ERRCODE = '25006';
            END IF;
            IF dropped.object_type = 'function'
                AND dropped.object_identity IN (
                    'platform.reject_owner_email_verification_mutation()',
                    'platform.reject_owner_email_verification_truncate()',
                    'platform.reject_owner_email_verification_guard()'
                ) THEN
                RAISE EXCEPTION 'a guard function cannot be replaced'
                    USING ERRCODE = '25006';
            END IF;
        END LOOP;
        RETURN;
    END IF;

    FOR command IN
        SELECT command_tag, objid
        FROM pg_event_trigger_ddl_commands()
    LOOP
        IF command.command_tag = 'ALTER TABLE'
            AND command.objid IS NOT DISTINCT FROM to_regclass('platform.owner_email_verifications')
            AND EXISTS (
                SELECT 1
                FROM pg_trigger
                WHERE tgrelid = command.objid
                  AND NOT tgisinternal
                  AND (
                      tgenabled NOT IN ('O', 'A')
                      OR (
                          tgenabled <> 'A'
                          AND tgname ~ '(_append_only|_no_truncate|_no_delete)$'
                      )
                  )
            ) THEN
            RAISE EXCEPTION 'append-only triggers cannot be disabled'
                USING ERRCODE = '25006';
        END IF;
        IF command.command_tag IN ('CREATE FUNCTION', 'ALTER FUNCTION', 'ALTER ROUTINE')
            AND command.objid IN (
                'platform.reject_owner_email_verification_mutation()'::regprocedure,
                'platform.reject_owner_email_verification_truncate()'::regprocedure,
                'platform.reject_owner_email_verification_guard()'::regprocedure
            ) THEN
            RAISE EXCEPTION 'a guard function cannot be replaced'
                USING ERRCODE = '25006';
        END IF;
    END LOOP;
END;
$_$;

DROP TRIGGER IF EXISTS owner_email_verifications_append_only ON platform.owner_email_verifications;
CREATE TRIGGER owner_email_verifications_append_only
    BEFORE DELETE OR UPDATE ON platform.owner_email_verifications
    FOR EACH ROW EXECUTE FUNCTION platform.reject_owner_email_verification_mutation();

ALTER TABLE platform.owner_email_verifications ENABLE ALWAYS TRIGGER owner_email_verifications_append_only;

DROP TRIGGER IF EXISTS owner_email_verifications_no_truncate ON platform.owner_email_verifications;
CREATE TRIGGER owner_email_verifications_no_truncate
    BEFORE TRUNCATE ON platform.owner_email_verifications
    FOR EACH STATEMENT EXECUTE FUNCTION platform.reject_owner_email_verification_truncate();

ALTER TABLE platform.owner_email_verifications ENABLE ALWAYS TRIGGER owner_email_verifications_no_truncate;

DROP EVENT TRIGGER IF EXISTS platform_reject_owner_email_verification_change;
CREATE EVENT TRIGGER platform_reject_owner_email_verification_change
    ON ddl_command_end
    WHEN TAG IN ('ALTER FUNCTION', 'ALTER ROUTINE', 'ALTER TABLE', 'CREATE FUNCTION')
    EXECUTE FUNCTION platform.reject_owner_email_verification_guard();

ALTER EVENT TRIGGER platform_reject_owner_email_verification_change ENABLE ALWAYS;

DROP EVENT TRIGGER IF EXISTS platform_reject_owner_email_verification_drop;
CREATE EVENT TRIGGER platform_reject_owner_email_verification_drop
    ON sql_drop
    WHEN TAG IN ('DROP FUNCTION', 'DROP TRIGGER')
    EXECUTE FUNCTION platform.reject_owner_email_verification_guard();

ALTER EVENT TRIGGER platform_reject_owner_email_verification_drop ENABLE ALWAYS;

ALTER TABLE platform.admin_audit
    DROP CONSTRAINT IF EXISTS admin_audit_action_chk;

ALTER TABLE platform.admin_audit
    ADD CONSTRAINT admin_audit_action_chk CHECK (action = ANY (ARRAY[
        'admin.sign_in'::text,
        'admin.sign_in_refused'::text,
        'admin.password_change'::text,
        'admin.password_reset_request'::text,
        'admin.password_reset'::text,
        'tenant.enter_as'::text,
        'tenant.enter_as_refused'::text,
        'tenant.delete'::text,
        'tenant.delete_refused'::text,
        'store.signup_toggle'::text,
        'store.signup'::text,
        'store.admin_create'::text,
        'store.email_verified'::text
    ]));

ALTER TABLE platform.admin_audit
    DROP CONSTRAINT IF EXISTS admin_audit_ok_admin_chk;

ALTER TABLE platform.admin_audit
    ADD CONSTRAINT admin_audit_ok_admin_chk CHECK (
        (outcome <> 'ok'::text)
        OR (admin_id IS NOT NULL)
        OR (action = ANY (ARRAY['store.signup'::text, 'store.email_verified'::text]))
    );

REVOKE ALL ON TABLE platform.settings FROM PUBLIC;
REVOKE ALL ON TABLE platform.signup_attempts FROM PUBLIC;
REVOKE ALL ON TABLE platform.owner_email_verifications FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.reject_owner_email_verification_mutation() FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.reject_owner_email_verification_truncate() FROM PUBLIC;
REVOKE ALL ON FUNCTION platform.reject_owner_email_verification_guard() FROM PUBLIC;

GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE platform.settings TO stall_app;
GRANT SELECT, INSERT, DELETE ON TABLE platform.signup_attempts TO stall_app;
GRANT SELECT, INSERT ON TABLE platform.owner_email_verifications TO stall_app;
GRANT UPDATE (used_at) ON TABLE platform.owner_email_verifications TO stall_app;
GRANT UPDATE (email_verified_at) ON TABLE platform.owners TO stall_app;
GRANT ALL ON FUNCTION platform.reject_owner_email_verification_mutation() TO stall_app;
GRANT ALL ON FUNCTION platform.reject_owner_email_verification_truncate() TO stall_app;
