-- Session B waits on the same unit. After A commits, the second snap stays unposted.
BEGIN;
CREATE TEMP TABLE snap_result (journal_no bigint, refusal text) ON COMMIT DROP;
INSERT INTO snap_result (journal_no)
SELECT stall_sync_snap('01900000-0000-7000-8000-0000000009f2');
UPDATE snap_result
SET refusal = (
    SELECT refusal
    FROM register_snaps
    WHERE posting_key = '01900000-0000-7000-8000-0000000009f2'
);
SELECT 1 / (
    CASE
        WHEN journal_no IS NULL AND refusal = 'there are not enough on hand' THEN 1
        ELSE 0
    END
)
FROM snap_result;
COMMIT;
