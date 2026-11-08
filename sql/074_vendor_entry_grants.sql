-- Narrow grants for stall_vendor_entry on consignor entry tables/functions.

GRANT USAGE ON SCHEMA public TO stall_vendor_entry;

GRANT SELECT (id, active) ON TABLE public.vendors TO stall_vendor_entry;
GRANT SELECT, INSERT, DELETE ON TABLE public.vendor_entry_requests TO stall_vendor_entry;
GRANT INSERT ON TABLE public.vendor_entry_tokens TO stall_vendor_entry;
GRANT EXECUTE ON FUNCTION public.stall_vendor_open(text) TO stall_vendor_entry;
