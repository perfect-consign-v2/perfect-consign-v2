-- A sale notice is its own job. Forward-only so 005 stays byte-for-byte.

ALTER TABLE platform.jobs DROP CONSTRAINT jobs_kind_chk;

ALTER TABLE platform.jobs ADD CONSTRAINT jobs_kind_chk CHECK (kind = ANY (ARRAY['assess-late-fees'::text, 'vendor-digest'::text, 'rent-reminder'::text, 'reconcile-reports'::text, 'sale-notice'::text]));
