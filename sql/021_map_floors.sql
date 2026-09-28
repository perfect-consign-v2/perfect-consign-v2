-- Named floors for the booth map. Apply inside each tenant schema, after 020.
-- A placed booth belongs to one floor. Overlap is checked on that floor only.
-- An insert that places a booth and leaves the floor blank is parked on the first floor.

CREATE TABLE map_floors (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    name text NOT NULL,
    sort_order integer NOT NULL,
    CONSTRAINT map_floors_name_chk CHECK (name = btrim(name) AND char_length(name) BETWEEN 1 AND 40),
    CONSTRAINT map_floors_sort_chk CHECK (sort_order >= 0)
);

CREATE UNIQUE INDEX map_floors_name_key ON map_floors (lower(name));

INSERT INTO map_floors (name, sort_order) VALUES ('Main Floor', 0);

ALTER TABLE booths
    ADD COLUMN floor_id uuid REFERENCES map_floors (id);

UPDATE booths
SET floor_id = (SELECT id FROM map_floors ORDER BY sort_order, lower(name) LIMIT 1)
WHERE x_ft IS NOT NULL;

ALTER TABLE booths
    ADD CONSTRAINT booths_floor_chk CHECK ((floor_id IS NULL) = (x_ft IS NULL));

DROP FUNCTION stall_assert_booth_on_floor(
    uuid, integer, integer, integer, integer, integer, integer, integer, integer
);

CREATE FUNCTION stall_assert_booth_on_floor(
    booth uuid,
    place_floor uuid,
    place_x integer,
    place_z integer,
    place_width integer,
    place_depth integer,
    default_width integer,
    default_depth integer,
    floor_width integer,
    floor_depth integer
)
RETURNS void
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    resolved_width integer;
    resolved_depth integer;
BEGIN
    IF place_x IS NULL THEN
        RETURN;
    END IF;
    resolved_width := COALESCE(place_width, default_width);
    resolved_depth := COALESCE(place_depth, default_depth);
    IF place_x + resolved_width > floor_width OR place_z + resolved_depth > floor_depth THEN
        RAISE EXCEPTION 'a booth cannot extend past the floor'
            USING ERRCODE = '23514';
    END IF;
    IF EXISTS (
        SELECT 1
        FROM public.booths AS other
        WHERE other.id IS DISTINCT FROM booth
          AND other.x_ft IS NOT NULL
          AND other.floor_id IS NOT DISTINCT FROM place_floor
          AND NOT (
              place_x + resolved_width <= other.x_ft
              OR other.x_ft + COALESCE(other.width_ft, default_width) <= place_x
              OR place_z + resolved_depth <= other.z_ft
              OR other.z_ft + COALESCE(other.depth_ft, default_depth) <= place_z
          )
    ) THEN
        RAISE EXCEPTION 'a booth cannot overlap another booth'
            USING ERRCODE = '23514';
    END IF;
END;
$$;

CREATE OR REPLACE FUNCTION stall_reject_booth_place()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    default_width integer;
    default_depth integer;
    floor_width integer;
    floor_depth integer;
BEGIN
    IF NEW.x_ft IS NULL THEN
        NEW.floor_id := NULL;
    ELSIF NEW.floor_id IS NULL THEN
        SELECT id INTO NEW.floor_id
        FROM public.map_floors
        ORDER BY sort_order, lower(name)
        LIMIT 1;
        IF NEW.floor_id IS NULL THEN
            RAISE EXCEPTION 'the mall has no floor'
                USING ERRCODE = '23514';
        END IF;
    END IF;
    SELECT booth_width_ft, booth_depth_ft, floor_width_ft, floor_depth_ft
    INTO default_width, default_depth, floor_width, floor_depth
    FROM public.mall
    FOR UPDATE;
    PERFORM public.stall_assert_booth_on_floor(
        NEW.id, NEW.floor_id, NEW.x_ft, NEW.z_ft, NEW.width_ft, NEW.depth_ft,
        default_width, default_depth, floor_width, floor_depth
    );
    RETURN NEW;
END;
$$;

CREATE TRIGGER booths_floor_id
    BEFORE UPDATE OF floor_id ON public.booths
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_booth_place();

ALTER TABLE booths ENABLE ALWAYS TRIGGER booths_floor_id;

CREATE OR REPLACE FUNCTION stall_reject_floor_change()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    placed record;
BEGIN
    PERFORM 1
    FROM public.mall
    FOR UPDATE;
    FOR placed IN
        SELECT id, floor_id, x_ft, z_ft, width_ft, depth_ft
        FROM public.booths
        WHERE x_ft IS NOT NULL
    LOOP
        PERFORM public.stall_assert_booth_on_floor(
            placed.id, placed.floor_id, placed.x_ft, placed.z_ft, placed.width_ft, placed.depth_ft,
            NEW.booth_width_ft, NEW.booth_depth_ft, NEW.floor_width_ft, NEW.floor_depth_ft
        );
    END LOOP;
    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION stall_reject_map_floor_insert()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF (SELECT count(*) FROM public.map_floors) >= 20 THEN
        RAISE EXCEPTION 'a mall keeps at most 20 floors'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER map_floors_limit
    BEFORE INSERT ON map_floors
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_map_floor_insert();

CREATE OR REPLACE FUNCTION stall_reject_map_floor_delete()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF (SELECT count(*) FROM public.map_floors) <= 1 THEN
        RAISE EXCEPTION 'the mall keeps at least one floor'
            USING ERRCODE = '25006';
    END IF;
    IF EXISTS (SELECT 1 FROM public.booths WHERE floor_id = OLD.id) THEN
        RAISE EXCEPTION 'take booths off this floor before removing it'
            USING ERRCODE = '23503';
    END IF;
    RETURN OLD;
END;
$$;

CREATE TRIGGER map_floors_no_delete
    BEFORE DELETE ON map_floors
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_map_floor_delete();

ALTER TABLE map_floors
    ENABLE ALWAYS TRIGGER map_floors_limit,
    ENABLE ALWAYS TRIGGER map_floors_no_delete;

GRANT SELECT, INSERT, UPDATE, DELETE ON map_floors TO stall_poster;
