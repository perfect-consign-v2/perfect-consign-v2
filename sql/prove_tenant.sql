-- Isolation proof. The ledger work below is installed inside this transaction and the transaction rolls back.
-- Poster roles are created outside the transaction because Postgres refuses CREATE ROLE inside one.
-- Run with php bin/prove-tenant.php.

BEGIN;

DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM information_schema.tables
        WHERE table_schema = 'public'
          AND table_name = 'journals'
    ) THEN
        RAISE EXCEPTION 'public holds a ledger';
    END IF;
END $$;

INSERT INTO platform.tenants (id, slug, schema_name, display_name)
VALUES
    (
        '01900000-0000-7000-8000-00000000f001',
        'proof-a',
        'tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'Proof A'
    ),
    (
        '01900000-0000-7000-8000-00000000f002',
        'proof-b',
        'tenant_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
        'Proof B'
    );

CREATE SCHEMA tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa;
REVOKE ALL ON SCHEMA tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa FROM PUBLIC;
CREATE SCHEMA tenant_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb;
REVOKE ALL ON SCHEMA tenant_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb FROM PUBLIC;

-- LEDGER_A
-- LEDGER_B

INSERT INTO tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000f021',
    '01900000-0000-7000-8000-000000000001',
    '2026-01-01',
    '2026-12-31',
    'open'
);

INSERT INTO tenant_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.vendors (id, code, first_name, last_name)
VALUES ('01900000-0000-7000-8000-00000000f101', 'other', 'Other', 'mall vendor');

DO $$
BEGIN
    SET LOCAL ROLE poster_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa;
    INSERT INTO tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000f031',
        '01900000-0000-7000-8000-000000000001',
        'adjustment:tenant-proof',
        '2026-03-03',
        '2026-03-03 12:00:00+00',
        '01900000-0000-7000-8000-00000000f021',
        'USD',
        'adjustment',
        'tenant-proof',
        'Tenant proof adjustment',
        1000,
        1000
    );
    INSERT INTO tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor
    ) VALUES
        (
            '01900000-0000-7000-8000-00000000f031',
            '01900000-0000-7000-8000-000000000001',
            1,
            '01900000-0000-7000-8000-000000000010',
            1000,
            0
        ),
        (
            '01900000-0000-7000-8000-00000000f031',
            '01900000-0000-7000-8000-000000000001',
            2,
            '01900000-0000-7000-8000-000000000011',
            0,
            1000
        );
    RESET ROLE;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE poster_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb;
    BEGIN
        PERFORM 1 FROM tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.journals;
        RAISE EXCEPTION 'the other poster read this tenant';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    BEGIN
        INSERT INTO tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.journals (
            id, book_id, posting_key, posting_date, occurred_at, period_id,
            currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
        ) VALUES (
            '01900000-0000-7000-8000-00000000f032',
            '01900000-0000-7000-8000-000000000001',
            'tenant-proof-intrusion',
            '2026-03-04',
            '2026-03-04 12:00:00+00',
            '01900000-0000-7000-8000-00000000f021',
            'USD',
            'adjustment',
            'intrusion',
            'Intrusion',
            1000,
            1000
        );
        RAISE EXCEPTION 'the other poster posted into this tenant';
    EXCEPTION
        WHEN insufficient_privilege THEN
            NULL;
    END;
    RESET ROLE;
END $$;

DO $$
BEGIN
    SET LOCAL ROLE poster_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa;
    INSERT INTO tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.journals (
        id, book_id, posting_key, posting_date, occurred_at, period_id,
        currency, source_type, source_reference, description, debit_total_minor, credit_total_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000f033',
        '01900000-0000-7000-8000-000000000001',
        'tenant-proof-other-vendor',
        '2026-03-05',
        '2026-03-05 12:00:00+00',
        '01900000-0000-7000-8000-00000000f021',
        'USD',
        'sale',
        'other-vendor',
        'Other tenant vendor',
        1000,
        1000
    );
    INSERT INTO tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor
    ) VALUES (
        '01900000-0000-7000-8000-00000000f033',
        '01900000-0000-7000-8000-000000000001',
        1,
        '01900000-0000-7000-8000-000000000010',
        1000,
        0
    );
    INSERT INTO tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.journal_lines (
        journal_id, book_id, line_no, account_id, debit_minor, credit_minor, subledger_type, subledger_ref
    ) VALUES (
        '01900000-0000-7000-8000-00000000f033',
        '01900000-0000-7000-8000-000000000001',
        2,
        '01900000-0000-7000-8000-000000000014',
        0,
        1000,
        'vendor',
        '01900000-0000-7000-8000-00000000f101'
    );
    RAISE EXCEPTION 'a journal from the other tenant was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM <> 'vendor payable subledger ref must be a vendor id' THEN
            RAISE;
        END IF;
        RESET ROLE;
END $$;

DO $$
BEGIN
    INSERT INTO tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.ledger_books (
        id, code, name, currency, currency_scale, is_default
    ) VALUES (
        '01900000-0000-7000-8000-00000000f010',
        'other',
        'Other',
        'USD',
        2,
        false
    );
    RAISE EXCEPTION 'a second book was accepted';
EXCEPTION
    WHEN SQLSTATE '23514' THEN
        IF SQLERRM <> 'the mall has one book' THEN
            RAISE;
        END IF;
END $$;

SET CONSTRAINTS ALL IMMEDIATE;

DO $$
BEGIN
    ALTER TABLE tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.register_session_posts
        DROP CONSTRAINT register_session_posts_journal_id_fkey;
    ALTER TABLE tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.register_checks
        DROP CONSTRAINT register_checks_journal_id_fkey;
    ALTER TABLE tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.sale_lines
        DROP CONSTRAINT sale_lines_journal_id_fkey;
    ALTER TABLE tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.item_returns
        DROP CONSTRAINT item_returns_journal_id_fkey;
    ALTER TABLE tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.item_returns
        DROP CONSTRAINT item_returns_sale_journal_id_fkey;
    ALTER TABLE tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.tickets
        DROP CONSTRAINT tickets_posted_journal_id_fkey;
    ALTER TABLE tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.payout_run_lines
        DROP CONSTRAINT payout_run_lines_settlement_journal_id_fkey;
    ALTER TABLE tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.payout_run_lines
        DROP CONSTRAINT payout_run_lines_disbursement_journal_id_fkey;
    ALTER TABLE tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.payout_run_sales
        DROP CONSTRAINT payout_run_sales_journal_id_fkey;
    ALTER TABLE tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.payout_run_journals
        DROP CONSTRAINT payout_run_journals_journal_id_fkey;
    ALTER TABLE tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.journal_cash_tendered
        DROP CONSTRAINT journal_cash_tendered_journal_id_fkey;
    TRUNCATE
        tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.journal_lines,
        tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.journals;
    RAISE EXCEPTION 'truncate wiped a posted journal';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        NULL;
END $$;

DO $$
BEGIN
    ALTER TABLE tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.journals
        DISABLE TRIGGER journals_append_only;
    UPDATE tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.journals
    SET description = 'wiped'
    WHERE posting_key = 'adjustment:tenant-proof';
    RAISE EXCEPTION 'owner disable trigger wiped a posted row';
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        NULL;
END $$;

DO $$
BEGIN
    IF (SELECT count(*) FROM tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.journals) <> 1 THEN
        RAISE EXCEPTION 'the posted journal was not kept';
    END IF;
    IF (
        SELECT description
        FROM tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.journals
        WHERE posting_key = 'adjustment:tenant-proof'
    ) IS DISTINCT FROM 'Tenant proof adjustment' THEN
        RAISE EXCEPTION 'the posted journal was edited';
    END IF;
    IF (SELECT count(*) FROM tenant_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.journals) <> 0 THEN
        RAISE EXCEPTION 'the other tenant received a journal';
    END IF;
END $$;

DO $$
DECLARE
    ext_schema text;
BEGIN
    SELECT n.nspname INTO ext_schema
    FROM pg_extension AS e
    JOIN pg_namespace AS n ON n.oid = e.extnamespace
    WHERE e.extname = 'btree_gist';
    IF ext_schema IS DISTINCT FROM 'extensions' THEN
        RAISE EXCEPTION 'btree_gist is not in extensions';
    END IF;
END $$;

DROP SCHEMA tenant_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa CASCADE;

DO $$
DECLARE
    ext_schema text;
BEGIN
    SELECT n.nspname INTO ext_schema
    FROM pg_extension AS e
    JOIN pg_namespace AS n ON n.oid = e.extnamespace
    WHERE e.extname = 'btree_gist';
    IF ext_schema IS DISTINCT FROM 'extensions' THEN
        RAISE EXCEPTION 'dropping a tenant dropped btree_gist';
    END IF;
END $$;

INSERT INTO tenant_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.accounting_periods (id, book_id, starts_on, ends_on, status)
VALUES (
    '01900000-0000-7000-8000-00000000f022',
    '01900000-0000-7000-8000-000000000001',
    '2026-01-01',
    '2026-12-31',
    'open'
);

DO $$
BEGIN
    INSERT INTO tenant_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.accounting_periods (id, book_id, starts_on, ends_on, status)
    VALUES (
        '01900000-0000-7000-8000-00000000f023',
        '01900000-0000-7000-8000-000000000001',
        '2026-06-01',
        '2026-12-31',
        'open'
    );
    RAISE EXCEPTION 'an overlapping period was accepted after the other tenant was dropped';
EXCEPTION
    WHEN SQLSTATE '23P01' THEN
        NULL;
END $$;

INSERT INTO tenant_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.booths (id, code, name)
VALUES ('01900000-0000-7000-8000-00000000f201', 'north', 'North');

INSERT INTO tenant_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.booth_assignments (id, booth_id, vendor_id, starts_on, ends_on)
VALUES (
    '01900000-0000-7000-8000-00000000f301',
    '01900000-0000-7000-8000-00000000f201',
    '01900000-0000-7000-8000-00000000f101',
    '2026-01-01',
    NULL
);

DO $$
BEGIN
    INSERT INTO tenant_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.booth_assignments (id, booth_id, vendor_id, starts_on, ends_on)
    VALUES (
        '01900000-0000-7000-8000-00000000f302',
        '01900000-0000-7000-8000-00000000f201',
        '01900000-0000-7000-8000-00000000f101',
        '2026-06-01',
        NULL
    );
    RAISE EXCEPTION 'an overlapping booth assignment was accepted after the other tenant was dropped';
EXCEPTION
    WHEN SQLSTATE '23P01' THEN
        NULL;
END $$;

ROLLBACK;
