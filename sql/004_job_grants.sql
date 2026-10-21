-- The web session uses the poster role, which does not inherit stall_app.

GRANT SELECT ON TABLE platform.jobs TO stall_poster;
