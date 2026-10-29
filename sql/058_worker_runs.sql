-- Record each php bin/work.php pass so operators can see the worker is alive.

CREATE TABLE platform.worker_runs (
    id uuid DEFAULT uuidv7() NOT NULL,
    worker text NOT NULL,
    started_at timestamp with time zone DEFAULT clock_timestamp() NOT NULL,
    finished_at timestamp with time zone,
    jobs_finished integer DEFAULT 0 NOT NULL,
    jobs_failed integer DEFAULT 0 NOT NULL,
    CONSTRAINT worker_runs_pkey PRIMARY KEY (id),
    CONSTRAINT worker_runs_worker_chk CHECK (
        ((worker = btrim(worker)) AND (char_length(worker) >= 1) AND (char_length(worker) <= 64))
    ),
    CONSTRAINT worker_runs_jobs_finished_chk CHECK ((jobs_finished >= 0)),
    CONSTRAINT worker_runs_jobs_failed_chk CHECK ((jobs_failed >= 0))
);

CREATE INDEX worker_runs_started_at_key
    ON platform.worker_runs (started_at DESC);

GRANT SELECT, DELETE ON TABLE platform.worker_runs TO stall_app;
GRANT SELECT, INSERT, UPDATE ON TABLE platform.worker_runs TO stall_worker;
