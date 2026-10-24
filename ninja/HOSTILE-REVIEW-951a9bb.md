# Perfect Consign v2 — Hostile Review, Round 3 (HEAD `951a9bb`)

Supersedes `HOSTILE-REVIEW-9ce884c.md`. Every earlier finding is carried forward with a status. New findings continue the numbering at N13.

**Scope:** 19 commits since `9ce884c` (103 files, +2,390/−711). The server was re-provisioned from scratch: fresh `consign` database, `provision-platform`, `enable-stall-app`. PHP 8.5.12, PostgreSQL 18.6, Composer 2.10.3, xdebug.

**Not run, by instruction:** mutation testing (N2 is carried as not re-tested).

* * *

## Verdict

**Big improvement. One new defect that matters more than anything the author fixed.**

The author took the last review seriously. Of 43 tracked findings, **25 are now resolved**, 9 partial, 6 open, 2 withdrawn, 1 not re-tested. Three of the five Criticals are gone. `composer check` is green on every gate. The red proofs are fixed properly: the three missing chart roles were added as real rows, with an extra guard.

The headline from last round is closed. Four things now matter:

1.  **N13 (new, Critical): the job worker cannot run.** `php bin/work.php` dies on its first pass with a PDO exception inside `JobQueue::enqueue()`. Nothing the worker does has ever run against a real PostgreSQL with native prepares: late fees, reconcile, sale notices, vendor digests, rent reminders. The author also removed the manual late-fee button, so **late fees currently cannot be posted by anyone**. The queue fixes in this round (retry, dead-letter, `ON CONFLICT`) are correct SQL sitting behind a statement that never executes.
2.  **C1 is still open.** The Gmail app password is still in `sql/dev_tenant.sql` and `bin/MallDemo.php`. It is now in **41 commits** (22 last round, 19 the round before). Each new commit carries it. The author redacted it in the copy of my review they filed under `ninja/`, so they know. It still has not been revoked or removed.
3.  **The test gap that let N13 ship.** Coverage is 90.59% against a floor of 90%, a margin of 0.59 points. **23 files sit at 0%**, including `JobRunner`, `JobSchedule`, `DigestJob` and `SaleNoticeJob`, which is exactly the code N13 breaks. `NsfController`, `MallSaleController`, `FloorController`, `ReportCsvController`, `SalesTaxFiling` and `SavedReport` are also still at 0%.
4.  **The mail-failure fix is half right and partly backwards.** An SMTP authentication failure (535) is classed as a permanent bounce. Revoking the leaked password, which I am telling you to do, would mark every vendor's address as bounced. See N6.

### Gate results at `951a9bb`

| Gate | Result |
| --- | --- |
| check:freeze | OK |
| check:max-lines (500) | OK |
| PHPMD | clean |
| PHPStan level 10 | OK |
| Unit | **724 tests, 2,981 assertions, OK** (was 716) |
| Integration | OK |
| HTTP | **76 tests, 1,182 assertions, OK** |
| prove:obligations / prove:race / prove:tenant | all exit 0 |
| **prove** | **exit 0 — 56 of 56 proofs pass** (was 54 of 56) |
| Coverage (unit + integration + http) | 827 tests, 4,459 assertions, **90.59% lines** (19,499 of 21,525) |
| Mutation | not run (instruction) |
| `bin/work.php` against the fresh database | **FATAL on first pass** (N13) |

Each `composer check` gate was run individually and exited 0. That includes the deploy-blocking gates.

* * *

## Status ledger

`RES` = resolved. `PART` = partially addressed. `OPEN` = unchanged. `WD` = withdrawn. `RES*` = the code is fixed, but it sits behind N13 and cannot run end to end.

| ID | Title | Status | Evidence at `951a9bb` |
| --- | --- | --- | --- |
| C1 | Live Gmail app password committed | **OPEN, worse** | `sql/dev_tenant.sql:6-7` and `bin/MallDemo.php:167`. In **41** commits (22 → 41). No secret scanning in `composer.json` or CI. |
| C2 | No upgrade path / deploy wipes | **PART** | The dangerous half is fixed: `deploy-demo.sh:62-64` now exits 1 on a checksum change instead of wiping. Forward-only files 002–012 are the norm now. `TenantMigrator`, `MigrationRecord` and `SchemaMigrator` still say "create a new database" for databases built from an older 001, and README still says "An existing database is not upgraded". See N16. |
| C3 | One-click wipe on the production demo | **RES** | `layout.php:41` shows the button only if `STALL_ALLOW_TENANT_RESET=1`. `DevResetController` re-checks the flag, owner role and slug, and requires typing `RESET`. The `'mall-demo'` fallback is gone. |
| C4 | `wipe-tenant.php` drops platform FKs permanently | **RES** | No `DROP CONSTRAINT` remains. `keepPasswordResetKey()` keeps or restores the FK and refuses if orphans exist. Read from code; the tenant reset tests pass in the HTTP suite. |
| C5 | Store use has no idempotency key | **RES** | `StoreUse::postingKey()` is SHA-256 over vendor, item, amount and a form token. Controller issues the token. `StoreUseTest` now checks same form → same key and missing token → refused. |
| H1 | Worker runs as DB superuser | **OPEN** | `bin/work.php` still uses `STALL_DB_USER`. No `stall_worker` role. |
| H2 | Failed job finished, never retried | **RES\*** | `JobQueue::finish()` keeps `finished_at` NULL and backs off `attempts` minutes, dead-lettering at attempt 5. Probed: attempts 1–4 stay open, attempt 5 sets `finished_at`, attempt 6 claims nothing. No alerting on dead jobs. |
| H3 | `enqueue()` check-then-insert race | **RES\*** | `ON CONFLICT … DO NOTHING` plus unique index `jobs_tenant_kind_day_key`. The statement itself fails (N13). With a one-token cast the double enqueue produced one row. |
| H4 | Late fees bill all history | **RES** | `mall.late_fee_effective_on` (migration 011). `LateFeeDue::due()` skips periods starting before it. |
| H5 | Demo deploys without waiting for `check` | **RES** | `deploy-demo.yml` triggers on `workflow_run` of `check`, only on success, only for `master`. |
| H6 | (withdrawn earlier) | WD | — |
| H7 | Migration checksum rewrites itself | **RES** | `MigrationRecord::replace()` is gone. A changed file throws `MigrationFileChanged`. Only a pure CRLF/LF difference is tolerated. |
| H8 | Audit trail is decorative | **PART** | About 10 call sites now (booth, NSF, mall sale, `SaleCeiling`, company). Actors come from the session. NSF and booth audits are written in `beforeCommit`, inside the ledger transaction. `request_id` is still `bin2hex(random_bytes(8))`. Still no audit on store use, payouts, floor findings, pickups, vendor edits. Mall-sale audit is written after the insert, not atomically. |
| H9 | `Permission` used in one place | **PART, unchanged** | Still two call sites, both `settings.edit`. |
| M1 | Tests that cannot fail | **PART** | `StoreUseTest` is now real. `PhaseCallsTest` is unchanged: it asserts a hardcoded string contains three substrings, and it is the only test named for the job subsystem. `CollectPageTest` and `EnvelopePageTest` also use `method_exists`\-style checks (not inspected). |
| M2 | SMTP header CRLF injection | **RES** | `SmtpClient::line()` strips CR/LF from `to`, `from`, `subject` and credentials. Residual: still no `Date:` or `Message-ID:` header. |
| M3 | Reset mail failure swallowed | **RES** | `ResetLetter::deliver()` returns bool. `ForgotController` shows "The reset link was not sent." The error message is logged. See N17. |
| M4 | Owner reset falls back to company inbox | **RES** | `forReset()` requires the account's own email. |
| M5 | Gmail app password plaintext in DB and backups | **OPEN** | `sql/001_tenant.sql:3536` still `gmail_app_password text`. |
| M6 | CSV unescaped / formula injection | **RES** | New `Shared\Http\Csv` uses `fputcsv` and an apostrophe guard. Used by all five report CSVs. Has a real test. See N14 for the side effect. |
| M7 | `.cursor/rules/` shipped | **PART** | `do-not-run-tests.mdc` and `update-demo-on-push.mdc` deleted. The other three remain, and `maintain-proven-level.mdc` and `prove-before-advance.mdc` still say "Do not start this run unless the user asked for tests or proofs in this message. See `do-not-run-tests`", pointing at a file that no longer exists. |
| M8 | `overdue()` inverted | **RES** | Now `finished_at IS NULL AND locked_at IS NULL AND run_at < now − 15 min`. Probed: idle and an hour late → 1; claimed and running → 0. |
| M9 | Web tier cannot enqueue | **RES** | `012_job_insert.sql` grants INSERT to `stall_app` and `stall_poster`. Confirmed in the live role grants. |
| M10 | Reconcile proves the view, not the report | **PART, unchanged** | `tie()` checks tax and role drift. The books report is still compared to the view it is built from. |
| M11 | Late-fee journals dated "today" | **RES** | `LateFeeDue::apply()` posts with the slot date. |
| L1 | `sendJson()` duplicated | **RES** | Shared `JsonResponse::send()`. Three private one-line wrappers remain. |
| L2 | No `Cache-Control: no-store` on JSON | **RES** | `JsonResponse` and `ReportCsvController` set it. |
| L3 | `StoreUse::itemId()` null on empty code | **RES** | Empty or non-unique item code throws. |
| L4 | Real-looking identities in `dev_tenant.sql` | **OPEN** | Same file as C1. |
| L5 | `'mall-demo'` fallback password | **RES** | `MallTenantReset` and the new `ShopTenantReset` throw if `STALL_OWNER_PASSWORD` is unset. |
| L6 | README is Windows-oriented | **OPEN** | README line 3 still names `C:\laragon\www\stall`. |
| N1 | Files at 0% coverage | **OPEN** | Measured: 90.59% lines, **23 files at 0%**. See below. |
| N2 | Parallel mutation shards collide | **NOT RE-TESTED** | Mutation not run. |
| N3 | `prove` red: two proofs failing | **RES** | 56 of 56 pass. `prove_chart.sql` now has the three role rows plus an explicit three-role check. `prove_vendors_booths.sql` has a named `keep_days` exemption and a day-count proof. |
| N4 | NSF actor client-supplied, audit outside transaction | **RES** | Actor from the session (`Actor::name()`, CR/LF refused). Audit in `beforeCommit`. |
| N5 | New features ship with no tests | **PART** | +3 test files (`BoothExtraPostTest`, `CsvTest`, `MailDeliveryFailedTest`), +8 unit tests. Controllers and jobs from N1 are still untested. |
| N6 | `email_bounced` sticky, set on any exception | **PART** | Now cleared when a vendor's email changes (`PartyStore`). Only a 5xx marks a bounce. But see below: auth failures count as bounces, and temporary failures are never retried. |
| N7 | Override has no throttling | **RES** | `SaleCeiling` refuses after 5 failures in 15 minutes per tenant, username and client address, and clears on success. |
| N8 | Mall sales: no audit, overlap, or cap | **RES** | Migration 011: `percent_bps` 1–5000 CHECK, `EXCLUDE USING gist` on the date range, audit row on create. |
| N9 | Booth deposit: no drawer link, no audit | **PART** | Deposit and release now carry the open register session, and the audit runs in the transaction. A release path was added. See N15. |
| N10 | Sale notice shows minor units | **RES** | Uses `Money::format()`. |
| N11 | `/reports*` prefix match on books role | **RES** | `RoleGate::reportPage()` is now an explicit list plus a saved-report UUID pattern. |
| N12 | `keep_days` has no CHECK | **WD, my error** | `sql/010_competitive.sql:430` has `vendors_keep_days_chk` (1–3650) and `expiry_action` is also constrained. I said none existed. I was wrong. |

**Tally of 43 tracked findings:** 25 resolved (H2 and H3 are fixed in code but sit behind N13; the late-fee fixes H4 and M11 also run only through the worker), 9 partial, 6 open (C1, H1, M5, L4, L6, N1), 2 withdrawn, 1 not re-tested.

* * *

## CRITICAL

### N13 — The job worker cannot run (NEW)

`JobQueue::enqueue()` (`src/Shared/Jobs/JobQueue.php:30-35`) is:

```
INSERT INTO platform.jobs (tenant, kind, payload, run_at)
VALUES (?, ?, jsonb_build_object('date', ?), clock_timestamp())
ON CONFLICT (tenant, kind, (payload ->> 'date')) DO NOTHING
```

With native prepared statements (`pdo_pgsql` default, and what `bin/work.php` uses), PostgreSQL cannot infer the type of the third parameter inside `jsonb_build_object`'s variadic `"any"` argument:

```
SQLSTATE[42P18]: Indeterminate datatype: 7 ERROR: could not determine data type of parameter $3
```

**What I ran:**

-   **`php bin/work.php` on unmodified HEAD**, against a clean database with five tenants: fatal `PDOException` at `JobQueue.php:35`, called from `JobSchedule.php:36`, called from `JobRunner.php:27`.
-   **The old 9ce884c statement** (`SELECT … WHERE NOT EXISTS`): the same error. So the defect predates this round. It was introduced with the job subsystem in `3aca023`, and has been in every review round since.
-   **One-token fix, `?::text`, applied temporarily and reverted.** `php bin/work.php` then ran all 15 jobs (five tenants × three kinds) to completion with no error, and `git status` is clean afterwards. A double enqueue gave one row.

**Why it matters:**

-   Late fees, report reconciliation, vendor digests, rent reminders and sale notices have never run through this worker.
-   Commit `48ac321` removed the manual late-fee button so that "only the daily job posts them". **Late fees cannot currently be posted at all.**
-   The tax-drift and role-drift checks that make the new reports trustworthy (`ReconcileJob::tie`) run only inside this worker, so they have never run either.
-   The `ON CONFLICT` and retry fixes are right, but they were written and shipped without being executed.

I do not know whether the deployed demo runs `bin/work.php` from cron (the repo does not say). If it does, it has been failing since 21 Oct. If it does not, the features above are simply off.

**Why nobody saw it:** `JobRunner`, `JobSchedule`, `DigestJob` and `SaleNoticeJob` are all at 0% coverage, and no test calls `enqueue()`. The only test named for this subsystem is the string-literal test in M1.

**My own miss:** I reviewed the queue twice and called H3 a race. I did not drive `work.php` end to end until this round. The earlier probes did not exercise `enqueue()` through PDO.

**Fix:** cast the parameter (`?::text`), then add an integration test that runs `JobSchedule::schedule()` and `JobRunner::pass()` against the real database and asserts that jobs exist and finish.

* * *

## HIGH

### C1 — Live Gmail app password in the repository (OPEN, worse)

`sql/dev_tenant.sql:6-7` holds `benanamen@gmail.com` and the app password. `bin/MallDemo.php:167` holds the same credential and writes it into the demo mall on every reset. It is in **41 commits** and in every clone. Removing it from HEAD does not help while history keeps it. The new `ShopTenantReset` and `MallTenantReset` are careful about the owner password but still load this file.

**Fix, in order:** revoke the app password in the Google account. Remove both copies. Purge history with `git filter-repo`. Add `gitleaks` to `composer check`.

### H1 — Worker runs as the database superuser (OPEN)

`bin/work.php` connects as `STALL_DB_USER`. Once N13 is fixed, six job kinds that handle vendor data and send mail will run with full DDL rights. Add a `stall_worker` role.

### N6 — Mail failure handling (PARTIAL)

What improved: only 5xx marks a bounce, and the flag clears when the vendor's email is edited. What I tested with a local fake SMTP server (three modes) and `SmtpClient`:

| Server behaviour | Result |
| --- | --- |
| `535 Username and Password not accepted` at AUTH | `MailDeliveryFailed`, status 535, **`permanent() = true`** |
| `550 no such user` at RCPT | status 550, permanent = true |
| `421 try later` at RCPT | status 421, permanent = false |

Two problems follow:

1.  **A sender-side failure is treated as a recipient bounce.** Wrong or revoked Gmail password (535), a policy block at MAIL FROM, or mailbox-full on the sender's side all mark the vendor `email_bounced = true`. Fixing C1 correctly by revoking the password would, on the next job pass, mark every vendor with an email as bounced, and `SaleNoticeJob` would then skip them. Only a 5xx on `RCPT TO` is a recipient bounce.
2.  **Temporary failures are not retried, despite the commit message.** `DigestJob` and `SaleNoticeJob` catch `MailDeliveryFailed` and any `RuntimeException` themselves, log, and return normally. The job is marked successful. The retry and backoff in `JobQueue::finish()` only apply to exceptions that escape the job. A 421 on Monday means that vendor gets no digest or notice for the day.

**Fix:** classify the SMTP stage. Treat 5xx at `RCPT TO` as a bounce and anything else as a sender or transport error. Let transport errors escape the job so the queue retries, or record the unsent addresses and retry only those.

### H8 — Audit trail (PARTIAL)

Better, as shown in the ledger. Still missing: a request-scoped `request_id`, and audit rows for store use, payouts, floor findings, pickups and vendor edits. The mall-sale audit is a separate statement after the insert.

* * *

## MEDIUM

### N14 — The CSV formula guard corrupts negative numbers (NEW)

`Csv::cell()` prefixes `'` to any value starting with `= + - @ TAB CR`. That is right for text and wrong for numbers. I ran the writer:

```
name,net_minor,balance_minor
"'=HYPERLINK(""http://x"")",'-500,1250
"Smith, Jane",1250,'-30
```

`-500` and `-30` are now text in Excel. Every negative balance, refund and reversal in `sales.csv`, `payouts.csv` and `stored-value.csv` will not sum. The report is what the owner sends to the accountant. The formula injection is closed and the accounting use is broken. Separately, the journal export (`ExportController` through `stall_export_journal_csv`) quotes in SQL but has no formula guard on vendor or item text. **Fix:** apply the guard to text columns only, or let strings that match a plain signed number through. Add a negative-number case to `CsvTest`.

### N15 — Booth deposits discard the booth, and a release is unbounded (NEW)

`BoothExtraController::record()` requires `booth_id` for deposits and releases and then drops it. The booth reference goes only to the debit line when `$charge` is true, and the deposit and release lines carry `subledger_ref = null`. The deposit-liability account therefore has no per-booth balance, and the field is neither validated nor stored. A release (`/booth-deposit/release`) is a free-text booth id plus an amount. Nothing checks that a deposit exists, that the amount is at most what was held, or that it is released once. A cash-out tied to a drawer session and an audit row is better than before, but "released $500 against a $50 deposit" posts fine. **Fix:** store the booth on both lines, validate it against `booths`, and have the DB refuse a release that takes the booth's deposit balance below zero.

### M1 — Fake test (PART)

`PhaseCallsTest` is still the only test named for jobs and still asserts that a literal contains `pass(`, `assess(` and `numbers(`. It sits next to N13.

### M5 — Gmail app password plaintext in DB and backups (OPEN)

Unchanged. Every `pg_dump` and every backup carries it.

### M7 — `.cursor/rules` (PART)

Two files were deleted, as asked. The other two rule files that matter (`maintain-proven-level.mdc` and `prove-before-advance.mdc`) still tell the agent not to run checks unless the user asks in that message, and reference the deleted rule by name. Delete the two lines, or the two files.

### N16 — Deploy script's fallback path (NEW, not tested)

`deploy-demo.sh:60-68`: if `migrate.php tenant mall` fails with an error that is _not_ a checksum or "create a new database" message, the script runs `provision-tenant.php mall` and `set-owner.php`. A failing new migration (for example, `011`'s `EXCLUDE` constraint on a mall that already has overlapping sales, or a transient connection error) lands in that branch. I did not run this, so I do not know whether provisioning refuses an existing slug cleanly or half-applies. It should stop on any migrate error, not only the two named ones. Unverified.

### N1 — Coverage (OPEN)

Lines 19,499 of 21,525 = **90.59%**. The nightly floor is 90%. Twenty-three files are at exactly 0%:

`ReportsController`, `ReportCsvController`, `SalesTaxFiling`, `LedgerReport`, `SavedReport`, `report/sales-tax.php`, `report/table.php`, `NsfController`, `MallSaleController`, `FloorController`, `StoreUseController`, `MissedSale`, `MissedSaleController`, `VendorMemo`, `VendorMemoController`, `vendor-memo.php`, `missed-sale.php`, `ReceiptMailSettings`, `ReceiptMailer`, `JobRunner`, `JobSchedule`, `DigestJob`, `SaleNoticeJob`.

The sales-tax worksheet and its filing, the owner reports and the reconcile path are all in that list.

* * *

## LOW

-   **N17** The reset-letter failure message ("The reset link was not sent.") is shown only when the account exists, has an email, and delivery fails. During a mail outage it tells an attacker which usernames exist. Show the same message for unknown users, or log the failure and keep the generic response for everyone.
-   **N18** The override lockout shares `platform.owner_sign_in_failures` with owner sign-in, keyed by client address. An attacker with many addresses is not slowed. A cashier who fails five times at the till also blocks the owner signing in from that same address for 15 minutes.
-   **M2 residual** `SmtpClient::payload()` still emits no `Date:` or `Message-ID:` header.
-   **L4 / L6** as in the ledger.

* * *

## What is genuinely good

-   The author answered nearly every finding, in a short time, and mostly in the right way: constraints in the database rather than checks in PHP (`mall_sales` exclusion and cap, `keep_days`), shared writers (`Csv`, `JsonResponse`), audit rows in the ledger transaction, actors from the session.
-   Forward-only migrations 009–012 and a freeze check that blocks edits to stamped files. The deploy no longer wipes on a checksum mismatch.
-   The chart proof was fixed by adding the rows and an explicit three-role check, not by editing a count. That is the right response to N3.
-   Idempotency for store use is derived from the business inputs and a form token, with a real test.
-   All gates run green: PHPStan level 10, PHPMD, 500-line cap, 56 of 56 proofs, race proofs, tenant isolation, 724 unit tests, 76 HTTP tests.
-   The override lockout, the register-linked deposit and the in-transaction audits are exactly the controls I asked for.

* * *

## The three things a competitor will say now

1.  **"Run the worker."** It crashes on its first query. Late fees cannot be posted, and the button was removed (N13).
2.  **"Search the history for `gmail`."** Same credential, 41 commits, still re-planted into the demo on every reset (C1).
3.  **"Where are the tests for the reports?"** Twenty-three files at 0%, including the sales-tax worksheet and every job (N1). Coverage clears the floor by 0.59 points.

* * *

## Suggested order of work

**Today:**

1.  Cast the `enqueue()` parameter (`?::text`) and add one integration test that runs a scheduling pass and a worker pass against PostgreSQL (N13).
2.  Revoke the Gmail app password, remove both copies, purge history, add `gitleaks` (C1).
3.  Make a mail failure at AUTH or MAIL FROM a sender error, not a vendor bounce, _before_ you revoke the password (N6).

**This week:** 4. Let transient mail failures escape the job so the queue retries them (N6). 5. Stop the CSV guard from prefixing numbers (N14). 6. Store and validate the booth on deposits and releases, and make the database refuse an over-release (N15). 7. Make `deploy-demo.sh` stop on any migrate failure (N16). 8. Tests for the 23 files in N1, starting with `JobRunner`, `SalesTaxFiling`, `NsfController` and `ReportCsvController`. Replace `PhaseCallsTest` (M1). 9. A `stall_worker` role (H1).

**Next:** 10. Request-scoped `request_id`, and audit rows for store use, payouts, floor findings, pickups and vendor edits (H8). 11. Encrypt the Gmail app password at rest (M5). 12. Finish M7 (the two remaining rule files), M10, H9, L4 and L6. 13. Re-run mutation when you allow it, to refresh N2.

* * *

## Method and limits

-   Pulled `origin/master` at `951a9bb` and re-provisioned from scratch. Ran freeze, max-lines, PHPMD, PHPStan L10, unit, integration, HTTP, obligations, race, prove, tenant, plus coverage. Mutation not run.
-   **Executed this round:** `bin/work.php` on HEAD and on HEAD with a temporary cast (reverted, `git status` clean); the queue probes (retry, dead-letter, overdue) through `JobQueue` against the live database; the `Csv` writer on hostile input; `SmtpClient` against a local fake SMTP server in auth-failure, 550 and 421 modes; a count of commits whose tree contains the credential.
-   **Verified by reading code only:** C2, C3, C4, C5, H4, H5, H7, N4, N7, N8, N9, N11 and the ledger rows not listed above. For C4, C5 and N7 I did not run a wipe, a double post or a lockout.
-   **Not examined:** the demo server's cron setup, whether `provision-tenant.php` refuses an existing slug (N16), and the contents of `CollectPageTest` and `EnvelopePageTest` (M1).
-   I withdrew N12 (my mistake) and I missed N13 in two earlier rounds. Both are recorded in the ledger.