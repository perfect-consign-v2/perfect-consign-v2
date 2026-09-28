-- Proof for named map floors. One transaction, then ROLLBACK.
-- Run with search_path set to the mall schema.

BEGIN;

DO $$
BEGIN
    INSERT INTO map_floors (name, sort_order) VALUES ('Main Floor', 1);
    RAISE EXCEPTION 'a duplicate floor name was accepted';
EXCEPTION
    WHEN unique_violation THEN
        NULL;
END $$;

INSERT INTO map_floors (name, sort_order) VALUES ('Annex', 1);

DO $$
DECLARE
    main_floor uuid;
    annex uuid;
BEGIN
    SELECT id INTO main_floor FROM map_floors WHERE name = 'Main Floor';
    SELECT id INTO annex FROM map_floors WHERE name = 'Annex';
    INSERT INTO booths (id, code, name, width_ft, depth_ft, x_ft, z_ft, floor_id)
    VALUES ('01900000-0000-7000-8000-000000000f01', 'F1', 'Main spot', 10, 10, 0, 0, main_floor);
    INSERT INTO booths (id, code, name, width_ft, depth_ft, x_ft, z_ft, floor_id)
    VALUES ('01900000-0000-7000-8000-000000000f02', 'F2', 'Annex spot', 10, 10, 0, 0, annex);
    IF (SELECT floor_id FROM booths WHERE id = '01900000-0000-7000-8000-000000000f02') IS DISTINCT FROM annex THEN
        RAISE EXCEPTION 'a booth did not stay on its own floor';
    END IF;
END $$;

DO $$
DECLARE
    main_floor uuid;
BEGIN
    SELECT id INTO main_floor FROM map_floors WHERE name = 'Main Floor';
    INSERT INTO booths (code, name, width_ft, depth_ft, x_ft, z_ft, floor_id)
    VALUES ('F3', 'Overlap', 10, 10, 0, 0, main_floor);
    RAISE EXCEPTION 'two booths overlapped on the same floor';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        NULL;
END $$;

DO $$
DECLARE
    annex uuid;
BEGIN
    SELECT id INTO annex FROM map_floors WHERE name = 'Annex';
    DELETE FROM map_floors WHERE id = annex;
    RAISE EXCEPTION 'a floor with booths was removed';
EXCEPTION
    WHEN SQLSTATE '23503' THEN
        NULL;
END $$;

UPDATE booths
SET x_ft = NULL, z_ft = NULL
WHERE id = '01900000-0000-7000-8000-000000000f02';

DELETE FROM map_floors WHERE name = 'Annex';

DO $$
BEGIN
    DELETE FROM map_floors WHERE name = 'Main Floor';
    RAISE EXCEPTION 'the last floor was removed';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        NULL;
END $$;

ROLLBACK;
