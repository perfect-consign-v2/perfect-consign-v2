-- Cashier-facing unique refusals: plain English (no constraint-name maps in PHP).

CREATE OR REPLACE FUNCTION public.stall_refuse_duplicate_list_name() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM public.colors AS other
        WHERE TG_TABLE_NAME = 'colors'
          AND lower(other.name) = lower(NEW.name)
          AND other.id IS DISTINCT FROM NEW.id
    ) OR EXISTS (
        SELECT 1
        FROM public.categories AS other
        WHERE TG_TABLE_NAME = 'categories'
          AND lower(other.name) = lower(NEW.name)
          AND other.id IS DISTINCT FROM NEW.id
    ) OR EXISTS (
        SELECT 1
        FROM public.sizes AS other
        WHERE TG_TABLE_NAME = 'sizes'
          AND lower(other.name) = lower(NEW.name)
          AND other.id IS DISTINCT FROM NEW.id
    ) OR EXISTS (
        SELECT 1
        FROM public.conditions AS other
        WHERE TG_TABLE_NAME = 'conditions'
          AND lower(other.name) = lower(NEW.name)
          AND other.id IS DISTINCT FROM NEW.id
    ) OR EXISTS (
        SELECT 1
        FROM public.departments AS other
        WHERE TG_TABLE_NAME = 'departments'
          AND lower(other.name) = lower(NEW.name)
          AND other.id IS DISTINCT FROM NEW.id
    ) THEN
        RAISE EXCEPTION 'That name is already used.'
            USING ERRCODE = '23505';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS colors_refuse_duplicate_name ON public.colors;
CREATE TRIGGER colors_refuse_duplicate_name BEFORE INSERT OR UPDATE OF name ON public.colors
    FOR EACH ROW EXECUTE FUNCTION public.stall_refuse_duplicate_list_name();

DROP TRIGGER IF EXISTS categories_refuse_duplicate_name ON public.categories;
CREATE TRIGGER categories_refuse_duplicate_name BEFORE INSERT OR UPDATE OF name ON public.categories
    FOR EACH ROW EXECUTE FUNCTION public.stall_refuse_duplicate_list_name();

DROP TRIGGER IF EXISTS sizes_refuse_duplicate_name ON public.sizes;
CREATE TRIGGER sizes_refuse_duplicate_name BEFORE INSERT OR UPDATE OF name ON public.sizes
    FOR EACH ROW EXECUTE FUNCTION public.stall_refuse_duplicate_list_name();

DROP TRIGGER IF EXISTS conditions_refuse_duplicate_name ON public.conditions;
CREATE TRIGGER conditions_refuse_duplicate_name BEFORE INSERT OR UPDATE OF name ON public.conditions
    FOR EACH ROW EXECUTE FUNCTION public.stall_refuse_duplicate_list_name();

DROP TRIGGER IF EXISTS departments_refuse_duplicate_name ON public.departments;
CREATE TRIGGER departments_refuse_duplicate_name BEFORE INSERT OR UPDATE OF name ON public.departments
    FOR EACH ROW EXECUTE FUNCTION public.stall_refuse_duplicate_list_name();

CREATE OR REPLACE FUNCTION public.stall_refuse_duplicate_booth_code() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM public.booths AS other
        WHERE other.code = NEW.code AND other.id IS DISTINCT FROM NEW.id
    ) THEN
        RAISE EXCEPTION 'That booth is already used.'
            USING ERRCODE = '23505';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS booths_refuse_duplicate_code ON public.booths;
CREATE TRIGGER booths_refuse_duplicate_code BEFORE INSERT OR UPDATE OF code ON public.booths
    FOR EACH ROW EXECUTE FUNCTION public.stall_refuse_duplicate_booth_code();

CREATE OR REPLACE FUNCTION public.stall_refuse_duplicate_staff_username() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM public.staff AS other
        WHERE lower(other.username) = lower(NEW.username)
          AND other.id IS DISTINCT FROM NEW.id
    ) THEN
        RAISE EXCEPTION 'That sign-in name is already used.'
            USING ERRCODE = '23505';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS staff_refuse_duplicate_username ON public.staff;
CREATE TRIGGER staff_refuse_duplicate_username BEFORE INSERT OR UPDATE OF username ON public.staff
    FOR EACH ROW EXECUTE FUNCTION public.stall_refuse_duplicate_staff_username();

CREATE OR REPLACE FUNCTION public.stall_refuse_duplicate_assignment_rent_rate() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM public.assignment_rent_rates AS other
        WHERE other.assignment_id = NEW.assignment_id
          AND other.starts_on = NEW.starts_on
          AND other.id IS DISTINCT FROM NEW.id
    ) THEN
        RAISE EXCEPTION 'That stay already has a rent rate on that date.'
            USING ERRCODE = '23505';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS assignment_rent_rates_refuse_duplicate_day ON public.assignment_rent_rates;
CREATE TRIGGER assignment_rent_rates_refuse_duplicate_day
    BEFORE INSERT OR UPDATE OF assignment_id, starts_on ON public.assignment_rent_rates
    FOR EACH ROW EXECUTE FUNCTION public.stall_refuse_duplicate_assignment_rent_rate();

CREATE OR REPLACE FUNCTION public.stall_refuse_duplicate_stored_value_number() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM public.stored_values AS other
        WHERE other.kind = NEW.kind
          AND lower(other.number) = lower(NEW.number)
          AND other.id IS DISTINCT FROM NEW.id
    ) THEN
        RAISE EXCEPTION 'That certificate number is already used.'
            USING ERRCODE = '23505';
    END IF;
    RETURN NEW;
END;
$$;

DROP TRIGGER IF EXISTS stored_values_refuse_duplicate_number ON public.stored_values;
CREATE TRIGGER stored_values_refuse_duplicate_number
    BEFORE INSERT OR UPDATE OF kind, number ON public.stored_values
    FOR EACH ROW EXECUTE FUNCTION public.stall_refuse_duplicate_stored_value_number();
