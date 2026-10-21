-- Append-only audit rows, and the check a report total must match the books.

CREATE FUNCTION public.stall_reject_audit_write() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    RAISE EXCEPTION 'an audit row cannot be changed'
        USING ERRCODE = '25006';
END;
$$;

CREATE FUNCTION public.stall_refuse_report_drift(report_minor bigint, books_minor bigint) RETURNS void
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF report_minor IS DISTINCT FROM books_minor THEN
        RAISE EXCEPTION 'a report disagrees with the books'
            USING ERRCODE = '23514';
    END IF;
END;
$$;

CREATE TABLE public.audit_events (
    id uuid DEFAULT uuidv7() NOT NULL,
    actor text NOT NULL,
    role text NOT NULL,
    action text NOT NULL,
    entity_type text NOT NULL,
    entity_id text NOT NULL,
    request_id text NOT NULL,
    at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    CONSTRAINT audit_events_pkey PRIMARY KEY (id),
    CONSTRAINT audit_events_actor_chk CHECK (actor = btrim(actor) AND actor <> ''),
    CONSTRAINT audit_events_role_chk CHECK (role = ANY (ARRAY['owner'::text, 'cashier'::text, 'vendor'::text, 'books'::text])),
    CONSTRAINT audit_events_action_chk CHECK (action = btrim(action) AND action <> '')
);

CREATE TRIGGER audit_events_no_delete
    BEFORE UPDATE OR DELETE ON public.audit_events
    FOR EACH ROW EXECUTE FUNCTION public.stall_reject_audit_write();

CREATE TRIGGER audit_events_no_truncate
    BEFORE TRUNCATE ON public.audit_events
    FOR EACH STATEMENT EXECUTE FUNCTION public.stall_reject_audit_write();

ALTER TABLE public.audit_events
    ENABLE ALWAYS TRIGGER audit_events_no_delete,
    ENABLE ALWAYS TRIGGER audit_events_no_truncate;

GRANT SELECT, INSERT ON TABLE public.audit_events TO stall_poster;
