-- One clock and one default booth size for the mall.
-- Apply inside each tenant schema, after 019. The provisioner rewrites stall_poster.

CREATE TABLE mall (
    id boolean PRIMARY KEY DEFAULT true,
    time_zone text NOT NULL,
    booth_width_ft integer NOT NULL,
    booth_depth_ft integer NOT NULL,
    CONSTRAINT mall_one_row CHECK (id),
    CONSTRAINT mall_zone_chk CHECK (time_zone = btrim(time_zone) AND time_zone <> ''),
    CONSTRAINT mall_booth_size_chk CHECK (booth_width_ft > 0 AND booth_depth_ft > 0)
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

INSERT INTO mall (time_zone, booth_width_ft, booth_depth_ft)
VALUES ('America/Los_Angeles', 10, 10);

GRANT SELECT, UPDATE ON mall TO stall_poster;
REVOKE INSERT, DELETE, TRUNCATE, TRIGGER ON mall FROM stall_poster;
