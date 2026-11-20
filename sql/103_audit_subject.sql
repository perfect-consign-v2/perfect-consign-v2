-- Store activity names the signed-in account. Old rows stay unlabeled.
-- A new row without that account is refused. Append-only guards stay.

ALTER TABLE public.audit_events
    ADD COLUMN account_id uuid,
    ADD COLUMN account_kind text,
    ADD COLUMN username text,
    ADD COLUMN authority_account_id uuid,
    ADD COLUMN authority_username text,
    ADD COLUMN entered_by_admin_id uuid,
    ADD COLUMN entered_by_email text,
    ADD COLUMN client_address text;

ALTER TABLE public.audit_events
    ADD CONSTRAINT audit_events_subject_chk CHECK ((
        (account_id IS NULL AND account_kind IS NULL AND username IS NULL)
        OR (
            account_id IS NOT NULL
            AND account_kind = ANY (ARRAY['owner'::text, 'staff'::text])
            AND username = btrim(username)
            AND username ~ '^[a-z][a-z0-9-]{0,62}$'
        )
    ));

ALTER TABLE public.audit_events
    ADD CONSTRAINT audit_events_authority_chk CHECK ((
        (authority_account_id IS NULL AND authority_username IS NULL)
        OR (
            authority_account_id IS NOT NULL
            AND authority_username = btrim(authority_username)
            AND authority_username ~ '^[a-z][a-z0-9-]{0,62}$'
        )
    ));

ALTER TABLE public.audit_events
    ADD CONSTRAINT audit_events_entered_by_chk CHECK ((
        (entered_by_admin_id IS NULL AND entered_by_email IS NULL)
        OR (
            entered_by_admin_id IS NOT NULL
            AND entered_by_email = btrim(entered_by_email)
            AND char_length(entered_by_email) <= 200
            AND entered_by_email ~ '^[^@\s]+@[^@\s]+\.[^@\s]+$'
        )
    ));

ALTER TABLE public.audit_events
    ADD CONSTRAINT audit_events_address_chk CHECK ((
        client_address IS NULL OR char_length(client_address) <= 64
    ));

CREATE FUNCTION public.stall_require_audit_subject() RETURNS trigger
    LANGUAGE plpgsql
    SECURITY DEFINER
    SET search_path TO pg_catalog
    AS $$
DECLARE
    mall_id uuid;
    staff_name text;
    owner_name text;
    authority_name text;
    admin_email text;
BEGIN
    IF NEW.account_id IS NULL
        OR NEW.account_kind IS NULL
        OR NEW.username IS NULL
        OR NEW.actor IS NULL
        OR btrim(NEW.actor) = ''
        OR NEW.role IS NULL
        OR btrim(NEW.role) = ''
        OR NEW.client_address IS NULL
    THEN
        RAISE EXCEPTION 'an audit row needs the signed-in account'
            USING ERRCODE = '23514';
    END IF;

    IF NEW.account_kind = 'staff' THEN
        EXECUTE format(
            'SELECT display_name FROM %I.staff WHERE id = $1 AND username = $2 AND role = $3',
            TG_TABLE_SCHEMA
        ) INTO staff_name USING NEW.account_id, NEW.username, NEW.role;
        IF staff_name IS NULL OR staff_name IS DISTINCT FROM NEW.actor THEN
            RAISE EXCEPTION 'an audit row needs the signed-in account'
                USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.account_kind = 'owner' THEN
        IF NEW.role IS DISTINCT FROM 'owner' OR NEW.actor IS DISTINCT FROM NEW.username THEN
            RAISE EXCEPTION 'an audit row needs the signed-in account'
                USING ERRCODE = '23514';
        END IF;
        SELECT tenants.id INTO mall_id
        FROM platform.tenants AS tenants
        WHERE tenants.schema_name = TG_TABLE_SCHEMA;
        IF mall_id IS NULL THEN
            RAISE EXCEPTION 'an audit row needs the signed-in account'
                USING ERRCODE = '23514';
        END IF;
        SELECT owners.username INTO owner_name
        FROM platform.owners AS owners
        WHERE owners.id = NEW.account_id
          AND owners.tenant_id = mall_id
          AND owners.username = NEW.username;
        IF owner_name IS NULL THEN
            RAISE EXCEPTION 'an audit row needs the signed-in account'
                USING ERRCODE = '23514';
        END IF;
    ELSE
        RAISE EXCEPTION 'an audit row needs the signed-in account'
            USING ERRCODE = '23514';
    END IF;

    IF (NEW.authority_account_id IS NULL) IS DISTINCT FROM (NEW.authority_username IS NULL) THEN
        RAISE EXCEPTION 'an audit row needs the signed-in account'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.authority_account_id IS NOT NULL THEN
        IF NEW.authority_account_id IS NOT DISTINCT FROM NEW.account_id THEN
            RAISE EXCEPTION 'a second signer is a different person'
                USING ERRCODE = '23514';
        END IF;
        EXECUTE format(
            'SELECT username FROM %I.staff WHERE id = $1 AND username = $2',
            TG_TABLE_SCHEMA
        ) INTO authority_name USING NEW.authority_account_id, NEW.authority_username;
        IF authority_name IS NULL THEN
            RAISE EXCEPTION 'a second signer is a different person'
                USING ERRCODE = '23514';
        END IF;
    END IF;

    IF (NEW.entered_by_admin_id IS NULL) IS DISTINCT FROM (NEW.entered_by_email IS NULL) THEN
        RAISE EXCEPTION 'an audit row needs the operator'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.entered_by_admin_id IS NOT NULL THEN
        SELECT admins.email INTO admin_email
        FROM platform.admins AS admins
        WHERE admins.id = NEW.entered_by_admin_id;
        IF admin_email IS NULL OR lower(admin_email) IS DISTINCT FROM lower(NEW.entered_by_email) THEN
            RAISE EXCEPTION 'an audit row needs the operator'
                USING ERRCODE = '23514';
        END IF;
    END IF;

    IF char_length(NEW.client_address) > 64 THEN
        RAISE EXCEPTION 'an audit row needs the signed-in account'
            USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END;
$$;

CREATE TRIGGER audit_events_subject_append_only
    BEFORE INSERT ON public.audit_events
    FOR EACH ROW
    EXECUTE FUNCTION public.stall_require_audit_subject();

ALTER TABLE public.audit_events ENABLE ALWAYS TRIGGER audit_events_subject_append_only;

REVOKE ALL ON FUNCTION public.stall_require_audit_subject() FROM PUBLIC;
GRANT ALL ON FUNCTION public.stall_require_audit_subject() TO stall_poster;

DROP FUNCTION IF EXISTS public.stall_confirm_markdown(uuid, text, boolean);

CREATE FUNCTION public.stall_confirm_markdown(
    proposal uuid,
    account_id uuid,
    account_kind text,
    account_username text,
    actor_name text,
    override_floor boolean
) RETURNS void
    LANGUAGE plpgsql
    SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'public'
    AS $$
DECLARE
    piece uuid;
    step uuid;
    proposed bigint;
    current_price bigint;
    floor_minor bigint;
    stored_role text;
    stored_name text;
    mall_schema text;
BEGIN
    SELECT row.item_id, row.step_id, row.proposed_minor
    INTO piece, step, proposed
    FROM public.markdown_proposals AS row
    WHERE row.id = proposal
      AND row.status = 'proposed'
    FOR UPDATE;

    IF piece IS NULL THEN
        RAISE EXCEPTION 'that markdown is not waiting'
            USING ERRCODE = '23514';
    END IF;

    SELECT item.price_minor INTO current_price
    FROM public.items AS item
    WHERE item.id = piece
    FOR UPDATE;

    SELECT floor.floor_minor INTO floor_minor
    FROM public.item_floors AS floor
    WHERE floor.item_id = piece;

    IF floor_minor IS NOT NULL AND proposed < floor_minor AND override_floor IS DISTINCT FROM true THEN
        RAISE EXCEPTION 'a markdown stays at or above the floor'
            USING ERRCODE = '23514';
    END IF;

    IF floor_minor IS NOT NULL AND proposed < floor_minor THEN
        IF account_kind = 'staff' THEN
            SELECT staff.display_name, staff.role
            INTO stored_name, stored_role
            FROM public.staff AS staff
            WHERE staff.id = account_id
              AND staff.username = account_username;
        ELSIF account_kind = 'owner' THEN
            SELECT namespace.nspname INTO mall_schema
            FROM pg_catalog.pg_class AS relation
            JOIN pg_catalog.pg_namespace AS namespace ON namespace.oid = relation.relnamespace
            WHERE relation.oid = 'public.mall'::regclass;
            SELECT owners.username INTO stored_name
            FROM platform.owners AS owners
            JOIN platform.tenants AS tenants ON tenants.id = owners.tenant_id
            WHERE owners.id = account_id
              AND owners.username = account_username
              AND tenants.schema_name = mall_schema;
            stored_role := 'owner';
        END IF;
        IF stored_name IS NULL
            OR stored_role IS NULL
            OR stored_name IS DISTINCT FROM actor_name
            OR (account_kind = 'owner' AND actor_name IS DISTINCT FROM account_username)
        THEN
            RAISE EXCEPTION 'a floor override names its actor'
                USING ERRCODE = '23514';
        END IF;
        INSERT INTO public.audit_events (
            actor, role, action, entity_type, entity_id, request_id,
            account_id, account_kind, username, client_address
        ) VALUES (
            actor_name, stored_role, 'markdown-floor', 'item', piece::text, proposal::text,
            account_id, account_kind, account_username, ''
        );
    END IF;

    INSERT INTO public.item_price_events (item_id, step_id, prior_minor, price_minor, applied_on)
    VALUES (
        piece,
        step,
        current_price,
        proposed,
        (SELECT (clock_timestamp() AT TIME ZONE mall.time_zone)::date FROM public.mall AS mall)
    );

    UPDATE public.markdown_proposals
    SET status = 'confirmed'
    WHERE id = proposal;
END;
$$;

REVOKE ALL ON FUNCTION public.stall_confirm_markdown(uuid, uuid, text, text, text, boolean) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.stall_confirm_markdown(uuid, uuid, text, text, text, boolean) TO stall_poster;
