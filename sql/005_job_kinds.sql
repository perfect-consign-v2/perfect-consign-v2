-- Rent reminders use the same queue. Forward-only so 002 stays byte-for-byte after it is stamped.

ALTER TABLE platform.jobs DROP CONSTRAINT jobs_kind_chk;

ALTER TABLE platform.jobs ADD CONSTRAINT jobs_kind_chk CHECK (kind = ANY (ARRAY['assess-late-fees'::text, 'vendor-digest'::text, 'rent-reminder'::text, 'reconcile-reports'::text]));
