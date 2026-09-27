-- One clock, one default booth size, and one floor for the mall.
-- Apply inside each tenant schema, after 019. The provisioner rewrites stall_poster.

CREATE TABLE mall (
    id boolean PRIMARY KEY DEFAULT true,
    time_zone text NOT NULL,
    booth_width_ft integer NOT NULL,
    booth_depth_ft integer NOT NULL,
    floor_width_ft integer NOT NULL,
    floor_depth_ft integer NOT NULL,
    CONSTRAINT mall_one_row CHECK (id),
    CONSTRAINT mall_zone_chk CHECK (time_zone = btrim(time_zone) AND time_zone <> ''),
    CONSTRAINT mall_booth_size_chk CHECK (booth_width_ft > 0 AND booth_depth_ft > 0),
    CONSTRAINT mall_floor_chk CHECK (floor_width_ft > 0 AND floor_depth_ft > 0)
);

CREATE OR REPLACE FUNCTION mall_reject_zone()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    BEGIN
        PERFORM (CURRENT_TIMESTAMP AT TIME ZONE NEW.time_zone);
    EXCEPTION
        WHEN invalid_parameter_value THEN
            RAISE EXCEPTION 'mall time zone is not an IANA name'
                USING ERRCODE = '23514';
    END;
    RETURN NEW;
END;
$$;

CREATE TRIGGER mall_zone
    BEFORE INSERT OR UPDATE OF time_zone ON mall
    FOR EACH ROW
    EXECUTE FUNCTION mall_reject_zone();

CREATE OR REPLACE FUNCTION mall_reject_delete()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    RAISE EXCEPTION 'the mall clock cannot be removed'
        USING ERRCODE = '25006';
END;
$$;

CREATE TRIGGER mall_no_delete
    BEFORE DELETE ON mall
    FOR EACH ROW
    EXECUTE FUNCTION mall_reject_delete();

CREATE TRIGGER mall_no_truncate
    BEFORE TRUNCATE ON mall
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

ALTER TABLE mall
    ENABLE ALWAYS TRIGGER mall_no_delete,
    ENABLE ALWAYS TRIGGER mall_no_truncate;

-- A placed booth uses its own size, or the mall default. It must stay on the floor
-- and clear every other placed booth. An unplaced booth is not on the floor.
CREATE OR REPLACE FUNCTION stall_assert_booth_on_floor(
    booth uuid,
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
    SELECT booth_width_ft, booth_depth_ft, floor_width_ft, floor_depth_ft
    INTO default_width, default_depth, floor_width, floor_depth
    FROM public.mall;
    PERFORM public.stall_assert_booth_on_floor(
        NEW.id, NEW.x_ft, NEW.z_ft, NEW.width_ft, NEW.depth_ft,
        default_width, default_depth, floor_width, floor_depth
    );
    RETURN NEW;
END;
$$;

CREATE TRIGGER booths_on_floor
    BEFORE INSERT OR UPDATE OF x_ft, z_ft, width_ft, depth_ft ON public.booths
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_booth_place();

CREATE OR REPLACE FUNCTION stall_reject_floor_change()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    placed record;
BEGIN
    FOR placed IN
        SELECT id, x_ft, z_ft, width_ft, depth_ft
        FROM public.booths
        WHERE x_ft IS NOT NULL
    LOOP
        PERFORM public.stall_assert_booth_on_floor(
            placed.id, placed.x_ft, placed.z_ft, placed.width_ft, placed.depth_ft,
            NEW.booth_width_ft, NEW.booth_depth_ft, NEW.floor_width_ft, NEW.floor_depth_ft
        );
    END LOOP;
    RETURN NEW;
END;
$$;

CREATE TRIGGER mall_floor
    BEFORE UPDATE OF booth_width_ft, booth_depth_ft, floor_width_ft, floor_depth_ft ON mall
    FOR EACH ROW
    EXECUTE FUNCTION stall_reject_floor_change();

ALTER TABLE booths ENABLE ALWAYS TRIGGER booths_on_floor;
ALTER TABLE mall ENABLE ALWAYS TRIGGER mall_floor;

INSERT INTO mall (time_zone, booth_width_ft, booth_depth_ft, floor_width_ft, floor_depth_ft)
VALUES ('America/Los_Angeles', 10, 10, 120, 90);

GRANT SELECT, UPDATE ON mall TO stall_poster;
REVOKE INSERT, DELETE, TRUNCATE, TRIGGER ON mall FROM stall_poster;
