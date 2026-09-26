-- QuickBooks and Xero journal files as CSV lines.
-- The writer reads this function. Safe to run again.

CREATE OR REPLACE FUNCTION stall_csv_field(value text)
RETURNS text
LANGUAGE sql
IMMUTABLE
SET search_path = pg_catalog
AS $$
    SELECT CASE
        WHEN value IS NULL OR value = '' THEN ''
        WHEN value ~ '[",\n\r]' THEN '"' || replace(value, '"', '""') || '"'
        ELSE value
    END;
$$;

CREATE OR REPLACE FUNCTION stall_export_journal_csv(
    system text,
    from_date date,
    to_date date,
    tax_rate text DEFAULT 'Tax Exempt'
)
RETURNS SETOF text
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF system IS NULL OR system NOT IN ('quickbooks', 'xero') THEN
        RAISE EXCEPTION 'the export system must be quickbooks or xero'
            USING ERRCODE = '23514';
    END IF;
    IF from_date IS NULL OR to_date IS NULL OR from_date > to_date THEN
        RAISE EXCEPTION 'the export range ends before it starts'
            USING ERRCODE = '23514';
    END IF;
    IF tax_rate IS NULL OR btrim(tax_rate) = '' THEN
        RAISE EXCEPTION 'a tax rate is required'
            USING ERRCODE = '23514';
    END IF;

    IF NOT EXISTS (
        SELECT 1
        FROM public.export_lines AS line
        WHERE line.posting_date >= from_date
          AND line.posting_date <= to_date
    ) THEN
        RAISE EXCEPTION 'no posted lines in that range'
            USING ERRCODE = '23514';
    END IF;

    IF EXISTS (
        SELECT 1
        FROM public.export_lines AS line
        JOIN public.journals AS journal
            ON journal.journal_no = line.journal_no
           AND journal.posting_key = line.posting_key
        JOIN public.accounts AS account
            ON account.book_id = journal.book_id
           AND account.code = line.account_code
        LEFT JOIN public.chart_roles AS role
            ON role.account_id = account.id
        LEFT JOIN public.export_accounts AS mapped
            ON mapped.role = role.role
           AND mapped.system = stall_export_journal_csv.system
        WHERE line.posting_date >= from_date
          AND line.posting_date <= to_date
          AND mapped.external_code IS NULL
    ) THEN
        RAISE EXCEPTION 'a posted line has no export account'
            USING ERRCODE = '23514';
    END IF;

    IF system = 'quickbooks' THEN
        RETURN NEXT 'Journal No.,Journal Date,Account Name,Debits,Credits,Description,Name';
    ELSE
        RETURN NEXT '*Narration,*Date,Description,*AccountCode,*TaxRate,*Amount,TrackingName1,TrackingOption1,TrackingName2,TrackingOption2';
    END IF;

    RETURN QUERY
    SELECT
        CASE
            WHEN stall_export_journal_csv.system = 'quickbooks' THEN
                public.stall_csv_field(line.journal_no::text)
                || ',' || public.stall_csv_field(to_char(line.posting_date, 'MM/DD/YYYY'))
                || ',' || public.stall_csv_field(mapped.external_code)
                || ',' || public.stall_csv_field(
                    CASE
                        WHEN line.debit_minor > 0 THEN to_char(
                            line.debit_minor::numeric / (10::numeric ^ book.currency_scale),
                            'FM999999999999990' || CASE
                                WHEN book.currency_scale > 0 THEN '.' || repeat('0', book.currency_scale)
                                ELSE ''
                            END
                        )
                        ELSE ''
                    END
                )
                || ',' || public.stall_csv_field(
                    CASE
                        WHEN line.credit_minor > 0 THEN to_char(
                            line.credit_minor::numeric / (10::numeric ^ book.currency_scale),
                            'FM999999999999990' || CASE
                                WHEN book.currency_scale > 0 THEN '.' || repeat('0', book.currency_scale)
                                ELSE ''
                            END
                        )
                        ELSE ''
                    END
                )
                || ',' || public.stall_csv_field(line.posting_key || ' ' || line.description)
                || ',' || public.stall_csv_field(
                    CASE
                        WHEN line.subledger_type = 'vendor' THEN vendor.name
                        WHEN line.subledger_type = 'booth' THEN booth.name
                        ELSE ''
                    END
                )
            ELSE
                public.stall_csv_field(line.posting_key)
                || ',' || public.stall_csv_field(to_char(line.posting_date, 'YYYY-MM-DD'))
                || ',' || public.stall_csv_field(line.description)
                || ',' || public.stall_csv_field(mapped.external_code)
                || ',' || public.stall_csv_field(tax_rate)
                || ',' || public.stall_csv_field(
                    to_char(
                        CASE
                            WHEN line.debit_minor > 0 THEN line.debit_minor::numeric
                            ELSE -line.credit_minor::numeric
                        END / (10::numeric ^ book.currency_scale),
                        'FM999999999999990' || CASE
                            WHEN book.currency_scale > 0 THEN '.' || repeat('0', book.currency_scale)
                            ELSE ''
                        END
                    )
                )
                || ',,,,'
        END
    FROM public.export_lines AS line
    JOIN public.journals AS journal
        ON journal.journal_no = line.journal_no
       AND journal.posting_key = line.posting_key
    JOIN public.ledger_books AS book
        ON book.id = journal.book_id
    JOIN public.accounts AS account
        ON account.book_id = journal.book_id
       AND account.code = line.account_code
    JOIN public.chart_roles AS role
        ON role.account_id = account.id
    JOIN public.export_accounts AS mapped
        ON mapped.role = role.role
       AND mapped.system = stall_export_journal_csv.system
    LEFT JOIN public.vendors AS vendor
        ON line.subledger_type = 'vendor'
       AND vendor.id = line.subledger_ref::uuid
    LEFT JOIN public.booths AS booth
        ON line.subledger_type = 'booth'
       AND booth.id = line.subledger_ref::uuid
    WHERE line.posting_date >= from_date
      AND line.posting_date <= to_date
    ORDER BY line.posting_date, line.journal_no, line.line_no;
END;
$$;

REVOKE ALL ON FUNCTION stall_csv_field(text) FROM PUBLIC;
REVOKE ALL ON FUNCTION stall_export_journal_csv(text, date, date, text) FROM PUBLIC;
GRANT EXECUTE ON FUNCTION stall_csv_field(text) TO stall_poster;
GRANT EXECUTE ON FUNCTION stall_export_journal_csv(text, date, date, text) TO stall_poster;
