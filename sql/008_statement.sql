-- One vendor's payable lines and that vendor's booth rent lines, in posting order.
-- This is the check against a payout. It reads journal lines, not sales.

CREATE VIEW vendor_statement AS
SELECT
    CASE
        WHEN role.role = 'vendor_payable' THEN line.subledger_ref::uuid
        ELSE assignment.vendor_id
    END AS vendor_id,
    journal.posting_date,
    journal.occurred_at,
    journal.journal_no,
    line.line_no,
    journal.id AS journal_id,
    journal.source_type,
    journal.posting_key,
    role.role,
    line.subledger_type,
    line.subledger_ref,
    line.debit_minor,
    line.credit_minor
FROM journal_lines AS line
JOIN journals AS journal ON journal.id = line.journal_id AND journal.book_id = line.book_id
JOIN chart_roles AS role ON role.account_id = line.account_id
LEFT JOIN booth_assignments AS assignment
    ON role.role = 'booth_rent_receivable'
    AND assignment.booth_id = line.subledger_ref::uuid
    AND assignment.assignment_range @> journal.posting_date
WHERE role.role IN ('vendor_payable', 'booth_rent_receivable');

DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_roles WHERE rolname = 'stall_poster') THEN
        CREATE ROLE stall_poster NOLOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE;
    END IF;
END $$;

GRANT USAGE ON SCHEMA public TO stall_poster;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO stall_poster;
REVOKE INSERT, UPDATE, DELETE, TRUNCATE, TRIGGER ON ALL TABLES IN SCHEMA public FROM stall_poster;
GRANT INSERT ON journals, journal_lines TO stall_poster;
ALTER DEFAULT PRIVILEGES FOR ROLE stall IN SCHEMA public
    REVOKE INSERT ON TABLES FROM stall_poster;
