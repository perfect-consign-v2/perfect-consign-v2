-- Sealed change path for guarded functions: a plain replace is refused, a
-- forged flag is refused, platform.replace_guarded_function changes exactly
-- the one named guard inside a migration, keeps its exceptions, writes one
-- append-only row, and closes the flag again. Who may call it and read it.
-- The script rolls back.

BEGIN;

DO $$
DECLARE
    audit_guard oid := 'platform.reject_admin_audit_mutation()'::regprocedure;
    ledger_guard oid := 'public.ledger_reject_mutation()'::regprocedure;
    kept_body text := E'\nBEGIN\n    -- sealed change proof\n    RAISE EXCEPTION ''an admin audit row cannot be updated or deleted''\n        USING ERRCODE = ''25006'';\nEND;\n';
    ledger_body text := E'\nBEGIN\n    -- sealed change proof\n    RAISE EXCEPTION ''a posted journal and its lines cannot be updated or deleted''\n        USING ERRCODE = ''25006'';\nEND;\n';
    r record;
    n integer;
BEGIN
    -- The guarded list comes from the catalog.
    IF NOT platform.guarded_function(audit_guard)
        OR NOT platform.guarded_function(ledger_guard)
        OR NOT platform.guarded_function('platform.reject_guard_function_replace()'::regprocedure)
        OR NOT platform.guarded_function('platform.reject_guarded_function_change_mutation()'::regprocedure)
        OR NOT platform.guarded_function('platform.sealed_change_open(oid)'::regprocedure)
        OR platform.guarded_function('platform.record_owner_backup(uuid, uuid, bigint, text)'::regprocedure) THEN
        RAISE EXCEPTION 'the guarded list is wrong';
    END IF;

    -- A plain replace is refused, and so is one with a forged flag.
    BEGIN
        EXECUTE $sql$CREATE OR REPLACE FUNCTION platform.reject_admin_audit_mutation() RETURNS trigger
            LANGUAGE plpgsql SET search_path TO 'pg_catalog'
            AS $body$ BEGIN RETURN NEW; END; $body$ $sql$;
        RAISE EXCEPTION 'a plain replace of a guard was accepted';
    EXCEPTION WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
            RAISE;
        END IF;
    END;
    BEGIN
        PERFORM set_config('platform.sealed_change', audit_guard::text, true);
        EXECUTE $sql$CREATE OR REPLACE FUNCTION platform.reject_admin_audit_mutation() RETURNS trigger
            LANGUAGE plpgsql SET search_path TO 'pg_catalog'
            AS $body$ BEGIN RETURN NEW; END; $body$ $sql$;
        RAISE EXCEPTION 'a forged flag let a guard be replaced';
    EXCEPTION WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
            RAISE;
        END IF;
    END;
    PERFORM set_config('platform.sealed_change', '', true);
    BEGIN
        SET LOCAL ROLE stall_app;
        INSERT INTO platform.guarded_function_changes (function_oid, function_name, old_sha256, new_sha256, reason, migration)
        VALUES (ledger_guard, 'x', repeat('a', 64), repeat('b', 64), 'a forged change row', '999_forged.sql');
        RAISE EXCEPTION 'the app wrote a change row';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;

    -- Outside a migration the path is closed.
    PERFORM set_config('stall.migration', '', true);
    BEGIN
        PERFORM platform.replace_guarded_function('platform.reject_admin_audit_mutation()', kept_body, 'proof outside a migration');
        RAISE EXCEPTION 'a guarded change outside a migration was accepted';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    PERFORM set_config('stall.migration', '999_sealed_change_proof.sql', true);

    -- Bad requests are refused.
    BEGIN
        PERFORM platform.replace_guarded_function('platform.reject_admin_audit_mutation()', kept_body, 'short');
        RAISE EXCEPTION 'a short reason was accepted';
    EXCEPTION WHEN invalid_parameter_value THEN NULL;
    END;
    BEGIN
        PERFORM platform.replace_guarded_function('reject_admin_audit_mutation()', kept_body, 'no schema in the name');
        RAISE EXCEPTION 'a name without a schema was accepted';
    EXCEPTION WHEN invalid_parameter_value THEN NULL;
    END;
    BEGIN
        PERFORM platform.replace_guarded_function('platform.no_such_guard()', kept_body, 'a function that is not there');
        RAISE EXCEPTION 'a missing function was accepted';
    EXCEPTION WHEN undefined_function THEN NULL;
    END;
    BEGIN
        PERFORM platform.replace_guarded_function(
            'platform.record_owner_backup(uuid, uuid, bigint, text)', kept_body, 'not a guarded function');
        RAISE EXCEPTION 'an unguarded function went through the sealed path';
    EXCEPTION WHEN invalid_parameter_value THEN NULL;
    END;
    BEGIN
        PERFORM platform.replace_guarded_function(
            'platform.sealed_change_open(oid)', E'BEGIN RETURN true; END;', 'open every guard at once');
        RAISE EXCEPTION 'the sealed path changed itself';
    EXCEPTION WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%cannot change itself%' THEN
            RAISE;
        END IF;
    END;
    BEGIN
        PERFORM platform.replace_guarded_function(
            'platform.reject_admin_audit_mutation()', E'BEGIN RETURN NEW; END;', 'drop the append-only refusal');
        RAISE EXCEPTION 'a body without the append-only exception was accepted';
    EXCEPTION WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%drops the append-only exception%' THEN
            RAISE;
        END IF;
    END;
    BEGIN
        PERFORM platform.replace_guarded_function(
            'platform.reject_admin_audit_mutation()',
            E'BEGIN RAISE EXCEPTION ''something else'' USING ERRCODE = ''25006''; END;',
            'change the refusal words');
        RAISE EXCEPTION 'a body with other refusal words was accepted';
    EXCEPTION WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%drops the exception%' THEN
            RAISE;
        END IF;
    END;

    -- A good change replaces exactly that function and logs it.
    PERFORM platform.replace_guarded_function(
        'platform.reject_admin_audit_mutation()', kept_body, 'proof: keep the refusal, add a comment');
    IF (SELECT prosrc FROM pg_proc WHERE oid = audit_guard) IS DISTINCT FROM kept_body THEN
        RAISE EXCEPTION 'the guard body was not replaced';
    END IF;
    IF (SELECT prosecdef FROM pg_proc WHERE oid = audit_guard)
        OR (SELECT proconfig FROM pg_proc WHERE oid = audit_guard) IS DISTINCT FROM ARRAY['search_path=pg_catalog'] THEN
        RAISE EXCEPTION 'the guard settings changed';
    END IF;
    IF COALESCE(current_setting('platform.sealed_change', true), '') <> '' THEN
        RAISE EXCEPTION 'the sealed flag stayed open';
    END IF;
    SELECT * INTO r FROM platform.guarded_function_changes WHERE function_oid = audit_guard;
    IF r.function_name <> 'platform.reject_admin_audit_mutation()'
        OR r.migration <> '999_sealed_change_proof.sql'
        OR r.reason <> 'proof: keep the refusal, add a comment'
        OR r.new_sha256 <> encode(sha256(convert_to(kept_body, 'UTF8')), 'hex')
        OR r.old_sha256 = r.new_sha256
        OR r.xact <> pg_current_xact_id() THEN
        RAISE EXCEPTION 'the change row is wrong';
    END IF;

    -- The row stays, and the same transaction cannot replace it again by hand.
    BEGIN
        EXECUTE $sql$CREATE OR REPLACE FUNCTION platform.reject_admin_audit_mutation() RETURNS trigger
            LANGUAGE plpgsql SET search_path TO 'pg_catalog'
            AS $body$ BEGIN RETURN NEW; END; $body$ $sql$;
        RAISE EXCEPTION 'a second replace by hand was accepted';
    EXCEPTION WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%a guard function cannot be replaced%' THEN
            RAISE;
        END IF;
    END;
    BEGIN
        UPDATE platform.guarded_function_changes SET reason = 'rewritten reason here';
        RAISE EXCEPTION 'a change row was rewritten';
    EXCEPTION WHEN SQLSTATE '25006' THEN NULL;
    END;
    BEGIN
        DELETE FROM platform.guarded_function_changes;
        RAISE EXCEPTION 'a change row was deleted';
    EXCEPTION WHEN SQLSTATE '25006' THEN NULL;
    END;
    BEGIN
        TRUNCATE platform.guarded_function_changes;
        RAISE EXCEPTION 'the change log was truncated';
    EXCEPTION WHEN SQLSTATE '25006' THEN NULL;
    END;
    BEGIN
        ALTER TABLE platform.guarded_function_changes DISABLE TRIGGER guarded_function_changes_append_only;
        RAISE EXCEPTION 'the change log trigger was switched off';
    EXCEPTION WHEN SQLSTATE '25006' THEN NULL;
    END;
    BEGIN
        DROP TRIGGER guarded_function_changes_no_truncate ON platform.guarded_function_changes;
        RAISE EXCEPTION 'the change log trigger was dropped';
    EXCEPTION WHEN SQLSTATE '25006' THEN NULL;
    END;

    -- A store's guard goes through the same path, and so does an event trigger guard.
    PERFORM platform.replace_guarded_function(
        'public.ledger_reject_mutation()', ledger_body, 'proof: a store guard through the sealed path');
    IF (SELECT prosrc FROM pg_proc WHERE oid = ledger_guard) IS DISTINCT FROM ledger_body THEN
        RAISE EXCEPTION 'the store guard body was not replaced';
    END IF;
    PERFORM platform.replace_guarded_function(
        'platform.reject_guard_function_again()',
        (SELECT prosrc FROM pg_proc WHERE oid = 'platform.reject_guard_function_again()'::regprocedure) || E'\n-- proof\n',
        'proof: an event trigger guard through the sealed path');
    SELECT count(*) INTO n FROM platform.guarded_function_changes WHERE xact = pg_current_xact_id();
    IF n <> 3 THEN
        RAISE EXCEPTION 'expected three change rows, found %', n;
    END IF;
END $$;

-- Only the migration role may call the path or read the log.
DO $$
BEGIN
    IF has_function_privilege('stall_app', 'platform.replace_guarded_function(text, text, text)', 'EXECUTE')
        OR has_function_privilege('stall_worker', 'platform.replace_guarded_function(text, text, text)', 'EXECUTE')
        OR has_function_privilege('stall_provisioner', 'platform.replace_guarded_function(text, text, text)', 'EXECUTE')
        OR has_table_privilege('stall_app', 'platform.guarded_function_changes', 'SELECT,INSERT,UPDATE,DELETE')
        OR has_table_privilege('stall_worker', 'platform.guarded_function_changes', 'SELECT,INSERT,UPDATE,DELETE') THEN
        RAISE EXCEPTION 'a non-migration role can reach the sealed path';
    END IF;
END $$;

ROLLBACK;
