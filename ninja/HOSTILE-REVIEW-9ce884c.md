# Perfect Consign v2 — Hostile Review, Round 2 (HEAD `9ce884c`)

Supersedes `HOSTILE-REVIEW-67d6a9a.md` and `HOSTILE-REVIEW-RUN-RESULTS.md`. Every earlier finding is carried forward with a status. New findings are numbered N3 onward.

**Scope of this round:** 3 new commits since `67d6a9a` (`050455f` sales-tax worksheet, owner reports, floor features; `422364e` mutation worktree fix; `9ce884c` pickup FK drop in a proof). 74 files, +2,520/−91 lines in the big commit. The server was re-provisioned from scratch (fresh `consign` database, PHP 8.5.12, PostgreSQL 18.6 on port 5436, Composer 2.10.3, xdebug).

**Deliberately not run:** mutation testing, per your instruction. **Not re-run this round:** the coverage floor (N1). It is carried at its last measured state and flagged as stale.

* * *

## Verdict

**Not better. In two respects worse.**

1.  **`composer check` is red at HEAD.** Two of the 56 database proofs fail. The project's central claim is "proven", and the gate that enforces it does not pass on the commit that was pushed. See N3.
2.  **The live Gmail app password is now in the repository twice, in 22 commits.** It was once in 19 commits. A second hardcoded copy was added in `bin/MallDemo.php`. See C1.

None of the five Critical findings from last round were fixed. Of 33 carried findings, **0 are fully resolved**, 4 are partially addressed, 1 was withdrawn earlier, 2 could not be re-measured, and the remaining 26 are unchanged or worse.

The new feature work (+1,877 lines in `src/`) landed with essentially **no tests**. The unit suite is still exactly 716 tests, the same as before the commit. Eleven new classes are referenced by zero tests. The only test change that touched them is a fake test that grew longer (M1).

The architecture underneath is still good. The new database-side drift refusals and the forward-only migrations 009 and 010 are the right pattern. The failures are in discipline: secrets, gates, tests, and deploy.

### Gate results at `9ce884c`

| Gate | Result |
| --- | --- |
| check:freeze | OK |
| check:max-lines (500) | OK |
| PHPMD | clean |
| PHPStan level 10 | `[OK] No errors` |
| Unit suite | **716 tests, 2,904 assertions, OK** (unchanged count) |
| Integration suite | 27 tests, 223 assertions, OK |
| HTTP suite | 76 tests, 1,182 assertions, OK (about 5 minutes) |
| prove:obligations | exit 0 |
| prove:race | exit 0 |
| prove:tenant | exit 0 |
| **prove** | **exit 1 — 2 of 56 proofs FAIL, 54 pass** |
| Mutation | not run (instruction) |
| Coverage | not re-run |

Because `prove` is part of `composer check`, `check` fails. `check.yml` in CI runs `composer run check`, so CI is red on this commit. Reproduced on a clean database.

* * *

## Status ledger of every earlier finding

| ID | Title | Status at `9ce884c` | Evidence |
| --- | --- | --- | --- |
| C1 | Live Gmail app password committed | **OPEN — WORSENED** | Still in `sql/dev_tenant.sql:5-6`. New second copy in `bin/MallDemo.php:166`. 22 commits now, was 19. |
| C2 | No upgrade path ("create a new database") | **OPEN** | Same throws in `TenantMigrator.php:30`, `MigrationRecord.php:68`, `SchemaMigrator.php:28`. `deploy-demo.sh:63` still wipes on "changed after applied". |
| C3 | One-click wipe button on the production demo | **OPEN** | `layout.php:36` and `:260-261`, `confirm()` only. `MallTenantReset.php:61` still falls back to `'mall-demo'`. |
| C4 | `wipe-tenant.php` drops platform FKs permanently | **OPEN** | Lines 75 and 94 unchanged. The line-94 `owner_backups` branch is dead code because that table has no FK. |
| C5 | Store use has no idempotency key | **OPEN** | `StoreUse.php:35` still `bin2hex(random_bytes(16))`. Line 38 posting key `'store-use:' . $reference`. |
| H1 | Worker runs as DB superuser | **OPEN** | `bin/work.php` still uses `STALL_DB_USER`. |
| H2 | Failed job marked finished, never retried | **PARTIAL** | `DigestJob` and `SaleNoticeJob` now catch their own failures. `JobQueue::finish()` still stamps `finished_at` on error. No retry, no dead-letter. The new catch-all creates a new problem (N6). |
| H3 | `enqueue()` check-then-insert race | **OPEN — surface grew** | Still `WHERE NOT EXISTS`, no `ON CONFLICT`. Five job kinds are now enqueued daily (was four). |
| H4 | Late fees retroactively bill history | **OPEN** | `LateFeeDue` unchanged. |
| H5 | Demo deploys on push without waiting for check | **OPEN — now acute** | `deploy-demo.yml` unchanged. This commit was deployable with `check` red (N3). |
| H6 | (withdrawn) | WITHDRAWN | Tested and found incorrect last round. |
| H7 | Migration checksum silently rewrites itself | **OPEN** | `MigrationRecord::replace()` still called at line 76. |
| H8 | Audit trail is decorative | **PARTIAL, then regressed** | About 6 call sites now. `request_id` is still random. The new NSF call site records a client-supplied actor (N4). |
| H9 | `Permission` verb list used in one place | **PARTIAL** | Two production call sites (`ReportCsvController:132`, `ReportsController:220`), both `settings.edit`. `RoleGate` is still the real authorization. |
| M1 | Tests that cannot fail | **OPEN — WORSENED** | `PhaseCallsTest` still asserts a hardcoded string, and the string grew with the new method names appended. `StoreUseTest` is unchanged. |
| M2 | SMTP header CRLF injection | **OPEN (partial mitigation)** | `SmtpClient::payload()` unchanged. `OutboundMail` and `ReceiptMailer` guard CR/LF, but `ResetLetter` passes `$account->email` straight through. |
| M3 | Reset mail failures swallowed | **OPEN** | Unchanged. |
| M4 | Owner reset falls back to shared company inbox | **OPEN** | Unchanged. |
| M5 | Gmail app password plaintext in DB and backups | **OPEN** | `sql/001_tenant.sql:3536` still `text`. |
| M6 | CSV unescaped, formula-injectable | **OPEN — spread to 5 more endpoints** | `ReportCsvController.php:117,123` use `implode(',')` with HTML-escaping. No `fputcsv`, no formula guard. |
| M7 | `.cursor/rules/` shipped | **OPEN** | All five `.mdc` files still present, including `do-not-run-tests.mdc`. |
| M8 | `overdue()` inverted | **OPEN** | `JobQueue.php:85-99` unchanged. |
| M9 | `platform.jobs` grants lack INSERT for web tier | **OPEN** | `002`/`004` still SELECT-only. |
| M10 | Reconcile proves the view, not the report | **PARTIAL** | The new `tie()` step does check tax and role drift against the ledger. The main books-report path is still checked against the same view. |
| M11 | Late-fee journals dated "today" | **OPEN** | Unchanged. |
| L1 | `sendJson()` duplicated | **OPEN** | `PosController` and `PosPayController` were touched, not de-duplicated. |
| L2 | No `Cache-Control: no-store` on JSON | **OPEN** | Only `TenantBackup.php:183` sets it. |
| L3 | `StoreUse::itemId()` returns null on empty code | **OPEN** | Unchanged. |
| L4 | Real-looking identities in `dev_tenant.sql` | **OPEN** | Unchanged. |
| L5 | `'mall-demo'` fallback password | **OPEN** | `MallTenantReset.php:61`. |
| L6 | README is Windows-oriented | **OPEN** | Unchanged. |
| N1 | Files at 0% coverage | **UNVERIFIED — likely worse** | Not re-run. Eleven new classes have no tests referencing them, so they cannot be covered by the unit suite. The HTTP suite hits none of the new routes (no test mentions `checks/nsf`, `booth-deposit`, `mall-sale`, `reports/*.csv`). |
| N2 | Parallel mutation shards collide on temp files | **NOT RE-TESTED** | Mutation was out of scope. Commit `422364e` touches the worktree cleanup in the same area, which suggests the author is still fighting it. |

Result: **0 resolved, 4 partial (H2, H8, H9, M10), 1 withdrawn (H6), 2 not re-measured (N1, N2), 26 open.** Of the open items, C1, H5, M1 and M6 are worse than before.

* * *

## CRITICAL

### C1 — A live Gmail app password is committed to the repository (OPEN, WORSENED)

-   `sql/dev_tenant.sql:5-6` holds `benanamen@gmail.com` and the app password `[app password]`.
-   **New:** `bin/MallDemo.php:166` hardcodes the same credential as `['gmail','benanamen@gmail.com','[app password]']`. `MallDemo::load()` writes it into the live demo mall's settings. `bin/seed-mall.php` and `MallTenantReset.php:25` call it. So every demo reset re-plants the secret into the running database, where `stall_backup` and every dump will carry it.
-   The secret is in **22 commits**. There is no secret scanning in `composer.json` or CI.
-   Removing the line at HEAD changes nothing. The credential is in git history, which you should treat as public to anyone who has ever had clone access.

**Fix, in this order:** revoke the app password in the Google account today. Rotate the Gmail address if it is a real mailbox. Then remove both copies and move to an environment variable. Purge the history with `git filter-repo` and force-push. Add `gitleaks` to `composer check` and CI so it cannot come back.

### C2 — No upgrade path (OPEN)

The forward-only files 009 and 010 are the right model and are a real improvement. But `sql/001_tenant.sql` is still edited in place and a checksum mismatch still throws "create a new database". `bin/deploy-demo.sh:63` still answers a changed checksum by running `wipe-tenant.php mall`. A paying customer on any previous version has no way to move forward.

**Fix:** freeze 000 and 001 forever. Everything after is a numbered forward migration. The freeze check already exists for the collapsed files. Make the deploy script fail on a checksum change, never wipe.

### C3 — One-click wipe on the production demo (OPEN)

`layout.php:36` shows the reset button for any owner on the `dev` or `mall` slug. The only protection is a browser `confirm()`. Any logged-in owner, or a forged POST if CSRF protection has a gap, resets the mall. The fallback owner password `'mall-demo'` (`MallTenantReset.php:61`, L5) is still a public default.

**Fix:** gate behind an environment flag that is off in production. Require a typed confirmation and a configured password. Fail if none is set.

### C4 — `wipe-tenant.php` permanently drops platform foreign keys (OPEN)

Line 75 drops the `password_resets` FK and never restores it. The `owner_backups` branch at line 94 is dead code. After the first wipe on any environment, referential integrity on the platform schema is quietly weaker for ever.

**Fix:** restore the constraints in a `finally`, or do not drop them. Use `DELETE` in dependency order.

### C5 — Store use has no idempotency key (OPEN)

`StoreUse.php:35` generates `bin2hex(random_bytes(16))` for the reference, so the posting key at line 38 is unique per submit. A double-click, a retry, or a back-button resubmit posts the money movement twice. Every other money operation uses a business-derived key. The unique-posting-key mechanism in the ledger exists precisely to prevent this, and this path opts out of it.

**Fix:** derive the key from the form (item, vendor, amount, and a per-render token). Add the real test that M1 says is missing.

### N3 — `composer check` is red: two proofs fail, and one was edited to hide it (NEW, CRITICAL)

This is the headline. The project sells "proven". The proof suite does not pass on HEAD.

**`FAIL prove_chart.sql` — "chart roles do not match the mall chart".** Commit `050455f` added three chart roles (`returned_checks`, `booth_other_revenue`, `booth_deposits`) in migration 010. In `sql/prove_chart.sql` the author changed the count assertion to `role_count <> 29` (at about lines 63 and 145). The expected-values list is still **26 rows**. The three new roles appear only in a **SQL comment** at line 146. The live tenant has 29 roles, the join returns 26, and the proof fails. This is worse than a stale test. The count was edited to the new number, which made the assertion look maintained, but the rows that give the assertion meaning were never added. Had the count been left alone, the failure would at least have been honest.

**`FAIL prove_vendors_booths.sql` — "vendor or booth tables carry money".** Migration 010 adds `vendors.keep_days integer`. The proof forbids any numeric, bigint, integer or smallint column in the vendor and booth tables other than `width_ft`, `depth_ft`, `x_ft`, `z_ft` and `commission_bps`. I confirmed against `information_schema` on a freshly provisioned tenant that `vendors.keep_days` is the only offender. The newest commit, `9ce884c`, touched this exact proof (to drop the pickup FK) and did not fix this.

**Why it matters beyond the two lines:**

-   It means commits are being pushed, and the demo deployed (H5), with the gate red.
-   It means the author is not running `composer check` before pushing. `.cursor/rules/do-not-run-tests.mdc` (M7) is a plausible explanation.
-   A competitor's engineer who clones the repo and runs `composer check` sees a failure in the first ten minutes.

**Fix:** add the three missing rows to `prove_chart.sql` and make the proof compare a named expected set rather than a count. Add `keep_days` to the exclusion list in `prove_vendors_booths.sql`, with a comment saying it is a retention period in days. Make `deploy-demo.yml` depend on `check` passing (H5).

* * *

## HIGH

### H1 — The worker runs as the database superuser (OPEN)

`bin/work.php` connects with `STALL_DB_USER`, which is `stall`. A bug in any job, now six job kinds including a mailer that handles vendor data, runs with full DDL and superuser rights. **Fix:** a dedicated `stall_worker` role with exactly the grants it needs.

### H2 — A failed job is marked finished and never retried (PARTIAL)

`DigestJob` and `SaleNoticeJob` now catch their own send failures, which stops one bad address aborting a whole pass. That is progress. But `JobQueue::finish()` still stamps `finished_at` on any outcome, there is no attempt counter, no backoff and no dead-letter state, and a job that crashes outside the new try/catch is lost silently. The new catch blocks make a different problem (N6). **Fix:** add `attempts`, `last_error`, `next_run_at` and a `dead` state. Only set `finished_at` on success.

### H3 — `enqueue()` check-then-insert race (OPEN, surface grew)

Still `INSERT ... WHERE NOT EXISTS` with no unique index or `ON CONFLICT`. Two schedulers, or one scheduler overlapping a manual run, can double-enqueue or abort with a unique violation. With the new daily `sale-notice` kind, a double-enqueue now means every vendor gets two "Sold today" emails. **Fix:** a partial unique index on `(kind, schedule_key)` and `ON CONFLICT DO NOTHING`.

### H4 — Enabling late fees retroactively bills every historical unpaid period (OPEN)

`LateFeeDue` is unchanged. Turning the feature on assesses fees for all past periods that were unpaid, in one pass. **Fix:** an `effective_from` date.

### H5 — Demo deploys on every push without waiting for `check` (OPEN, now demonstrated)

`deploy-demo.yml` still triggers on push to `master` with no `needs`. N3 shows this is no longer hypothetical: `9ce884c` is deployable and red. **Fix:** a single workflow with `deploy` needing `check`, or `workflow_run` on success only.

### H7 — Migration checksum silently rewrites itself (OPEN)

`MigrationRecord::replace()` still runs at line 76. A tampered or edited migration updates its own recorded checksum, so verification can never fail twice. **Fix:** never overwrite. Refuse and alert.

### H8 — The audit trail is decorative (PARTIAL, with a regression: N4)

There are now about six call sites: company settings, NSF, and the `SaleCeiling` override paths (sale.void, rent.waive, payout.close, discount and tax-free ceilings). That is a better start. But `request_id` is still `bin2hex(random_bytes(8))` rather than a request-scoped id, so entries cannot be correlated. `AuditLog` silently returns on an empty field. And most money-moving paths still write no audit row at all (booth charge and deposit, mall sale creation, floor findings, pickups, store use, vendor edits, payout creation).

### H9 — `Permission` verb list used in two places (PARTIAL)

Two production call sites, both `settings.edit`. Authorization is still the path allowlist in `RoleGate`. Two permission systems that disagree is a future privilege bug.

### N4 — NSF (returned-check) audit actor is client-supplied, and the audit is outside the transaction (NEW, HIGH)

`src/Feature/Check/NsfController.php`:

-   The audit actor is taken from a **form field named `actor`**, and the role is hardcoded to `'owner'`. Any owner session can post any name. The audit row says whatever the form says. For the record of who bounced a customer's check and reversed revenue, that is forgeable.
-   The audit is written **after** the journal post, outside the ledger transaction. If the process dies between the two, the books change with no audit row. A ledger posting with no record of who did it is the exact scenario an audit trail exists for.
-   Good: the posting key `'check-return:' . $reference` is deterministic, so a double-submit is idempotent.

**Fix:** derive the actor from the authenticated session only. Write the audit row in the same transaction as the journal.

### N5 — The new features ship with essentially no tests (NEW, HIGH)

Between `67d6a9a` and HEAD, `src/` gained about 1,877 lines across 56 files. The unit suite stayed at **716 tests**. `git diff --stat` on `tests/` shows 9 insertions across 6 files, almost all of them `createStub(SaleCeiling::class)` constructor fixes so existing tests compile. I grepped `tests/` for each class: `NsfController`, `BoothExtraController`, `MallSaleController`, `SalesTaxSheet`, `SalesTaxFiling`, `ReportCsvController`, `SaleNoticeJob`, `MailBounce`, `FloorController`, `SavedReport` and `LedgerReport` have **zero** references. `SaleCeiling` has three, all stubs. The SQL proofs cover the database functions, and credit for that. But nothing exercises the controllers, the CSV output, the sales-tax filing arithmetic in PHP, the sale-notice email, the discount-ceiling enforcement, or the override flow. The sales-tax worksheet is the kind of feature where a rounding error is a filing error. The "every change proven" rule in `maintain-proven-level.mdc` is not being followed.

* * *

## MEDIUM

### M1 — Tests that cannot fail (OPEN, WORSENED)

`PhaseCallsTest::testTheNewMethodsAreNamed` still asserts that a hardcoded literal contains `'pass('`, `'assess('` and `'numbers('`. The literal is now over a hundred method names long, including the new ones (`salesTax(`, `second(`, `override(`, `mallSale(`, and so on). It gives the appearance of covering every new method. It calls nothing. `StoreUseTest` is unchanged and still only calls `method_exists`. These are the tests for the money paths in C5 and N5.

### M2 — SMTP header CRLF injection (OPEN)

`SmtpClient::payload()` (lines 124-126) still interpolates `From`, `To` and `Subject` raw, with no `Date:` or `Message-ID:`. Guards exist upstream in `OutboundMail` and `ReceiptMailer`, but `ResetLetter` passes `$account->email` directly. A library should defend itself. **Fix:** strip `[\r\n]` inside `SmtpClient`.

### M3 — Reset mail failures swallowed (OPEN)

`ResetLetter` still catches and logs one line. The user is told it was sent. If C1 is fixed by revocation, and the demo still uses that credential, password recovery on the demo silently breaks.

### M4 — Owner reset falls back to shared company inbox (OPEN)

`LoginAccounts::forReset()` unchanged.

### M5 — Gmail app password plaintext in DB and backups (OPEN)

`gmail_app_password text` with only a shape check. Every dump carries it. Combine with C1 for the full picture.

### M6 — CSV unescaped and formula-injectable (OPEN, spread)

Five new endpoints (`sales-tax.csv`, `sales.csv`, `rent.csv`, `payouts.csv`, `stored-value.csv`) in `ReportCsvController` write `implode(',', $cells)` where each cell has been through `View::text()`. That is HTML escaping. It is not CSV escaping, so a vendor name containing a comma breaks columns, a quote is mishandled, and a name starting with `=`, `+`, `-` or `@` becomes a formula in Excel. Vendor names are user-entered and the sale-notice job proves they reach other systems. The reports are the thing an owner emails to their accountant. **Fix:** `fputcsv` plus a leading-apostrophe guard, in one shared writer, with a test.

### M7 — `.cursor/rules/` shipped (OPEN)

Five rule files remain, including `do-not-run-tests.mdc` and `update-demo-on-push.mdc`. N3 and H5 are the visible results.

### M8 — `overdue()` inverted (OPEN)

Counts locked, running jobs as overdue and does not count failed-and-finished ones.

### M9 — `platform.jobs` grants lack INSERT for the web tier (OPEN)

Still SELECT-only for `stall_app` and `stall_poster`.

### M10 — Reconcile proves the view, not the report (PARTIAL)

`JobRunner::handle` now calls `$this->reconcile->tie($date)`, which invokes `stall_refuse_tax_drift` for the month and `stall_refuse_role_drift` for five roles. That is a real extension and it is computed against the ledger. The main books report is still reconciled against the same view it is built from.

### M11 — Late-fee journals dated "today" (OPEN)

Unchanged.

### N6 — `email_bounced` is a sticky flag set on any exception, with no way to clear it (NEW, MEDIUM)

`DigestJob` and `SaleNoticeJob` wrap delivery in `catch (\Throwable)` and call `MailBounce::mark($email)`, which sets `vendors.email_bounced = true`. The catch does not distinguish a hard bounce from an SMTP outage, a wrong Gmail app password (C1 revoked!), a timeout, or a programming error. One bad night of mail delivery marks every vendor as bounced. I grepped: `email_bounced` is only **set** in `MailBounce` and only **read** in `SaleNoticeJob`. Nothing clears it, not even changing the vendor's email address. After a transient outage, vendors stop getting sale notices permanently and nothing says why. **Fix:** only mark on a permanent SMTP failure code (5xx). Log and retry on everything else. Clear the flag whenever the email changes. Show the flag in the vendor UI.

### N7 — The second-person override has no throttling (NEW, MEDIUM)

`SaleCeiling::override()` takes a second person's username and password to approve a discount, void, waive or close. That is the right design, and the audit rows are written. But there is no attempt counter, lockout or delay. A cashier standing at the counter can try passwords against the owner's username at network speed. This is a password-guessing oracle with a privileged target. **Fix:** per-username and per-IP throttling shared with the login path, and a lockout that emails the owner.

### N8 — Mall sales: no audit, no overlap guard, silent winner (NEW, MEDIUM)

`MallSaleController` inserts into `mall_sales` (name, dates, `percent_bps`, `absorbed_by`) with no audit row. `TicketPricing::mallSale()` applies the percentage at pricing time and selects with `ORDER BY starts_on DESC LIMIT 1`. Two overlapping sales do not conflict. One silently wins. A mistyped 5000 bps (50%) instead of 500 bps is not bounded by a CHECK that I could find and is invisible in the audit trail. When `absorbed_by` is `mall`, the discount is booked to `store_discount`, so it directly reduces the mall's own margin. **Fix:** an exclusion constraint on the date range, a CHECK on a sane maximum, and an audit row on create, edit and delete.

### N9 — Booth deposit posts cash with no drawer or session link, and no audit (NEW, MEDIUM)

`BoothExtraController::deposit` debits `cash` and credits `booth_deposits` using the key `$key . $reference`. The key is deterministic, which is good. But the cash is not tied to a register session, so a drawer count cannot reconcile against it, and no audit row is written. Refunding a deposit, which is where abuse happens, has no visible path in what I reviewed. **Fix:** link to the open register session and audit every deposit and every release.

* * *

## LOW

All six earlier lows (L1 through L6) remain open and are listed in the ledger above. New ones:

-   **N10** `SaleNoticeJob` emails vendors `code net_minor` in minor units. A vendor sees `1250`, not `$12.50`. That is an easy bug to ship because no test reads the email body (N5).
-   **N11** `RoleGate::books()` now allows any GET path beginning with `/reports`. The prefix match is broad. A future route added under `/reports/...` that has a side effect on GET becomes accessible to the read-only books role with no code change.
-   **N12** `vendors.keep_days` and `expiry_action` have no CHECK constraint that I found, so zero, negative or absurd retention values pass.

* * *

## What is genuinely good (unchanged, and a few additions)

-   The ledger kernel is real: append-only double entry, unique posting keys, reversal-only, `ENABLE ALWAYS` triggers, an insert-only `stall_poster` role. Nothing in this round weakens it.
-   **New:** `stall_refuse_tax_drift` and `stall_refuse_role_drift` put drift detection in the database, which is where it belongs. They are called by the reconcile job.
-   **New:** 009 and 010 are proper forward-only migrations, correctly registered in `SchemaCatalogTest`. This is the pattern C2 needs everywhere.
-   **New:** `SaleCeiling` is a good design (per-staff ceilings, second-person override, audit rows). N7 is a gap in it, not a flaw in the idea.
-   Check returns and booth extras use deterministic posting keys, so a double-submit cannot double-post.
-   PHPStan at level 10, PHPMD, a 500-line file cap and a freeze check all pass. The tooling culture is better than most projects of this size. The problem is that the author does not run it before pushing.
-   54 of 56 proofs pass, race proofs pass, tenant isolation proofs pass.

* * *

## The three things a competitor will say

1.  **"Run `composer check`."** It fails on a fresh clone, because two proofs are broken and one was edited to hide it (N3). That ends the "proven" pitch.
2.  **"Search the git history for `gmail`."** A live credential, in 22 commits, copied a second time since last round, and re-planted into the demo database on every reset (C1).
3.  **"Upgrade a customer."** You cannot. The only path is "create a new database", and the deploy script wipes on mismatch (C2, C3).

* * *

## Suggested order of work

**Today (hours):**

1.  Revoke the Gmail app password. Then remove both copies (`dev_tenant.sql`, `MallDemo.php:166`) and purge history (C1).
2.  Fix the two red proofs: add the three roles to `prove_chart.sql` as real rows, and exempt `keep_days` in `prove_vendors_booths.sql` (N3).
3.  Make `deploy-demo.yml` need `check` (H5).
4.  Delete `.cursor/rules/do-not-run-tests.mdc` and `update-demo-on-push.mdc` (M7).

**This week:** 5. Idempotency key for store use, with a real double-post test (C5). 6. Take the reset button out of production and remove the `'mall-demo'` fallback (C3, L5). 7. Stop `wipe-tenant.php` dropping FKs (C4). Stop the deploy script wiping on a checksum change (C2). 8. Replace the fake tests (M1) and write controller and job tests for every class listed in N5. Start with `SalesTaxSheet`, `NsfController`, `SaleCeiling` and `ReportCsvController`. 9. NSF actor from session and audit in-transaction (N4). 10. `fputcsv` and a formula guard in one shared CSV writer (M6).

**Next:** 11. Job reliability: `attempts`, dead-letter, unique enqueue key, `stall_worker` role, correct `overdue()`, bounce handling that only trusts permanent failures (H1, H2, H3, M8, M9, N6). 12. Override throttling (N7), mall-sale constraints and audit (N8), booth-deposit register linkage (N9). 13. Forward-only migrations for everything, with an upgrade test from the last release (C2, H7). 14. Re-run coverage and, when you allow it, mutation, to refresh N1 and N2.

**Process rule that would have prevented most of this round:** no push unless `composer check` is green locally, enforced by a pre-push hook and by CI blocking the deploy.

* * *

## Method and limits

-   Pulled `origin/master` at `9ce884c` and re-provisioned from scratch (new database, `provision-platform`, `enable-stall-app`).
-   Ran: check:freeze, check:max-lines, PHPMD, PHPStan L10, unit, integration, HTTP, prove:obligations, prove:race, prove, prove:tenant. Mutation was not run, per your instruction. Coverage was not re-run.
-   The root causes of both failing proofs were confirmed by reading the proof files and querying `information_schema` on a freshly provisioned tenant.
-   Carried findings were re-verified by reading the current code (`grep`, `diff` against `67d6a9a`). I did not re-execute the earlier empirical probes (C5 double post, C4 FK drop, H2 failed job, H3 enqueue race, M9 grant). The code in those paths is unchanged, so the earlier reproductions stand, but treat them as "confirmed by code, not re-run".
-   N7 to N9 and N10 to N12 are from reading the new code. I did not exercise them against a running server. The absence of a throttle, constraint or audit row is based on grep and reading the controller and migration, so confirm before you quote them externally.