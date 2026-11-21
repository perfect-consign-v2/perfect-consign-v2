-- The activity list reads newest-first, and by one account.

CREATE INDEX audit_events_listed_idx
    ON public.audit_events (at DESC, id DESC);

CREATE INDEX audit_events_account_listed_idx
    ON public.audit_events (account_id, at DESC, id DESC)
    WHERE account_id IS NOT NULL;
