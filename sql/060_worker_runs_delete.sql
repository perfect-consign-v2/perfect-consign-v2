-- stall_worker auto-prunes platform.worker_runs on each php bin/work.php pass.

GRANT DELETE ON TABLE platform.worker_runs TO stall_worker;
