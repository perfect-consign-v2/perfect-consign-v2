-- The poster may add identity rows and an open period. It still cannot edit a journal.
-- Apply inside each tenant schema. The provisioner rewrites stall_poster to that mall's role.

GRANT INSERT, UPDATE ON vendors, booths, booth_assignments, booth_rent_rates TO stall_poster;
GRANT DELETE ON booths TO stall_poster;
GRANT INSERT ON accounting_periods TO stall_poster;
REVOKE DELETE ON vendors, booth_assignments, booth_rent_rates, accounting_periods FROM stall_poster;
REVOKE TRUNCATE, TRIGGER ON vendors, booths, booth_assignments, booth_rent_rates, accounting_periods FROM stall_poster;
