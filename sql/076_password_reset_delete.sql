-- Prune stale forgot-password rows as stall_app (pair with 075 client_address).

GRANT SELECT, INSERT, DELETE ON TABLE platform.password_reset_requests TO stall_app;
GRANT SELECT, INSERT, DELETE ON TABLE platform.admin_password_reset_requests TO stall_app;
