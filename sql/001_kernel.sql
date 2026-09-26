-- Generic double-entry kernel. No hash columns. No mall chart.

CREATE EXTENSION IF NOT EXISTS btree_gist;

CREATE TABLE ledger_books (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    code text NOT NULL,
    name text NOT NULL,
    currency char(3) NOT NULL,
    currency_scale smallint NOT NULL,
    is_default boolean NOT NULL DEFAULT false,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT ledger_books_code_chk CHECK (code = btrim(code) AND code <> ''),
    CONSTRAINT ledger_books_name_chk CHECK (name = btrim(name) AND name <> ''),
    CONSTRAINT ledger_books_currency_chk CHECK (currency = upper(currency) AND currency ~ '^[A-Z]{3}$'),
    CONSTRAINT ledger_books_scale_chk CHECK (currency_scale BETWEEN 0 AND 4),
    CONSTRAINT ledger_books_code_key UNIQUE (code)
);

CREATE UNIQUE INDEX ledger_books_one_default ON ledger_books (is_default) WHERE is_default;

CREATE TABLE book_journal_counters (
    book_id uuid PRIMARY KEY REFERENCES ledger_books (id),
    last_no bigint NOT NULL,
    CONSTRAINT book_journal_counters_last_no_chk CHECK (last_no >= 0)
);

CREATE OR REPLACE FUNCTION ledger_seed_journal_counter()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    INSERT INTO public.book_journal_counters (book_id, last_no)
    VALUES (NEW.id, 0);
    RETURN NEW;
END;
$$;

CREATE TRIGGER ledger_books_journal_counter
    AFTER INSERT ON ledger_books
    FOR EACH ROW
    EXECUTE FUNCTION ledger_seed_journal_counter();

CREATE TABLE accounts (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    book_id uuid NOT NULL REFERENCES ledger_books (id),
    code text NOT NULL,
    name text NOT NULL,
    account_type text NOT NULL,
    normal_balance text NOT NULL,
    is_postable boolean NOT NULL,
    is_control boolean NOT NULL DEFAULT false,
    subledger_type text,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT accounts_code_chk CHECK (code = btrim(code) AND code <> ''),
    CONSTRAINT accounts_name_chk CHECK (name = btrim(name) AND name <> ''),
    CONSTRAINT accounts_type_chk CHECK (account_type IN ('asset', 'liability', 'equity', 'revenue', 'expense')),
    CONSTRAINT accounts_normal_chk CHECK (normal_balance IN ('debit', 'credit')),
    CONSTRAINT accounts_control_subledger_chk CHECK (
        (is_control AND subledger_type IS NOT NULL AND subledger_type = btrim(subledger_type) AND subledger_type <> '')
        OR (NOT is_control AND subledger_type IS NULL)
    ),
    CONSTRAINT accounts_book_code_key UNIQUE (book_id, code),
    CONSTRAINT accounts_book_id_key UNIQUE (book_id, id)
);

CREATE TABLE accounting_periods (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    book_id uuid NOT NULL REFERENCES ledger_books (id),
    starts_on date NOT NULL,
    ends_on date NOT NULL,
    status text NOT NULL,
    period_range daterange GENERATED ALWAYS AS (daterange(starts_on, ends_on, '[]')) STORED,
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    CONSTRAINT accounting_periods_range_chk CHECK (starts_on <= ends_on),
    CONSTRAINT accounting_periods_status_chk CHECK (status IN ('open', 'closed')),
    CONSTRAINT accounting_periods_book_start_key UNIQUE (book_id, starts_on),
    CONSTRAINT accounting_periods_book_id_key UNIQUE (book_id, id),
    CONSTRAINT accounting_periods_no_overlap EXCLUDE USING gist (book_id WITH =, period_range WITH &&)
);

CREATE TABLE journals (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    book_id uuid NOT NULL REFERENCES ledger_books (id),
    journal_no bigint NOT NULL,
    posting_key text NOT NULL,
    posting_date date NOT NULL,
    occurred_at timestamptz NOT NULL,
    posted_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    posting_xid xid8 NOT NULL DEFAULT pg_current_xact_id(),
    period_id uuid NOT NULL,
    currency char(3) NOT NULL,
    source_type text NOT NULL,
    source_reference text NOT NULL,
    description text NOT NULL,
    is_reversal boolean NOT NULL DEFAULT false,
    reverses_journal_id uuid REFERENCES journals (id),
    debit_total_minor bigint NOT NULL,
    credit_total_minor bigint NOT NULL,
    CONSTRAINT journals_currency_chk CHECK (currency = upper(currency) AND currency ~ '^[A-Z]{3}$'),
    CONSTRAINT journals_key_chk CHECK (posting_key = btrim(posting_key) AND posting_key <> ''),
    CONSTRAINT journals_source_chk CHECK (
        source_type = btrim(source_type) AND source_type <> ''
        AND source_reference = btrim(source_reference) AND source_reference <> ''
    ),
    CONSTRAINT journals_description_chk CHECK (description = btrim(description) AND description <> ''),
    CONSTRAINT journals_totals_chk CHECK (
        debit_total_minor > 0
        AND credit_total_minor > 0
        AND debit_total_minor = credit_total_minor
    ),
    CONSTRAINT journals_reversal_chk CHECK (
        (is_reversal AND reverses_journal_id IS NOT NULL AND reverses_journal_id <> id)
        OR (NOT is_reversal AND reverses_journal_id IS NULL)
    ),
    CONSTRAINT journals_book_no_key UNIQUE (book_id, journal_no),
    CONSTRAINT journals_book_key UNIQUE (book_id, posting_key),
    CONSTRAINT journals_id_book_key UNIQUE (id, book_id),
    CONSTRAINT journals_period_book_fkey FOREIGN KEY (book_id, period_id) REFERENCES accounting_periods (book_id, id)
);

CREATE UNIQUE INDEX journals_one_reversal ON journals (reverses_journal_id) WHERE reverses_journal_id IS NOT NULL;

CREATE TABLE journal_lines (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    journal_id uuid NOT NULL,
    book_id uuid NOT NULL,
    line_no integer NOT NULL,
    account_id uuid NOT NULL,
    debit_minor bigint NOT NULL,
    credit_minor bigint NOT NULL,
    subledger_type text,
    subledger_ref text,
    memo text NOT NULL DEFAULT '',
    CONSTRAINT journal_lines_no_chk CHECK (line_no > 0),
    CONSTRAINT journal_lines_amounts_chk CHECK (debit_minor >= 0 AND credit_minor >= 0),
    CONSTRAINT journal_lines_one_side_chk CHECK (
        (debit_minor > 0 AND credit_minor = 0)
        OR (credit_minor > 0 AND debit_minor = 0)
    ),
    CONSTRAINT journal_lines_subledger_chk CHECK (
        (subledger_type IS NULL AND subledger_ref IS NULL)
        OR (
            subledger_type IS NOT NULL
            AND subledger_type = btrim(subledger_type)
            AND subledger_type <> ''
            AND subledger_ref IS NOT NULL
            AND subledger_ref = btrim(subledger_ref)
            AND subledger_ref <> ''
        )
    ),
    CONSTRAINT journal_lines_journal_line_key UNIQUE (journal_id, line_no),
    CONSTRAINT journal_lines_journal_book_fkey FOREIGN KEY (journal_id, book_id) REFERENCES journals (id, book_id),
    CONSTRAINT journal_lines_account_book_fkey FOREIGN KEY (account_id, book_id) REFERENCES accounts (id, book_id)
);

CREATE OR REPLACE FUNCTION ledger_reject_journal_context()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog
AS $$
DECLARE
    book_currency char(3);
    period_start date;
    period_end date;
    period_status text;
    next_no bigint;
BEGIN
    IF NEW.journal_no IS NOT NULL THEN
        RAISE EXCEPTION 'a journal number is assigned by the database'
            USING ERRCODE = '23514';
    END IF;

    SELECT last_no INTO next_no
    FROM public.book_journal_counters
    WHERE book_id = NEW.book_id
    FOR UPDATE;
    IF next_no IS NULL THEN
        RAISE EXCEPTION 'journal book has no number counter'
            USING ERRCODE = '23503';
    END IF;
    next_no := next_no + 1;
    UPDATE public.book_journal_counters
    SET last_no = next_no
    WHERE book_id = NEW.book_id;
    NEW.journal_no := next_no;

    SELECT currency INTO book_currency
    FROM public.ledger_books
    WHERE id = NEW.book_id
    FOR UPDATE;
    IF book_currency IS NULL THEN
        RAISE EXCEPTION 'journal book does not exist'
            USING ERRCODE = '23503';
    END IF;
    IF NEW.currency <> book_currency THEN
        RAISE EXCEPTION 'journal currency must equal its book currency'
            USING ERRCODE = '23514';
    END IF;

    SELECT starts_on, ends_on, status
    INTO period_start, period_end, period_status
    FROM public.accounting_periods
    WHERE id = NEW.period_id
    FOR UPDATE;
    IF period_start IS NULL THEN
        RAISE EXCEPTION 'journal period does not exist'
            USING ERRCODE = '23503';
    END IF;
    IF NEW.posting_date < period_start OR NEW.posting_date > period_end THEN
        RAISE EXCEPTION 'journal posting date must fall inside its period'
            USING ERRCODE = '23514';
    END IF;
    IF period_status <> 'open' THEN
        RAISE EXCEPTION 'a closed period refuses a new journal'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER journals_context
    BEFORE INSERT ON journals
    FOR EACH ROW
    EXECUTE FUNCTION ledger_reject_journal_context();

CREATE OR REPLACE FUNCTION ledger_reject_book_currency()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF NEW.currency IS DISTINCT FROM OLD.currency
        OR NEW.currency_scale IS DISTINCT FROM OLD.currency_scale THEN
        IF EXISTS (SELECT 1 FROM public.journals WHERE book_id = OLD.id) THEN
            RAISE EXCEPTION 'book currency and scale are frozen once a journal exists'
                USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER ledger_books_currency_frozen
    BEFORE UPDATE OF currency, currency_scale ON ledger_books
    FOR EACH ROW
    EXECUTE FUNCTION ledger_reject_book_currency();

CREATE OR REPLACE FUNCTION ledger_reject_period_change()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF TG_OP = 'DELETE' THEN
        IF OLD.status = 'closed' OR EXISTS (
            SELECT 1 FROM public.journals WHERE period_id = OLD.id
        ) THEN
            RAISE EXCEPTION 'a closed or referenced period cannot be deleted'
                USING ERRCODE = '23514';
        END IF;
        RETURN OLD;
    END IF;

    IF OLD.status = 'closed' AND (
        NEW.status IS DISTINCT FROM OLD.status
        OR NEW.starts_on IS DISTINCT FROM OLD.starts_on
        OR NEW.ends_on IS DISTINCT FROM OLD.ends_on
        OR NEW.book_id IS DISTINCT FROM OLD.book_id
    ) THEN
        RAISE EXCEPTION 'a closed period cannot be reopened or moved'
            USING ERRCODE = '23514';
    END IF;

    IF (
        NEW.book_id IS DISTINCT FROM OLD.book_id
        OR NEW.starts_on IS DISTINCT FROM OLD.starts_on
        OR NEW.ends_on IS DISTINCT FROM OLD.ends_on
    ) AND EXISTS (
        SELECT 1 FROM public.journals WHERE period_id = OLD.id
    ) THEN
        RAISE EXCEPTION 'a referenced period cannot be moved'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER accounting_periods_frozen
    BEFORE UPDATE OR DELETE ON accounting_periods
    FOR EACH ROW
    EXECUTE FUNCTION ledger_reject_period_change();

CREATE OR REPLACE FUNCTION ledger_reject_account_change()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    IF NEW.book_id IS DISTINCT FROM OLD.book_id
        OR NEW.is_postable IS DISTINCT FROM OLD.is_postable
        OR NEW.is_control IS DISTINCT FROM OLD.is_control
        OR NEW.subledger_type IS DISTINCT FROM OLD.subledger_type
        OR NEW.account_type IS DISTINCT FROM OLD.account_type
        OR NEW.normal_balance IS DISTINCT FROM OLD.normal_balance
        OR NEW.code IS DISTINCT FROM OLD.code THEN
        IF EXISTS (SELECT 1 FROM public.journal_lines WHERE account_id = OLD.id) THEN
            RAISE EXCEPTION 'a posted account book, postable flag, control flag, subledger type, type, normal balance, and code are frozen'
                USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER accounts_posted_frozen
    BEFORE UPDATE OF book_id, is_postable, is_control, subledger_type, account_type, normal_balance, code ON accounts
    FOR EACH ROW
    EXECUTE FUNCTION ledger_reject_account_change();

CREATE OR REPLACE FUNCTION ledger_reject_line_account()
RETURNS trigger
LANGUAGE plpgsql
SECURITY DEFINER
SET search_path = pg_catalog
AS $$
DECLARE
    account_postable boolean;
    account_control boolean;
    account_subledger text;
BEGIN
    SELECT is_postable, is_control, subledger_type
    INTO account_postable, account_control, account_subledger
    FROM public.accounts
    WHERE id = NEW.account_id
    FOR UPDATE;
    IF account_postable IS NULL THEN
        RAISE EXCEPTION 'journal line account does not exist'
            USING ERRCODE = '23503';
    END IF;
    IF NOT account_postable THEN
        RAISE EXCEPTION 'journal line account must be postable'
            USING ERRCODE = '23514';
    END IF;
    IF account_control THEN
        IF NEW.subledger_type IS DISTINCT FROM account_subledger OR NEW.subledger_ref IS NULL THEN
            RAISE EXCEPTION 'a line on a control account requires that subledger'
                USING ERRCODE = '23514';
        END IF;
    ELSIF NEW.subledger_type IS NOT NULL THEN
        RAISE EXCEPTION 'a line on a non-control account must not carry a subledger'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER journal_lines_account
    BEFORE INSERT ON journal_lines
    FOR EACH ROW
    EXECUTE FUNCTION ledger_reject_line_account();

CREATE OR REPLACE FUNCTION ledger_reject_line_after_post()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    journal_xid xid8;
BEGIN
    SELECT posting_xid INTO journal_xid
    FROM public.journals
    WHERE id = NEW.journal_id;
    IF journal_xid IS NULL THEN
        RAISE EXCEPTION 'journal line journal does not exist'
            USING ERRCODE = '23503';
    END IF;
    IF journal_xid IS DISTINCT FROM pg_current_xact_id() THEN
        RAISE EXCEPTION 'a journal line cannot be added after its journal is posted'
            USING ERRCODE = '23514';
    END IF;
    RETURN NEW;
END;
$$;

CREATE TRIGGER journal_lines_seal
    BEFORE INSERT ON journal_lines
    FOR EACH ROW
    EXECUTE FUNCTION ledger_reject_line_after_post();

CREATE OR REPLACE FUNCTION ledger_reject_mutation()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    RAISE EXCEPTION 'a posted journal and its lines cannot be updated or deleted'
        USING ERRCODE = '25006';
END;
$$;

CREATE TRIGGER journals_append_only
    BEFORE UPDATE OR DELETE ON journals
    FOR EACH ROW
    EXECUTE FUNCTION ledger_reject_mutation();

CREATE TRIGGER journal_lines_append_only
    BEFORE UPDATE OR DELETE ON journal_lines
    FOR EACH ROW
    EXECUTE FUNCTION ledger_reject_mutation();

CREATE OR REPLACE FUNCTION ledger_reject_truncate()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
BEGIN
    RAISE EXCEPTION 'table % is append-only and cannot be truncated', TG_TABLE_NAME
        USING ERRCODE = '25006';
END;
$$;

CREATE TRIGGER journals_no_truncate
    BEFORE TRUNCATE ON journals
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

CREATE TRIGGER journal_lines_no_truncate
    BEFORE TRUNCATE ON journal_lines
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

CREATE TRIGGER accounts_no_truncate
    BEFORE TRUNCATE ON accounts
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

CREATE TRIGGER accounting_periods_no_truncate
    BEFORE TRUNCATE ON accounting_periods
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

CREATE TRIGGER ledger_books_no_truncate
    BEFORE TRUNCATE ON ledger_books
    FOR EACH STATEMENT
    EXECUTE FUNCTION ledger_reject_truncate();

CREATE OR REPLACE FUNCTION ledger_assert_journal_balance()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    checked_journal_id uuid;
    header_debit bigint;
    header_credit bigint;
    line_count integer;
    debit_sum bigint;
    credit_sum bigint;
    both_sides integer;
BEGIN
    IF TG_TABLE_NAME = 'journals' THEN
        checked_journal_id := NEW.id;
    ELSE
        checked_journal_id := NEW.journal_id;
    END IF;

    SELECT debit_total_minor, credit_total_minor
    INTO header_debit, header_credit
    FROM public.journals
    WHERE id = checked_journal_id;

    SELECT count(*), COALESCE(sum(debit_minor), 0), COALESCE(sum(credit_minor), 0)
    INTO line_count, debit_sum, credit_sum
    FROM public.journal_lines
    WHERE journal_id = checked_journal_id;

    IF line_count < 2 THEN
        RAISE EXCEPTION 'a journal must have at least two lines'
            USING ERRCODE = '23514';
    END IF;
    IF debit_sum <> credit_sum OR debit_sum <> header_debit OR credit_sum <> header_credit THEN
        RAISE EXCEPTION 'journal line totals must equal the header totals'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO both_sides
    FROM (
        SELECT account_id
        FROM public.journal_lines
        WHERE journal_id = checked_journal_id
        GROUP BY account_id
        HAVING sum(debit_minor) > 0 AND sum(credit_minor) > 0
    ) AS sided;
    IF both_sides > 0 THEN
        RAISE EXCEPTION 'an account may appear on only one side of a journal'
            USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

CREATE CONSTRAINT TRIGGER journals_balanced
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION ledger_assert_journal_balance();

CREATE OR REPLACE FUNCTION ledger_assert_reversal()
RETURNS trigger
LANGUAGE plpgsql
SET search_path = pg_catalog
AS $$
DECLARE
    original_book uuid;
    original_currency char(3);
    original_occurred timestamptz;
    original_posting date;
    swapped_missing integer;
    extra_lines integer;
BEGIN
    IF NOT NEW.is_reversal THEN
        RETURN NULL;
    END IF;

    SELECT book_id, currency, occurred_at, posting_date
    INTO original_book, original_currency, original_occurred, original_posting
    FROM public.journals
    WHERE id = NEW.reverses_journal_id;

    IF original_book IS NULL THEN
        RAISE EXCEPTION 'reversal target journal does not exist'
            USING ERRCODE = '23503';
    END IF;
    IF original_book <> NEW.book_id OR original_currency <> NEW.currency THEN
        RAISE EXCEPTION 'a reversal must use the same book and currency'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.occurred_at <= original_occurred THEN
        RAISE EXCEPTION 'a reversal must be at a later time'
            USING ERRCODE = '23514';
    END IF;
    IF NEW.posting_date < original_posting THEN
        RAISE EXCEPTION 'a reversal posting date must be on or after the original'
            USING ERRCODE = '23514';
    END IF;

    SELECT count(*) INTO swapped_missing
    FROM (
        SELECT account_id, credit_minor AS debit_minor, debit_minor AS credit_minor, subledger_type, subledger_ref
        FROM public.journal_lines
        WHERE journal_id = NEW.reverses_journal_id
        EXCEPT ALL
        SELECT account_id, debit_minor, credit_minor, subledger_type, subledger_ref
        FROM public.journal_lines
        WHERE journal_id = NEW.id
    ) AS missing;

    SELECT count(*) INTO extra_lines
    FROM (
        SELECT account_id, debit_minor, credit_minor, subledger_type, subledger_ref
        FROM public.journal_lines
        WHERE journal_id = NEW.id
        EXCEPT ALL
        SELECT account_id, credit_minor, debit_minor, subledger_type, subledger_ref
        FROM public.journal_lines
        WHERE journal_id = NEW.reverses_journal_id
    ) AS extra;

    IF swapped_missing > 0 OR extra_lines > 0 THEN
        RAISE EXCEPTION 'a reversal must swap the debit and credit sides'
            USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END;
$$;

CREATE INDEX journal_lines_account_subledger_idx
    ON journal_lines (account_id, subledger_type, subledger_ref);

CREATE INDEX journals_period_idx
    ON journals (book_id, period_id);

CREATE INDEX journals_posting_date_idx
    ON journals (book_id, posting_date);

CREATE CONSTRAINT TRIGGER journals_reversal_shape
    AFTER INSERT ON journals
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW
    EXECUTE FUNCTION ledger_assert_reversal();

CREATE VIEW account_balances AS
SELECT
    account.book_id,
    account.id AS account_id,
    account.code,
    account.account_type,
    account.normal_balance,
    COALESCE(sum(line.debit_minor), 0)::bigint AS debit_minor,
    COALESCE(sum(line.credit_minor), 0)::bigint AS credit_minor,
    (CASE account.normal_balance
        WHEN 'debit' THEN COALESCE(sum(line.debit_minor), 0) - COALESCE(sum(line.credit_minor), 0)
        ELSE COALESCE(sum(line.credit_minor), 0) - COALESCE(sum(line.debit_minor), 0)
    END)::bigint AS balance_minor
FROM accounts AS account
LEFT JOIN journal_lines AS line
    ON line.account_id = account.id
   AND line.book_id = account.book_id
GROUP BY
    account.book_id,
    account.id,
    account.code,
    account.account_type,
    account.normal_balance;
