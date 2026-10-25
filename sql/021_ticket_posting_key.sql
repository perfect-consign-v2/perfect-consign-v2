-- The sale key is chosen when the ticket opens and stored before tender.
-- A retry posts that same key. The receipt number stays journal_no.
-- Existing tickets receive the key from their id. A posted ticket cannot be updated.

ALTER TABLE public.tickets
    ADD COLUMN posting_key text GENERATED ALWAYS AS (('sale:'::text || (id)::text)) STORED;

ALTER TABLE public.tickets
    ADD CONSTRAINT tickets_posting_key_chk CHECK ((posting_key ~ '^sale:[0-9a-f-]{36}$'::text));

ALTER TABLE public.tickets
    ADD CONSTRAINT tickets_posting_key_key UNIQUE (posting_key);
