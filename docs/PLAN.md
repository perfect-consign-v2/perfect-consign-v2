# Stall

Internal name for the Perfect Consign database. Customers never see this name.

## Scope right now

Database, plus one command-line export. No screens, no register, no tags, and no vendor login.

`emp-pos` is a bad example of payout code that recomputes history and rewrites rows. Do not migrate it. Do not copy a table because that schema had one. Read it only to remember a bug.

The finished kernel in `C:\laragon\www\double-entry` stays where it is. Stall does not share its port, volume, or database.

## Decisions

- Folder: `C:\laragon\www\perfect-consign`. Workspace file `perfect-consign.code-workspace`. Copied from Stall `27e5e88a740e676024268fdf2ebf2e09ba3bad87`.
- Docker Compose in `C:\laragon\www\perfect-consign`. Volume `perfect_consign_pg`. Database name `consign`. User `stall`. Host port **5436**. Container `perfect-consign-postgres`. Image `postgres:18.6`. Do not use 5432, 5433, 5434, or 5435. Do not use volume `stall_pg`. Do not touch database `emp`, `emp_pos`, `double-entry`, or `stall`.
- The schema is `sql/000_platform.sql` and `sql/001_tenant.sql`. Apply both to an empty database. An existing database is not upgraded.
- Each behavior has a `prove_*.sql` that runs in one transaction and rolls back. Do not start the next fact until that proof is clean.
- No hash column. No stored balance column. A balance is the sum of `journal_lines`.
- A mistake is a reversal. Posted rows are not edited or deleted.
- One posting key cannot be inserted twice.

## Kernel already in place

`ledger_books`, `accounts`, `accounting_periods`, `journals`, `journal_lines`.

- A journal has at least two lines. Line debits equal line credits and equal the header totals. Amounts are non-negative integers in the book's `currency_scale`. Each line is only a debit or only a credit. An account appears on only one side of a journal.
- Lines belong to the posting transaction (`posting_xid`). A line inserted after that transaction commits is refused.
- A posted journal and its lines cannot be updated or deleted.
- Book currency and scale freeze once a journal exists. A referenced period's dates freeze. A closed or referenced period cannot be deleted. A posted account's book, postable flag, control flag, and subledger type freeze.
- Periods on one book do not overlap. A journal date falls inside its period. A closed period refuses a new journal.
- One reversal per original journal, same book and currency, swapped sides, later time, posting date on or after the original.
- A control account requires a subledger type, and a line on it requires that subledger.
- `sql/prove.sql` rolls back. On an empty kernel it leaves `accounts` empty. Once the mall book exists, it proves the same rules against that book and leaves the chart in place.

## Database work, in order

1. **Chart seed.** One book. Cash, bank, commission revenue, sales-tax payable, a vendor-payable control account, and a booth-rent receivable control account.
2. **Vendors and booths.** Identity only. The vendor id is the payable subledger ref. The booth id is the rent subledger ref. A vendor assigned to a booth is a row with a start date, not a money table.
3. **Sale.** One posting key per sale. Debit cash or card, credit commission, credit sales tax, credit that vendor's payable. The same sale posted twice is refused. A void is a reversal.
4. **Rent charge.** One posting key per booth per month. Debit that booth's rent receivable, credit rent revenue. The amount is the rate stored for that month. Payout does not calculate it again.
5. **Rent payment.** Debit cash, credit that booth's receivable, for rent paid outside a payout.
6. **Payout.** Read the open payable and the open rent receivable. One journal clears them against cash. It does not look back at sales. A second payout for the same vendor and month is refused. A wrong payout is reversed.
7. **Statement query.** For one vendor, the payable lines and that booth's rent lines, in order. That result is the check. If it disagrees with the payout journal, the schema is wrong.

## Ledger gate, proven

Truncate is refused on the ledger tables and on vendors, booths, assignments, rates, and chart roles. A posted account's type, normal balance, and code freeze. Names stay editable. Vendor payable and booth rent accept only a sale, rent charge, rent payment, or payout, and they cannot go below zero. A rent charge requires an assignment that covers the charge date. An assignment end or delete cannot uncover rent already posted. A post locks the book, the period, the account, and the vendor or booth. A rent charge also locks the rate and the assignment. `stall_poster` may insert only journals and lines.

## Database finished

These slices are proven. Each fact `prove_*.sql` rolls back and exits 0. `sql/prove_race.ps1` exits 0.

1. **Journal number.** The database assigns `journal_no`. A caller-supplied number is refused. The posting key stays the business identity.
2. **Adjustment.** Operating expense `6000`. A chart account refuses a source type it does not list. Non-control chart accounts accept an adjustment. Vendor payable and booth rent do not.
3. **Sales-tax payment.** Debit sales-tax payable, credit cash or bank, for the open tax or less. Sales-tax payable cannot go below zero.
4. **Opening balance.** One journal per book. An opening is refused once any other journal exists. A reversed opening can be posted again only while the book has no other journal. It may post to any chart account. A control line still requires its subledger.
5. **Export.** One row per posted line, plus `export_accounts` for QuickBooks Online and Xero. `bin/export-journals.php` writes the Online journal CSV and the Xero manual journal CSV. The Online description starts with the posting key. The Online account column is the account name stored for that company. The Xero date is `YYYY-MM-DD` and the tax rate must match that organisation. The QuickBooks value is the account name in that company. The Xero value is the account code. Seeded values are Stall’s codes until a company is mapped. It is not a screen.
6. **Year-end close.** Retained earnings `3000`. One closing journal zeros that period's income and expense, then the period is closed. A year with no income, or whose income journals were reversed in that year, is closed without a journal. A later year cannot close while an earlier year is open, and a new period cannot start before a closed one. A second close is refused. A reversal is a later journal in an open period. A period close is not reversible.

The allow-list is `chart_source_rules`, and `account_balances` is the sum of the lines.

## Choices that stay

These are deliberate.

- The login `stall` is the cluster superuser and the table owner. It can mark a period closed directly. Postgres does not let that power be removed from the superuser. The application uses `stall_poster`, which cannot.
- Append-only triggers on a mall schema, and on `platform.owner_backups`, are `ENABLE ALWAYS`. They cannot be disabled, limited to replica, or dropped, and they cannot be put back to origin. Postgres does not fire event triggers for `ALTER EVENT TRIGGER` or `DROP EVENT TRIGGER`, and a superuser can set `event_triggers=false`. Those are the ways the guard itself comes off.
- `session_replication_role=replica` still runs those triggers, so update, delete, and truncate are refused. Only a superuser can set replica mode. `stall_poster` and `stall_app` cannot. The guard event triggers are `ENABLE ALWAYS`, so that session still cannot disable or drop the row triggers.
- Cash may go below zero. A mall can be overdrawn. Cash and bank have no floor.
- An adjustment may credit commission or debit sales tax. The closing journal sweeps the net into retained earnings. The journal's source type shows that it was an adjustment.
- A source type is free text. A chart line must be `opening` or a row in `chart_source_rules`. A second list in the kernel would repeat that table.
- A subledger id that is not a UUID fails as a cast error. The row does not post.
- The export amount mask has 16 integer digits. A larger amount prints as `#`. That size is not a consignment posting.
- The export does not change a field that starts with `=`. The file is for the mall owner to import.
- A line added after its journal commits is refused by `journal_lines_seal`. This repo has no proof that commits a journal to show it. That script would leave a journal on this volume.
- Export account codes are Stall's own codes until a real company is mapped.

Committed data is the mall chart, the export map, the journal counter, and `stall_poster`. Journals, vendors, booths, and periods are 0.

## Later

QuickBooks Desktop. One tenant imports an IIF journal, not the Online CSV. That file is a later slice: map system `quickbooks_desktop`, account names from that Desktop company, posting key in `MEMO`. Do not start it until the Online and Xero files are in use.

## Not in this database pass

Screens, tags, the register, a vendor login, a chart-of-accounts screen, bank feeds, and profit-and-loss.
