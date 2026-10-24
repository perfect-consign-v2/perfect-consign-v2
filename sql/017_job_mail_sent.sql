-- One row per letter that has already gone out, so a retry does not send it again.

CREATE TABLE platform.job_mail_sent (
    tenant text NOT NULL,
    kind text NOT NULL,
    mail_date date NOT NULL,
    email text NOT NULL,
    sent_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT job_mail_sent_pkey PRIMARY KEY (tenant, kind, mail_date, email),
    CONSTRAINT job_mail_sent_kind_chk CHECK (kind = ANY (ARRAY['vendor-digest'::text, 'rent-reminder'::text, 'sale-notice'::text])),
    CONSTRAINT job_mail_sent_email_chk CHECK (((email = btrim(email)) AND (char_length(email) >= 3) AND (char_length(email) <= 200)))
);

GRANT SELECT, INSERT ON TABLE platform.job_mail_sent TO stall_app;
GRANT SELECT, INSERT ON TABLE platform.job_mail_sent TO stall_worker;
GRANT SELECT, INSERT ON TABLE platform.job_mail_sent TO stall_poster;

DO $$
DECLARE
    mall record;
    poster text;
BEGIN
    FOR mall IN SELECT slug, schema_name FROM platform.tenants LOOP
        IF mall.slug = 'mall' THEN
            poster := 'stall_poster';
        ELSE
            poster := 'poster_' || substr(mall.schema_name, 8);
        END IF;
        IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = poster) THEN
            EXECUTE format(
                'GRANT SELECT, INSERT ON TABLE platform.job_mail_sent TO %I',
                poster
            );
        END IF;
    END LOOP;
END $$;
