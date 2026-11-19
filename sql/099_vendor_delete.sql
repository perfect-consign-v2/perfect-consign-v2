-- An owner can delete a vendor that no journal line names.
-- vendors_referenced_no_delete still refuses a vendor on a posted line.

GRANT DELETE ON TABLE public.vendors TO stall_poster;
