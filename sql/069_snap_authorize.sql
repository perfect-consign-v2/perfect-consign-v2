-- Offline snaps: server-mint price/tax from the open ticket; poster cannot INSERT free-form money.

ALTER TABLE public.register_snaps
    ADD COLUMN ticket_id uuid,
    ADD COLUMN line_no integer,
    ADD COLUMN tax_waiver_reason text;

ALTER TABLE public.register_snaps
    ADD CONSTRAINT register_snaps_waiver_chk CHECK (
        (tax_waiver_reason IS NULL)
        OR (tax_waiver_reason = ANY (ARRAY[
            'resale'::text,
            'charity'::text,
            'government or school'::text,
            'other'::text
        ]))
    );

ALTER TABLE public.register_snaps
    ADD CONSTRAINT register_snaps_line_chk CHECK (
        (line_no IS NULL) OR (line_no > 0)
    );

CREATE OR REPLACE FUNCTION public.stall_mint_register_snaps(
    p_session_id uuid,
    p_ticket_id uuid
) RETURNS SETOF text
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'public'
    AS $$
DECLARE
    business date;
    mall_tax integer;
    waiver text;
    ticket_line RECORD;
    snap_key text;
    line_tax bigint;
    vendor_exempt boolean;
    line_count integer;
    waiting_count integer;
    mismatch boolean;
BEGIN
    IF p_session_id IS NULL OR p_ticket_id IS NULL THEN
        RAISE EXCEPTION 'a register session is required'
            USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM public.register_sessions AS session
        WHERE session.id = p_session_id
          AND session.closed_at IS NULL
    ) THEN
        RAISE EXCEPTION 'a register session is required'
            USING ERRCODE = '23514';
    END IF;

    SELECT ticket.tax_waiver_reason
    INTO waiver
    FROM public.tickets AS ticket
    WHERE ticket.id = p_ticket_id
      AND ticket.session_id = p_session_id
      AND ticket.status = 'open';

    IF NOT FOUND THEN
        RAISE EXCEPTION 'there is no open ticket'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*)::integer INTO line_count
    FROM public.ticket_lines AS tl
    WHERE tl.ticket_id = p_ticket_id;

    IF line_count < 1 THEN
        RAISE EXCEPTION 'there is no open ticket'
            USING ERRCODE = '23514';
    END IF;

    SELECT mall.tax_bps,
           (clock_timestamp() AT TIME ZONE mall.time_zone)::date
    INTO mall_tax, business
    FROM public.mall AS mall;

    SELECT count(*)::integer INTO waiting_count
    FROM public.register_snaps AS snap
    WHERE snap.ticket_id = p_ticket_id
      AND snap.status = 'waiting';

    mismatch := waiting_count IS DISTINCT FROM line_count
        OR EXISTS (
            SELECT 1
            FROM public.ticket_lines AS tl
            JOIN public.items AS item ON item.id = tl.item_id
            LEFT JOIN public.vendors AS vendor ON vendor.id = item.vendor_id
            WHERE tl.ticket_id = p_ticket_id
              AND NOT EXISTS (
                  SELECT 1
                  FROM public.register_snaps AS snap
                  WHERE snap.ticket_id = p_ticket_id
                    AND snap.status = 'waiting'
                    AND snap.line_no = tl.line_no
                    AND snap.item_id = tl.item_id
                    AND snap.price_minor = tl.price_minor
                    AND snap.quantity = tl.quantity
                    AND snap.tax_waiver_reason IS NOT DISTINCT FROM waiver
                    AND snap.tax_minor = CASE
                        WHEN waiver IS NOT NULL OR COALESCE(vendor.tax_exempt, false) THEN 0
                        ELSE public.stall_tax_minor(tl.price_minor * tl.quantity, mall_tax)
                    END
              )
        );

    IF NOT mismatch THEN
        RETURN QUERY
        SELECT snap.posting_key
        FROM public.register_snaps AS snap
        WHERE snap.ticket_id = p_ticket_id
          AND snap.status = 'waiting'
        ORDER BY snap.line_no;
        RETURN;
    END IF;

    DELETE FROM public.register_snaps AS snap
    WHERE snap.ticket_id = p_ticket_id
      AND snap.status = 'waiting';

    FOR ticket_line IN
        SELECT tl.line_no,
               tl.item_id,
               tl.price_minor,
               tl.quantity,
               COALESCE(vendor.tax_exempt, false) AS tax_exempt
        FROM public.ticket_lines AS tl
        JOIN public.items AS item ON item.id = tl.item_id
        LEFT JOIN public.vendors AS vendor ON vendor.id = item.vendor_id
        WHERE tl.ticket_id = p_ticket_id
        ORDER BY tl.line_no
    LOOP
        vendor_exempt := ticket_line.tax_exempt;
        IF waiver IS NOT NULL OR vendor_exempt THEN
            line_tax := 0;
        ELSE
            line_tax := public.stall_tax_minor(ticket_line.price_minor * ticket_line.quantity, mall_tax);
        END IF;

        snap_key := uuidv7()::text;
        INSERT INTO public.register_snaps (
            posting_key,
            session_id,
            business_date,
            item_id,
            price_minor,
            tax_minor,
            commission_minor,
            commission_bps,
            tax_bps,
            quantity,
            ticket_id,
            line_no,
            tax_waiver_reason
        ) VALUES (
            snap_key,
            p_session_id,
            business,
            ticket_line.item_id,
            ticket_line.price_minor,
            line_tax,
            0,
            0,
            0,
            ticket_line.quantity,
            p_ticket_id,
            ticket_line.line_no,
            waiver
        );
        RETURN NEXT snap_key;
    END LOOP;
END;
$$;

CREATE OR REPLACE FUNCTION public.stall_sync_snap(snap_key text) RETURNS bigint
    LANGUAGE plpgsql SECURITY DEFINER
    SET search_path TO 'pg_catalog', 'public'
    AS $$
DECLARE
    snap public.register_snaps%ROWTYPE;
    on_hand integer;
    vendor uuid;
    vendor_exempt boolean;
    cash_id uuid;
    commission_id uuid;
    payable_id uuid;
    tax_id uuid;
    posted_id uuid;
    payable bigint;
    posted_no bigint;
    book uuid;
    period uuid;
    commission_bps integer;
    commission_minor bigint;
    tax_expected bigint;
    mall_tax integer;
BEGIN
    SELECT * INTO snap
    FROM public.register_snaps AS row
    WHERE row.posting_key = snap_key
    FOR UPDATE;

    IF snap.id IS NULL THEN
        RAISE EXCEPTION 'that snap is not on this register'
            USING ERRCODE = '23514';
    END IF;

    IF snap.status = 'posted' AND snap.journal_no IS NOT NULL THEN
        RETURN snap.journal_no;
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM public.register_sessions AS session
        WHERE session.id = snap.session_id
          AND session.closed_at IS NULL
    ) THEN
        UPDATE public.register_snaps
        SET status = 'refused', refusal = 'a register session is required'
        WHERE id = snap.id;
        RETURN NULL;
    END IF;

    IF EXISTS (
        SELECT 1 FROM public.journals AS journal WHERE journal.posting_key = snap.posting_key
    ) THEN
        SELECT journal.id, journal.journal_no
        INTO posted_id, posted_no
        FROM public.journals AS journal
        WHERE journal.posting_key = snap.posting_key;
        UPDATE public.register_snaps
        SET status = 'posted', journal_id = posted_id, journal_no = posted_no, refusal = NULL
        WHERE id = snap.id;
        RETURN posted_no;
    END IF;

    SELECT role.account_id INTO cash_id FROM public.chart_roles AS role WHERE role.role = 'cash';
    SELECT role.account_id INTO commission_id FROM public.chart_roles AS role WHERE role.role = 'commission_revenue';
    SELECT role.account_id INTO payable_id FROM public.chart_roles AS role WHERE role.role = 'vendor_payable';
    SELECT role.account_id INTO tax_id FROM public.chart_roles AS role WHERE role.role = 'sales_tax_payable';
    SELECT id INTO book FROM public.ledger_books WHERE is_default;
    SELECT open_period.id INTO period
    FROM public.accounting_periods AS open_period
    WHERE open_period.book_id = book
      AND open_period.starts_on <= snap.business_date
      AND open_period.ends_on >= snap.business_date
      AND open_period.status = 'open';

    IF period IS NULL THEN
        UPDATE public.register_snaps
        SET status = 'refused', refusal = 'a closed period refuses a new journal'
        WHERE id = snap.id;
        RETURN NULL;
    END IF;

    SELECT item.quantity, item.vendor_id, COALESCE(vendor.tax_exempt, false)
    INTO on_hand, vendor, vendor_exempt
    FROM public.items AS item
    LEFT JOIN public.vendors AS vendor ON vendor.id = item.vendor_id
    WHERE item.id = snap.item_id
      AND item.placement = 'consigned'
    FOR UPDATE OF item;

    IF vendor IS NULL THEN
        UPDATE public.register_snaps
        SET status = 'refused', refusal = 'a sale line needs an item'
        WHERE id = snap.id;
        RETURN NULL;
    END IF;

    IF on_hand < snap.quantity THEN
        UPDATE public.register_snaps
        SET status = 'refused', refusal = 'there are not enough on hand'
        WHERE id = snap.id;
        RETURN NULL;
    END IF;

    commission_bps := public.stall_commission_for(snap.item_id, snap.price_minor, snap.business_date);
    commission_minor := public.stall_half_up(snap.price_minor * snap.quantity, commission_bps);
    SELECT mall.tax_bps INTO mall_tax FROM public.mall AS mall;
    IF snap.tax_waiver_reason IS NOT NULL OR vendor_exempt THEN
        tax_expected := 0;
    ELSE
        tax_expected := public.stall_tax_minor(snap.price_minor * snap.quantity, mall_tax);
    END IF;

    IF snap.tax_minor IS DISTINCT FROM tax_expected THEN
        UPDATE public.register_snaps
        SET status = 'refused',
            refusal = 'a sale tax does not match the mall rate',
            tax_expected_minor = tax_expected,
            tax_variance_minor = snap.tax_minor - tax_expected
        WHERE id = snap.id;
        RETURN NULL;
    END IF;

    UPDATE public.register_snaps
    SET tax_expected_minor = tax_expected,
        tax_variance_minor = 0
    WHERE id = snap.id;

    payable := snap.price_minor * snap.quantity - commission_minor;
    posted_id := uuidv7();

    BEGIN
        INSERT INTO public.journals (
            id, book_id, posting_key, posting_date, occurred_at, period_id,
            currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
        ) VALUES (
            posted_id, book, snap.posting_key, snap.business_date, clock_timestamp(), period,
            'USD', 'sale', snap.posting_key, 'Snapped sale',
            snap.price_minor * snap.quantity + snap.tax_minor,
            snap.price_minor * snap.quantity + snap.tax_minor
        );
        INSERT INTO public.journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
        VALUES (posted_id, book, 1, cash_id, snap.price_minor * snap.quantity + snap.tax_minor, 0);
        IF commission_minor > 0 THEN
            INSERT INTO public.journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
            VALUES (posted_id, book, 2, commission_id, 0, commission_minor);
        END IF;
        INSERT INTO public.journal_lines (
            journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
        ) VALUES (
            posted_id, book, 3, payable_id, 0, payable, 'vendor', vendor::text
        );
        IF snap.tax_minor > 0 THEN
            INSERT INTO public.journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
            VALUES (posted_id, book, 4, tax_id, 0, snap.tax_minor);
        END IF;
        INSERT INTO public.sale_lines (journal_id, line_no, item_id, price_minor, quantity, commission_minor, tax_minor)
        VALUES (posted_id, 1, snap.item_id, snap.price_minor, snap.quantity, commission_minor, snap.tax_minor);
        INSERT INTO public.register_session_posts (session_id, journal_id)
        VALUES (snap.session_id, posted_id);
        IF snap.ticket_id IS NOT NULL THEN
            DELETE FROM public.ticket_lines AS tl
            WHERE tl.ticket_id = snap.ticket_id
              AND tl.item_id = snap.item_id
              AND (snap.line_no IS NULL OR tl.line_no = snap.line_no);
            DELETE FROM public.tickets AS ticket
            WHERE ticket.id = snap.ticket_id
              AND ticket.status = 'open'
              AND NOT EXISTS (
                  SELECT 1 FROM public.ticket_lines AS left_line WHERE left_line.ticket_id = ticket.id
              );
        END IF;
        SET CONSTRAINTS ALL IMMEDIATE;
        SELECT journal.journal_no INTO posted_no FROM public.journals AS journal WHERE journal.id = posted_id;
    EXCEPTION WHEN OTHERS THEN
        UPDATE public.register_snaps
        SET status = 'refused', refusal = SQLERRM
        WHERE id = snap.id;
        RETURN NULL;
    END;

    SET CONSTRAINTS ALL DEFERRED;

    UPDATE public.register_snaps
    SET status = 'posted', journal_id = posted_id, journal_no = posted_no, refusal = NULL
    WHERE id = snap.id;

    RETURN posted_no;
END;
$$;

REVOKE ALL ON TABLE public.register_snaps FROM PUBLIC;
REVOKE ALL ON TABLE public.register_snaps FROM stall_poster;
REVOKE ALL ON TABLE public.register_snaps FROM stall_backup;
GRANT SELECT ON TABLE public.register_snaps TO stall_poster;
GRANT SELECT ON TABLE public.register_snaps TO stall_backup;

REVOKE ALL ON FUNCTION public.stall_mint_register_snaps(uuid, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.stall_mint_register_snaps(uuid, uuid) TO stall_poster;

REVOKE ALL ON FUNCTION public.stall_sync_snap(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION public.stall_sync_snap(text) FROM stall_backup;
GRANT EXECUTE ON FUNCTION public.stall_sync_snap(text) TO stall_poster;
