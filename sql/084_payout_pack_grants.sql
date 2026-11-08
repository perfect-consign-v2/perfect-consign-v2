-- Recreated payout_pack / quote helpers in 080 lost poster SELECT/EXECUTE grants.
-- Consignor entry joins persons via vendors.party_id; prune needs DELETE on requests.
GRANT SELECT (id, active, party_id) ON TABLE public.vendors TO stall_vendor_entry;
GRANT SELECT (party_id, email) ON TABLE public.persons TO stall_vendor_entry;
GRANT SELECT, INSERT, DELETE ON TABLE public.vendor_entry_requests TO stall_vendor_entry;
GRANT INSERT ON TABLE public.vendor_entry_tokens TO stall_vendor_entry;

GRANT SELECT ON TABLE public.payout_pack TO stall_poster;
GRANT SELECT ON TABLE public.payout_pack TO stall_backup;
GRANT SELECT ON TABLE public.vendor_display TO stall_poster;
GRANT SELECT ON TABLE public.vendor_display TO stall_backup;
GRANT ALL ON FUNCTION public.stall_payout_pack(uuid, date, date, date, date) TO stall_poster;
GRANT ALL ON FUNCTION public.stall_payout_pack(uuid, date, date, date, date) TO stall_backup;
GRANT EXECUTE ON FUNCTION public.stall_payout_quote(uuid, date, date, date, date) TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_payout_quote(uuid, date, date, date, date) TO stall_backup;
GRANT EXECUTE ON FUNCTION public.stall_payout_run_quote(uuid, uuid) TO stall_poster;
GRANT EXECUTE ON FUNCTION public.stall_payout_run_quote(uuid, uuid) TO stall_backup;
