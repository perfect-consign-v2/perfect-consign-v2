-- A markdown is proposed before the price changes. Confirmation writes the existing price event.
-- Held pieces are skipped. A price under the floor needs an audit actor.

ALTER TABLE public.items ADD COLUMN held boolean DEFAULT false NOT NULL;

CREATE TABLE public.item_floors (
    item_id uuid NOT NULL,
    floor_minor bigint NOT NULL,
    CONSTRAINT item_floors_pkey PRIMARY KEY (item_id),
    CONSTRAINT item_floors_amount_chk CHECK ((floor_minor >= 0))
);

CREATE TABLE public.markdown_proposals (
    id uuid DEFAULT uuidv7() NOT NULL,
    item_id uuid NOT NULL,
    step_id uuid NOT NULL,
    proposed_minor bigint NOT NULL,
    status text DEFAULT 'proposed'::text NOT NULL,
    proposed_on date NOT NULL,
    CONSTRAINT markdown_proposals_pkey PRIMARY KEY (id),
    CONSTRAINT markdown_proposals_price_chk CHECK ((proposed_minor > 0)),
    CONSTRAINT markdown_proposals_status_chk CHECK ((status = ANY (ARRAY['proposed'::text, 'confirmed'::text, 'skipped'::text])))
);

CREATE FUNCTION public.stall_propose_markdowns(on_date date) RETURNS integer
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    filed integer := 0;
    piece record;
    next_step uuid;
    original bigint;
    proposed bigint;
BEGIN
    IF on_date IS NULL THEN
        RAISE EXCEPTION 'a markdown needs a day'
            USING ERRCODE = '23514';
    END IF;

    FOR piece IN
        SELECT item.id
        FROM public.items AS item
        WHERE item.placement = 'consigned'
          AND item.quantity > 0
          AND item.held IS DISTINCT FROM true
    LOOP
        SELECT step.id
        INTO next_step
        FROM public.markdown_steps AS step
        WHERE NOT EXISTS (
            SELECT 1
            FROM public.item_price_events AS prior
            WHERE prior.item_id = piece.id
              AND prior.step_id = step.id
        )
        AND NOT EXISTS (
            SELECT 1
            FROM public.markdown_proposals AS open_row
            WHERE open_row.item_id = piece.id
              AND open_row.step_id = step.id
              AND open_row.status = 'proposed'
        )
        ORDER BY step.days_on_floor
        LIMIT 1;

        IF next_step IS NULL THEN
            CONTINUE;
        END IF;

        IF EXISTS (
            SELECT 1
            FROM public.items AS aged
            JOIN public.markdown_steps AS step ON step.id = next_step
            WHERE aged.id = piece.id
              AND aged.intake_on + step.days_on_floor > on_date
        ) THEN
            CONTINUE;
        END IF;

        SELECT event.prior_minor INTO original
        FROM public.item_price_events AS event
        WHERE event.item_id = piece.id
        ORDER BY event.applied_on, event.id
        LIMIT 1;
        IF original IS NULL THEN
            SELECT price_minor INTO original FROM public.items WHERE id = piece.id;
        END IF;

        SELECT public.stall_half_up(original, 10000 - step.percent * 100)
        INTO proposed
        FROM public.markdown_steps AS step
        WHERE step.id = next_step;

        INSERT INTO public.markdown_proposals (item_id, step_id, proposed_minor, proposed_on)
        VALUES (piece.id, next_step, proposed, on_date);
        filed := filed + 1;
    END LOOP;

    RETURN filed;
END;
$$;

CREATE FUNCTION public.stall_confirm_markdown(proposal uuid, actor text, override_floor boolean) RETURNS void
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    piece uuid;
    step uuid;
    proposed bigint;
    current_price bigint;
    floor_minor bigint;
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
        IF actor IS NULL OR btrim(actor) = '' THEN
            RAISE EXCEPTION 'a floor override names its actor'
                USING ERRCODE = '23514';
        END IF;
        INSERT INTO public.audit_events (actor, role, action, entity_type, entity_id, request_id)
        VALUES (btrim(actor), 'owner', 'markdown-floor', 'item', piece::text, proposal::text);
    END IF;

    INSERT INTO public.item_price_events (item_id, step_id, prior_minor, price_minor, applied_on)
    VALUES (piece, step, current_price, proposed, (SELECT (clock_timestamp() AT TIME ZONE mall.time_zone)::date FROM public.mall AS mall));

    UPDATE public.markdown_proposals
    SET status = 'confirmed'
    WHERE id = proposal;
END;
$$;

GRANT ALL ON TABLE public.item_floors TO stall_poster;
GRANT ALL ON TABLE public.item_floors TO stall_backup;
GRANT ALL ON TABLE public.markdown_proposals TO stall_poster;
GRANT ALL ON TABLE public.markdown_proposals TO stall_backup;
GRANT ALL ON FUNCTION public.stall_propose_markdowns(on_date date) TO stall_poster;
GRANT ALL ON FUNCTION public.stall_propose_markdowns(on_date date) TO stall_backup;
GRANT ALL ON FUNCTION public.stall_confirm_markdown(proposal uuid, actor text, override_floor boolean) TO stall_poster;
GRANT ALL ON FUNCTION public.stall_confirm_markdown(proposal uuid, actor text, override_floor boolean) TO stall_backup;
