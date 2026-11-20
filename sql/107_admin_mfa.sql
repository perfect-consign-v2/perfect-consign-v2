-- Platform admin authenticator (TOTP) and one-time recovery codes.
--
-- DROP EVENT TRIGGER is not in platform.reject_admin_append_only_guard's tag
-- list (DROP FUNCTION, DROP TRIGGER only), so Postgres does not deliver that
-- command to the guard. This file drops the two event triggers, replaces the
-- guard so it also covers the authenticator, and creates the triggers again.

ALTER TABLE platform.admins
    ADD COLUMN mfa_secret text,
    ADD COLUMN mfa_confirmed_at timestamp with time zone,
    ADD COLUMN mfa_last_used_step bigint;

ALTER TABLE platform.admins
    ADD CONSTRAINT admins_mfa_chk CHECK (
        (
            mfa_secret IS NULL
            AND mfa_confirmed_at IS NULL
            AND mfa_last_used_step IS NULL
        )
        OR (
            mfa_secret ~ '^v1:[A-Za-z0-9+/]+=*$'
            AND char_length(mfa_secret) BETWEEN 8 AND 200
            AND mfa_confirmed_at IS NOT NULL
            AND mfa_last_used_step IS NOT NULL
            AND mfa_last_used_step >= 0
        )
    );

CREATE TABLE platform.admin_mfa_recovery_codes (
    id uuid DEFAULT uuidv7() NOT NULL,
    admin_id uuid NOT NULL,
    code_hash character(64) NOT NULL,
    batch_issued_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    used_at timestamp with time zone,
    CONSTRAINT admin_mfa_recovery_codes_hash_chk CHECK ((code_hash ~ '^[0-9a-f]{64}$'::text))
);

CREATE TABLE platform.admin_mfa_failures (
    id uuid DEFAULT uuidv7() NOT NULL,
    email text NOT NULL,
    failed_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    client_address text DEFAULT ''::text NOT NULL,
    CONSTRAINT admin_mfa_failures_address_chk CHECK ((char_length(client_address) <= 64)),
    CONSTRAINT admin_mfa_failures_email_chk CHECK (((email = btrim(email)) AND (char_length(email) <= 200) AND (email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text)))
);

ALTER TABLE ONLY platform.admin_mfa_recovery_codes
    ADD CONSTRAINT admin_mfa_recovery_codes_pkey PRIMARY KEY (id);

ALTER TABLE ONLY platform.admin_mfa_failures
    ADD CONSTRAINT admin_mfa_failures_pkey PRIMARY KEY (id);

CREATE UNIQUE INDEX admin_mfa_recovery_codes_hash
    ON platform.admin_mfa_recovery_codes USING btree (admin_id, code_hash);

CREATE INDEX admin_mfa_recovery_codes_admin
    ON platform.admin_mfa_recovery_codes USING btree (admin_id);

CREATE INDEX admin_mfa_failures_window
    ON platform.admin_mfa_failures USING btree (email, client_address, failed_at);

CREATE INDEX admin_mfa_failures_address
    ON platform.admin_mfa_failures USING btree (client_address, failed_at);

CREATE INDEX admin_mfa_failures_email
    ON platform.admin_mfa_failures USING btree (email, failed_at);

CREATE INDEX admin_mfa_failures_stale
    ON platform.admin_mfa_failures USING btree (failed_at);

ALTER TABLE ONLY platform.admin_mfa_recovery_codes
    ADD CONSTRAINT admin_mfa_recovery_codes_admin_id_fkey
    FOREIGN KEY (admin_id) REFERENCES platform.admins (id);

CREATE FUNCTION platform.reject_admin_mfa_transition() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    owner name;
BEGIN
    IF NEW.mfa_secret IS NOT DISTINCT FROM OLD.mfa_secret
        AND NEW.mfa_confirmed_at IS NOT DISTINCT FROM OLD.mfa_confirmed_at
        AND NEW.mfa_last_used_step IS NOT DISTINCT FROM OLD.mfa_last_used_step THEN
        RETURN NEW;
    END IF;

    SELECT tableowner INTO owner
    FROM pg_tables
    WHERE schemaname = 'platform'
      AND tablename = 'admins';

    IF current_user = owner
        AND NEW.mfa_secret IS NULL
        AND NEW.mfa_confirmed_at IS NULL
        AND NEW.mfa_last_used_step IS NULL THEN
        RETURN NEW;
    END IF;

    IF OLD.mfa_secret IS NULL
        AND OLD.mfa_confirmed_at IS NULL
        AND OLD.mfa_last_used_step IS NULL
        AND NEW.mfa_secret IS NOT NULL
        AND NEW.mfa_confirmed_at IS NOT NULL
        AND NEW.mfa_last_used_step IS NOT NULL
        AND NEW.mfa_last_used_step >= 0 THEN
        RETURN NEW;
    END IF;

    IF NEW.mfa_secret IS NOT DISTINCT FROM OLD.mfa_secret
        AND NEW.mfa_confirmed_at IS NOT DISTINCT FROM OLD.mfa_confirmed_at
        AND OLD.mfa_last_used_step IS NOT NULL
        AND NEW.mfa_last_used_step IS NOT NULL
        AND NEW.mfa_last_used_step > OLD.mfa_last_used_step THEN
        RETURN NEW;
    END IF;

    RAISE EXCEPTION 'an admin authenticator cannot be rewritten'
        USING ERRCODE = '25006';
END;
$$;

CREATE FUNCTION platform.reject_admin_mfa_recovery_mutation() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'a recovery code cannot be removed'
            USING ERRCODE = '25006';
    END IF;
    IF OLD.used_at IS NOT NULL THEN
        RAISE EXCEPTION 'a recovery code was already used'
            USING ERRCODE = '25006';
    END IF;
    IF NEW.used_at IS NULL
        OR NEW.id IS DISTINCT FROM OLD.id
        OR NEW.admin_id IS DISTINCT FROM OLD.admin_id
        OR NEW.code_hash IS DISTINCT FROM OLD.code_hash
        OR NEW.batch_issued_at IS DISTINCT FROM OLD.batch_issued_at THEN
        RAISE EXCEPTION 'a recovery code cannot be rewritten'
            USING ERRCODE = '25006';
    END IF;

    RETURN NEW;
END;
$$;

CREATE FUNCTION platform.reject_admin_mfa_recovery_truncate() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
BEGIN
    RAISE EXCEPTION 'recovery codes cannot be truncated'
        USING ERRCODE = '25006';
END;
$$;

CREATE TRIGGER admin_mfa_transition
    BEFORE UPDATE ON platform.admins
    FOR EACH ROW
    EXECUTE FUNCTION platform.reject_admin_mfa_transition();

ALTER TABLE platform.admins ENABLE ALWAYS TRIGGER admin_mfa_transition;

CREATE TRIGGER admin_mfa_recovery_append_only
    BEFORE DELETE OR UPDATE ON platform.admin_mfa_recovery_codes
    FOR EACH ROW
    EXECUTE FUNCTION platform.reject_admin_mfa_recovery_mutation();

ALTER TABLE platform.admin_mfa_recovery_codes ENABLE ALWAYS TRIGGER admin_mfa_recovery_append_only;

CREATE TRIGGER admin_mfa_recovery_no_truncate
    BEFORE TRUNCATE ON platform.admin_mfa_recovery_codes
    FOR EACH STATEMENT
    EXECUTE FUNCTION platform.reject_admin_mfa_recovery_truncate();

ALTER TABLE platform.admin_mfa_recovery_codes ENABLE ALWAYS TRIGGER admin_mfa_recovery_no_truncate;

GRANT UPDATE (mfa_secret, mfa_confirmed_at, mfa_last_used_step) ON TABLE platform.admins TO stall_app;

GRANT SELECT, INSERT ON TABLE platform.admin_mfa_recovery_codes TO stall_app;

GRANT UPDATE (used_at) ON TABLE platform.admin_mfa_recovery_codes TO stall_app;

GRANT SELECT, INSERT, DELETE ON TABLE platform.admin_mfa_failures TO stall_app;

REVOKE ALL ON FUNCTION platform.reject_admin_mfa_transition() FROM PUBLIC;

GRANT EXECUTE ON FUNCTION platform.reject_admin_mfa_transition() TO stall_app;

REVOKE ALL ON FUNCTION platform.reject_admin_mfa_recovery_mutation() FROM PUBLIC;

GRANT EXECUTE ON FUNCTION platform.reject_admin_mfa_recovery_mutation() TO stall_app;

REVOKE ALL ON FUNCTION platform.reject_admin_mfa_recovery_truncate() FROM PUBLIC;

GRANT EXECUTE ON FUNCTION platform.reject_admin_mfa_recovery_truncate() TO stall_app;

DROP EVENT TRIGGER platform_reject_admin_append_only_change;

DROP EVENT TRIGGER platform_reject_admin_append_only_drop;

CREATE OR REPLACE FUNCTION platform.reject_admin_append_only_guard() RETURNS event_trigger
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
                AND (
                    right(dropped.object_identity, length(' on platform.admin_password_resets'))
                        = ' on platform.admin_password_resets'
                    OR right(dropped.object_identity, length(' on platform.admin_audit'))
                        = ' on platform.admin_audit'
                    OR right(dropped.object_identity, length(' on platform.admin_mfa_recovery_codes'))
                        = ' on platform.admin_mfa_recovery_codes'
                    OR dropped.object_identity = 'admin_mfa_transition on platform.admins'
                ) THEN
                RAISE EXCEPTION 'append-only triggers cannot be dropped'
                    USING ERRCODE = '25006';
            END IF;
            IF dropped.object_type = 'function'
                AND dropped.object_identity IN (
                    'platform.reject_admin_password_reset_mutation()',
                    'platform.reject_admin_password_reset_truncate()',
                    'platform.reject_admin_audit_mutation()',
                    'platform.reject_admin_audit_truncate()',
                    'platform.reject_admin_append_only_guard()',
                    'platform.reject_admin_mfa_transition()',
                    'platform.reject_admin_mfa_recovery_mutation()',
                    'platform.reject_admin_mfa_recovery_truncate()'
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
            AND command.objid IN (
                to_regclass('platform.admin_password_resets'),
                to_regclass('platform.admin_audit'),
                to_regclass('platform.admin_mfa_recovery_codes'),
                to_regclass('platform.admins')
            )
            AND EXISTS (
                SELECT 1
                FROM pg_trigger
                WHERE tgrelid = command.objid
                  AND NOT tgisinternal
                  AND (
                      tgenabled NOT IN ('O', 'A')
                      OR (
                          tgenabled <> 'A'
                          AND tgname ~ '(_append_only|_no_truncate|_no_delete|_transition)$'
                      )
                  )
            ) THEN
            RAISE EXCEPTION 'append-only triggers cannot be disabled'
                USING ERRCODE = '25006';
        END IF;
        IF command.command_tag IN ('CREATE FUNCTION', 'ALTER FUNCTION', 'ALTER ROUTINE')
            AND command.objid IN (
                'platform.reject_admin_password_reset_mutation()'::regprocedure,
                'platform.reject_admin_password_reset_truncate()'::regprocedure,
                'platform.reject_admin_audit_mutation()'::regprocedure,
                'platform.reject_admin_audit_truncate()'::regprocedure,
                'platform.reject_admin_append_only_guard()'::regprocedure,
                'platform.reject_admin_mfa_transition()'::regprocedure,
                'platform.reject_admin_mfa_recovery_mutation()'::regprocedure,
                'platform.reject_admin_mfa_recovery_truncate()'::regprocedure
            ) THEN
            RAISE EXCEPTION 'a guard function cannot be replaced'
                USING ERRCODE = '25006';
        END IF;
    END LOOP;
END;
$_$;

CREATE EVENT TRIGGER platform_reject_admin_append_only_change
    ON ddl_command_end
    WHEN TAG IN ('ALTER FUNCTION', 'ALTER ROUTINE', 'ALTER TABLE', 'CREATE FUNCTION')
    EXECUTE FUNCTION platform.reject_admin_append_only_guard();

ALTER EVENT TRIGGER platform_reject_admin_append_only_change ENABLE ALWAYS;

CREATE EVENT TRIGGER platform_reject_admin_append_only_drop
    ON sql_drop
    WHEN TAG IN ('DROP FUNCTION', 'DROP TRIGGER')
    EXECUTE FUNCTION platform.reject_admin_append_only_guard();

ALTER EVENT TRIGGER platform_reject_admin_append_only_drop ENABLE ALWAYS;

ALTER TABLE platform.admin_audit
    DROP CONSTRAINT IF EXISTS admin_audit_action_chk;

ALTER TABLE platform.admin_audit
    ADD CONSTRAINT admin_audit_action_chk CHECK (action = ANY (ARRAY[
        'admin.sign_in'::text,
        'admin.sign_in_refused'::text,
        'admin.mfa_enrolled'::text,
        'admin.mfa_refused'::text,
        'admin.mfa_cleared'::text,
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
        'store.email_verified'::text,
        'app.log_clear'::text,
        'store.load'::text,
        'probe_ban_lift'::text
    ]));
