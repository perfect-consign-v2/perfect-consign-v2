# Perfect Consign v2 — Hostile Review, Round 4 (HEAD `bdbdb6a`)

Supersedes `HOSTILE-REVIEW-951a9bb.md`. Every earlier finding is carried forward with a status. New findings continue the numbering at N19.

**Scope:** 4 commits since `951a9bb` (`cdb6e41`, `9a4e633`, `93dbef5`, `bdbdb6a`; 79 files, +1,811/−196). The server was re-provisioned from scratch: fresh `consign` database, `provision-platform`, `enable-stall-app` (now enables `stall_app`, `stall_backup` and `stall_worker`), `migrate platform`. PHP 8.5.12, PostgreSQL 18.6, Composer 2.10.3, xdebug.

**Not run, by standing instruction:** mutation testing (N2 is carried as not re-tested).

* * *

## Verdict

**This is the best round so far. Nothing from the last review is still open and unchanged. The one item I cannot call closed is the leaked credential, and no code change can close it.**

The author fixed the worker crash (N13) and ran the worker to confirm it. They also fixed the other findings I raised last time. Of 49 ledger rows, **39 are resolved, 7 partial, 0 open and unchanged, 2 withdrawn, 1 not re-tested.** Every `composer check` gate exits 0.

What I executed rather than read:

-   `php bin/work.php` ran to completion as the new `stall_worker` login. It claimed and finished 15 scheduled jobs across five tenants, with no PDO crash.
-   A second pass created no duplicate jobs.
-   A manually queued `rent-reminder` finished.
-   A manually queued `vendor-digest` failed with `smtp is not configured`, as expected in this sandbox. It stayed queued at attempt 1, so the retry path works.
-   `stall_worker` is not a superuser and has no table or schema rights on tenant data. It can only act through `SET ROLE` to a mall's poster role.
-   The booth-deposit trigger refused an over-release.
-   In a two-session concurrent test, exactly one of two full-deposit releases committed and the other was refused. The booth ended at zero.
-   The CSV guard keeps `-500` and `-5.25` as numbers and still neutralises formulas.
-   `SealedSecret` seals, opens, refuses plaintext and refuses a wrong key.

**What is still wrong, in order of importance:**

1.  **C1 (PARTIAL): the Gmail app password is gone from HEAD but still in 42 of 197 commits.** History is not purged, I have no evidence it was revoked, and there is no secret scanning. The author's `ninja/` copies of my reviews still carry the Gmail address.
2.  **N19 (new, Medium-High): the mail-retry fix is correct for classification and wrong for delivery.** A transient failure now escapes the job so the queue retries it. The retry restarts the whole recipient list, so vendors who already got the digest or "Sold today" notice get it again. One vendor with a persistent 4xx also blocks everyone after them in the loop.
3.  **N21 (new): the code changed this round is mostly not executed by any test.** `FloorController` is at 0%, `ReceiptMailSettings` (which now seals the password) is at 0%, `BoothExtraController` is at 7%, and `SaleCeiling` (the new lockout table) is at 27%. No CI gate runs `bin/work.php`, the file whose crash I found.
4.  **N20 (new): the sealed-secret rollout has no data migration.** A mall whose Gmail password was stored in plaintext before `016` will throw when its mailbox is read. Because of the order of operations in `PasswordRecovery::request()`, that exception is an HTTP 500 for existing usernames only. It is a username oracle.

### Gate results at `bdbdb6a`

| Gate | Result |
| --- | --- |
| check:freeze | OK |
| check:max-lines (500) | OK |
| PHPMD | clean |
| PHPStan level 10 | OK |
| Unit | **738 tests, 3,023 assertions, OK** (was 724 / 2,981) |
| Integration | **28 tests, 238 assertions, OK** |
| HTTP | **76 tests, 1,182 assertions, OK** |
| prove:obligations / prove:tenant / prove:race | all exit 0 |
| **prove** | **exit 0 — 57 of 57 proofs pass** (was 56; new `prove_booth_deposit.sql`) |
| Coverage (unit + integration + http) | 842 tests, 4,502 assertions, **90.98% lines** (19,665 of 21,614). Floor is 90%, margin 0.98 |
| Mutation | not run (instruction) |
| `bin/work.php` against the fresh database | **passes** (N13 closed) |

* * *

## Status ledger

`RES` = resolved. `PART` = partially addressed. `WD` = withdrawn. `NR` = not re-tested. Round 3's text said "43 tracked". The ledger below has 49 rows, and the rows are authoritative.

| ID | Title | Status | Evidence at `bdbdb6a` |
| --- | --- | --- | --- |
| C1 | Live Gmail app password committed | **PART** | Removed from HEAD. `bin/MallDemo.php` and `sql/dev_tenant.sql` now plant the mailbox through `MailboxSeed::plant()` from `STALL_GMAIL_ADDRESS` and `STALL_GMAIL_APP_PASSWORD`, sealed with `STALL_MAIL_KEY`. `git grep` for the password at HEAD finds nothing. **History still holds it: 42 of 197 commits** (41 last round; `cdb6e41`, the commit just before the removal, is the 42nd whose tree contains it. `9a4e633` removed it). `ninja/HOSTILE-REVIEW-951a9bb.md` and `-9ce884c.md` still carry `benanamen@gmail.com`. No `gitleaks` or equivalent in `composer.json` or CI (the only "secret" matches are the demo-deploy SSH secrets). No evidence of revocation. |
| C2 | No upgrade path / deploy wipes | **PART** | Forward-only 002–016 and a checksum stamp. `deploy-demo.sh` now stops on any `migrate.php tenant mall` failure (N16). "create a new database" still appears in `TenantMigrator`, `MigrationRecord` and `SchemaMigrator`, and README line 19 still says an existing database is not upgraded. |
| C3 | One-click wipe on production demo | **RES** | Unchanged from Round 3. |
| C4 | `wipe-tenant.php` drops platform FKs | **RES** | Unchanged. |
| C5 | Store use has no idempotency key | **RES** | Unchanged. Store use now also audits in-transaction (H8). |
| H1 | Worker runs as DB superuser | **RES** | `013_worker_role.sql` creates `stall_worker` NOINHERIT, NOLOGIN until `enable-stall-app`, NOSUPERUSER. Grants: CONNECT, USAGE on `platform`, SELECT/INSERT/UPDATE on `platform.jobs`, SELECT on `platform.tenants`, plus membership in each tenant's poster role. `JobRunner::handle()` and `JobSchedule::mallClock()` do `SET ROLE <poster>` with a regex-validated name and `RESET ROLE` in `finally`. **Executed:** logged in as `stall_worker`, `rolsuper=f`. `SELECT` on a tenant schema, on `platform.owners` and on `platform.override_sign_in_failures` all returned "permission denied". `pg_has_role(...,'member')=t`, `'usage'=f`. Five tenants (dev, mall, shop, webprobe, race) all ran under it. See N24 for the residual. |
| H2 | Failed job finished, never retried | **RES** | The failed `vendor-digest` job (mall, dev) stayed open at `attempts=1`, `finished_at` NULL, error recorded, with backoff. Dead-letter at 5 attempts is unchanged. Still no alert on dead jobs (N23). |
| H3 | `enqueue()` check-then-insert race | **RES** | `?::text` cast in `JobQueue::enqueue()`. **Executed:** two worker passes produced 15 rows, not 30. |
| H4 | Late fees bill all history | **RES** | Unchanged. The job now runs. |
| H5 | Demo deploys without waiting for `check` | **RES** | Unchanged. |
| H6 | (withdrawn earlier) | WD | — |
| H7 | Migration checksum rewrites itself | **RES** | Unchanged. |
| H8 | Audit trail is decorative | **PART** | Real progress. New `JournalAudit` wrapper. Audit call sites went from 14 to 33 matches in `src`. Floor findings, pickups, store use, payouts (settler and disburser) and vendor edits (`PartyStore`) now audit, and the floor ones run inside a transaction. Still missing, by grep of POST-route controllers containing no "audit": period close, adjustments, manual journals, opening, backup, mall settings, password change and reset, dev reset, offers, inventory, POS controllers. `request_id` is still `bin2hex(random_bytes(8))` made in the `AuditLog` constructor. |
| H9 | `Permission` used in one place | **PART** | Three call sites now (`ReportCsvController`, `ReportsController`, `RoleGate`). The role matrix is still mostly enforced by path lists. |
| M1 | Tests that cannot fail | **RES** | `PhaseCallsTest` is deleted. No `method_exists`\-style tests remain. New `JobQueueTest` is a real integration test (enqueue twice, one row). `JobDispatchTest` is stub-heavy: it tests dispatch, role reset and the unknown-kind refusal, not a worker pass. See N21. |
| M2 | SMTP header CRLF injection | **RES** | Residual closed: `SmtpClient::payload()` now adds `Date:` and a random `Message-ID:`. |
| M3 | Reset mail failure swallowed | **RES** | The reset user-facing failure message is gone (see N17). Failure is still logged. |
| M4 | Owner reset falls back to company inbox | **RES** | Unchanged. |
| M5 | Gmail app password plaintext in DB and backups | **RES (with caveats, N20)** | `SealedSecret` uses libsodium secretbox, random nonce, `v1:` prefix, key from `STALL_MAIL_KEY`. **Executed:** seal/open round-trips. Opening a plaintext value throws "not sealed". Opening with a different key throws "could not be opened". `ReceiptMailSettings` and `MailboxSeed` seal on write. Backups now carry ciphertext. No key rotation (the `v1` prefix allows it, nothing implements it). |
| M6 | CSV unescaped / formula injection | **RES** | Unchanged. |
| M7 | `.cursor/rules/` shipped | **PART** | The dangling `do-not-run-tests` references are gone. A new `catch-it-yourself.mdc` is a good rule and quotes my review almost word for word. `maintain-proven-level.mdc` and `prove-before-advance.mdc` still say the full check waits until the user asks. The directory is still in the product repo. |
| M8 | `overdue()` inverted | **RES** | Unchanged. |
| M9 | Web tier cannot enqueue | **RES** | Unchanged. |
| M10 | Reconcile proves the view, not the report | **RES (read only)** | `ReconcileJob::run()` now compares `BooksReport::displayedTotal()`, the sum of the rows the report shows, against `lineTotal()`, the ledger line total. New `BooksReportTest`. I read the diff but not `rows()`. |
| M11 | Late-fee journals dated "today" | **RES** | Unchanged. |
| L1 | `sendJson()` duplicated | **RES** | Unchanged. |
| L2 | No `Cache-Control: no-store` on JSON | **RES** | Unchanged. |
| L3 | `StoreUse::itemId()` null on empty code | **RES** | Unchanged. |
| L4 | Real-looking identities in `dev_tenant.sql` | **RES** | Vendor email is now `ada@example.test`, phone `555-0100`. The Gmail address survives only in the `ninja/` review copies (C1). |
| L5 | `'mall-demo'` fallback password | **RES** | Unchanged. |
| L6 | README is Windows-oriented | **RES** | No `laragon` string remains in README. |
| N1 | Files at 0% coverage | **PART** | 90.98% lines (19,665/21,614), up from 90.59%. Zero-coverage files went from 23 to 18: `ReportsController`, `MissedSale`, `NsfController`, `VendorMemo`, `FloorController`, `MissedSaleController`, `VendorMemoController`, `ReceiptMailSettings`, `MallSaleController`, `StoreUseController`, `LedgerReport`, `ReportCsvController`, `SavedReport`, `vendor-memo.php`, `ReceiptMailer`, `missed-sale.php`, `sales-tax.php`, `table.php`. Job files moved off zero but are still thin: `JobRunner` 46.8%, `JobSchedule` 31.0%, `DigestJob` 4.3%, `SaleNoticeJob` 3.7%. |
| N2 | Parallel mutation shards collide | **NR** | Mutation not run. |
| N3 | `prove` red: two proofs failing | **RES** | 57 of 57. |
| N4 | NSF actor client-supplied | **RES** | Unchanged. |
| N5 | New features ship with no tests | **PART** | +14 unit tests, new `SmtpStageTest`, `SealedSecretTest`, `MailboxSeedTest`, `ObligationSymbolTest`, `SalesTaxFilingTest`, `BooksReportTest`, `ExportStoreTest`. Still not exercising the controllers (N1, N21). |
| N6 | `email_bounced` sticky, set on any exception | **RES (classification), see N19** | `MailDeliveryFailed` now carries the SMTP stage. `permanent()` is true only for 5xx at `RCPT TO`. 535 at AUTH is not a bounce, 421 at RCPT is not a bounce. `MailAttempt::send()` rethrows non-permanent failures so the queue retries. `SmtpStageTest` runs a real fake SMTP server in three modes and passes. The retry semantics have a new flaw (N19). |
| N7 | Override has no throttling | **RES** | Unchanged. Moved to its own table (N18, N22). |
| N8 | Mall sales: no audit, overlap, or cap | **RES** | Unchanged. |
| N9 | Booth deposit: no drawer link, no audit | **RES** | Register session and in-transaction audit were already there. The booth is now stored and validated (N15). |
| N10 | Sale notice shows minor units | **RES** | Unchanged. |
| N11 | `/reports*` prefix match on books role | **RES** | Unchanged. |
| N12 | `keep_days` has no CHECK | **WD, my error** | Withdrawn in Round 3. |
| N13 | Job worker cannot run | **RES** | `jsonb_build_object('date', ?::text)`. **Executed:** `php bin/work.php` as `stall_worker` exits 0 and finishes every claimed job. `JobQueueTest` covers the enqueue statement. |
| N14 | CSV guard corrupts negative numbers | **RES** | **Executed:** `Csv::protect('-500')` → `-500`, `-5.25` → `-5.25`, `1,000.00` unchanged. `=1+1`, `-1+2`, `@x`, `+5`, `-12abc`, `-$5.00`, `-(5)`, a leading tab and a bare `-` all get the apostrophe. The journal export (`ExportStore`) now re-parses each line and applies the same guard, with `ExportStoreTest`. |
| N15 | Booth deposits drop the booth, release unbounded | **RES** | `014_booth_deposit.sql` adds control account `2151 booth_deposit_held` (`subledger_type = booth`) and a `SECURITY DEFINER` trigger, `ENABLE ALWAYS`. It requires a booth on every line to that account, requires the booth to exist, and raises 23514 if the booth's balance would go negative. `BoothExtraController` validates the UUID and the booth, and puts the booth on the deposit and the release line. **Executed:** a release larger than the deposit was refused. Two concurrent full releases against a $50.00 deposit: one committed, one refused, the booth ended at 0. See caveats below the ledger. |
| N16 | Deploy script's fallback path | **RES (read only)** | `deploy-demo.sh` now prints the error and exits 1 on any `migrate.php tenant mall` failure. The `provision-tenant.php mall` fallback is gone. |
| N17 | Reset-letter failure message leaks username existence | **RES** | `ForgotController::submit()` always redirects with the same notice. See N20 for a way the 500 oracle returns. |
| N18 | Override lockout shares the owner table | **RES** | `015_override_failures.sql` creates `platform.override_sign_in_failures`, keyed by tenant and username. `SaleCeiling` uses it. Granted to `stall_app`, `stall_poster` and each existing poster role. See N22 for the trade-off. |

**Tally of 49 ledger rows:** 39 resolved, 7 partial (C1, C2, H8, H9, M7, N1, N5), 0 open and unchanged, 2 withdrawn (H6, N12), 1 not re-tested (N2).

**N15 caveats:**

-   `prove:race` has no booth-deposit case. My two-session run is not a proof, and I did not identify which lock serialises the two sessions. The result was correct.
-   The old `booth_deposits` account is replaced by `booth_deposit_held`. I did not check what happens to deposits already posted to the old account. A demo mall resets, but a real mall would have balances stranded without a booth reference.

* * *

## HIGH

### C1 — Live Gmail app password in the repository (PARTIAL)

HEAD is clean. History is not: **42 of 197 commits** contain the password, and every clone has it. The `ninja/` review files, which the author keeps in the repo, still carry the Gmail address. There is no evidence that the app password was revoked.

**Fix, in order:**

1.  Revoke the app password in the Google account. It is safe now, because N6 no longer treats a 535 as a vendor bounce.
2.  Purge history with `git filter-repo`, or accept that the credential is public and rely on step 1.
3.  Add `gitleaks` or equivalent to `composer check`.
4.  Remove the address from `ninja/`.

### N19 — Mail retries resend to everyone, and one bad recipient blocks the rest (NEW)

`DigestJob::send()` and `SaleNoticeJob` loop over vendors and call `MailAttempt::send()`. For a non-permanent `MailDeliveryFailed`, and for any `RuntimeException` (connection failure, "smtp is not configured"), the exception now escapes the loop and the job. `JobRunner::pass()` catches it and `JobQueue::finish()` reschedules the **whole job**.

Three consequences follow from reading the code:

-   Vendors earlier in the loop already received the letter. The retry sends it to them again, up to five times.
-   A vendor whose address returns a persistent 4xx at `RCPT TO` (greylisting, mailbox full, 452) means every vendor after them in the loop gets nothing until the job dead-letters. Each retry also re-mails the vendors before them.
-   There is no per-recipient "sent" record.

This is by code reading. I did not execute a partial-failure run, because the harness needs a selective SMTP failure per recipient.

**Fix:** record each recipient's outcome. For example, add a `(job_id, email)` sent table, or enqueue one job per vendor. Retry only the unsent. Keep the permanent-bounce rule as is.

* * *

## MEDIUM

### N20 — Sealed-secret rollout: no data migration, and a 500 that tells attackers who exists (NEW)

-   `016_mail_secret.sql` only widens the CHECK. It does not re-seal existing rows, and the CHECK still allows a 16–64 character alphanumeric plaintext value. A mall that stored its Gmail password before `016` (the live demo, unless it was reset) holds a value that `SealedSecret::open()` refuses with "not sealed".
-   `PasswordRecovery::request()` returns `true` early for an unknown or throttled username. It reads the company mailbox only after the account is found. A legacy plaintext row, or a missing or wrong `STALL_MAIL_KEY`, therefore throws from `LoginAccounts::gmail()` only for usernames that exist. `ForgotController` does not catch it, so existing usernames get a 500 and unknown ones get the generic notice. That is the N17 oracle again, on misconfiguration.
-   `STALL_MAIL_KEY` appears in README but not in `deploy-demo.sh`. Nothing checks it is set before the app serves traffic.

**Fix:**

-   Add a migration or `bin/` step that seals existing plaintext, then tighten the CHECK to `v1:` only.
-   Catch mailbox errors inside `PasswordRecovery::request()` and return the generic notice.
-   Fail fast at boot if `STALL_MAIL_KEY` is missing and any mall uses Gmail.

### N21 — The code changed this round is mostly never executed by a test (NEW)

The author added a rule, "execute the path you edited", in `catch-it-yourself.mdc`. The suite does not show it:

-   `FloorController` was rewritten into transactions with audit rows and is at **0%**.
-   `ReceiptMailSettings`, which now seals the password on save, is at **0%**.
-   `BoothExtraController` is at **7.2%**. The booth fix is covered by the SQL proof and nothing in PHP.
-   `SaleCeiling`, which moved to a new table, is at **27%**.
-   `JobRunner` is 46.8%, `DigestJob` 4.3%, `SaleNoticeJob` 3.7%.
-   No CI gate runs `bin/work.php` (no match in `.github` or `composer.json`). `JobQueueTest` covers one SQL statement. The failure I found twice would be caught by one worker pass in CI.

I did not execute `FloorController` or `BoothExtraController` through the web either. I am flagging them as untested, not broken.

**Fix:** add a CI step that runs one `bin/work.php` pass against the provisioned database. Add HTTP tests for the floor note, the pickup, a booth release and the override lockout. Then raise the coverage floor.

### M7 — `.cursor/rules` (PART)

The directory ships in the product repo. The two older rules still tell an agent to withhold the full check until the user asks. The new rule says so explicitly. I would delete the older text.

### N1 — Coverage (PART)

See the ledger. Eighteen files remain at 0%, including the sales-tax, NSF, store-use, report CSV and saved-report paths.

* * *

## LOW

-   **N22 — Override lockout is per username, not per address.** Anyone who can reach the till can lock an override user out for 15 minutes with five bad attempts. Failure rows are deleted only on success, so they accumulate for usernames that never succeed. Add a retention delete, and consider a combined username and address key.
-   **N23 — Digests use the server mailbox, receipts and resets use the mall's Gmail.** `DigestJob` and `SaleNoticeJob` send through `OutboundMail`, which reads `STALL_SMTP_*` from the environment (I read `OutboundMail::settings()`). Receipts and resets use the mall's own Gmail. In my run both `vendor-digest` jobs failed with "smtp is not configured". Dead-lettered jobs still raise no alert (H2 residual).
-   **N24 — Worker blast radius.** `stall_worker` is a member of every tenant's poster role, by design, because one worker serves all tenants. A compromise of the worker login is every tenant's poster rights, not superuser. Acceptable, but worth a line in the threat model.
-   **H8, H9, C2** as in the ledger.

* * *

## What is genuinely good

-   **The author ran the worker.** N13 is fixed with a one-token cast, and the new `catch-it-yourself` rule shows they understood why I found it.
-   **The worker is least-privilege.** `stall_worker` is not a superuser, cannot read tenant tables directly, and assumes the mall's poster role per job and resets it in `finally`.
-   **The mail-failure fix is classified by SMTP stage and tested against a real fake server.** `SmtpStageTest` starts a stand-in on a port and checks 535, 550 and 421. It is the right way to test a socket client.
-   **Booth deposits are enforced in the database.** A control account, a required booth on every line, an existence check, a `SECURITY DEFINER` trigger that refuses a negative balance, and a new proof.
-   **The CSV guard is correct.** Negative numbers stay numeric and formulas are neutralised, in both the report writer and the journal export.
-   **Secrets are sealed at rest.** The error cases (plaintext, wrong key) fail closed.
-   **Audit coverage is wider and runs inside the ledger transaction.** Store use, payouts, vendor edits, floor findings and pickups now write audit rows.
-   **All gates are green.** PHPStan level 10, PHPMD, the 500-line cap, 57 of 57 proofs, race, tenant, 738 unit, 28 integration and 76 HTTP tests.

* * *

## The three things a competitor will say now

1.  **"Search the history for `gmail`."** Same credential, 42 commits, still not revoked or purged (C1).
2.  **"Break the mail job."** One persistent 4xx on one vendor starves the rest, and every retry re-mails the vendors before them (N19).
3.  **"Where are the tests for the code you changed?"** The floor controller, the mailbox settings that seal the password, the booth controller and the override lockout are all at or near 0% (N21).

* * *

## Suggested order of work

**Today:**

1.  Revoke the Gmail app password. Purge history or accept it is public. Add `gitleaks`. Remove the address from `ninja/` (C1).
2.  Make the mail jobs resumable per recipient (N19).

**This week:** 3. Seal existing plaintext mailbox rows, tighten the CHECK to `v1:`, and catch mailbox errors in `PasswordRecovery::request()` (N20). 4. Add a CI step that runs `bin/work.php` once. Add HTTP tests for `FloorController`, `BoothExtraController` release and the override lockout (N21). 5. Decide what happens to deposits posted to the old booth account (N15 caveat).

**Next:** 6. Request-scoped `request_id`, and audit rows for close, adjustments, manual journals, mall settings and password changes (H8). 7. Finish M7, H9 and C2 wording. Retention for override failures (N22). Alerting for dead-lettered jobs (N23). 8. Re-run mutation when you allow it, to refresh N2.

* * *

## Method and limits

-   Pulled `origin/master` at `bdbdb6a` and re-provisioned from scratch. Ran freeze, max-lines, PHPMD, PHPStan L10, unit, integration, obligations, tenant, race, prove and HTTP individually, plus coverage (unit, integration and HTTP). Mutation not run.
-   **Executed this round:**
    -   `bin/work.php` twice as `stall_worker`, plus a manual queue of `vendor-digest` and `rent-reminder`.
    -   Role and grant probes as `stall_worker`.
    -   A two-session concurrent booth release against the live database.
    -   `Csv::protect()` on hostile inputs.
    -   `SealedSecret` seal, open, plaintext and wrong-key cases.
    -   A count of commits whose tree contains the credential.
-   **Verified by reading code only:** `MailAttempt` and `SmtpClient` stage handling (plus the passing `SmtpStageTest`), N19's retry duplication, N20's order of operations, `ExportStore`, `FloorController`, `SaleCeiling`, `deploy-demo.sh`, `ForgotController`, `ReconcileJob` and `BooksReport`, and the audit counts, which are greps.
-   **Not examined:** the live demo server (cron, whether its mailbox row is plaintext), `BooksReport::rows()`, whether `prove:race` should gain a booth case, and any pre-`014` deposits.
-   Coverage was run last, and its test suites recreate tenant schemas. My manual concurrent-release probe ran before that.