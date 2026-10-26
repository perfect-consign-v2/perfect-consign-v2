-- Incomplete Instant Add lines sit on the register as drafts until vendor/description/price are filled.

CREATE TABLE public.ticket_draft_lines (
    id uuid DEFAULT uuidv7() NOT NULL,
    session_id uuid NOT NULL,
    draft_no integer NOT NULL,
    vendor_id uuid,
    vendor_label text DEFAULT ''::text NOT NULL,
    description text DEFAULT ''::text NOT NULL,
    price_text text DEFAULT ''::text NOT NULL,
    brand_id uuid,
    brand_label text DEFAULT ''::text NOT NULL,
    discount_kind text DEFAULT 'consignor_default'::text NOT NULL,
    discount_value text DEFAULT ''::text NOT NULL,
    attributes_json text DEFAULT '{}'::text NOT NULL,
    CONSTRAINT ticket_draft_lines_pkey PRIMARY KEY (id),
    CONSTRAINT ticket_draft_lines_session_draft_key UNIQUE (session_id, draft_no),
    CONSTRAINT ticket_draft_lines_no_chk CHECK (draft_no > 0),
    CONSTRAINT ticket_draft_lines_session_fkey
        FOREIGN KEY (session_id) REFERENCES public.register_sessions (id) ON DELETE CASCADE,
    CONSTRAINT ticket_draft_lines_vendor_fkey
        FOREIGN KEY (vendor_id) REFERENCES public.vendors (id),
    CONSTRAINT ticket_draft_lines_brand_fkey
        FOREIGN KEY (brand_id) REFERENCES public.brands (id)
);

CREATE INDEX ticket_draft_lines_session_idx ON public.ticket_draft_lines (session_id);
