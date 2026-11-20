-- Mail worker queue for platform admin, and one-time notice stamps.
-- stall_app can read platform.jobs but not a store's letters or mail setting.

ALTER TABLE platform.jobs
    ADD COLUMN IF NOT EXISTS overdue_noticed_at timestamp with time zone,
    ADD COLUMN IF NOT EXISTS abandoned_noticed_at timestamp with time zone;

CREATE OR REPLACE FUNCTION platform.job_attention(p_bucket text)
RETURNS TABLE (
    id text,
    tenant text,
    kind text,
    run_at text,
    finished_at text,
    attempts integer,
    error text,
    subject text,
    recipients text,
    mail_via text,
    mailbox text,
    payload_date text,
    due boolean
)
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path TO pg_catalog, platform
AS $_$
DECLARE
    job record;
    list_sql text;
    schema_name text;
    letter_id uuid;
    found_subject text;
    found_recipients text;
    found_via text;
    found_mailbox text;
    found_date text;
BEGIN
    IF p_bucket IS NULL OR p_bucket NOT IN ('open', 'overdue', 'given_up', 'abandoned') THEN
        RAISE EXCEPTION 'the job list is not a list'
            USING ERRCODE = '22023';
    END IF;
    IF p_bucket = 'open' THEN
        list_sql := $open$
            SELECT j.id, j.tenant, j.kind, j.payload, j.run_at, j.finished_at, j.attempts, j.error
            FROM platform.jobs AS j
            WHERE j.finished_at IS NULL
              AND j.locked_at IS NULL
              AND NOT (
                  j.kind = 'outbound-mail'
                  AND EXISTS (
                      SELECT 1
                      FROM platform.mail_outbox AS mail
                      WHERE mail.id::text = j.payload ->> 'mail'
                        AND mail.sent_at IS NOT NULL
                  )
              )
            ORDER BY j.run_at, j.id
        $open$;
    ELSIF p_bucket = 'overdue' THEN
        list_sql := $overdue$
            SELECT j.id, j.tenant, j.kind, j.payload, j.run_at, j.finished_at, j.attempts, j.error
            FROM platform.jobs AS j
            WHERE j.finished_at IS NULL
              AND j.locked_at IS NULL
              AND j.overdue_noticed_at IS NULL
              AND j.run_at < clock_timestamp() - interval '15 minutes'
              AND NOT (
                  j.kind = 'outbound-mail'
                  AND EXISTS (
                      SELECT 1
                      FROM platform.mail_outbox AS mail
                      WHERE mail.id::text = j.payload ->> 'mail'
                        AND mail.sent_at IS NOT NULL
                  )
              )
            ORDER BY j.run_at, j.id
        $overdue$;
    ELSIF p_bucket = 'given_up' THEN
        list_sql := $given$
            SELECT j.id, j.tenant, j.kind, j.payload, j.run_at, j.finished_at, j.attempts, j.error
            FROM platform.jobs AS j
            WHERE j.finished_at IS NOT NULL
              AND j.error IS NOT NULL
              AND btrim(j.error) <> ''
              AND j.attempts >= 5
            ORDER BY j.finished_at DESC, j.id
            LIMIT 50
        $given$;
    ELSE
        list_sql := $abandoned$
            SELECT j.id, j.tenant, j.kind, j.payload, j.run_at, j.finished_at, j.attempts, j.error
            FROM platform.jobs AS j
            WHERE j.finished_at IS NOT NULL
              AND j.error IS NOT NULL
              AND btrim(j.error) <> ''
              AND j.attempts >= 5
              AND j.abandoned_noticed_at IS NULL
            ORDER BY j.finished_at, j.id
        $abandoned$;
    END IF;

    FOR job IN EXECUTE list_sql
    LOOP
        found_subject := '';
        found_recipients := '';
        found_via := '';
        found_mailbox := '';
        found_date := COALESCE(job.payload ->> 'date', '');
        schema_name := NULL;
        IF job.tenant ~ '^[a-z][a-z0-9-]{0,62}$' THEN
            SELECT tenants.schema_name INTO schema_name
            FROM platform.tenants AS tenants
            WHERE tenants.slug = job.tenant;
        END IF;
        IF schema_name IS NOT NULL AND schema_name !~ '^tenant_[0-9a-f]{32}$' THEN
            schema_name := NULL;
        END IF;
        IF job.kind = 'vendor-letter'
           AND COALESCE(job.payload ->> 'letter', '') ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
           AND schema_name IS NOT NULL
        THEN
            letter_id := (job.payload ->> 'letter')::uuid;
            EXECUTE format('SELECT subject FROM %I.vendor_letters WHERE id = $1', schema_name)
                INTO found_subject USING letter_id;
            EXECUTE format(
                'SELECT string_agg(copy.email, '', '' ORDER BY copy.email) FROM %I.vendor_letter_copies AS copy WHERE copy.letter_id = $1',
                schema_name
            ) INTO found_recipients USING letter_id;
            EXECUTE format('SELECT receipt_mail_via FROM %I.mall LIMIT 1', schema_name)
                INTO found_via;
        ELSIF job.kind = 'outbound-mail'
              AND COALESCE(job.payload ->> 'mail', '') ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
        THEN
            SELECT mail.subject, mail.address, mail.mailbox
            INTO found_subject, found_recipients, found_mailbox
            FROM platform.mail_outbox AS mail
            WHERE mail.id = (job.payload ->> 'mail')::uuid;
            IF found_mailbox = 'mall' AND schema_name IS NOT NULL THEN
                EXECUTE format('SELECT receipt_mail_via FROM %I.mall LIMIT 1', schema_name)
                    INTO found_via;
            END IF;
        END IF;
        id := job.id::text;
        tenant := job.tenant;
        kind := job.kind;
        run_at := job.run_at::text;
        finished_at := COALESCE(job.finished_at::text, '');
        attempts := job.attempts;
        error := COALESCE(job.error, '');
        subject := COALESCE(found_subject, '');
        recipients := COALESCE(found_recipients, '');
        mail_via := COALESCE(found_via, '');
        mailbox := COALESCE(found_mailbox, '');
        payload_date := found_date;
        due := job.run_at <= clock_timestamp();
        RETURN NEXT;
    END LOOP;
END;
$_$;

REVOKE ALL ON FUNCTION platform.job_attention(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION platform.job_attention(text) TO stall_app;
GRANT EXECUTE ON FUNCTION platform.job_attention(text) TO stall_worker;
