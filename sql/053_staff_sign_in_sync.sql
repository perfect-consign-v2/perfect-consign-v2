-- Keep platform.sign_in_directory aligned with staff recovery emails.

CREATE FUNCTION public.staff_sign_in_directory() RETURNS trigger
    LANGUAGE plpgsql
    SECURITY DEFINER
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    mall_id uuid;
    who text;
BEGIN
    SELECT tenants.id INTO mall_id
    FROM platform.tenants AS tenants
    WHERE tenants.schema_name = TG_TABLE_SCHEMA;
    IF mall_id IS NULL THEN
        RAISE EXCEPTION 'the tenant for this schema is missing';
    END IF;
    IF TG_OP = 'DELETE' THEN
        PERFORM platform.clear_sign_in_directory('staff', OLD.id);
        RETURN OLD;
    END IF;
    who := lower(NEW.username);
    IF TG_OP = 'UPDATE' AND OLD.email IS NOT NULL AND NEW.email IS NULL THEN
        PERFORM platform.clear_sign_in_directory('staff', NEW.id);
        RETURN NEW;
    END IF;
    PERFORM platform.put_sign_in_directory(
        NEW.email,
        mall_id,
        'staff',
        NEW.id,
        who
    );
    RETURN NEW;
END;
$$;

CREATE TRIGGER staff_sign_in_directory
    AFTER INSERT OR UPDATE OF email, username ON public.staff
    FOR EACH ROW
    EXECUTE FUNCTION public.staff_sign_in_directory();

CREATE TRIGGER staff_sign_in_directory_delete
    AFTER DELETE ON public.staff
    FOR EACH ROW
    EXECUTE FUNCTION public.staff_sign_in_directory();

GRANT EXECUTE ON FUNCTION platform.put_sign_in_directory(text, uuid, text, uuid, text) TO stall_poster;
GRANT EXECUTE ON FUNCTION platform.clear_sign_in_directory(text, uuid) TO stall_poster;
