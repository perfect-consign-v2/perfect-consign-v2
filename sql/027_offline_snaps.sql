-- An offline snap posts the price, tax, and commission it captured.
-- A refused snap stays unposted. The drawer shows that cash apart from snaps still waiting.

CREATE TABLE public.register_snaps (
    id uuid DEFAULT uuidv7() NOT NULL,
    posting_key text NOT NULL,
    session_id uuid NOT NULL,
    business_date date NOT NULL,
    item_id uuid NOT NULL,
    price_minor bigint NOT NULL,
    tax_minor bigint NOT NULL,
    commission_minor bigint NOT NULL,
    commission_bps integer DEFAULT 0 NOT NULL,
    tax_bps integer DEFAULT 0 NOT NULL,
    quantity integer DEFAULT 1 NOT NULL,
    status text DEFAULT 'waiting'::text NOT NULL,
    refusal text,
    journal_id uuid,
    journal_no bigint,
    CONSTRAINT register_snaps_pkey PRIMARY KEY (id),
    CONSTRAINT register_snaps_key_chk CHECK ((posting_key ~ '^[0-9a-f-]{36}$')),
    CONSTRAINT register_snaps_key_key UNIQUE (posting_key),
    CONSTRAINT register_snaps_money_chk CHECK (((price_minor > 0) AND (tax_minor >= 0) AND (commission_minor >= 0) AND (quantity > 0) AND (commission_bps >= 0) AND (commission_bps <= 10000) AND (tax_bps >= 0) AND (tax_bps <= 100000))),
    CONSTRAINT register_snaps_status_chk CHECK ((status = ANY (ARRAY['waiting'::text, 'posted'::text, 'refused'::text])))
);

CREATE FUNCTION public.stall_sync_snap(snap_key text) RETURNS bigint
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    snap public.register_snaps%ROWTYPE;
    on_hand integer;
    vendor uuid;
    cash_id uuid;
    commission_id uuid;
    payable_id uuid;
    tax_id uuid;
    posted_id uuid;
    payable bigint;
    posted_no bigint;
    book uuid;
    period uuid;
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

    SELECT item.quantity, item.vendor_id
    INTO on_hand, vendor
    FROM public.items AS item
    WHERE item.id = snap.item_id
      AND item.placement = 'consigned'
    FOR UPDATE;

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

    payable := snap.price_minor * snap.quantity - snap.commission_minor;
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
        IF snap.commission_minor > 0 THEN
            INSERT INTO public.journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor)
            VALUES (posted_id, book, 2, commission_id, 0, snap.commission_minor);
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
        VALUES (posted_id, 1, snap.item_id, snap.price_minor, snap.quantity, snap.commission_minor, snap.tax_minor);
        INSERT INTO public.register_session_posts (session_id, journal_id)
        VALUES (snap.session_id, posted_id);
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

GRANT ALL ON TABLE public.register_snaps TO stall_poster;
GRANT ALL ON TABLE public.register_snaps TO stall_backup;
GRANT ALL ON FUNCTION public.stall_sync_snap(snap_key text) TO stall_poster;
GRANT ALL ON FUNCTION public.stall_sync_snap(snap_key text) TO stall_backup;

CREATE FUNCTION public.stall_apply_snap_price() RETURNS trigger
    LANGUAGE plpgsql
    SET search_path TO 'pg_catalog'
    AS $$
DECLARE
    snap_price bigint;
    snap_bps integer;
    snap_tax integer;
    prior integer;
    prior_tax integer;
    posting text;
BEGIN
    SELECT journal.posting_key INTO posting
    FROM public.journals AS journal
    WHERE journal.id = NEW.journal_id;
    SELECT snap.price_minor, snap.commission_bps, snap.tax_bps
    INTO snap_price, snap_bps, snap_tax
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
    SELECT item.commission_bps INTO prior FROM public.items AS item WHERE item.id = NEW.item_id;
    SELECT mall.tax_bps INTO prior_tax FROM public.mall AS mall;
    PERFORM set_config('stall.snap_priced', '1', true);
    PERFORM set_config('stall.prior_bps', COALESCE(prior::text, ''), true);
    PERFORM set_config('stall.prior_tax', prior_tax::text, true);
    UPDATE public.items
    SET commission_bps = snap_bps
    WHERE id = NEW.item_id
      AND placement <> 'store';
    UPDATE public.mall
    SET tax_bps = snap_tax;

    RETURN NEW;
END;
$$;

CREATE TRIGGER sale_lines_00_price
    BEFORE INSERT ON public.sale_lines
    FOR EACH ROW EXECUTE FUNCTION public.stall_apply_snap_price();

GRANT ALL ON FUNCTION public.stall_apply_snap_price() TO stall_poster;
GRANT ALL ON FUNCTION public.stall_apply_snap_price() TO stall_backup;
