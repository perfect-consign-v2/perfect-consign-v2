-- Offline snaps: mint/sync ticket discounts; snap_priced sales must match snap tax/discounts.

ALTER TABLE public.register_snaps
    ADD COLUMN discount_minor bigint DEFAULT 0 NOT NULL,
    ADD COLUMN store_discount_minor bigint DEFAULT 0 NOT NULL;

ALTER TABLE public.register_snaps
    ADD CONSTRAINT register_snaps_discount_chk CHECK (
        (discount_minor >= 0)
        AND (store_discount_minor >= 0)
        AND (discount_minor + store_discount_minor <= price_minor * quantity)
    );

CREATE OR REPLACE FUNCTION public.stall_apply_snap_price() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog', 'public'
    AS $$
DECLARE
    snap_price bigint;
    snap_tax bigint;
    snap_discount bigint;
    snap_store bigint;
    posting text;
BEGIN
    SELECT journal.posting_key INTO posting
    FROM public.journals AS journal
    WHERE journal.id = NEW.journal_id;
    SELECT snap.price_minor, snap.tax_minor, snap.discount_minor, snap.store_discount_minor
    INTO snap_price, snap_tax, snap_discount, snap_store
    FROM public.register_snaps AS snap
    WHERE snap.posting_key = posting
      AND snap.item_id = NEW.item_id;
    IF snap_price IS NULL THEN
        RETURN NEW;
    END IF;
    IF NEW.price_minor IS DISTINCT FROM snap_price THEN
        RAISE EXCEPTION 'a snapped sale keeps its price'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.tax_minor IS DISTINCT FROM snap_tax THEN
        RAISE EXCEPTION 'a sale tax does not match the mall rate'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.discount_minor IS DISTINCT FROM COALESCE(snap_discount, 0)
        OR NEW.store_discount_minor IS DISTINCT FROM COALESCE(snap_store, 0) THEN
        RAISE EXCEPTION 'a sale discount does not match the ticket'
            USING ERRCODE = '23514';
    END IF;
    PERFORM set_config('stall.snap_priced', '1', true);

    RETURN NEW;
END;
$$;

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
    line_discount bigint;
    line_store bigint;
    vendor_exempt boolean;
    line_count integer;
    waiting_count integer;
    mismatch boolean;
    ticket_kind text;
    ticket_value bigint;
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

    SELECT ticket.tax_waiver_reason, ticket.discount_kind, ticket.discount_value
    INTO waiver, ticket_kind, ticket_value
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
                    AND snap.discount_minor = public.stall_line_discount(
                            p_ticket_id, tl.item_id, ticket_kind, ticket_value
                        ) + tl.line_discount_minor + tl.offer_discount_minor
                    AND snap.store_discount_minor = tl.store_discount_minor
                    AND snap.tax_minor = CASE
                        WHEN waiver IS NOT NULL OR COALESCE(vendor.tax_exempt, false) THEN 0
                        ELSE public.stall_tax_minor(
                            tl.price_minor * tl.quantity
                                - (
                                    public.stall_line_discount(
                                        p_ticket_id, tl.item_id, ticket_kind, ticket_value
                                    ) + tl.line_discount_minor + tl.offer_discount_minor
                                    + tl.store_discount_minor
                                ),
                            mall_tax
                        )
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
               tl.line_discount_minor,
               tl.offer_discount_minor,
               tl.store_discount_minor,
               COALESCE(vendor.tax_exempt, false) AS tax_exempt
        FROM public.ticket_lines AS tl
        JOIN public.items AS item ON item.id = tl.item_id
        LEFT JOIN public.vendors AS vendor ON vendor.id = item.vendor_id
        WHERE tl.ticket_id = p_ticket_id
        ORDER BY tl.line_no
    LOOP
        vendor_exempt := ticket_line.tax_exempt;
        line_discount := public.stall_line_discount(
            p_ticket_id, ticket_line.item_id, ticket_kind, ticket_value
        ) + ticket_line.line_discount_minor + ticket_line.offer_discount_minor;
        line_store := ticket_line.store_discount_minor;
        IF line_discount + line_store > ticket_line.price_minor * ticket_line.quantity THEN
            RAISE EXCEPTION 'a sale discount does not match the ticket'
                USING ERRCODE = '23514';
        END IF;
        IF waiver IS NOT NULL OR vendor_exempt THEN
            line_tax := 0;
        ELSE
            line_tax := public.stall_tax_minor(
                ticket_line.price_minor * ticket_line.quantity - line_discount - line_store,
                mall_tax
            );
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
            tax_waiver_reason,
            discount_minor,
            store_discount_minor
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
            waiver,
            line_discount,
            line_store
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
    promo_id uuid;
    posted_id uuid;
    payable bigint;
    posted_no bigint;
    book uuid;
    period uuid;
    commission_bps integer;
    commission_minor bigint;
    tax_expected bigint;
    mall_tax integer;
    net bigint;
    line_no integer := 1;
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
    SELECT role.account_id INTO promo_id FROM public.chart_roles AS role WHERE role.role = 'promotion_expense';
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

    net := snap.price_minor * snap.quantity - snap.discount_minor - snap.store_discount_minor;
    commission_bps := public.stall_commission_for(snap.item_id, snap.price_minor, snap.business_date);
    commission_minor := public.stall_half_up(
        snap.price_minor * snap.quantity - snap.discount_minor,
        commission_bps
    );
    SELECT mall.tax_bps INTO mall_tax FROM public.mall AS mall;
    IF snap.tax_waiver_reason IS NOT NULL OR vendor_exempt THEN
        tax_expected := 0;
    ELSE
        tax_expected := public.stall_tax_minor(net, mall_tax);
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

    payable := snap.price_minor * snap.quantity - snap.discount_minor - commission_minor;
    posted_id := uuidv7();

    BEGIN
        INSERT INTO public.journals (
            id, book_id, posting_key, posting_date, occurred_at, period_id,
            currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
        ) VALUES (
            posted_id, book, snap.posting_key, snap.business_date, clock_timestamp(), period,
            'USD', 'sale', snap.posting_key, 'Snapped sale',
            net + snap.tax_minor + snap.store_discount_minor,
            net + snap.tax_minor + snap.store_discount_minor
        );
        INSERT INTO public.journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
        VALUES (posted_id, book, line_no, cash_id, net + snap.tax_minor, 0);
        line_no := line_no + 1;
        IF snap.store_discount_minor > 0 THEN
            INSERT INTO public.journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
            VALUES (posted_id, book, line_no, promo_id, snap.store_discount_minor, 0);
            line_no := line_no + 1;
        END IF;
        IF commission_minor > 0 THEN
            INSERT INTO public.journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
            VALUES (posted_id, book, line_no, commission_id, 0, commission_minor);
            line_no := line_no + 1;
        END IF;
        IF payable > 0 THEN
            INSERT INTO public.journal_lines (
                journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
            ) VALUES (
                posted_id, book, line_no, payable_id, 0, payable, 'vendor', vendor::text
            );
            line_no := line_no + 1;
        END IF;
        IF snap.tax_minor > 0 THEN
            INSERT INTO public.journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
            VALUES (posted_id, book, line_no, tax_id, 0, snap.tax_minor);
        END IF;
        INSERT INTO public.sale_lines (
            journal_id, line_no, item_id, price_minor, quantity, commission_minor, tax_minor,
            discount_minor, store_discount_minor
        ) VALUES (
            posted_id, 1, snap.item_id, snap.price_minor, snap.quantity, commission_minor, snap.tax_minor,
            snap.discount_minor, snap.store_discount_minor
        );
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

REVOKE ALL ON FUNCTION public.stall_mint_register_snaps(uuid, uuid) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.stall_mint_register_snaps(uuid, uuid) TO stall_poster;

REVOKE ALL ON FUNCTION public.stall_sync_snap(text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.stall_sync_snap(text) TO stall_poster;

REVOKE ALL ON FUNCTION public.stall_apply_snap_price() FROM PUBLIC;
GRANT EXECUTE ON FUNCTION public.stall_apply_snap_price() TO stall_poster;
