-- One outbound mail door: file to platform.mail_outbox, deliver on the worker.

CREATE TABLE platform.mail_outbox (
    id uuid DEFAULT uuidv7() NOT NULL,
    tenant text NOT NULL,
    mailbox text NOT NULL,
    purpose text NOT NULL,
    address text NOT NULL,
    subject text NOT NULL,
    body text NOT NULL,
    created_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    sent_at timestamp with time zone,
    CONSTRAINT mail_outbox_pkey PRIMARY KEY (id),
    CONSTRAINT mail_outbox_tenant_chk CHECK (((tenant = btrim(tenant)) AND (tenant ~ '^[a-z][a-z0-9-]{0,62}$'::text))),
    CONSTRAINT mail_outbox_mailbox_chk CHECK ((mailbox = ANY (ARRAY['mall'::text, 'platform'::text]))),
    CONSTRAINT mail_outbox_purpose_chk CHECK (((purpose = btrim(purpose)) AND (char_length(purpose) >= 1) AND (char_length(purpose) <= 64))),
    CONSTRAINT mail_outbox_address_chk CHECK (((address = btrim(address)) AND (char_length(address) <= 200) AND (address ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'::text))),
    CONSTRAINT mail_outbox_subject_chk CHECK (((char_length(subject) >= 1) AND (char_length(subject) <= 200) AND (subject !~ '[\r\n]'::text))),
    CONSTRAINT mail_outbox_body_chk CHECK (((char_length(body) >= 1) AND (char_length(body) <= 20000)))
);

CREATE INDEX mail_outbox_waiting
    ON platform.mail_outbox (created_at)
    WHERE (sent_at IS NULL);

ALTER TABLE platform.jobs DROP CONSTRAINT jobs_kind_chk;
ALTER TABLE platform.jobs ADD CONSTRAINT jobs_kind_chk CHECK (kind = ANY (ARRAY[
    'assess-late-fees'::text,
    'vendor-digest'::text,
    'rent-reminder'::text,
    'reconcile-reports'::text,
    'sale-notice'::text,
    'vendor-letter'::text,
    'markdown-propose'::text,
    'outbound-mail'::text
]));

DROP INDEX IF EXISTS platform.jobs_tenant_kind_day_key;
CREATE UNIQUE INDEX jobs_tenant_kind_day_key
    ON platform.jobs USING btree (tenant, kind, ((payload ->> 'date'::text)))
    WHERE (((payload ->> 'letter'::text) IS NULL) AND ((payload ->> 'mail'::text) IS NULL));

CREATE UNIQUE INDEX IF NOT EXISTS jobs_tenant_kind_mail_key
    ON platform.jobs USING btree (tenant, kind, ((payload ->> 'mail'::text)))
    WHERE ((payload ->> 'mail'::text) IS NOT NULL);

CREATE FUNCTION platform.file_outbound_mail(
    p_tenant text,
    p_mailbox text,
    p_purpose text,
    p_address text,
    p_subject text,
    p_body text
) RETURNS uuid
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'platform'
    AS $_$
DECLARE
    poster text;
    tenant_slug text;
    mail_id uuid;
    today text;
BEGIN
    IF p_mailbox IS NULL OR p_mailbox NOT IN ('mall', 'platform') THEN
        RAISE EXCEPTION 'the mailbox is not a mailbox'
            USING ERRCODE = '23514';
    END IF;
    IF p_tenant IS NULL OR p_tenant !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RAISE EXCEPTION 'the mall is not a mall'
            USING ERRCODE = '23514';
    END IF;
    poster := nullif(current_setting('role', true), 'none');
    IF p_mailbox = 'platform' THEN
        IF poster IS NOT NULL AND poster <> '' THEN
            RAISE EXCEPTION 'platform mail is not filed as a poster'
                USING ERRCODE = '42501';
        END IF;
        IF p_tenant <> 'platform' THEN
            RAISE EXCEPTION 'platform mail names platform'
                USING ERRCODE = '23514';
        END IF;
        tenant_slug := 'platform';
    ELSIF poster IS NULL OR poster = '' THEN
        tenant_slug := p_tenant;
    ELSIF poster = 'stall_poster' THEN
        tenant_slug := 'mall';
        IF p_tenant <> tenant_slug THEN
            RAISE EXCEPTION 'a poster may only file mail for its own mall'
                USING ERRCODE = '42501';
        END IF;
    ELSIF poster ~ '^poster_[0-9a-f]{32}$' THEN
        SELECT slug INTO tenant_slug
        FROM platform.tenants
        WHERE schema_name = 'tenant_' || substr(poster, 8);
        IF tenant_slug IS NULL OR tenant_slug <> p_tenant THEN
            RAISE EXCEPTION 'a poster may only file mail for its own mall'
                USING ERRCODE = '42501';
        END IF;
    ELSE
        RAISE EXCEPTION 'a poster role must file the mail'
            USING ERRCODE = '42501';
    END IF;
    INSERT INTO platform.mail_outbox (tenant, mailbox, purpose, address, subject, body)
    VALUES (tenant_slug, p_mailbox, p_purpose, lower(p_address), p_subject, p_body)
    RETURNING id INTO mail_id;
    today := to_char((CURRENT_TIMESTAMP AT TIME ZONE 'UTC')::date, 'YYYY-MM-DD');
    INSERT INTO platform.jobs (tenant, kind, payload, run_at)
    VALUES (
        tenant_slug,
        'outbound-mail',
        jsonb_build_object('date', today, 'mail', mail_id::text),
        clock_timestamp()
    )
    ON CONFLICT (tenant, kind, (payload ->> 'mail')) WHERE ((payload ->> 'mail') IS NOT NULL) DO NOTHING;
    RETURN mail_id;
END;
$_$;

REVOKE ALL ON FUNCTION platform.file_outbound_mail(text, text, text, text, text, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION platform.file_outbound_mail(text, text, text, text, text, text) TO stall_app;
GRANT EXECUTE ON FUNCTION platform.file_outbound_mail(text, text, text, text, text, text) TO stall_poster;

GRANT SELECT, INSERT ON TABLE platform.mail_outbox TO stall_app;
GRANT SELECT, UPDATE ON TABLE platform.mail_outbox TO stall_worker;
GRANT SELECT, UPDATE ON TABLE platform.mail_outbox TO stall_poster;

CREATE OR REPLACE FUNCTION platform.grant_poster_jobs(poster text) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $_$
BEGIN
    IF poster IS NULL OR poster !~ '^[a-z_][a-z0-9_]{0,62}$' THEN
        RAISE EXCEPTION 'a poster role name is required'
            USING ERRCODE = '23514';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = poster) THEN
        RAISE EXCEPTION 'poster role % is missing', poster
            USING ERRCODE = '42704';
    END IF;
    IF to_regclass('platform.jobs') IS NULL THEN
        RAISE EXCEPTION 'platform.jobs is missing'
            USING ERRCODE = '42P01';
    END IF;
    EXECUTE format('REVOKE INSERT ON TABLE platform.jobs FROM %I', poster);
    EXECUTE format('GRANT SELECT ON TABLE platform.jobs TO %I', poster);
    EXECUTE format('GRANT EXECUTE ON FUNCTION platform.enqueue_vendor_letter(date, uuid) TO %I', poster);
    EXECUTE format('GRANT EXECUTE ON FUNCTION platform.file_outbound_mail(text, text, text, text, text, text) TO %I', poster);
    EXECUTE format('GRANT SELECT, UPDATE ON TABLE platform.mail_outbox TO %I', poster);
END;
$_$;

DO $$
DECLARE
    poster text;
BEGIN
    FOR poster IN
        SELECT rolname
        FROM pg_roles
        WHERE rolname = 'stall_poster' OR rolname ~ '^poster_[0-9a-f]{32}$'
    LOOP
        PERFORM platform.grant_poster_jobs(poster);
    END LOOP;
END;
$$;

CREATE OR REPLACE FUNCTION platform.wipe_tenant(target_slug text) RETURNS void
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'platform', 'pg_catalog'
    AS $_$
DECLARE
    schema_name text;
    poster text;
    reset_present boolean;
    mall_id uuid;
BEGIN
    IF target_slug IS NULL OR target_slug !~ '^[a-z][a-z0-9-]{0,62}$' THEN
        RAISE EXCEPTION 'refusing to remove %', target_slug;
    END IF;
    SELECT tenants.id, tenants.schema_name INTO mall_id, schema_name
    FROM platform.tenants AS tenants
    WHERE tenants.slug = target_slug;
    IF schema_name IS NULL THEN
        RETURN;
    END IF;
    IF schema_name !~ '^tenant_[0-9a-f]{32}$' THEN
        RAISE EXCEPTION 'refusing to remove %', schema_name;
    END IF;
    SELECT EXISTS (
        SELECT 1
        FROM platform.password_resets AS resets
        WHERE resets.tenant_id = mall_id
        LIMIT 1
    ) INTO reset_present;
    IF reset_present THEN
        RAISE EXCEPTION 'a password reset still names this mall';
    END IF;
    poster := CASE
        WHEN target_slug = 'mall' THEN 'stall_poster'
        ELSE 'poster_' || substr(schema_name, length('tenant_') + 1)
    END;
    DELETE FROM platform.jobs WHERE tenant = target_slug;
    DELETE FROM platform.mail_outbox WHERE tenant = target_slug;
    DELETE FROM platform.sign_in_directory WHERE tenant_id = mall_id;
    DELETE FROM platform.owner_sign_in_failures WHERE tenant_id = mall_id;
    DELETE FROM platform.password_reset_requests WHERE tenant_id = mall_id;
    IF to_regclass('platform.owner_email_verifications') IS NOT NULL THEN
        PERFORM set_config('event_triggers', 'off', true);
        ALTER TABLE platform.owner_email_verifications DISABLE TRIGGER owner_email_verifications_append_only;
        ALTER TABLE platform.owner_email_verifications DISABLE TRIGGER owner_email_verifications_no_truncate;
        DELETE FROM platform.owner_email_verifications
        WHERE owner_id IN (SELECT id FROM platform.owners WHERE tenant_id = mall_id);
        ALTER TABLE platform.owner_email_verifications ENABLE ALWAYS TRIGGER owner_email_verifications_append_only;
        ALTER TABLE platform.owner_email_verifications ENABLE ALWAYS TRIGGER owner_email_verifications_no_truncate;
        PERFORM set_config('event_triggers', 'on', true);
    END IF;
    DELETE FROM platform.owners WHERE tenant_id = mall_id;
    DELETE FROM platform.tenants WHERE slug = target_slug;
    EXECUTE format('DROP SCHEMA %I CASCADE', schema_name);
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = poster) THEN
        RETURN;
    END IF;
    IF EXISTS (
        SELECT 1
        FROM pg_auth_members AS membership
        JOIN pg_roles AS granted ON granted.oid = membership.roleid
        JOIN pg_roles AS login ON login.oid = membership.member
        WHERE granted.rolname = poster AND login.rolname = 'stall_app'
    ) THEN
        EXECUTE format('REVOKE %I FROM stall_app', poster);
    END IF;
    EXECUTE format('GRANT %I TO CURRENT_USER', poster);
    EXECUTE format('DROP OWNED BY %I', poster);
    EXECUTE format('DROP ROLE %I', poster);
END;
$_$;

REVOKE ALL ON FUNCTION platform.wipe_tenant(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION platform.wipe_tenant(text) TO stall_app;
