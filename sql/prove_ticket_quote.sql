-- The register quote (D1): stall_ticket_quote gives each line's discount, the
-- store's own discount, the store take and the tax, including a mall-wide
-- sale on either side, and a sale posted with those figures is the one the
-- database accepts. A sale that leaves out the mall-wide sale is refused. The
-- offline snaps carry the same figures. One transaction, then ROLLBACK.

BEGIN;

-- Discounts and tax-free sales stand only with a sign-off for their shift in
-- the same transaction (T3). A cashier signs off each shift below, as the
-- register does; the second-signature rule is off, so no second person.
INSERT INTO staff (id, username, display_name, password_hash, role)
VALUES ('01900000-0000-7000-8000-00000000a5f1', 'proofcash', 'Proof Cashier', '$2y$10$proofproofproofproofproofproo', 'cashier');

CREATE FUNCTION pg_temp.sign_shifts() RETURNS void
    LANGUAGE sql
    AS $fn$
    INSERT INTO sign_offs (action, entity_id, actor_account, actor_username, amount)
    SELECT wanted.action, shift.id::text, '01900000-0000-7000-8000-00000000a5f1', 'proofcash', wanted.amount
    FROM register_sessions AS shift
    CROSS JOIN (VALUES ('sale.discount', 10000::bigint), ('sale.tax_free', 1::bigint)) AS wanted (action, amount)
    WHERE NOT EXISTS (
        SELECT 1 FROM sign_offs AS signed
        WHERE signed.entity_id = shift.id::text AND signed.action = wanted.action AND signed.written_xid = pg_current_xact_id()
    );
$fn$;

UPDATE mall SET tax_bps = 8000, cash_round_minor = 0;
SET CONSTRAINTS ALL DEFERRED;

INSERT INTO accounting_periods (id, book_id, starts_on, ends_on, status)
SELECT '01900000-0000-7000-8000-00000000d902', '01900000-0000-7000-8000-000000000001',
       date_trunc('year', day.today)::date, (date_trunc('year', day.today) + interval '1 year - 1 day')::date, 'open'
FROM (SELECT (clock_timestamp() AT TIME ZONE time_zone)::date AS today FROM mall) AS day
WHERE NOT EXISTS (
    SELECT 1 FROM accounting_periods AS period
    WHERE period.book_id = '01900000-0000-7000-8000-000000000001' AND period.period_range @> day.today
);

SELECT stall_seed_vendor('01900000-0000-7000-8000-00000000d901', 'quote', 'Quinn', 'Lane', 1000);

INSERT INTO items (id, code, vendor_id, description, price_minor, barcode, intake_on, quantity, tax_exempt)
VALUES
    ('01900000-0000-7000-8000-00000000d911', 'QuoteCoat', '01900000-0000-7000-8000-00000000d901', 'Wool coat', 1000, 'BC-QUOTE-COAT', DATE '2026-01-01', 2, false),
    ('01900000-0000-7000-8000-00000000d912', 'QuoteHat', '01900000-0000-7000-8000-00000000d901', 'Felt hat', 1000, 'BC-QUOTE-HAT', DATE '2026-01-01', 2, false),
    ('01900000-0000-7000-8000-00000000d913', 'QuoteBook', '01900000-0000-7000-8000-00000000d901', 'Old book', 1000, 'BC-QUOTE-BOOK', DATE '2026-01-01', 1, true);

INSERT INTO registers (id, name) VALUES ('01900000-0000-7000-8000-00000000d921', 'Quote proof');
INSERT INTO register_sessions (id, register_id, cashier, opening_counts, opening_cash_minor)
VALUES ('01900000-0000-7000-8000-00000000d922', '01900000-0000-7000-8000-00000000d921', 'Quinn',
    '{"10000":0,"5000":0,"2000":0,"1000":0,"500":0,"100":0,"25":0,"10":0,"5":0,"1":0}'::jsonb, 0);
SELECT pg_temp.sign_shifts();

-- Ticket one: two coats, $1.00 off the line and $0.50 off the ticket.
INSERT INTO tickets (id, session_id, discount_kind, discount_value, discount_reason)
VALUES ('01900000-0000-7000-8000-00000000d931', '01900000-0000-7000-8000-00000000d922', 'amount', 50, 'other');
INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor, quantity, line_discount_kind, line_discount_value, line_discount_reason, line_discount_minor)
VALUES ('01900000-0000-7000-8000-00000000d931', 1, '01900000-0000-7000-8000-00000000d911', 1000, 2, 'amount', 100, 'other', 100);

DO $$
BEGIN
    -- No mall-wide sale: $20.00 less $1.50; take 10% of $18.50; tax 8% of $18.50.
    IF (SELECT discount_minor || '/' || store_discount_minor || '/' || commission_minor || '/' || tax_minor || '/' || commission_bps
        FROM stall_ticket_quote('01900000-0000-7000-8000-00000000d931')) <> '150/0/185/148/1000' THEN
        RAISE EXCEPTION 'the quote without a mall sale reads %', (SELECT row(quote.*)::text FROM stall_ticket_quote('01900000-0000-7000-8000-00000000d931') AS quote);
    END IF;
END $$;

-- A tax-free ticket owes no tax.
UPDATE tickets SET tax_waiver_reason = 'resale' WHERE id = '01900000-0000-7000-8000-00000000d931';

DO $$
BEGIN
    IF (SELECT tax_minor FROM stall_ticket_quote('01900000-0000-7000-8000-00000000d931')) <> 0 THEN
        RAISE EXCEPTION 'a tax-free ticket still owes tax';
    END IF;
END $$;

UPDATE tickets SET tax_waiver_reason = NULL WHERE id = '01900000-0000-7000-8000-00000000d931';

-- A 10% mall-wide sale today, taken by the vendor: $2.00 more off the
-- vendor's line, so less take and less tax.
INSERT INTO mall_sales (id, name, starts_on, ends_on, percent_bps, absorbed_by)
SELECT '01900000-0000-7000-8000-00000000d941', 'Quote vendor sale', today, today, 1000, 'vendor'
FROM (SELECT (clock_timestamp() AT TIME ZONE time_zone)::date AS today FROM mall) AS day;

DO $$
BEGIN
    IF (SELECT discount_minor || '/' || store_discount_minor || '/' || commission_minor || '/' || tax_minor
        FROM stall_ticket_quote('01900000-0000-7000-8000-00000000d931')) <> '350/0/165/132' THEN
        RAISE EXCEPTION 'the quote with a vendor mall sale reads %', (SELECT row(quote.*)::text FROM stall_ticket_quote('01900000-0000-7000-8000-00000000d931') AS quote);
    END IF;
END $$;

-- A sale that leaves the mall-wide sale out is refused; the quoted one posts.
INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
)
SELECT '01900000-0000-7000-8000-00000000d951', '01900000-0000-7000-8000-000000000001', 'sale:quote-vendor', day.today, clock_timestamp(), period.id,
       'USD', 'sale', 'quote-vendor', 'Quoted sale', 1782, 1782
FROM (SELECT (clock_timestamp() AT TIME ZONE time_zone)::date AS today FROM mall) AS day
JOIN accounting_periods AS period ON period.period_range @> day.today AND period.status = 'open';

DO $$
BEGIN
    BEGIN
        INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, quantity, commission_minor, tax_minor, discount_minor, store_discount_minor)
        VALUES ('01900000-0000-7000-8000-00000000d951', 1, '01900000-0000-7000-8000-00000000d911', 1000, 2, 185, 148, 150, 0);
        RAISE EXCEPTION 'a sale that left out the mall-wide sale was accepted';
    EXCEPTION
        WHEN check_violation THEN
            IF SQLERRM <> 'a sale discount does not match the ticket' THEN
                RAISE;
            END IF;
    END;
END $$;

INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES
    ('01900000-0000-7000-8000-00000000d951', '01900000-0000-7000-8000-000000000001', 1, (SELECT account_id FROM chart_roles WHERE role = 'cash'), 1782, 0, NULL, NULL),
    ('01900000-0000-7000-8000-00000000d951', '01900000-0000-7000-8000-000000000001', 2, (SELECT account_id FROM chart_roles WHERE role = 'commission_revenue'), 0, 165, NULL, NULL),
    ('01900000-0000-7000-8000-00000000d951', '01900000-0000-7000-8000-000000000001', 3, (SELECT account_id FROM chart_roles WHERE role = 'sales_tax_payable'), 0, 132, NULL, NULL),
    ('01900000-0000-7000-8000-00000000d951', '01900000-0000-7000-8000-000000000001', 4, (SELECT account_id FROM chart_roles WHERE role = 'vendor_payable'), 0, 1485, 'vendor', '01900000-0000-7000-8000-00000000d901');
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, quantity, commission_minor, tax_minor, discount_minor, store_discount_minor)
SELECT '01900000-0000-7000-8000-00000000d951', quote.line_no, quote.item_id, quote.price_minor, quote.quantity, quote.commission_minor, quote.tax_minor,
       quote.discount_minor, quote.store_discount_minor
FROM stall_ticket_quote('01900000-0000-7000-8000-00000000d931') AS quote;
INSERT INTO register_session_posts (session_id, journal_id) VALUES ('01900000-0000-7000-8000-00000000d922', '01900000-0000-7000-8000-00000000d951');
UPDATE tickets SET status = 'posted', posted_journal_id = '01900000-0000-7000-8000-00000000d951' WHERE id = '01900000-0000-7000-8000-00000000d931';

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

-- The same sale taken by the store: the $2.00 is the store's own discount, a
-- promotion expense; the vendor's share and the take are as without it.
DELETE FROM mall_sales WHERE id = '01900000-0000-7000-8000-00000000d941';
INSERT INTO mall_sales (id, name, starts_on, ends_on, percent_bps, absorbed_by)
SELECT '01900000-0000-7000-8000-00000000d942', 'Quote store sale', today, today, 1000, 'mall'
FROM (SELECT (clock_timestamp() AT TIME ZONE time_zone)::date AS today FROM mall) AS day;

INSERT INTO tickets (id, session_id, discount_kind, discount_value, discount_reason)
VALUES ('01900000-0000-7000-8000-00000000d932', '01900000-0000-7000-8000-00000000d922', 'amount', 50, 'other');
INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor, quantity, line_discount_kind, line_discount_value, line_discount_reason, line_discount_minor)
VALUES ('01900000-0000-7000-8000-00000000d932', 1, '01900000-0000-7000-8000-00000000d912', 1000, 2, 'amount', 100, 'other', 100);

DO $$
BEGIN
    IF (SELECT discount_minor || '/' || store_discount_minor || '/' || commission_minor || '/' || tax_minor
        FROM stall_ticket_quote('01900000-0000-7000-8000-00000000d932')) <> '150/200/185/132' THEN
        RAISE EXCEPTION 'the quote with a store mall sale reads %', (SELECT row(quote.*)::text FROM stall_ticket_quote('01900000-0000-7000-8000-00000000d932') AS quote);
    END IF;
END $$;

INSERT INTO journals (
    id, book_id, posting_key, posting_date, occurred_at, period_id,
    currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
)
SELECT '01900000-0000-7000-8000-00000000d952', '01900000-0000-7000-8000-000000000001', 'sale:quote-store', day.today, clock_timestamp(), period.id,
       'USD', 'sale', 'quote-store', 'Quoted sale, store deal', 1982, 1982
FROM (SELECT (clock_timestamp() AT TIME ZONE time_zone)::date AS today FROM mall) AS day
JOIN accounting_periods AS period ON period.period_range @> day.today AND period.status = 'open';
INSERT INTO journal_lines (journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref)
VALUES
    ('01900000-0000-7000-8000-00000000d952', '01900000-0000-7000-8000-000000000001', 1, (SELECT account_id FROM chart_roles WHERE role = 'cash'), 1782, 0, NULL, NULL),
    ('01900000-0000-7000-8000-00000000d952', '01900000-0000-7000-8000-000000000001', 2, (SELECT account_id FROM chart_roles WHERE role = 'promotion_expense'), 200, 0, NULL, NULL),
    ('01900000-0000-7000-8000-00000000d952', '01900000-0000-7000-8000-000000000001', 3, (SELECT account_id FROM chart_roles WHERE role = 'commission_revenue'), 0, 185, NULL, NULL),
    ('01900000-0000-7000-8000-00000000d952', '01900000-0000-7000-8000-000000000001', 4, (SELECT account_id FROM chart_roles WHERE role = 'sales_tax_payable'), 0, 132, NULL, NULL),
    ('01900000-0000-7000-8000-00000000d952', '01900000-0000-7000-8000-000000000001', 5, (SELECT account_id FROM chart_roles WHERE role = 'vendor_payable'), 0, 1665, 'vendor', '01900000-0000-7000-8000-00000000d901');
INSERT INTO sale_lines (journal_id, line_no, item_id, price_minor, quantity, commission_minor, tax_minor, discount_minor, store_discount_minor)
SELECT '01900000-0000-7000-8000-00000000d952', quote.line_no, quote.item_id, quote.price_minor, quote.quantity, quote.commission_minor, quote.tax_minor,
       quote.discount_minor, quote.store_discount_minor
FROM stall_ticket_quote('01900000-0000-7000-8000-00000000d932') AS quote;
INSERT INTO register_session_posts (session_id, journal_id) VALUES ('01900000-0000-7000-8000-00000000d922', '01900000-0000-7000-8000-00000000d952');
UPDATE tickets SET status = 'posted', posted_journal_id = '01900000-0000-7000-8000-00000000d952' WHERE id = '01900000-0000-7000-8000-00000000d932';

SET CONSTRAINTS ALL IMMEDIATE;
SET CONSTRAINTS ALL DEFERRED;

-- A tax-exempt piece and a tax-free ticket owe no tax; the offline snaps for
-- a ticket carry the quote's figures, and a second mint keeps them.
INSERT INTO tickets (id, session_id, tax_waiver_reason) VALUES ('01900000-0000-7000-8000-00000000d933', '01900000-0000-7000-8000-00000000d922', NULL);
INSERT INTO ticket_lines (ticket_id, line_no, item_id, price_minor, quantity)
VALUES ('01900000-0000-7000-8000-00000000d933', 1, '01900000-0000-7000-8000-00000000d913', 1000, 1);

DO $$
DECLARE
    first_keys text;
    again_keys text;
BEGIN
    IF (SELECT tax_minor || '/' || tax_exempt || '/' || store_discount_minor FROM stall_ticket_quote('01900000-0000-7000-8000-00000000d933')) <> '0/true/100' THEN
        RAISE EXCEPTION 'a tax-exempt piece reads %', (SELECT row(quote.*)::text FROM stall_ticket_quote('01900000-0000-7000-8000-00000000d933') AS quote);
    END IF;
    SELECT string_agg(minted, ',') INTO first_keys FROM stall_mint_register_snaps('01900000-0000-7000-8000-00000000d922', '01900000-0000-7000-8000-00000000d933') AS minted;
    IF NOT EXISTS (
        SELECT 1 FROM register_snaps AS snap
        JOIN stall_ticket_quote('01900000-0000-7000-8000-00000000d933') AS quote ON quote.line_no = snap.line_no
        WHERE snap.ticket_id = '01900000-0000-7000-8000-00000000d933' AND snap.status = 'waiting'
          AND snap.discount_minor = quote.discount_minor
          AND snap.store_discount_minor = quote.store_discount_minor
          AND snap.tax_minor = quote.tax_minor
    ) THEN
        RAISE EXCEPTION 'the snaps do not carry the quote';
    END IF;
    SELECT string_agg(minted, ',') INTO again_keys FROM stall_mint_register_snaps('01900000-0000-7000-8000-00000000d922', '01900000-0000-7000-8000-00000000d933') AS minted;
    IF again_keys IS DISTINCT FROM first_keys THEN
        RAISE EXCEPTION 'a second mint changed snaps that still match the quote';
    END IF;
END $$;

ROLLBACK;
