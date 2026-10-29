-- One heartbeat row per worker name so idle passes do not flood worker_runs.

CREATE TABLE platform.worker_heartbeat (
    worker text NOT NULL,
    seen_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    last_jobs_finished integer DEFAULT 0 NOT NULL,
    last_jobs_failed integer DEFAULT 0 NOT NULL,
    CONSTRAINT worker_heartbeat_pkey PRIMARY KEY (worker),
    CONSTRAINT worker_heartbeat_worker_chk CHECK (
        ((worker = btrim(worker)) AND (char_length(worker) >= 1) AND (char_length(worker) <= 64))
    ),
    CONSTRAINT worker_heartbeat_jobs_finished_chk CHECK ((last_jobs_finished >= 0)),
    CONSTRAINT worker_heartbeat_jobs_failed_chk CHECK ((last_jobs_failed >= 0))
);

GRANT SELECT ON TABLE platform.worker_heartbeat TO stall_app;
GRANT SELECT, INSERT, UPDATE ON TABLE platform.worker_heartbeat TO stall_worker;
