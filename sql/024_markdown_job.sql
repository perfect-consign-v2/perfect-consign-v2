-- The morning pass proposes markdowns. It does not change a price.

ALTER TABLE platform.jobs DROP CONSTRAINT jobs_kind_chk;

ALTER TABLE platform.jobs ADD CONSTRAINT jobs_kind_chk CHECK (kind = ANY (ARRAY['assess-late-fees'::text, 'vendor-digest'::text, 'rent-reminder'::text, 'reconcile-reports'::text, 'sale-notice'::text, 'vendor-letter'::text, 'markdown-propose'::text]));
