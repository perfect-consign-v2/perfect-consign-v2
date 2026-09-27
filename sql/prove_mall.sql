-- Proof for the mall clock and default booth size. One transaction, then ROLLBACK.
-- Run with search_path set to the mall schema. The poster role is stall_poster.

BEGIN;

DO $$
BEGIN
    INSERT INTO mall (time_zone, booth_width_ft, booth_depth_ft)
    VALUES ('America/Chicago', 8, 8);
    RAISE EXCEPTION 'a second mall row was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE mall SET time_zone = '';
    RAISE EXCEPTION 'a blank mall time zone was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE mall SET time_zone = 'Not/AZone';
    RAISE EXCEPTION 'an unknown mall time zone was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    UPDATE mall SET booth_width_ft = 0;
    RAISE EXCEPTION 'a zero default booth size was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
BEGIN
    DELETE FROM mall;
    RAISE EXCEPTION 'removing the mall clock was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        NULL;
END $$;

DO $$
BEGIN
    TRUNCATE mall;
    RAISE EXCEPTION 'mall truncate was accepted';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        NULL;
END $$;

UPDATE mall
SET time_zone = 'America/Chicago', booth_width_ft = 12, booth_depth_ft = 8;

DO $$
DECLARE
    zone text;
    width integer;
    depth integer;
BEGIN
    SELECT time_zone, booth_width_ft, booth_depth_ft
    INTO zone, width, depth
    FROM mall;
    IF zone <> 'America/Chicago' OR width <> 12 OR depth <> 8 THEN
        RAISE EXCEPTION 'the mall clock did not keep the new zone and size';
    END IF;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE stall_poster;
    UPDATE mall SET time_zone = 'America/Los_Angeles', booth_width_ft = 10, booth_depth_ft = 10;
    IF (SELECT time_zone FROM mall) <> 'America/Los_Angeles' THEN
        RAISE EXCEPTION 'poster did not set the mall time zone';
    END IF;
    BEGIN
        DELETE FROM mall;
        RAISE EXCEPTION 'poster removed the mall clock';
    EXCEPTION
        WHEN insufficient_privilege OR SQLSTATE '25006' THEN
            NULL;
    END;
    RESET ROLE;
END $$;

ROLLBACK;
