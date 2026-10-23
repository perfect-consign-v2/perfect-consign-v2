-- The web roles can enqueue a job. They already can read the queue.

GRANT INSERT ON TABLE platform.jobs TO stall_app;
GRANT INSERT ON TABLE platform.jobs TO stall_poster;

GRANT SELECT, INSERT, DELETE ON TABLE platform.owner_sign_in_failures TO stall_poster;
