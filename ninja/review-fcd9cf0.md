# Hostile Review — `18bc68c` → `fcd9cf0`

**Range:** `18bc68c..fcd9cf0` — **19 commits**, 184 files, **+14927 / −570**. **HEAD:** `fcd9cf0` _"Run the artifact actions on Node.js 24."_ **Environment:** fresh Debian 12 · PHP **8.5.11** · PostgreSQL **18.6** (port 5436, db `consign`) · Xdebug 3.x · fresh CI-faithful database. **Method:** full `composer check` chain (10 gates), the combined `unit,integration,http` suite, and the coverage harness were run. **Mutation (`bin/mutate.php`) was NOT run** — per your standing instruction that it takes too long.

* * *

## 1\. Executive summary

This is the biggest batch in several reviews and it is a real step forward on the scoreboard: **the entire `composer check` chain is GREEN for the first time in a long while**, coverage is back **above the floor (96.91%)**, and both blockers from the previous review — the non-idempotent `064` (A8) and the advisory-lock leak (A9) — are fixed _in code_.

But read how they were fixed. The `064` blocker was fixed by **editing an already-applied migration file** — the exact violation the previous review flagged as A4. So the loud failure (a re-run blows up) was traded for a silent one (any tenant that stamped the old bytes now fails the checksum guard on the next migrate). The team has done the right thing before — `8c130d0` reverted a `041` edit into a _new forward migration_ — and did not do it here.

The batch also shipped three things that widen the blast radius rather than shrink it:

1.  A **mutation harness that can leave mutated source in the working tree** when it is killed (N1). It was killed in this very session and left a live `&&`\-for-`||` mutant in `PartyStore.php`. That is a "commit a security check turned off" bug waiting to happen.
2.  A **production auto-deploy** (`deploy-demo.yml`) that fires on every green `master` push and runs `git reset --hard` + migrations on the live demo host (N5) — with no concurrency guard, no approval gate, and a health check that only asserts `/mall` returns 200.
3.  **Mutation and coverage moved off the per-push gate to a nightly cron** (N6). The per-push `check` that now _gates the deploy_ no longer enforces the mutation score or the coverage floor. A push that drops coverage under 90% or leaves survivors still deploys.

Net: greener board, more ways to quietly ship a broken demo.

* * *

## 2\. Gate board (this session)

| Gate | Result |
| --- | --- |
| `check:max-lines` | **GREEN** |
| `check:phpmd` | **GREEN** (was exit 2 / 8 violations on `18bc68c`) |
| `phpstan` L10 | **GREEN** (368 files) |
| `test` (unit) | **GREEN** (697 tests) |
| `test:integration` | **GREEN** |
| `test:http` | **GREEN** |
| `prove:obligations` | **GREEN** |
| `prove:race` | **GREEN** |
| `prove` (suite) | **GREEN** |
| `prove:tenant` | **GREEN** |
| combined `unit,integration,http` | **OK — 802 tests, 4448 assertions** |
| `test:coverage` | **Lines 18776/19375 (96.91%)** — above the 90% floor |
| JS test | 5 / 5 |
| `test:mutate` | **not run** (per instruction) |

`composer check` went from **failing at step 2 of 12** on `18bc68c` to **fully green** on `fcd9cf0`.

* * *

## 3\. What genuinely got fixed (verified in code)

**A8 — `064` is now re-runnable.** `sql/064_sign_in_address.sql` was rewritten to wrap both statements in `DO $address$ … IF NOT EXISTS … END $address$;`. Re-execution no longer raises `42701`/`42710`. **Closed** — but see A4 for the cost.

**A9 — advisory lock no longer leaks.** New `src/Schema/MigrationLock.php`: `release()` calls `clearAbortedTransaction()` first (probes `SELECT 1`, rolls back on `PDOException`) before `pg_advisory_unlock(814202615)`. The "a migration is already running" cascade is gone. **Closed.**

**A10 — phpmd green again.** `NightlyCopy::latest()` was split into `directory()` / `usableRoot()` / `newest()` / `day()`; the two controllers were pulled back under the coupling ceiling. `phpmd` exits 0. **Closed.**

**A7 — stale assertions updated.** `LayoutBranchesTest`, `OwnerJourneyTest`, `WaveScreensTest`, `PayoutRunScreenTest` were reconciled with the new copy. Unit and HTTP suites are green. **Closed.**

**C3 — catalog validation messages restored.** `DatabaseRefusal::shown()` now `self::uniqueName($raw) ?? self::requiredName($raw)`, and `requiredName()` maps `brands_name_chk`→"Enter a brand name.", `colors_name_chk`, `categories_name_chk`, `sizes_name_chk`, `conditions_name_chk`, `departments_name_chk`. `NameListStore`/`BrandStore` also gained a `requireName()` guard. **Closed.**

**E6 — `item_photos` FK added to the proofs.** `sql/prove_append_only_guard.sql` and `sql/prove_items.sql` now drop `item_photos_item_id_fkey` before `TRUNCATE items`. `prove` suite green. **Closed.**

**A5 — coverage back above the floor.** 96.91% (was 86.38% on `18bc68c`). **Closed** on the number — but see N2 for the flakiness.

* * *

## 4\. Still open / regressed

### A4 — Applied migrations edited in place (still live, now about `064`)

`sql/064_sign_in_address.sql` was **created** in `233dfe4` (bare `ALTER TABLE platform.owner_sign_in_failures ADD COLUMN client_address …`) and **edited** in `73fbdb7` to the `DO $address$` form. `MigrationRecord::verify()` hashes the **raw file bytes** (`SchemaCatalog::checksum()` = `hash('sha256', $this->read($file))`, comments included). Any database that applied the _old_ `064` bytes now throws `MigrationFileChanged` on the next migrate.

**Evidence (live):** running `php bin/provision-platform.php` against the pre-existing `consign` database fails with _"064\_sign\_in\_address.sql changed after it was applied."_ The gate run only passed because it used a freshly-created database.

**Fix:** revert the `064` edit and ship the idempotent form as a **new forward migration** that re-creates the column/constraint with `IF NOT EXISTS` guards (the `057` / `054` pattern). This is the one item on the board that is a genuine data-integrity hazard.

### E5 — `LateFeeDue::apply()` writes on every `GET /rent`

`src/Feature/Rent/RentController.php:33` `#[Route('/([a-z][a-z0-9-]{0,62})/rent', ['GET'])] form()` → `render()` → **line 211 `$this->lateDue->apply($today);`**. A read-only GET posts to the ledger and runs an unbounded scan of `journals ⋈ journal_lines ⋈ chart_roles` (plus a `NOT EXISTS` reversal subquery, plus a second scan in `postedKeys()`). Idempotent via `posting_key`, so no double-post — but a GET must not write, and it does O(all rent history) work per view. **Open.**

### D1 — `.cursor/rules/*.mdc` still committed

`do-not-run-tests.mdc`, `maintain-proven-level.mdc`, `prove-before-advance.mdc`, `update-demo-on-push.mdc`, `git-do-not-mention-idea-leftovers.mdc` all still ship in the repo. **Open.**

### A6 — Probe harness still swallows the checksum signal

`tests/Support/ProbeMall.php:69` still `catch (TenantBaselineMismatch | MigrationFileChanged)` → `wipeTenant(...)`. It masks A4 instead of surfacing it. **Partial, wrong direction.**

### E1–E4 — micro hygiene (unchanged)

`VendorAccount::numbers()` unindexed aggregate; `VendorStickers::printOwn()` opens the tenant twice; `ListTable::assetVersion()` stats the asset file every render. **Open (micro).**

* * *

## 5\. New findings this batch

### N1 — HIGH — the mutation harness can leave mutated source in the working tree

`bin/mutate.php` restores originals two ways: a per-iteration `file_put_contents($file, $originals[$file])` (lines 263/271) **and** a `register_shutdown_function` (line 228). `register_shutdown_function` **does not run on SIGTERM/SIGKILL**. A killed run — a Ctrl-C locally, or a hosted CI runner hitting its time limit (which is _exactly why_ the batch added sharding) — leaves the **last mutant applied to the source tree**.

**Evidence:** the working tree was found dirty this session with `src/Feature/Party/PartyStore.php:79` = `if (!is_string($id) && $id === '')` — a live mutant of the correct `||`. Had that been committed, a validation guard would be silently inverted.

**Fix:** install a `pcntl_signal` handler for SIGINT/SIGTERM that restores and exits, and/or write mutants to a temp copy and run against that instead of mutating `src/` in place. At minimum, refuse to start if the tree is dirty and verify the tree is clean on exit.

### N2 — MEDIUM-HIGH — the coverage gate is flaky

The coverage harness (`bin/coverage-run.php`) reported **1 failure**: `tests/Http/PartyRentWaitListCoverageTest.php:225` `self::assertSees('Vendor moved.', $moved)` received the **sign-in page**. The same test passes in the plain combined run (802 OK) and the `http` suite passes under Xdebug coverage standalone (76 OK). So the failure is specific to the coverage harness's environment.

**Root cause:** `tests/Support/ProbeServer.php::environment()` copies the **full parent `getenv()`** into the spawned `php -S` app server, so during a coverage run the app server inherits `XDEBUG_MODE=coverage` and `PC_COVERAGE` and runs under Xdebug itself — heavier and slower, and a session is lost. Because the coverage harness is the **final step of the nightly `merge` job**, the nightly can now fail spuriously.

**Fix:** scrub `XDEBUG_MODE` / `PC_COVERAGE` from the spawned app server's environment (the app server should not be instrumented; only the test process needs coverage).

### N3 — LOW-MEDIUM — `SqlObjects::definitions()` regex change is incomplete

`3c7d1fd` changed the regex from `'/\$([A-Za-z0-9_]*)\$(.*?)\$1\$/s'` to `'/\$\$(.*?)\$\$/s'`. Verified empirically:

-   **Old** regex stripped **nothing** — `$1` in the pattern is a literal, not a backreference (a backreference would be `\1`), so it only matched bodies ending in `$1$`.
-   **New** regex strips `$$…$$` bodies but **not** named-tag bodies (`$body$`, `$footer$`, `$address$`, …).

The commit message says the intent was to "keep named dollar-quote stamps visible" — but they were _already_ visible. The real effect is that `$$` bodies are now stripped while named-tag bodies are still scanned as "definitions". `sql/` uses 1388 `$$` and ~46 `$body$` plus a handful of other named tags, so the scan is inconsistent. Impact is limited (it only matters when a `CREATE TABLE`/`CREATE FUNCTION`/`ADD CONSTRAINT` appears _inside_ a named-tag body), but the function is now neither "strip all bodies" nor "strip none".

### N4 — MEDIUM — `check.yml` `fetch-depth: 2` breaks `prove:obligations` on a multi-commit push

`check.yml` checks out with `fetch-depth: 2` and, for a push, sets `OBLIGATION_BASE=${{ github.event.before }}`. `bin/prove-obligations.php::obligationBase()` returns that value verbatim, and `addedLines()` runs `git diff -U0 <base> -- src sql` through `git()`, which **throws `RuntimeException('could not run git')` on any non-zero exit**. A push of **two or more commits at once** sets `before` to a commit that is not in the 2-deep clone → `git diff` fails with a bad object → the obligation proof fails. This is pre-existing (the `fetch-depth` was not changed in this batch) but the batch did not fix it, and it is a real hazard for anyone who pushes a stack of commits. **Fix:** `fetch-depth: 0`, or fetch `$before` explicitly.

### N5 — MEDIUM — the new production auto-deploy has no guardrails

`deploy-demo.yml` (new, `e8b8f59`) triggers on a green `check` for `master`, SSHes to the demo host and runs `DEPLOY_SHA=… bash bin/deploy-demo.sh`, which does `git reset --hard`, `composer install --no-dev`, `migrate.php platform`, `migrate.php tenant-all`, then `curl https://v2.perfectconsign.com/mall` and asserts `200`.

Problems:

-   **No `concurrency` guard.** Two green `master` pushes in quick succession launch two deploy jobs that both SSH to the same host and `git reset --hard`. The script's `origin/master == sha` check narrows but does not close the race (both can fetch after the second push).
-   **No `environment:` / approval gate.** Every green push deploys to production automatically.
-   **The health check is `200` on `/mall` only.** A deploy that breaks every authenticated flow but still renders a 200 (login page, cached page) passes. There is no post-deploy smoke of a real flow.
-   **It runs migrations on the live host** — and will therefore **hit A4**: if the demo host already applied the old `064` bytes, `migrate.php platform` fails with `MigrationFileChanged`. The new pipeline is coupled to the migration-integrity bug; the next deploy may simply fail.
-   Hardcoded `/opt/plesk/php/8.5/bin/php` and `v2.perfectconsign.com`; `git reset --hard` discards any demo-host local state with no backup.

### N6 — MEDIUM — mutation + coverage moved off the per-push gate

`c3b6190` moved mutation (8 shards) and coverage to a nightly cron (`nightly.yml`, `0 6 * * *`). The per-push `check` — which now **gates the deploy (N5)** — no longer enforces the mutation score or the coverage floor. A push that drops coverage below 90% or leaves survivors still deploys to the demo. Faster PRs, weaker per-push guarantee; make sure that is a conscious trade.

### N7 — LOW — misc code notes on the new files

-   `MallMoneyForm::apply()` redirects and then constructs a `MallMoneyOutcome`; this is only safe because `MallApp::redirect()` is typed `: never`. Correct today, fragile if `redirect()` ever returns.
-   `ReceiptFooter::length()` counts UTF-8 **code points** (strips continuation bytes `[\x80-\xBF]`), matching the DB's `char_length` — good. But the settings `<textarea maxlength="400">` counts **UTF-16 code units** in the browser, so a 400-emoji footer is blocked client-side at ~200 emoji while the server would accept it. Minor UX mismatch.
-   `ItemPhotoIntake::hex()` does `file_get_contents` then `bin2hex` — a 2 MB photo becomes a ~4 MB hex string in memory before storage. Fine under the 2 MB cap, but it is memory-doubling.
-   `ItemPhotoIntake::kind()` sniffs magic bytes only; a polyglot passes. Low risk because the bytes are stored as hex in a DB column and never served as a file — good design.
-   Receipt footer is rendered through `View::text()` (escaped) in `slip-foot.php` and `settings.php` — **no XSS**. Good.
-   `prove_mall.sql` receipt-footer proof block (lines 238–301) was inspected: every `DO $$ … END $$;` is well-formed; the 401-char and trailing-newline cases are correctly wrapped in `EXCEPTION WHEN SQLSTATE '23514'`. **Not a finding** — checked to preempt a false positive.

* * *

## 6\. Verdict & priority

The green board is real and should be acknowledged: the previous review's two blockers are fixed, phpmd/phpstan/unit/integration/http/prove are all green, and coverage is above the floor. But the batch's headline fix (A8) was achieved by committing the previous review's headline violation (A4), and the batch added a mutation harness that can silently corrupt the source tree and a production deploy with no guardrails.

**Do these, in order:**

1.  **A4** — revert the `064` edit; ship the idempotent form as a new forward migration. This is the only genuine data-integrity hazard and it is now wired into the auto-deploy.
2.  **N1** — make the mutation harness restore on SIGINT/SIGTERM (and refuse to run on a dirty tree). A harness that can leave a validation guard inverted is worse than no harness.
3.  **E5** — stop writing to the ledger on `GET /rent`; move late-fee application to a scheduled job or an explicit action.
4.  **N5** — add a `concurrency` guard and an approval `environment` to the deploy, and make the health check exercise a real authenticated flow, not just `200` on `/mall`.
5.  **N2** — scrub `XDEBUG_MODE`/`PC_COVERAGE` from the probe app server's environment so the coverage gate is deterministic.
6.  **N4** — `fetch-depth: 0` (or fetch `$before`) so a multi-commit push does not break `prove:obligations`.
7.  **N3 / N6 / D1 / A6 / E1–E4** — the smaller items.

* * *

_Report generated against `fcd9cf0`. Mutation suite intentionally not run this session. Standing tracker: `unfinished-changes.md`._