REVOKE ALL ON TABLE public.ticket_draft_lines FROM PUBLIC;
REVOKE ALL ON TABLE public.ticket_draft_lines FROM stall_poster;
REVOKE ALL ON TABLE public.ticket_draft_lines FROM stall_backup;
GRANT SELECT, INSERT, UPDATE, DELETE ON TABLE public.ticket_draft_lines TO stall_poster;
GRANT SELECT ON TABLE public.ticket_draft_lines TO stall_backup;
