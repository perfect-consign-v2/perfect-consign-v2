-- One row per posted line, and the external account map.
-- No file. Safe to run again.

CREATE OR REPLACE VIEW export_lines AS
SELECT
    journal.journal_no,
    journal.posting_date,
    journal.occurred_at,
    line.line_no,
    journal.posting_key,
    journal.source_type,
    journal.source_reference,
    account.code AS account_code,
    line.debit_minor,
    line.credit_minor,
    line.subledger_type,
    line.subledger_ref,
    journal.description,
    journal.currency
FROM journal_lines AS line
JOIN journals AS journal ON journal.id = line.journal_id AND journal.book_id = line.book_id
JOIN accounts AS account ON account.id = line.account_id AND account.book_id = line.book_id;

CREATE TABLE IF NOT EXISTS export_accounts (
    system text NOT NULL,
    role text NOT NULL REFERENCES chart_roles (role),
    external_code text NOT NULL,
    PRIMARY KEY (system, role),
    CONSTRAINT export_accounts_system_chk CHECK (system IN ('quickbooks', 'xero')),
    CONSTRAINT export_accounts_code_chk CHECK (external_code = btrim(external_code) AND external_code <> '')
);

INSERT INTO export_accounts (system, role, external_code)
SELECT systems.system, role.role, account.code
FROM chart_roles AS role
JOIN accounts AS account ON account.id = role.account_id
CROSS JOIN (VALUES ('quickbooks'), ('xero')) AS systems (system)
WHERE NOT EXISTS (
    SELECT 1
    FROM export_accounts AS existing
    WHERE existing.system = systems.system
      AND existing.role = role.role
);

GRANT SELECT ON export_lines, export_accounts TO stall_poster;
REVOKE INSERT, UPDATE, DELETE, TRUNCATE, TRIGGER ON export_accounts FROM stall_poster;
