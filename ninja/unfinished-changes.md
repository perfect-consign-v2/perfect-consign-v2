# Unfinished Changes — Standing Tracker

Running list of things this repo has **started but not finished**, or has claimed to fix but hasn't. Maintained across every pull. Each item records the commit where it was first observed, the current status, and the evidence. Items are only closed when the code genuinely refutes them — not on pushback.

Last updated: review of `9f32c16` (covers `f661a3a` … `9f32c16`, 28 commits). Previous: review of `6343ff9`.

Legend: **OPEN** = still present · **PARTIAL** = partly addressed · **REGRESSED** = was fixed, came back · **CLOSED** = genuinely resolved.

* * *

## A. Gate integrity

### A1 — Unit suite depends on an undeclared `pdo_sqlite` → `test:mutate` and `test:coverage` cannot pass

-   **Status: REGRESSED** · first seen `385c5dd`; **fixed** in `f661a3a`; **reintroduced** in `2b74139`
-   `f661a3a` removed `new PDO('sqlite::memory:')` from `tests/Feature/Party/VendorMoveTest.php` and `tests/Feature/Rent/LateFeeTest.php`. A **later** commit, `2b74139` (verified: `git merge-base --is-ancestor f661a3a 2b74139` → true), added `tests/Feature/Company/CompanyStoreTest.php`, which puts it straight back:
    -   `CompanyStoreTest.php:91` — `$memory = new PDO('sqlite::memory:');`
    -   `CompanyStoreTest.php:111` — anonymous `extends PDO` constructor: `parent::__construct('sqlite::memory:');`
-   Evidence on `9f32c16`: `test` = 301 tests, **3 errors** (`PDOException: could not find driver`); `test:mutate` = exit 1 (`unit suite is not green, so mutants cannot be scored`); `test:coverage` = exit 2. The app is PostgreSQL-only; CI declares `extensions: pdo_pgsql, zip, curl` (no sqlite) and is green only because `setup-php`'s PHP 8.4 image happens to bundle `pdo_sqlite`.
-   **Fix:** inject a fake `Statements` double (the pattern `f661a3a` chose), or declare `pdo_sqlite` in CI **and** the dev requirements. Do not leave it implicit.

### A2 — `prove:tenant` is outside `composer check` and outside CI

-   **Status: OPEN (and now proven to matter)** · first seen before `385c5dd`
-   `composer.json:38` defines `prove:tenant`; the `check` array omits it and `.github/workflows/check.yml` never calls it. On `9f32c16` this gate is **RED** (see A3) and nobody would have known, because it never runs. A gate that never runs is not a gate.
-   **Fix:** add it to `check` (or a workflow step).

### A3 — NEW: `056_quantity_offers.sql` disables every event-trigger guard for the rest of provisioning

-   **Status: OPEN** · first seen `6ca7a18`
-   `sql/056_quantity_offers.sql:6` — `SET LOCAL event_triggers = false;`. It needs this to replace three guard-backed functions, but `SET LOCAL` is transaction-scoped and `bin/provision-tenant.php` applies the whole ledger in one transaction — so from `056` onward, **every event trigger is off** for `057`–`060` and beyond: the append-only guard, the guard-function-replace guards, and the password-reset guards.
-   Evidence: reproduced directly — install the ledger into a fresh schema in one transaction, then `ALTER TABLE … DISABLE TRIGGER journals_append_only` **succeeds** (guard does not fire); in a _new_ transaction it is blocked with `25006`. The minimal control (no ledger) fires the guard, so `056` is the cause. This is also why **`prove:tenant` is RED** (`owner disable trigger wiped a posted row`).
-   **Fix:** bracket only the three `CREATE OR REPLACE FUNCTION` statements with `SET LOCAL event_triggers = false; … true;`, or `ALTER EVENT TRIGGER … DISABLE/ENABLE` around them.

### A4 — NEW: eight already-applied migrations edited in place (rename); no data migration for the renamed discriminator

-   **Status: OPEN** · first seen `cb2db5a`
-   `cb2db5a` finished the `Pieces → Items` rename by editing **already-applied** files: `023_items.sql`, `029_register_posts.sql`, `031_sale_lines.sql`, `032_tickets.sql`, `037_item_quantity.sql`, `040_layaway.sql`, `041_payout_run.sql`, `053_booth_correction.sql` (`029/032/037/041` change real SQL — the `source_type` value `piece_return → item_return` and trigger bodies; the rest are comments).
-   `MigrationRecord::verify()` hashes the raw file bytes (comments included) and throws `RuntimeException: <file> changed after it was applied` for any tenant that stamped the old bytes. Hit directly on the first integration run: `023_items.sql changed after it was applied` (`MigrationRecord.php:66` ← `TenantMigrator.php:30` ← `SchemaMigrator.php:42` ← `ProbeMall.php:63`).
-   The team knows the rule: `8c130d0` is titled _"Leave the applied payout migration unchanged"_ and **reverts** a `041` edit into a new `054_paid_check_number.sql`. That pattern was not applied to the rename.
-   Also: `grep -rn "piece_return" sql/ src/` now returns nothing, but **no migration `UPDATE`s existing `journals.source_type`** from `piece_return` to `item_return`. Existing tenants keep stale rows and the checksum guard blocks the file that would fix them.
-   **Fix:** revert the in-place edits; if the discriminator rename is needed, add a forward migration that updates `journals.source_type` and re-creates the affected triggers.

### A5 — NEW: line coverage below the enforced floor

-   **Status: OPEN** · first seen `9f32c16`
-   `test:coverage` = `Lines: 15699/17539 (89.51%)`, `line coverage 89.51% is below 90%`. On `6343ff9` it was 90.39% (≥ floor). The 3 sqlite errors contribute, but the report shows new code shipping under the bar: `Feature/Dev/MallTenantReset.php` 0%, `Feature/Dev/DevTenantReset.php` 0%, `Feature/Dev/DevResetController.php` 0%, `Feature/Payout/PrintedChecks.php` 2.0%, `Feature/Payout/PayoutOnce.php` 12.3%, `Feature/Party/VendorMove.php` 17.9%.
-   **Fix:** cover the new Dev/\* and payout paths before adding more features.

### A6 — NEW: the probe harness cannot recover from a checksum mismatch

-   **Status: OPEN** · first seen `9f32c16`
-   `tests/Support/ProbeMall.php` catches `TenantBaselineMismatch` and wipes/re-provisions. The checksum failure is a plain `RuntimeException` (`MigrationRecord.php:66`), which that catch does not cover — so a single edited migration bricks the integration suite with no automatic recovery. `bin/wipe-tenant.php` only defines functions (no CLI entry point), so there is no easy manual reset either.
-   **Fix:** treat "a stamped file changed" like a baseline mismatch in `ProbeMall`; give `wipe-tenant.php` a CLI entry point.

* * *

## B. Authorization / security

### B1 — Owner-default fallbacks (`?? 'owner'`)

-   **Status: CLOSED** in `f661a3a`
-   `src/Shared/Web/layout.php` now defaults the role to `''` (`is_string($actorRole ?? null) ? $actorRole : ''`) and builds the owner nav only inside `if ($actorRole === 'owner')`; `src/Feature/Rent/rent.php` no longer uses `($actorRole ?? 'owner')`. `grep -rn "?? 'owner'" src/` → **none**. Correct fix at the correct layer.

* * *

## C. UX / product correctness

### C1 — `afterprint` wipes the sticker selection even on print-cancel

-   **Status: OPEN** · first seen `2dc616a`
-   `src/Feature/Inventory/stickers.php:58` removes the `sessionStorage` selection key on `afterprint`. `afterprint` fires on cancel too, so previewing/cancelling the print dialog destroys a multi-page selection. Contradicts the "grandma-friendly" bar.
-   **Fix:** clear on an explicit action, or only on a completed print.

### C2 — `Pieces → Items` rename

-   **Status: CLOSED (rename), but by the wrong method** · first seen `2dc616a`
-   `grep -rin "piece"` across `src/`, `public/`, `sql/`, `tests/`, `*.js` → **zero occurrences**. The rename is complete and consistent. It was executed by editing applied migrations, though — see A4.

* * *

## D. Governance / hygiene

### D1 — `.cursor/rules/*.mdc` still committed, including `do-not-run-tests`

-   **Status: OPEN** · long-standing
-   `.cursor/rules/` still contains `do-not-run-tests.mdc`, `maintain-proven-level.mdc`, `prove-before-advance.mdc`, `update-demo-on-push.mdc`, `git-do-not-mention-idea-leftovers.mdc`. `do-not-run-tests` instructs the agent to skip `composer check`, PHPUnit, `prove-suite`, `prove:race`, `prove:obligations`, `prove:tenant`, `test:mutate`, and `sql/prove_*.sql`.
-   **Fix:** remove `do-not-run-tests.mdc` at minimum; ideally move agent rules out of the shipped repo.

* * *

## E. Performance / correctness hygiene

### E1 — `VendorAccount::pieces()` is unbounded and sorts in PHP

-   **Status: OPEN** · first seen `2dc616a` · (note: method may have been renamed with the Items rename)

### E2 — `VendorAccount::numbers()` aggregates with no obvious supporting index

-   **Status: OPEN** · first seen `2dc616a`

### E3 — `VendorStickers::printOwn()` opens the tenant twice

-   **Status: OPEN** · first seen `2dc616a`

### E4 — `ListTable::assetVersion()` stats the asset file every render

-   **Status: OPEN (micro)** · first seen `2dc616a`

* * *

## F. Closed

### F1 — Vendor account screens were absent from git

-   **Status: CLOSED** in `2dc616a` — `.gitignore` `vendor/` → `/vendor/`.

### F2 — CSS cache-busted from the JS mtime

-   **Status: CLOSED** in `2dc616a` — versions from the CSS file's own mtime.

* * *

## Snapshot of gate status on `9f32c16`

GREEN: `check:max-lines`, `check:phpmd`, `phpstan` L10 (331 files), `test:integration` (22/190), `test:http` (56/856), `prove:obligations`, `prove:race`, `prove` (suite), JS test (5/0). RED: `test` (unit — 3 sqlite errors), `prove:tenant` (exit 1 — 056 guard bypass), `test:mutate` (unit not green), `test:coverage` (exit 2; 89.51% < 90%).