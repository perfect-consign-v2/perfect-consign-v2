-- Session A places a booth and holds the mall row, then commits.
BEGIN;
SELECT booth_width_ft FROM mall FOR UPDATE;
INSERT INTO booths (id, code, name, width_ft, depth_ft, x_ft, z_ft)
VALUES (
    '01900000-0000-7000-8000-00000000d501',
    'race-place',
    'Race place',
    10,
    10,
    0,
    0
);
SELECT pg_sleep(6);
COMMIT;
