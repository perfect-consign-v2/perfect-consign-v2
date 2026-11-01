-- People lifecycle: suspend owners without deleting the row.

ALTER TABLE platform.owners
    ADD COLUMN IF NOT EXISTS can_sign_in boolean NOT NULL DEFAULT true;

GRANT UPDATE (can_sign_in) ON TABLE platform.owners TO stall_app;
