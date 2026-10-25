-- Per-recipient mail progress stays inside the mall schema.
-- A same-day address change is a new letter; the old address keeps its row.

CREATE TABLE public.job_mail_sent (
    kind text NOT NULL,
    mail_date date NOT NULL,
    email text NOT NULL,
    sent_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT job_mail_sent_pkey PRIMARY KEY (kind, mail_date, email),
    CONSTRAINT job_mail_sent_kind_chk CHECK (kind = ANY (ARRAY['vendor-digest'::text, 'rent-reminder'::text, 'sale-notice'::text])),
    CONSTRAINT job_mail_sent_email_chk CHECK (((email = btrim(email)) AND (char_length(email) >= 3) AND (char_length(email) <= 200)))
);

DO $$
BEGIN
    IF to_regclass('platform.job_mail_sent') IS NOT NULL THEN
        INSERT INTO public.job_mail_sent (kind, mail_date, email, sent_at)
        SELECT platform_row.kind, platform_row.mail_date, platform_row.email, platform_row.sent_at
        FROM platform.job_mail_sent AS platform_row
        JOIN platform.tenants AS mall ON mall.slug = platform_row.tenant
        WHERE mall.schema_name = current_schema()
        ON CONFLICT (kind, mail_date, email) DO NOTHING;
    END IF;
END $$;

GRANT SELECT, INSERT ON TABLE public.job_mail_sent TO stall_poster;
GRANT SELECT ON TABLE public.job_mail_sent TO stall_backup;
