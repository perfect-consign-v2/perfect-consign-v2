# Hostile Review — `perfect-consign-v2` @ `9f32c16`

**Range reviewed:** `6343ff9` → `9f32c16` (28 commits, 322 files, +17,707 / −2,198). **HEAD:** `9f32c161f64acef2aa1fcde593c50b87189a76e7` — _"Let a mall turn booth rent off or start each rent period on a chosen day."_ **Environment:** PHP 8.4.26 · PostgreSQL 18.6 (port 5436) · PHPUnit 13.3.5 · Xdebug 3.5.3 · **no `pdo_sqlite`** · Docker not available. **Method:** fresh pull (`git fetch` + `checkout -f -B master origin/master`), full gate suite run by hand, plus targeted reproduction of every failure. No finding is withdrawn unless the code refutes it.

* * *

## 0\. Verdict up front

This range ships a lot of genuinely good work (a well-built password-reset subsystem, a real rent-cycle engine, append-only guards for the new platform tables). But it also **re-breaks a gate that a previous commit had just fixed, silently disables the append-only guard for the rest of the provisioning transaction, edits eight already-applied migrations in place, and drops line coverage below the enforced floor.** Three of the ten gates in `composer check` are red in a spec-conformant environment, and a fourth (`prove:tenant`) is red but nobody would ever know because it is not wired into the gate chain or CI.

The pattern is the one that has been recurring: **the checks are strong, and then a feature commit routes around them instead of through them.**

* * *

## 1\. Gate scoreboard on `9f32c16`

| Gate | Result | Evidence |
| --- | --- | --- |
| `check:max-lines` | **GREEN** | "All PHP files under src are within 500 lines" |
| `check:phpmd` | **GREEN** | exit 0 |
| `phpstan` (level 10) | **GREEN** | `[OK] No errors`, 331 files |
| `test` (unit) | **RED** | 301 tests, **3 errors** — `PDOException: could not find driver` |
| `test:integration` | **GREEN** | 22 tests, 190 assertions (after clearing a stale local tenant — see §3) |
| `test:http` | **GREEN** | 56 tests, 856 assertions |
| `prove:obligations` | **GREEN** | `ok obligations` |
| `prove:race` | **GREEN** | `ok race` |
| `prove` (suite) | **GREEN** | every `prove_*.sql` `ok` |
| `prove:tenant` | **RED** | exit 1 — `owner disable trigger wiped a posted row` |
| `test:mutate` | **RED** | exit 1 — `unit suite is not green, so mutants cannot be scored` |
| `test:coverage` | **RED** | exit 2 — 3 errors **and** `line coverage 89.51% is below 90%` |
| JS test (`node tests/js/*.test.cjs`) | **GREEN** | 5 pass, 0 fail |

So: **four red gates** (`test`, `prove:tenant`, `test:mutate`, `test:coverage`), two of which cascade from the first. `composer check` cannot pass. `composer test:coverage` (the second CI step) cannot pass.

* * *

## 2\. R1 — REGRESSION: the undeclared `pdo_sqlite` dependency is back

`f661a3a` ("Do not treat a missing role as the owner, **and stop the unit tests from opening SQLite**") correctly removed `new PDO('sqlite::memory:')` from `VendorMoveTest` and `LateFeeTest`. Then **`2b74139`** — a _later_ commit, verified with `git merge-base --is-ancestor f661a3a 2b74139` → true — added `tests/Feature/Company/CompanyStoreTest.php`, which reintroduces the exact same pattern:

-   `CompanyStoreTest.php:91` — `$memory = new PDO('sqlite::memory:');`
-   `CompanyStoreTest.php:111` — the anonymous `extends PDO` subclass constructor: `parent::__construct('sqlite::memory:');`

```
1) CompanyStoreTest::testACompanySaveRefusesAMissingMallRow   PDOException: could not find driver
2) CompanyStoreTest::testAMissingTenantRefusesTheRename       PDOException: could not find driver
3) CompanyStoreTest::testAFailedCompanyCommandIsRefused       PDOException: could not find driver
Tests: 301, Assertions: 1462, Errors: 3.
```

**Why this matters.** The app is PostgreSQL-only. CI declares `extensions: pdo_pgsql, zip, curl` — **no `pdo_sqlite`**. CI is green only because `shivammathur/setup-php`'s default PHP 8.4 build on ubuntu-24.04 happens to bundle `pdo_sqlite`. That is an accident of the runner image, not a declared dependency. In any spec-conformant environment — this sandbox, a slim container, a fresh dev laptop — the unit suite is red. And because the unit suite is red:

-   `bin/mutate.php` refuses to score (`unit suite is not green, so mutants cannot be scored`) → **`test:mutate` is red**, so the mutation gate — the one thing that proves the tests actually bite — never runs.
-   `bin/coverage-run.php` exits 2 → **`test:coverage` is red**.

**The fix is the one `f661a3a` already chose:** inject a fake `Statements` double (the class `CompanyStore` already depends on `Statements`, not on `PDO` for the happy path), or declare `pdo_sqlite` in CI _and_ the dev requirements. Do not leave it implicit. This is a straight regression of a fix that was already made — the single most damning item in the range.

* * *

## 3\. N1 — NEW: `056_quantity_offers.sql` turns the append-only guard off for the rest of the provisioning transaction

`sql/056_quantity_offers.sql:6`:

```sql
SET LOCAL event_triggers = false;
```

The file needs this because it does `CREATE OR REPLACE FUNCTION stall_assert_sale_shape()` / `stall_assert_ticket_line()` / `stall_assert_item_return()`, and those functions back triggers named `sale_lines_append_only`, `ticket_lines_no_delete`, `item_returns_no_delete` — which `platform.reject_guard_function_replace()` refuses to let you replace. Fine. **The problem is the scope.** `SET LOCAL` is transaction-scoped, and `bin/provision-tenant.php` applies the _entire_ ledger inside **one** transaction (`beginTransaction()` → `applyLedger()` → `commit()`). So from `056` onward, `event_triggers` is `false` for `057`, `058`, `059` (×2), `060`, and every file after — i.e. **every event-trigger-based guard in the system is switched off for the back half of every tenant provisioning:**

-   `platform_reject_append_only_trigger_change` (the append-only guard),
-   `platform_reject_guard_function_replace` / `_again`,
-   `platform_reject_password_reset_change` / `_drop`.

**Reproduced directly.** Install the ledger into a fresh schema inside one transaction, then try to disable an append-only trigger:

```
A) same-txn DISABLE SUCCEEDED (guard did NOT fire)          ← because 056 set event_triggers=false
B) new-txn  DISABLE blocked: 25006 append-only triggers cannot be disabled
```

The minimal control (create table + trigger + `DISABLE TRIGGER` in one transaction, no ledger) **does** fire the guard — so the culprit is unambiguously `056`, not PostgreSQL, not the transaction, not the schema name.

**Consequences.**

1.  **`prove:tenant` is red.** `bin/prove-tenant.php` installs the ledger inside the proof transaction and then asserts the guard refuses `DISABLE TRIGGER journals_append_only`. Because `056` has already disabled event triggers, the `DISABLE` succeeds, the `UPDATE` succeeds, and the proof raises `owner disable trigger wiped a posted row` and exits 1. On `6343ff9` this gate was **green** — so this is a regression introduced by `6ca7a18`, and it went unnoticed precisely because `prove:tenant` is not in `composer check` and not in CI (see A2).
2.  **Defense-in-depth is silently off during provisioning.** Any later migration that disables/drops an append-only trigger, or replaces a guard function, is no longer caught. The whole point of these `ENABLE ALWAYS` event triggers is that they cannot be bypassed; a migration file turns them off with one line and nothing records it.
3.  **It is a blunt instrument where a scalpel exists.** The intent is to bypass the guard for three `CREATE OR REPLACE FUNCTION` statements. The fix is to bracket _only_ those statements (`SET LOCAL event_triggers = false;` … the three replacements … `SET LOCAL event_triggers = true;`), or to `ALTER EVENT TRIGGER … DISABLE` / re-`ENABLE` around them. Turning the global switch off and leaving it off for the rest of the transaction is the shortcut the "no shortcuts" bar forbids.

This is the most serious finding in the range: it defeats a security control _and_ it is the reason a gate is red.

* * *

## 4\. N2 — NEW: eight already-applied migrations were edited in place to finish the rename

The `Pieces → Items` rename (commit `cb2db5a`, _"…and call stock items instead of pieces"_) was completed by **editing migration files that had already been applied**, not by adding a new migration:

```
M  sql/023_items.sql          (comment: "One piece" → "One item")
M  sql/029_register_posts.sql (source_type 'piece_return' → 'item_return'; trigger body)
M  sql/031_sale_lines.sql     (comment)
M  sql/032_tickets.sql        (variable names, error strings, source_type)
M  sql/037_item_quantity.sql  (piece → item; FROM public.piece_returns → public.item_returns)
M  sql/040_layaway.sql        (comment)
M  sql/041_payout_run.sql     (source_type 'piece_return' → 'item_return')
M  sql/053_booth_correction.sql (comment)
```

`MigrationRecord::verify()` computes `sha256` of the **raw file bytes** (`SchemaCatalog::checksum()` → `hash('sha256', $this->read($file))`, comments included) and throws `RuntimeException: <file> changed after it was applied` for any tenant that already stamped the old bytes. So every existing tenant is now un-migratable. I hit this on the very first integration run:

```
RuntimeException: 023_items.sql changed after it was applied
  src/Schema/MigrationRecord.php:66 ← TenantMigrator.php:30 ← SchemaMigrator.php:42 ← ProbeMall.php:63
```

**The team knows the rule.** Commit `8c130d0` is literally titled _"Leave the applied payout migration unchanged…"_ and it **reverts** a change to `sql/041_payout_run.sql`, moving the logic into a brand-new `sql/054_paid_check_number.sql`. That is the correct pattern. It was applied to the check-number change and then **not** applied to the rename, which touched eight files. The rename is cosmetic in intent but it changes real behaviour in `029/032/037/041` (the `source_type` discriminator value).

**And there is no data migration for the renamed discriminator.** `grep -rn "piece_return" sql/ src/` now returns nothing — the app writes `item_return`, and a fresh provision has triggers that expect `item_return`. But **no migration `UPDATE`s existing `journals.source_type` from `piece_return` to `item_return`.** Any tenant that processed an item return before this range has rows the new code no longer recognises, and the checksum guard blocks the file that would have fixed it. Either the rename of the discriminator was unnecessary (leave `piece_return` in the data and only rename the UI), or it needs a real forward migration. It has neither.

* * *

## 5\. N3 — NEW: line coverage dropped below the enforced floor

`6343ff9` measured **90.39%** (≥ floor). `9f32c16` measures:

```
Lines: 15699/17539 (89.51%)
line coverage 89.51% is below 90%
```

The three failing `CompanyStoreTest` cases contribute, but they are not the whole story — `CompanyStore.php` is not even in the report's low-coverage list, so it is covered elsewhere. The shortfall is driven by the new code shipping under the bar:

```
  0.0%    0/  49  Feature/Dev/MallTenantReset.php
  0.0%    0/  47  Feature/Dev/DevTenantReset.php
  0.0%    0/  20  Feature/Dev/DevResetController.php
  2.0%    1/  51  Feature/Payout/PrintedChecks.php
 12.3%    7/  57  Feature/Payout/PayoutOnce.php
 17.9%    5/  28  Feature/Party/VendorMove.php
 27.8%   10/  36  Feature/Vendor/VendorStickers.php
 36.0%   18/  50  Feature/Rent/LateFee.php
 72.5%  169/ 233  Feature/Rent/rent.php
```

`PrintedChecks.php` (123 new lines from `8c130d0`) at 2.0% and `PayoutOnce.php` at 12.3% are the notable ones — new money-path code, barely exercised. The floor is enforced for a reason; the range ships under it.

* * *

## 6\. N4 — NEW: the test harness cannot recover from the checksum guard

`tests/Support/ProbeMall.php` handles a stale probe tenant by catching `TenantBaselineMismatch` and wiping/re-provisioning. But the checksum failure is a **plain `RuntimeException`** (`MigrationRecord.php:66`), which `TenantBaselineMismatch` does not cover. So a single edited migration file turns the integration suite into a hard failure with **no automatic recovery** — I had to drop the `webprobe` schema by hand (`wipeTenant()` is not even callable from the CLI; `bin/wipe-tenant.php` only defines functions, it has no entry point) before the suite would run. A self-healing probe should treat "a stamped file changed" the same way it treats a baseline mismatch.

* * *

## 7\. Carried findings (still open)

-   **A2 — `prove:tenant` is outside `composer check` and outside CI.** `composer.json:38` defines it; the `check` array omits it and `.github/workflows/check.yml` never calls it. A gate that never runs is not a gate — and this range is the proof: `prove:tenant` went red and nothing noticed.
-   **C1 — `afterprint` wipes the sticker selection on print-cancel.** `src/Feature/Inventory/stickers.php:58` still removes the `sessionStorage` key on `afterprint`, which fires on cancel too. Previewing the print dialog still destroys a multi-page selection.
-   **D1 — `.cursor/rules/*.mdc` still committed**, including `do-not-run-tests.mdc` (which instructs the agent to skip `composer check`, PHPUnit, `prove-suite`, `prove:race`, `prove:obligations`, `prove:tenant`, `test:mutate`, and `sql/prove_*.sql`). Agent policy does not belong in the shipped repo.
-   **E1–E4 — performance hygiene** (unbounded `VendorAccount` sort in PHP, unindexed aggregates, double tenant-open in `VendorStickers::printOwn()`, `filemtime()` per render in `ListTable`). Unchanged.

* * *

## 8\. Closed / genuinely fixed

-   **F2 (owner-default fallback) — CLOSED.** `f661a3a` did the right thing: `src/Shared/Web/layout.php` now reads `$actorRole = is_string($actorRole ?? null) ? $actorRole : '';` and only builds the owner nav inside `if ($actorRole === 'owner')`. `grep -rn "?? 'owner'" src/` → **none**. The authorization input no longer defaults to the most privileged value. Correct fix at the correct layer.
-   **C2 (`Pieces → Items` rename) — CLOSED, but by the wrong method.** `grep -rin "piece"` across `src/`, `public/`, `sql/`, `tests/`, `*.js` → **zero occurrences**. The rename is complete and consistent. It was just executed by editing applied migrations (§4) rather than by a forward migration.
-   **`e8574e4`** (drop the `password_hash() === false` check that PHP never returns) is correct — `password_hash()` with `PASSWORD_DEFAULT` returns a string or throws; the guard was dead code. Dev-only class, no risk.

* * *

## 9\. What is genuinely good (fair credit)

-   **Password reset (`057`/`058` + `src/Feature/Password/`)** is well-built: 32 random bytes → base64url → 43-char token, only the `sha256` hash stored, 30-minute expiry, `SELECT … FOR UPDATE` + `UPDATE … WHERE used_at IS NULL` (single-use under concurrency), issuing a new token expires the account's old ones, per-account rate limit (5 / 15 min), no user enumeration (`request()` returns silently for unknown accounts), `credentials_version` bumped on change to invalidate sessions, and the reset table is append-only with its own `ENABLE ALWAYS` guards. This is the standard the rest of the range should meet.
-   **`RentCycle` / `RentSpan` / `RentMoves`** model the "rent starts on the Nth" policy cleanly, with the cycle day frozen on a posted charge and a `prove_booth_rent_policy.sql` proof.
-   **`c911615`** correctly stops a card/check overpayment from being recorded as cash tendered, and the `PayVendorsPageTest` assertion flip is legitimate (the variables are `strpos()` positions — the column order, not amounts).

* * *

## 10\. Recommendations, in priority order

1.  **Fix `056`'s scope.** Bracket the three `CREATE OR REPLACE FUNCTION` statements with `SET LOCAL event_triggers = false; … true;` (or `ALTER EVENT TRIGGER … DISABLE/ENABLE`). Then `prove:tenant` goes green again and the guard is only off for the statements that need it.
2.  **Revert the in-place edits to `023/029/031/032/037/040/041/053`** and, if the discriminator rename is required, add a forward migration that `UPDATE`s `journals.source_type` and re-creates the affected triggers. Follow the `8c130d0` pattern.
3.  **Remove `pdo_sqlite` from the unit suite** (inject a `Statements` double) — the fix `f661a3a` already made. Then `test:mutate` and `test:coverage` can run.
4.  **Wire `prove:tenant` into `composer check`** (and CI). It caught a real regression this range and nobody saw it.
5.  **Make `ProbeMall` recover from a checksum mismatch**, and give `bin/wipe-tenant.php` a CLI entry point.
6.  **Bring coverage back over 90%** before adding features — the new `Dev/*` and payout files are the gap.
7.  **Delete `.cursor/rules/do-not-run-tests.mdc`** from the repo.

* * *

_Prepared after a fresh pull to `9f32c16`, a full hand-run of every gate, and direct reproduction of each failure (including the same-transaction append-only-guard bypass and the checksum trip). Findings stand on the code; none were softened._