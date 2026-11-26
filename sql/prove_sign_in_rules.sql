-- Sign-in limits: the rules row, the account names, the pair, address and
-- account-wide limits, the trusted device, the once-per-window alert, the
-- password reset, devices, the clean-up, and who may touch what.
-- The script rolls back.

BEGIN;

DO $$
DECLARE
    mall_id uuid;
    r record;
    device_a text := repeat('a', 64);
    device_b text := repeat('b', 64);
    forgotten uuid;
    n integer;
BEGIN
    SELECT id INTO mall_id FROM platform.tenants WHERE schema_name = current_schema();
    IF mall_id IS NULL THEN
        RAISE EXCEPTION 'the tenant for this schema is missing';
    END IF;

    -- The rules row and its bounds.
    SELECT * INTO r FROM platform.sign_in_rules;
    IF r.pair_limit <> 5 OR r.address_limit <> 40 OR r.email_soft_limit <> 20 OR r.window_minutes <> 15
        OR r.attempts_kept_days <> 90 OR r.device_days <> 180 THEN
        RAISE EXCEPTION 'the sign-in rules are not the defaults';
    END IF;
    BEGIN
        UPDATE platform.sign_in_rules SET pair_limit = 0;
        RAISE EXCEPTION 'a pair limit of 0 was kept';
    EXCEPTION WHEN check_violation THEN NULL;
    END;
    BEGIN
        INSERT INTO platform.sign_in_rules (id, pair_limit, address_limit, email_soft_limit, window_minutes, attempts_kept_days, device_days)
        VALUES (false, 5, 40, 20, 15, 90, 180);
        RAISE EXCEPTION 'a second rules row was kept';
    EXCEPTION WHEN check_violation THEN NULL;
    END;

    -- Account names.
    IF platform.sign_in_account('admin', ' Boss@Example.com ', NULL, NULL) <> 'admin:boss@example.com'
        OR platform.sign_in_account('store', 'x@example.com', mall_id, 'ada') <> 'store:' || mall_id::text || ':ada'
        OR platform.sign_in_account('store', 'Nobody@Example.com', NULL, NULL) <> 'email:nobody@example.com' THEN
        RAISE EXCEPTION 'the account names are wrong';
    END IF;
    BEGIN
        PERFORM platform.sign_in_account('store', '', NULL, NULL);
        RAISE EXCEPTION 'an empty account was named';
    EXCEPTION WHEN invalid_parameter_value THEN NULL;
    END;
    BEGIN
        PERFORM platform.sign_in_account('admin', 'a@example.com', mall_id, NULL);
        RAISE EXCEPTION 'an admin with a store was named';
    EXCEPTION WHEN invalid_parameter_value THEN NULL;
    END;
    BEGIN
        PERFORM platform.sign_in_account('store', NULL, mall_id, 'Bad Name');
        RAISE EXCEPTION 'a bad sign-in name was named';
    EXCEPTION WHEN invalid_parameter_value THEN NULL;
    END;

    -- Small limits so the proof stays short.
    UPDATE platform.sign_in_rules SET pair_limit = 2, address_limit = 4, email_soft_limit = 3;

    -- An attempt must be started before it is finished.
    BEGIN
        PERFORM platform.sign_in_finish('store', NULL, mall_id, 'ada', '192.0.2.1', 'ua', NULL, false, 'wrong_password');
        RAISE EXCEPTION 'an attempt finished without starting';
    EXCEPTION WHEN object_not_in_prerequisite_state THEN NULL;
    END;
    SELECT * INTO r FROM platform.sign_in_begin('store', NULL, mall_id, 'ada', '192.0.2.1', 'ua', NULL);
    IF r.verdict <> 'open' OR r.trusted OR r.alerted THEN
        RAISE EXCEPTION 'a first attempt was not open: %', r;
    END IF;
    BEGIN
        PERFORM platform.sign_in_finish('store', NULL, mall_id, 'ada', '192.0.2.99', 'ua', NULL, false, 'wrong_password');
        RAISE EXCEPTION 'an attempt finished from another address';
    EXCEPTION WHEN object_not_in_prerequisite_state THEN NULL;
    END;
    BEGIN
        PERFORM platform.sign_in_finish('store', NULL, mall_id, 'ada', '192.0.2.1', 'ua', NULL, false, 'locked_pair');
        RAISE EXCEPTION 'an attempt finished as a lock';
    EXCEPTION WHEN invalid_parameter_value THEN NULL;
    END;
    BEGIN
        PERFORM platform.sign_in_finish('store', NULL, mall_id, 'ada', '192.0.2.1', 'ua', NULL, false, 'ok');
        RAISE EXCEPTION 'a sign-in without a device was kept';
    EXCEPTION WHEN invalid_parameter_value THEN NULL;
    END;

    -- The pair limit: two wrong passwords from one address, then refused there.
    PERFORM platform.sign_in_finish('store', NULL, mall_id, 'ada', '192.0.2.1', 'ua', NULL, false, 'wrong_password');
    BEGIN
        PERFORM platform.sign_in_finish('store', NULL, mall_id, 'ada', '192.0.2.1', 'ua', NULL, false, 'wrong_password');
        RAISE EXCEPTION 'one start finished twice';
    EXCEPTION WHEN object_not_in_prerequisite_state THEN NULL;
    END;
    PERFORM platform.sign_in_begin('store', NULL, mall_id, 'ada', '192.0.2.1', 'ua', NULL);
    PERFORM platform.sign_in_finish('store', NULL, mall_id, 'ada', '192.0.2.1', 'ua', NULL, false, 'wrong_password');
    SELECT * INTO r FROM platform.sign_in_begin('store', NULL, mall_id, 'ada', '192.0.2.1', 'ua', NULL);
    IF r.verdict <> 'locked_pair' THEN
        RAISE EXCEPTION 'the pair limit did not refuse: %', r;
    END IF;
    IF (SELECT count(*) FROM platform.sign_in_attempts WHERE client_address = '192.0.2.1' AND outcome = 'locked_pair') <> 1 THEN
        RAISE EXCEPTION 'the refused attempt was not recorded';
    END IF;
    BEGIN
        PERFORM platform.sign_in_finish('store', NULL, mall_id, 'ada', '192.0.2.1', 'ua', NULL, false, 'wrong_password');
        RAISE EXCEPTION 'a refused attempt was finished';
    EXCEPTION WHEN object_not_in_prerequisite_state THEN NULL;
    END;

    -- Another address may still try the account.
    SELECT * INTO r FROM platform.sign_in_begin('store', NULL, mall_id, 'ada', '192.0.2.2', 'ua', NULL);
    IF r.verdict <> 'open' THEN
        RAISE EXCEPTION 'the pair limit refused another address: %', r;
    END IF;
    -- The right password from there remembers the device and ends the count.
    PERFORM platform.sign_in_finish('store', NULL, mall_id, 'ada', '192.0.2.2', 'Shop PC', device_a, false, 'ok');
    IF NOT EXISTS (
        SELECT 1 FROM platform.sign_in_devices
        WHERE account_key = 'store:' || mall_id::text || ':ada' AND token_hash = device_a AND label = 'Shop PC'
    ) THEN
        RAISE EXCEPTION 'the device was not remembered';
    END IF;
    SELECT * INTO r FROM platform.sign_in_begin('store', NULL, mall_id, 'ada', '192.0.2.1', 'ua', NULL);
    IF r.verdict <> 'open' THEN
        RAISE EXCEPTION 'a sign-in did not end the pair count: %', r;
    END IF;
    PERFORM platform.sign_in_finish('store', NULL, mall_id, 'ada', '192.0.2.1', 'ua', NULL, false, 'wrong_password');

    -- The account-wide limit: three wrong passwords from three addresses.
    PERFORM platform.sign_in_begin('store', NULL, mall_id, 'ada', '192.0.2.3', 'ua', NULL);
    PERFORM platform.sign_in_finish('store', NULL, mall_id, 'ada', '192.0.2.3', 'ua', NULL, false, 'wrong_password');
    PERFORM platform.sign_in_begin('store', NULL, mall_id, 'ada', '192.0.2.4', 'ua', NULL);
    PERFORM platform.sign_in_finish('store', NULL, mall_id, 'ada', '192.0.2.4', 'ua', NULL, false, 'wrong_password');
    SELECT * INTO r FROM platform.sign_in_begin('store', NULL, mall_id, 'ada', '192.0.2.5', 'ua', NULL);
    IF r.verdict <> 'locked_email' OR NOT r.alerted OR r.trusted THEN
        RAISE EXCEPTION 'the account-wide limit did not refuse and alert: %', r;
    END IF;
    SELECT * INTO r FROM platform.sign_in_begin('store', NULL, mall_id, 'ada', '192.0.2.6', 'ua', device_b);
    IF r.verdict <> 'locked_email' OR r.alerted THEN
        RAISE EXCEPTION 'the alert was sent twice in one window: %', r;
    END IF;
    -- The trusted browser still gets in, and is recorded as trusted.
    SELECT * INTO r FROM platform.sign_in_begin('store', NULL, mall_id, 'ada', '192.0.2.7', 'ua', device_a);
    IF r.verdict <> 'open' OR NOT r.trusted THEN
        RAISE EXCEPTION 'the trusted device was refused: %', r;
    END IF;
    PERFORM platform.sign_in_finish('store', NULL, mall_id, 'ada', '192.0.2.7', 'Shop PC', device_a, r.trusted, 'ok');
    IF (SELECT count(*) FROM platform.sign_in_devices WHERE account_key = 'store:' || mall_id::text || ':ada') <> 1 THEN
        RAISE EXCEPTION 'a device seen again was added twice';
    END IF;
    IF NOT (
        SELECT trusted_device FROM platform.sign_in_attempts
        WHERE client_address = '192.0.2.7' ORDER BY attempted_at DESC LIMIT 1
    ) THEN
        RAISE EXCEPTION 'the trusted sign-in was not marked trusted';
    END IF;

    -- A device belongs to one account.
    SELECT * INTO r FROM platform.sign_in_begin('store', NULL, mall_id, 'bea', '192.0.2.8', 'ua', device_a);
    IF r.trusted THEN
        RAISE EXCEPTION 'one account''s device trusted another';
    END IF;
    PERFORM platform.sign_in_finish('store', NULL, mall_id, 'bea', '192.0.2.8', 'ua', NULL, false, 'unknown_email');

    -- The address limit counts every account from one address.
    PERFORM platform.sign_in_begin('store', NULL, mall_id, 'cy', '192.0.2.8', 'ua', NULL);
    PERFORM platform.sign_in_finish('store', NULL, mall_id, 'cy', '192.0.2.8', 'ua', NULL, false, 'wrong_password');
    PERFORM platform.sign_in_begin('store', NULL, mall_id, 'dee', '192.0.2.8', 'ua', NULL);
    PERFORM platform.sign_in_finish('store', NULL, mall_id, 'dee', '192.0.2.8', 'ua', NULL, false, 'wrong_password');
    PERFORM platform.sign_in_begin('store', NULL, mall_id, 'eve', '192.0.2.8', 'ua', NULL);
    PERFORM platform.sign_in_finish('store', NULL, mall_id, 'eve', '192.0.2.8', 'ua', NULL, false, 'wrong_password');
    SELECT * INTO r FROM platform.sign_in_begin('store', NULL, mall_id, 'fay', '192.0.2.8', 'ua', NULL);
    IF r.verdict <> 'locked_address' THEN
        RAISE EXCEPTION 'the address limit did not refuse: %', r;
    END IF;

    -- An email that signs in nowhere is counted and refused, but nobody is told.
    FOR n IN 1..3 LOOP
        PERFORM platform.sign_in_begin('store', 'ghost@example.com', NULL, NULL, '192.0.2.' || (20 + n)::text, 'ua', NULL);
        PERFORM platform.sign_in_finish('store', 'ghost@example.com', NULL, NULL, '192.0.2.' || (20 + n)::text, 'ua', NULL, false, 'unknown_email');
    END LOOP;
    SELECT * INTO r FROM platform.sign_in_begin('store', 'ghost@example.com', NULL, NULL, '192.0.2.30', 'ua', NULL);
    IF r.verdict <> 'locked_email' OR r.alerted THEN
        RAISE EXCEPTION 'an unknown email was not refused quietly: %', r;
    END IF;
    PERFORM platform.sign_in_begin('store', 'ghost@example.com', NULL, NULL, '192.0.2.31', 'ua', device_a);
    BEGIN
        PERFORM platform.sign_in_begin('store', 'ghost2@example.com', NULL, NULL, '192.0.2.32', 'ua', NULL);
        PERFORM platform.sign_in_finish('store', 'ghost2@example.com', NULL, NULL, '192.0.2.32', 'ua', device_a, false, 'ok');
        RAISE EXCEPTION 'an unknown email signed in';
    EXCEPTION WHEN invalid_parameter_value THEN NULL;
    END;

    -- A password reset ends the count, and only an account can be reset.
    PERFORM platform.sign_in_begin('admin', 'boss@example.com', NULL, NULL, '192.0.2.40', 'ua', NULL);
    PERFORM platform.sign_in_finish('admin', 'boss@example.com', NULL, NULL, '192.0.2.40', 'ua', NULL, false, 'wrong_password');
    PERFORM platform.sign_in_begin('admin', 'boss@example.com', NULL, NULL, '192.0.2.40', 'ua', NULL);
    PERFORM platform.sign_in_finish('admin', 'boss@example.com', NULL, NULL, '192.0.2.40', 'ua', NULL, false, 'wrong_password');
    SELECT * INTO r FROM platform.sign_in_begin('admin', 'boss@example.com', NULL, NULL, '192.0.2.40', 'ua', NULL);
    IF r.verdict <> 'locked_pair' THEN
        RAISE EXCEPTION 'the admin pair limit did not refuse: %', r;
    END IF;
    PERFORM platform.sign_in_reset('admin', 'boss@example.com', NULL, NULL, '192.0.2.41', 'ua');
    SELECT * INTO r FROM platform.sign_in_begin('admin', 'boss@example.com', NULL, NULL, '192.0.2.40', 'ua', NULL);
    IF r.verdict <> 'open' THEN
        RAISE EXCEPTION 'a password reset did not end the count: %', r;
    END IF;
    PERFORM platform.sign_in_finish('admin', 'boss@example.com', NULL, NULL, '192.0.2.40', 'ua', NULL, false, 'wrong_password');
    BEGIN
        PERFORM platform.sign_in_reset('store', 'ghost@example.com', NULL, NULL, '192.0.2.41', 'ua');
        RAISE EXCEPTION 'an unknown email was reset';
    EXCEPTION WHEN invalid_parameter_value THEN NULL;
    END;

    -- Long input is cut to fit, never refused.
    PERFORM platform.sign_in_begin('store', NULL, mall_id, 'ada', repeat('9', 80), repeat('u', 400), NULL);
    PERFORM platform.sign_in_finish('store', NULL, mall_id, 'ada', repeat('9', 80), repeat('u', 400), NULL, false, 'wrong_password');

    -- Devices: listed without hashes, forgotten only by their account.
    IF (SELECT count(*) FROM platform.sign_in_device_list(mall_id, 'ada', NULL)) <> 1
        OR NOT (SELECT is_current FROM platform.sign_in_device_list(mall_id, 'ada', device_a))
        OR (SELECT is_current FROM platform.sign_in_device_list(mall_id, 'ada', device_b)) THEN
        RAISE EXCEPTION 'the device list is wrong';
    END IF;
    SELECT id INTO forgotten FROM platform.sign_in_device_list(mall_id, 'ada', NULL);
    IF platform.sign_in_device_forget(mall_id, 'bea', forgotten) THEN
        RAISE EXCEPTION 'another account forgot a device';
    END IF;
    IF NOT platform.sign_in_device_forget(mall_id, 'ada', forgotten) THEN
        RAISE EXCEPTION 'the account could not forget its device';
    END IF;
    SELECT * INTO r FROM platform.sign_in_begin('store', NULL, mall_id, 'ada', '192.0.2.50', 'ua', device_a);
    IF r.trusted THEN
        RAISE EXCEPTION 'a forgotten device was still trusted';
    END IF;
    PERFORM platform.sign_in_finish('store', NULL, mall_id, 'ada', '192.0.2.50', 'ua', device_a, false, 'ok');

    -- The clean-up: old attempts and unused devices go, recent ones stay.
    INSERT INTO platform.sign_in_attempts (attempted_at, door, account_key, client_address, user_agent, outcome, trusted_device)
    VALUES (clock_timestamp() - interval '91 days', 'store', 'email:old@example.com', '192.0.2.60', '', 'unknown_email', false);
    UPDATE platform.sign_in_devices SET last_seen_at = clock_timestamp() - interval '181 days'
    WHERE account_key = 'store:' || mall_id::text || ':ada';
    PERFORM platform.sign_in_reap();
    IF EXISTS (SELECT 1 FROM platform.sign_in_attempts WHERE account_key = 'email:old@example.com')
        OR EXISTS (SELECT 1 FROM platform.sign_in_devices WHERE account_key = 'store:' || mall_id::text || ':ada') THEN
        RAISE EXCEPTION 'the clean-up kept old rows';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM platform.sign_in_attempts WHERE client_address = '192.0.2.50') THEN
        RAISE EXCEPTION 'the clean-up removed a recent attempt';
    END IF;

    -- Checks on the rows themselves.
    BEGIN
        INSERT INTO platform.sign_in_attempts (door, account_key, client_address, user_agent, outcome, trusted_device)
        VALUES ('store', 'email:x@example.com', '', '', 'guessed', false);
        RAISE EXCEPTION 'an unknown outcome was kept';
    EXCEPTION WHEN check_violation THEN NULL;
    END;
    BEGIN
        INSERT INTO platform.sign_in_attempts (door, account_key, client_address, user_agent, outcome, trusted_device, alerted)
        VALUES ('store', 'email:x@example.com', '', '', 'ok', false, true);
        RAISE EXCEPTION 'an alert on a sign-in was kept';
    EXCEPTION WHEN check_violation THEN NULL;
    END;
    BEGIN
        INSERT INTO platform.sign_in_devices (account_key, token_hash, label)
        VALUES ('email:x@example.com', device_b, '');
        RAISE EXCEPTION 'a device for an unknown email was kept';
    EXCEPTION WHEN check_violation THEN NULL;
    END;

    -- Who may touch what.
    SET LOCAL ROLE stall_app;
    PERFORM count(*) FROM platform.sign_in_attempts;
    PERFORM * FROM platform.sign_in_rules;
    SELECT * INTO r FROM platform.sign_in_begin('store', NULL, mall_id, 'ada', '192.0.2.70', 'ua', NULL);
    PERFORM platform.sign_in_finish('store', NULL, mall_id, 'ada', '192.0.2.70', 'ua', NULL, false, 'wrong_password');
    BEGIN
        INSERT INTO platform.sign_in_attempts (door, account_key, client_address, user_agent, outcome, trusted_device)
        VALUES ('store', 'email:x@example.com', '', '', 'ok', false);
        RAISE EXCEPTION 'stall_app wrote an attempt directly';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    BEGIN
        DELETE FROM platform.sign_in_attempts;
        RAISE EXCEPTION 'stall_app deleted attempts';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    BEGIN
        UPDATE platform.sign_in_attempts SET outcome = 'ok';
        RAISE EXCEPTION 'stall_app changed attempts';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    BEGIN
        PERFORM token_hash FROM platform.sign_in_devices;
        RAISE EXCEPTION 'stall_app read device hashes';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    BEGIN
        UPDATE platform.sign_in_rules SET pair_limit = 100;
        RAISE EXCEPTION 'stall_app changed the rules';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    BEGIN
        PERFORM platform.sign_in_reap();
        RAISE EXCEPTION 'stall_app ran the clean-up';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    RESET ROLE;
    SET LOCAL ROLE stall_worker;
    PERFORM platform.sign_in_reap();
    BEGIN
        PERFORM platform.sign_in_begin('store', NULL, mall_id, 'ada', '192.0.2.71', 'ua', NULL);
        RAISE EXCEPTION 'stall_worker started a sign-in';
    EXCEPTION WHEN insufficient_privilege THEN NULL;
    END;
    RESET ROLE;
END;
$$;

ROLLBACK;
