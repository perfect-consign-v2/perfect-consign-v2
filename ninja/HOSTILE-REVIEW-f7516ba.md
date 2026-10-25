# Perfect Consign v2 — Hostile Review, Round 5 (HEAD `f7516ba`)

Supersedes `HOSTILE-REVIEW-bdbdb6a.md`. Every earlier finding is carried forward with a status. New findings continue the numbering at N25.

**Scope:** 3 commits since `bdbdb6a` (`531896f`, `34267bc`, `f7516ba`; 29 files, +1,079/−44). The server was re-provisioned from scratch: the `consign` database was dropped and recreated, stale `stall_app` / `stall_backup` / `stall_worker` / `stall_poster` / `poster_*` roles were dropped, then `provision-platform`, `enable-stall-app`, `provision-tenant mall "Mall Demo"` and `migrate platform` were run. PHP 8.5.12, PostgreSQL 18.6 on port 5436, Composer 2.10.3, xdebug.

**Not run, by standing instruction:** mutation testing (N2 is carried as not re-tested).

* * *

## Verdict

**The mail path is now genuinely fixed, and I proved it by sending mail. But this round added a new platform table that repeats the exact mistake I flagged in N18, and while chasing that I found that the discount-override path cannot work at all under the role the application actually uses.**

The good news is real and measurable. N19 was the worst thing in the last review — a retry that re-mailed everyone, and one bad address that starved the rest of the list. That is gone. I ran a real worker pass against a real TLS SMTP server with one deliberately bad recipient: 14 letters went out, 14 per-recipient rows were written, the bad address was refused, the job stayed open, and the second pass re-attempted **only** the bad address. The two good addresses were not mailed twice. That is the correct behaviour and it is the first time in five rounds that I have been able to demonstrate it rather than infer it from code.

The bad news is that the mechanism used to achieve it, `platform.job_mail_sent`, is a cross-tenant table with a `tenant` column, table-wide grants, and no row-level security anywhere in the schema. I confirmed by execution that a mall's tenant role can read every other tenant's vendor email addresses and which digest each received on which date, and — worse — can **insert** a "sent" row for another tenant, which permanently suppresses that letter for that recipient on that date. There is no `DELETE` grant, so the victim cannot even clean it up without a superuser.

One thing got better outside the code: C1 is closed. The owner has confirmed the Gmail app password is revoked. That is the right fix — revocation removes the risk, and a history rewrite would only have been cosmetic. I have dropped it from the finding list and replaced it with a low about the absence of secret scanning.

Then, checking whether the same grant pattern affected the override lockout, I found something I should have caught in Round 4: `SaleCeiling` resolves the tenant with `SELECT id FROM platform.tenants WHERE slug = ?`, and `stall_poster` has no privilege on `platform.tenants`. Mall requests run as `stall_poster`. So every call to `refuseLocked()` raises `permission denied for table tenants`. The lockout has never worked in production, and any discount or payout that needs an override returns a 500 instead of a refusal. The suite is green because nothing reaches that code: `SaleCeiling` sits at 25.3% and the only tests that touch it use a stubbed `Statements`.

**What is still wrong, in order of importance:**

1.  **N25 (new, High): `platform.job_mail_sent` is readable and writable across tenants.** Confirmed: 15 rows visible from the mall's poster role, including `other-tenant / secret-vendor@example.test`. Inserting a row for `other-tenant` succeeded and will suppress that letter forever.
2.  **N27 (new, High): the override ceiling path throws `permission denied` under the tenant role.** Confirmed at the statement level. This is a live 500 on the till and a lockout that records nothing.
3.  **N26 (new, High): `platform.override_sign_in_failures` can be deleted across tenants.** Confirmed: `DELETE 2` across two different tenants from one tenant role. Blanket deletion defeats the lockout for everyone.
4.  **N21 (PARTIAL): the code changed this round is still mostly not executed by a test.** CI now runs one `php bin/work.php` pass, which is the right instinct, but `JobRunner` is 43.4%, `JobSchedule` 31.0%, `JobQueue` 27.5%, `SaleCeiling` 25.3%, `ReceiptMailSettings` 0%, `FloorController` 0%, and the new `job_mail_sent` table has no integration test at all.

**What is now closed:** C1. The owner has confirmed the Gmail app password is revoked and no longer works, which is the resolution that actually removes the risk — history rewriting is cosmetic. A dead credential in 42 commits is a dead credential. What is left is hygiene, not exposure: there is no secret scanning in CI, so the _next_ credential committed will not be caught. That is L7, and it is a low.

### Gate results at `f7516ba`

| Gate | Result |
| --- | --- |
| check:freeze | OK |
| check:max-lines (500) | OK |
| PHPMD | clean |
| PHPStan level 10 | OK |
| Unit | **756 tests, 3,083 assertions, OK** (was 738 / 3,023) |
| Integration | **28 tests, 238 assertions, OK** |
| HTTP | **76 tests, 1,182 assertions, OK** |
| prove:obligations / prove:tenant / prove:race | all exit 0 |
| **prove** | **exit 0 — 57 of 57 proofs pass** |
| Coverage (unit + integration + http) | 860 tests, 4,562 assertions, **91.13% lines** (19,784 of 21,709). Floor is 90%, margin 1.13 |
| Mutation | not run (instruction) |
| `bin/work.php` against the fresh database | **passes**, 12 letters sent, 12 rows, second pass sent 0 |
| Failure-path worker probe (new this round) | **passes**, see below |

### What I executed, not read

-   Provisioned a second tenant and ran `bin/work.php` with `STALL_MAIL_FILE` set: 12 letters, 12 `job_mail_sent` rows, exactly one per recipient; a second pass sent 0 more.
-   Built a TLS-capable fake SMTP server (`aiosmtpd`, self-signed cert added to the system trust store, because `SmtpClient` requires `STARTTLS` and verifies the peer — a plaintext fake server cannot be used at all, which is itself a good sign). Seeded three vendors: `good1`, `good2`, and `bad`, where the server answers `421 4.7.0 try later` to any `RCPT TO` containing `bad`.
-   Ran the worker twice against it. **Pass 1:** 14 accepted, `bad` rejected once, 14 progress rows, job open at `attempts=1` with `a letter is still waiting to be sent`. **Pass 2** (after forcing `run_at` forward, because backoff correctly kept it from being due): `bad` rejected a second time, `good1` and `good2` still at exactly one acceptance each, progress rows still 14, `attempts=2`, job still open. No starvation, no duplicate mail.
-   As `stall_poster`: read `platform.job_mail_sent` across tenants (15 rows, other tenant's address visible); inserted a row for `other-tenant` (succeeded); `DELETE FROM platform.override_sign_in_failures WHERE username = 'victim'` removed **2 rows belonging to 2 different tenants**.
-   As `stall_poster`: `SELECT id FROM platform.tenants` → `permission denied for table tenants`. The same subquery is inside `SaleCeiling::refuseLocked()`, `recordFailure()` and `clearFailures()`.
-   Privilege matrix for `stall_poster`: `jobs` SELECT only; `job_mail_sent` SELECT+INSERT; `override_sign_in_failures` SELECT+INSERT+DELETE; `password_resets`, `password_reset_requests`, `owners`, `tenants`, `owner_backups`, `schema_migrations` all false for every privilege.
-   `grep -rn "ROW LEVEL SECURITY\|CREATE POLICY" sql/ src/` → **no matches**.

* * *

## Status ledger

`RES` = resolved. `PART` = partially addressed. `OPEN` = unchanged. `WD` = withdrawn. `NR` = not re-tested.

Round 4's ledger had 49 rows. This one has 62: the 49 carried forward, N19–N24 from Round 4's prose, and seven new rows (N25–N30, L7). Tally: **40 resolved, 8 partial, 4 open, 2 withdrawn, 1 not re-tested, 7 new.**

Two reclassifications, stated openly. Round 4 marked C3, C4 and C5 "RES" while its own evidence said "unchanged". They were not resolved; they are open, and I have marked them so. C1 moves the other way: it is closed on the owner's confirmation that the credential was revoked, which is the resolution that actually matters.

| ID | Title | Status | Evidence at `f7516ba` |
| --- | --- | --- | --- |
| C1 | Live Gmail app password committed | **RES (revoked)** | The owner confirms the password is revoked and no longer valid. That is the resolution that matters: a dead credential in 42 of 197 commits is a dead credential, and no history rewrite was required. HEAD was already clean. Residual is hygiene only — no secret scanning (L7) — not exposure. |
| C2 | No upgrade path / deploy wipes | **PART** | Forward-only 002–018, checksum stamp, `deploy-demo.sh` stops on migration failure. "create a new database" still appears in `TenantMigrator`, `MigrationRecord` and `SchemaMigrator`; README still says an existing database is not upgraded. |
| C3 | One-click wipe on production demo | **OPEN** | Unchanged. |
| C4 | `wipe-tenant.php` drops platform FKs | **OPEN** | Unchanged. |
| C5 | Store use has no idempotency key | **OPEN** | Unchanged. |
| H1 | Worker runs as DB superuser | **RES** | `stall_worker` is `rolsuper=f`, `rolbypassrls=f`, `NOINHERIT`. It reaches tenant data only through `SET ROLE <poster>`. Re-verified on the fresh cluster. Residual is N24. |
| H2 | Failed job finished, never retried | **RES** | A job with a bad recipient stays open at `attempts=1`, error recorded, and retries with backoff. Dead-letter at 5 attempts unchanged. No alert (N23). |
| H3 | `enqueue()` check-then-insert race | **RES** | `?::text` cast; two passes produced 15 rows, not 30. |
| H4 | Late fees bill all history | **RES** | Unchanged. |
| H5 | Demo deploys without waiting for `check` | **RES** | Unchanged. |
| H6 | (withdrawn earlier) | WD | — |
| H7 | Migration checksum rewrites itself | **RES** | Unchanged. |
| H8 | Audit trail is decorative | **PART** | 33 audit call sites. Still missing on period close, adjustments, manual journals, opening, backup, mall settings, password change/reset, dev reset, offers, inventory and POS controllers. `request_id` is still `bin2hex(random_bytes(8))` from the constructor. |
| H9 | `Permission` used in one place | **PART** | Three call sites. Role matrix still mostly path lists. |
| M1 | Tests that cannot fail | **RES** | `PhaseCallsTest` gone; `JobQueueTest` is a real integration test. `JobDispatchTest` is still stub-heavy. |
| M2 | SMTP header CRLF injection | **RES** | `payload()` adds `Date:` and a random `Message-ID:`. |
| M3 | Reset mail failure swallowed | **RES** | Now caught deliberately in `ResetLetter::sendReset()` and logged (N20). |
| M4 | Owner reset falls back to company inbox | **RES** | Unchanged. |
| M5 | Gmail app password plaintext in DB and backups | **RES** | `SealedSecret` secretbox with `v1:` prefix. `018` now makes plaintext an invalid stored value. Caveat: N28. |
| M6 | CSV unescaped / formula injection | **RES** | Unchanged. |
| M7 | `.cursor/rules/` shipped | **PART** | Dangling references gone; two rules still say the full check waits until the user asks; directory still in the product repo. |
| M8 | `overdue()` inverted | **RES** | Unchanged. |
| M9 | Web tier cannot enqueue | **RES** | Unchanged. |
| M10 | Reconcile proves the view, not the report | **RES (read only)** | `ReconcileJob` compares `BooksReport::displayedTotal()` against `lineTotal()`. I read the diff, not `rows()`. |
| M11 | Late-fee journals dated "today" | **RES** | Unchanged. |
| L1 | `sendJson()` duplicated | **RES** | Unchanged. |
| L2 | No `Cache-Control: no-store` on JSON | **RES** | Unchanged. |
| L3 | `StoreUse::itemId()` null on empty code | **RES** | Unchanged. |
| L4 | Real-looking identities in `dev_tenant.sql` | **RES** | Vendor email `ada@example.test`, phone `555-0100`. |
| L5 | `'mall-demo'` fallback password | **RES** | Unchanged. |
| L6 | README is Windows-oriented | **RES** | No `laragon` string remains. |
| **L7** | **No secret scanning in `composer check`** | **NEW — LOW** | `composer.json` and `.github/workflows/check.yml` have no `gitleaks`/`trufflehog` step. C1 is closed because the credential was revoked, not because anything would have stopped it being committed. |
| N1 | Files at 0% coverage | **PART** | 91.13% (19,784/21,709), up from 90.98%. Still **20 files at 0.0%**: `ReportsController`, `MissedSale`, `NsfController`, `VendorMemo`, `FloorController`, `MissedSaleController`, `VendorMemoController`, `ReceiptMailSettings`, `MallSaleController`, `StoreUseController`, `LedgerReport`, `ReportCsvController`, `SavedReport`, `vendor-memo.php`, `ReceiptMailer`, `missed-sale.php`, `sales-tax.php`, `table.php`, `store-use.php`, `reports.php`. Jobs are still thin: `JobRunner` 43.4%, `JobSchedule` 31.0%, `JobQueue` 27.5%. |
| N2 | Parallel mutation shards collide | **NR** | Mutation not run. |
| N3 | `prove` red: two proofs failing | **RES** | 57 of 57. |
| N4 | NSF actor client-supplied | **RES** | Unchanged. |
| N5 | New features ship with no tests | **PART** | +18 unit tests this round (`MailKeyFile`, `MailProgress`, `MailboxSeal`, password request/boundary, four dispatch cases, migration boundary, schema catalog). Still no integration test for `job_mail_sent` and no test that reaches the controllers. |
| N6 | `email_bounced` sticky, set on any exception | **RES** | Stage-classified `MailDeliveryFailed`; permanent only for 5xx at `RCPT TO`. `SmtpStageTest` runs a real fake server. |
| N7 | Override has no throttling | **RES (nominal)** | A lockout exists in code, but see N27: it cannot run under the tenant role. |
| N8 | Mall sales: no audit, overlap, or cap | **RES** | Unchanged. |
| N9 | Booth deposit: no drawer link, no audit | **RES** | Register session, in-transaction audit, booth validated. |
| N10 | Sale notice shows minor units | **RES** | Unchanged. |
| N11 | `/reports*` prefix match on books role | **RES** | Unchanged. |
| N12 | `keep_days` has no CHECK | **WD, my error** | Withdrawn in Round 3. |
| N13 | Job worker cannot run | **RES** | `jsonb_build_object('date', ?::text)`; `bin/work.php` exits 0 and finishes every claimed job. |
| N14 | CSV guard corrupts negative numbers | **RES** | `-500`, `-5.25` preserved; formulas neutralised. |
| N15 | Booth deposits drop the booth, release unbounded | **RES** | `2151 booth_deposit_held`, `SECURITY DEFINER` trigger, `ENABLE ALWAYS`, negative balance refused. Race caveat carried. |
| N16 | Deploy script's fallback path | **RES (read only)** | Exits 1 on any tenant migration failure. |
| N17 | Reset-letter failure message leaks username existence | **RES** | Always redirects with the same notice. |
| N18 | Override lockout shares the owner table | **RES** | `platform.override_sign_in_failures`, keyed by tenant and username. But the table is granted table-wide with no RLS — that is now N26, and the code that uses it is N27. |
| N19 | Mail retries resend to everyone, one bad recipient blocks the rest | **RES** | **Proved by execution.** `017_job_mail_sent` + `MailProgress` give one row per letter. 14 sent, `bad` refused, job open, second pass re-attempted only `bad`, `good1`/`good2` accepted exactly once each. `JobRunner::pass()` has no transaction wrapper, so per-recipient rows commit before the final throw — the fix genuinely holds. Residual: dead-letter after 5 attempts still raises no alert (N23), and the table it relies on is N25. |
| N20 | Sealed-secret rollout: no data migration, and a 500 that tells attackers who exists | **RES** | `MailboxSeal::sealPlain()` runs as a pre-step to `018` and seals every plaintext row; `018` then refuses plaintext (`23514` confirmed live on the tenant schema). `ResetLetter::sendReset()` catches the mailbox read failure, logs `the reset mailbox could not be read`, and returns `true`, so `ForgotController` always redirects with `ResetNotice::SENT` — the oracle is closed. `MailKeyFile` generates a key into `.env` (idempotent, replace and append both tested); `deploy-demo.sh` calls it before migrating; `public/index.php` now loads `.env`, so the web tier sees `STALL_MAIL_KEY`. Caveat: N28. |
| N21 | The code changed this round is mostly never executed by a test | **PART** | CI now runs one `php bin/work.php` pass — the right instinct. New unit tests cover `MailProgress`, `MailboxSeal`, `MailKeyFile`, dispatch refusal and the password boundary. But `JobRunner` 43.4%, `JobSchedule` 31.0%, `JobQueue` 27.5%, `SaleCeiling` 25.3%, `BoothExtraController` 7.2%, `ReceiptMailSettings` 0%, `FloorController` 0%. `MailProgressTest` stubs `Statements`, so no test ever inserts into the real table. |
| N22 | Override lockout is per username, not per address | **PART** | The retention delete was added at the top of `refuseLocked()`. The per-address half was not done. And the retention delete is one of the statements that cannot run under the tenant role (N27). |
| N23 | Digests use the server mailbox, receipts use the mall's Gmail | **OPEN** | Unchanged. Dead-lettered jobs still raise no alert. |
| N24 | Worker blast radius | **RES (accepted)** | `stall_worker` is a member of every tenant's poster role by design. Worth a line in the threat model; not a defect. |
| **N25** | **`platform.job_mail_sent` is readable and writable across tenants** | **NEW — HIGH** | Confirmed by execution. See below. |
| **N26** | **`platform.override_sign_in_failures` can be deleted across tenants** | **NEW — HIGH** | Confirmed by execution: `DELETE 2` across two tenants. |
| **N27** | **The override ceiling path throws `permission denied` under the tenant role** | **NEW — HIGH** | Confirmed by execution. See below. |
| **N28** | **`MailboxSeal` leaves malformed values that then abort the migration** | **NEW — LOW** | See below. |
| **N29** | **`job_mail_sent` has no integration test and no retention** | **NEW — LOW** | See below. |
| **N30** | **A changed vendor email gets a second letter on the same date** | **NEW — LOW** | See below. |

* * *

## HIGH

### N25 — `platform.job_mail_sent` is a cross-tenant table with table-wide grants and no RLS

`017_job_mail_sent.sql` creates a single platform table with a `tenant` text column, a primary key of `(tenant, kind, mail_date, email)`, and then:

```sql
GRANT SELECT, INSERT ON TABLE platform.job_mail_sent TO stall_app;
GRANT SELECT, INSERT ON TABLE platform.job_mail_sent TO stall_worker;
GRANT SELECT, INSERT ON TABLE platform.job_mail_sent TO stall_poster;
-- and, in a DO loop, to every poster_<hex>
```

There is no `WHERE tenant = current_setting(...)`, no view, no policy. `grep` for `ROW LEVEL SECURITY` or `CREATE POLICY` across `sql/` and `src/` returns nothing. The `tenant` column is a label, not a boundary.

**Read.** As `stall_poster`:

```
SELECT tenant, email, mail_date FROM platform.job_mail_sent WHERE tenant='other-tenant';
    tenant    |           email            | mail_date
--------------+----------------------------+------------
 other-tenant | secret-vendor@example.test | 2026-01-05
SELECT count(*) FROM platform.job_mail_sent;   -- 15
```

Every tenant's vendor email addresses, which digest or sale notice each received, and on what date. For a consignment shop that is a supplier list — the most commercially sensitive data the product holds, and the thing a departing manager would most like to take.

**Write.** Also as `stall_poster`:

```
INSERT INTO platform.job_mail_sent (tenant, kind, mail_date, email)
VALUES ('other-tenant','vendor-digest','2026-03-01','suppressed@example.test');
INSERT 0 1
```

`DigestJob` asks `MailProgress::sent()` first and skips anything already there. So this insert **permanently suppresses** that letter to that recipient on that date. There is no `DELETE` grant, so the victim tenant cannot clean it up; only a superuser can. A tenant can quietly stop another tenant's vendors from being told they are owed money.

**Why this happened.** It is the same shape as N18: a tenant-scoped fact stored in a platform table with a `tenant` column, granted table-wide because the application switches roles with `SET ROLE` rather than connecting as a per-tenant login. N18 got a table; this got a table; the next feature will get a table.

**Fix.** Pick one and do it everywhere, not just here:

1.  **Preferred:** put the table in the tenant schema (`tenant_x.job_mail_sent`, no `tenant` column). Schema isolation already works — the privilege matrix shows `stall_poster` cannot touch `platform.owners`, `platform.tenants` or `platform.password_resets`. The worker already does `SET ROLE` and `SET search_path`, so it can write there. The only thing the platform needs is the job row, which it already has.
2.  If it must stay in `platform`: `ALTER TABLE ... ENABLE ROW LEVEL SECURITY` plus `FORCE`, a policy of `tenant = current_setting('stall.tenant', true)`, set that GUC in `JobRunner::handle()` and in `MallApp::enterBooks()`, and grant nothing to `PUBLIC`. Then add a test that fails if a poster role can see another tenant's row — the test I just ran by hand should be in `tests/Integration`.

Do the same audit for `platform.override_sign_in_failures` (N26) and `platform.jobs` while you are there.

### N26 — `platform.override_sign_in_failures` can be deleted across tenants

`015_override_failures.sql` grants `SELECT, INSERT, DELETE` to `stall_poster` and every `poster_*`, again with no predicate. Confirmed:

```
-- seed one row for 'victim' in tenant mall and one in another tenant
SELECT count(*) FROM platform.override_sign_in_failures;  -- 2
SET ROLE stall_poster;
DELETE FROM platform.override_sign_in_failures WHERE username = 'victim';
DELETE 2
```

Two rows, two different tenants, one statement, from a single tenant's role. `DELETE FROM platform.override_sign_in_failures` with no predicate clears every lockout in the system.

The practical consequence is that the lockout is advisory. A cashier who wants to keep guessing an override password can clear their own failures between attempts, or clear everyone's. The `INSERT` side is also open: the foreign key to `platform.tenants` is checked as the table owner, so a poster role can insert a row with any `tenant_id` it can name, without `REFERENCES` privilege. It cannot _enumerate_ tenant ids — `SELECT` on `platform.tenants` is denied — but the schema name is `tenant_<uuid-hex>`, and a tenant that has ever seen another tenant's schema name (a shared support screen, a log line, a backup listing) has the id.

**Fix.** Same as N25. Additionally: a lockout counter should not be deletable by the role it is meant to constrain. Move the read and the write behind a `SECURITY DEFINER` function that takes the slug from the session, or keep the table in the tenant schema and grant the poster role `INSERT` only — never `DELETE` — with expiry handled by a worker.

### N27 — the override ceiling path cannot run under the tenant role

This is the one that should worry you most, because it is not a security trade-off, it is a broken feature that the green suite cannot see.

`SaleCeiling::refuseLocked()`:

```php
$this->statements->change(
    "DELETE FROM platform.override_sign_in_failures
     WHERE failed_at <= clock_timestamp() - interval '15 minutes'", []);
$rows = $this->statements->bound(
    "SELECT count(*) AS attempts
     FROM platform.override_sign_in_failures
     WHERE tenant_id = (SELECT id FROM platform.tenants WHERE slug = ?)
       AND username = ? ...", [$slug, $username]);
```

`recordFailure()` and `clearFailures()` contain the same subquery. Mall requests run under `stall_poster` (`MallApp::enterBooks()` does `SET ROLE $tenant->role`), and `stall_poster` has **no privilege on `platform.tenants`** — only `stall_app`, `stall_backup` and `stall_worker` do. Confirmed:

```
SET ROLE stall_poster;
SELECT count(*) FROM platform.override_sign_in_failures
 WHERE tenant_id = (SELECT id FROM platform.tenants WHERE slug='mall') ...;
ERROR:  permission denied for table tenants
```

`SET ROLE` drops superuser status, so this fails in the dev environment too, not just in production.

The consequences:

-   `refuseLocked()` is the **first** thing `override()` does, before it verifies the password. So _every_ discount over the cashier's ceiling, every tax-free sale, and every payout over ceiling throws a `PDOException` — a 500, not "a second person has to sign this". A correct override fails the same way as a wrong one.
-   `recordFailure()` never records, so the lockout N7/N18/N22 describe has never counted a single failure in a real request.
-   `clearFailures()` never clears, so even if the reads worked, a successful override would leave the failures behind.

**Why no test caught it.** `SaleCeiling` is at 25.3%, and the tests that reach it construct `Statements` against a superuser connection or a stub. The HTTP suite is green because no HTTP test applies a discount above a ceiling. This is exactly what N21 is about: a gate that runs the code is not the same as a gate that exercises the branch.

**Fix.** Do not resolve the tenant with a subquery on `platform.tenants` from a tenant role. The slug is already in the session; resolve the `tenant_id` once, at sign-in, in `MallApp`, while the connection still has the privilege to read it, and pass it down (or store it in a GUC: `SET stall.tenant_id = ...`). Then add an HTTP test that posts a discount above the ceiling as a cashier and asserts a 4xx refusal, and one that does it five times and asserts the lockout message. That test would have caught this in one run.

* * *

## MEDIUM / LOW

### N28 — `MailboxSeal` leaves malformed values that then abort the migration (LOW)

`sealPlain()` selects rows `WHERE gmail_app_password IS NOT NULL AND left(gmail_app_password, 3) <> 'v1:'`. A value of exactly `v1:` (three characters), or `v1:short`, or `v1:not base64!!` is skipped by the seal and then rejected by the new `018` CHECK, which requires `^v1:[A-Za-z0-9+/]+=*$` and a length of 20–200. The migration then aborts, and that tenant cannot be migrated at all until someone edits the row by hand. Fail-closed is the right direction — a plaintext password must not survive — but "this tenant will not upgrade" is a bad failure mode for a demo-day deploy.

Two smaller points on the same code. `sealPlain()` runs inside the migration transaction, before `018` swaps the constraint, and calls `SealedSecret::seal()`, which needs `STALL_MAIL_KEY`; if the key is missing the migration aborts (acceptable, and `deploy-demo.sh` now generates one first). And `sealPlain()` seals `mall` in the _current_ `search_path` only, which is correct for `migrate.php tenant <slug>` but was not exercised for `tenant-all` or for two tenants in one run.

**Fix.** Widen the selector to "not a valid sealed value" rather than "does not start with `v1:`" — i.e. seal anything that fails the new CHECK's regex or length — and add a migration test with a 3-character value and a `v1:`\-prefixed-but-short value.

### N29 — `job_mail_sent` has no integration test and no retention (LOW)

`MailProgressTest` stubs `Statements`, so nothing in the suite ever inserts into the real table, and nothing asserts the primary key or the `kind` CHECK. The table also grows without bound: one row per letter, forever, with no `keep_days` and no cleanup job. At a 200-vendor mall sending a weekly digest that is 10,400 rows a year — trivial in size, but it is another table with no retention policy, and the product already has one of those (N12, withdrawn, but the pattern repeats).

**Fix.** One integration test: enqueue, run a pass, assert one row per recipient; run again, assert the count did not change. Add `DELETE ... WHERE mail_date < current_date - interval '400 days'` to the digest job, or a `keep_days` setting like the audit log has.

### N30 — a changed vendor email gets a second letter on the same date (LOW)

The primary key is `(tenant, kind, mail_date, email)`. If a vendor's address is corrected at noon and the digest job retries, the corrected address is a new key and gets the letter — which is correct and desirable. But the _old_ address also keeps its row, so if the address is changed back the same day, that recipient is skipped. Minor, and arguably right; worth a comment in the migration so the next reader does not "fix" it.

### Carried, unchanged, and still worth doing

-   **C1 (closed):** the password is revoked, so the history copy is inert. Do not spend a day on `git filter-repo`. Do add secret scanning (L7) so the next one is caught before it lands, and remove the address from `ninja/` when convenient — that is tidiness, not risk.
-   **C2/C3/C4:** the deploy path still says "create a new database", the demo still has a one-click wipe, and `wipe-tenant.php` still drops platform FKs.
-   **H8:** 33 audit call sites, but period close, adjustments, manual journals and the POS controllers still do not audit. `request_id` is still generated in a constructor, so it identifies a request object, not a request.
-   **N23:** a dead-lettered job raises no alert. With N19 fixed, the only way a digest silently stops is now a dead letter, which makes the missing alert more important, not less.

* * *

## What is genuinely good

The mail path is now the best-engineered thing in the codebase, and it was the worst two rounds ago. `MailDeliveryFailed` carries a stage, so a 535 at `AUTH` is not a vendor bounce and a 421 at `RCPT` is not one either. `MailProgress` records per recipient, and because `JobRunner::pass()` does not wrap `handle()` in a transaction, those rows commit before the job is rescheduled — which is precisely why the retry does not re-send. I tried to break it with a real TLS server and a real bad recipient and could not: the good vendors got one letter each, the bad one was retried alone, and the job stayed open with a truthful error. That is a genuine fix, not a code-shaped one.

`SmtpClient` requiring `STARTTLS` and verifying the peer is also right, and it is why my first attempt at a fake server could not work at all. A test harness that cannot accidentally talk plaintext is a good harness.

The sealed-secret rollout is now complete rather than half-done: a pre-migration seal, a constraint that makes plaintext an invalid stored value, a key file that generates itself on deploy, and a reset path that no longer turns a mailbox read failure into a 500 that only fires for real usernames. The `ResetLetter` catch is small and correct.

And the discipline is holding: 57 of 57 proofs, PHPStan level 10 clean, 91.13% coverage, 860 tests. The author is not just closing findings, they are closing them with tests — which is why the two things I found this round are things the tests do not reach, not things the tests got wrong.

## The three things a competitor will say now

1.  **"Your mail log is a cross-tenant table."** Any multi-tenant product that stores tenant data in a shared table with a label column gets asked this in a security review. Right now the answer is "yes, and there is no RLS", and one tenant can suppress another tenant's letters. Fix N25 before a customer's IT person asks.
2.  **"Your discount override is broken."** A cashier who needs a manager to approve a 40% markdown gets a 500. That is a till-stopping bug on the busiest day of the year, and it is invisible until someone actually needs an override.
3.  **"Your override lockout is advisory."** A cashier can clear their own failures, or everyone's, with one statement. The control that is supposed to stop a shared till password from being brute-forced is not a control. That is N26, and it is the one an auditor will find.

## Suggested order of work

1.  **N27** — resolve `tenant_id` at sign-in, pass it down, add the two HTTP tests. One day, and it turns a 500 into a working feature.
2.  **N25 + N26** — move tenant-scoped facts into tenant schemas, or add RLS and a GUC. Add the cross-tenant test as an integration test so it cannot regress. Two days.
3.  **L7** — add `gitleaks` (or equivalent) to `composer check`. C1 is closed because the credential was revoked, not because the process would have caught it.
4.  **N21** — raise `SaleCeiling`, `JobRunner`, `JobSchedule`, `JobQueue` and `ReceiptMailSettings` off their current numbers with tests that execute the branch, not the file.
5.  **N28/N29** — the seal selector and the integration test for `job_mail_sent`.
6.  **N23** — an alert on dead-lettered jobs. A cron that counts `finished_at IS NULL AND attempts >= 5` and pages is enough.
7.  **H8, H9, M7, C2** — the long tail.

## Method and limits

I re-provisioned the database and roles from scratch, ran all eleven gates, and executed the worker against both a file transport and a real TLS SMTP server. I read `SaleCeiling`, `JobRunner`, `MailProgress`, `MailboxSeal`, `MailKeyFile`, `ResetLetter`, `017` and `018` in full. I did not read `BooksReport::rows()` (M10 remains read-only), I did not run mutation testing (instruction), and I did not exercise `tenant-all` migration ordering for `MailboxSeal` (N28). The N27 finding is confirmed at the statement level under the exact role the application uses; I did not drive it through a browser session, because the statement is the whole failure and no HTTP test reaches it — which is itself the finding.

My earlier probes are in `tmp/` (`probe1.php`, `probe2.php`, `probe3.php`, `probe_worker.sh`, `probe_fail2.sh`, `tlsmail.py`) if you want to re-run them.