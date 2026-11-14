-- Duplicate tag, barcode, and certificate refusals keep the cashier sentence
-- and use a dedicated SQLSTATE so the app retries on the code, not the text.
-- P1001 tag, P1002 barcode, P1003 stored-value number.

CREATE OR REPLACE FUNCTION public.stall_refuse_duplicate_item_code() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM public.items AS other
        WHERE lower(other.code) = lower(NEW.code) AND other.id IS DISTINCT FROM NEW.id
    ) THEN
        RAISE EXCEPTION 'That tag is already used.'
            USING ERRCODE = 'P1001';
    END IF;
    RETURN NEW;
END;
$$;

CREATE OR REPLACE FUNCTION public.stall_refuse_duplicate_item_barcode() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
BEGIN
    IF NEW.barcode IS NULL OR btrim(NEW.barcode) = '' THEN
        RETURN NEW;
    END IF;
    IF EXISTS (
        SELECT 1 FROM public.items AS other
        WHERE lower(other.barcode) = lower(NEW.barcode) AND other.id IS DISTINCT FROM NEW.id
    ) THEN
        RAISE EXCEPTION 'That barcode is already used.'
            USING ERRCODE = 'P1002';
    END IF;
    RETURN NEW;
END;
$$;

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
            USING ERRCODE = 'P1003';
    END IF;
    RETURN NEW;
END;
$$;
