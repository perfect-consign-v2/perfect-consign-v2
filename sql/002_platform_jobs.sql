-- Platform job queue. Applied once after 000_platform.sql. Idempotent for a database that already has the table.

CREATE TABLE IF NOT EXISTS platform.jobs (
    id uuid DEFAULT uuidv7() NOT NULL,
    tenant text NOT NULL,
    kind text NOT NULL,
    payload jsonb DEFAULT '{}'::jsonb NOT NULL,
    run_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    attempts integer DEFAULT 0 NOT NULL,
    locked_by text,
    locked_at timestamp with time zone,
    finished_at timestamp with time zone,
    error text,
    CONSTRAINT jobs_pkey PRIMARY KEY (id),
    CONSTRAINT jobs_tenant_chk CHECK (((tenant = btrim(tenant)) AND (tenant ~ '^[a-z][a-z0-9-]{0,62}$'::text))),
    CONSTRAINT jobs_kind_chk CHECK (kind = ANY (ARRAY['assess-late-fees'::text, 'vendor-digest'::text, 'reconcile-reports'::text])),
    CONSTRAINT jobs_attempts_chk CHECK (attempts >= 0)
);

CREATE UNIQUE INDEX IF NOT EXISTS jobs_tenant_kind_day_key
    ON platform.jobs (tenant, kind, (payload ->> 'date'::text));

GRANT SELECT ON TABLE platform.jobs TO stall_app;
