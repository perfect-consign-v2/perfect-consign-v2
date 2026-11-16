-- stall_backup reads tenant tables. It does not execute tenant functions.
-- PUBLIC execute is revoked in this schema. Later functions in this schema
-- must GRANT EXECUTE to stall_poster (and to stall_vendor / stall_vendor_entry
-- only when that role calls them). The migrator role's default privileges
-- no longer hand EXECUTE to PUBLIC.

REVOKE ALL ON ALL FUNCTIONS IN SCHEMA public FROM PUBLIC;

REVOKE ALL ON ALL FUNCTIONS IN SCHEMA public FROM stall_backup;

GRANT EXECUTE ON ALL FUNCTIONS IN SCHEMA public TO stall_poster;

GRANT EXECUTE ON FUNCTION public.stall_vendor_prefer(text) TO stall_vendor;

GRANT EXECUTE ON FUNCTION public.stall_vendor_open(text) TO stall_vendor_entry;

GRANT EXECUTE ON FUNCTION public.stall_party_primary_email(uuid) TO stall_vendor_entry;

ALTER DEFAULT PRIVILEGES IN SCHEMA public REVOKE EXECUTE ON FUNCTIONS FROM PUBLIC;
