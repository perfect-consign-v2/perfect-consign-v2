-- Progress rows now live in each mall. Drop the shared table after every tenant has copied.

DO $$
DECLARE
    mall record;
    poster text;
BEGIN
    IF to_regclass('platform.job_mail_sent') IS NULL THEN
        NULL;
    ELSIF EXISTS (
        SELECT 1
        FROM platform.tenants AS tenant_row
        WHERE NOT EXISTS (
            SELECT 1
            FROM pg_catalog.pg_class AS cls
            JOIN pg_catalog.pg_namespace AS nsp ON nsp.oid = cls.relnamespace
            WHERE nsp.nspname = tenant_row.schema_name
              AND cls.relname = 'job_mail_sent'
              AND cls.relkind = 'r'
        )
    ) THEN
        RAISE EXCEPTION '029_job_mail_sent.sql must be applied to every tenant before dropping platform.job_mail_sent';
    ELSE
        FOR mall IN SELECT slug, schema_name FROM platform.tenants LOOP
            IF mall.slug = 'mall' THEN
                poster := 'stall_poster';
            ELSE
                poster := 'poster_' || substr(mall.schema_name, 8);
            END IF;
            IF EXISTS (SELECT 1 FROM pg_roles WHERE rolname = poster) THEN
                EXECUTE format('REVOKE SELECT, INSERT ON TABLE platform.job_mail_sent FROM %I', poster);
            END IF;
        END LOOP;
        REVOKE SELECT, INSERT ON TABLE platform.job_mail_sent FROM stall_app;
        REVOKE SELECT, INSERT ON TABLE platform.job_mail_sent FROM stall_worker;
        REVOKE SELECT, INSERT ON TABLE platform.job_mail_sent FROM stall_poster;
        DROP TABLE platform.job_mail_sent;
    END IF;
END $$;
