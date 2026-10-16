# Unfinished Changes — Standing Tracker

Running list of things this repo has **started but not finished**, or has claimed to fix but hasn't. Maintained across every pull. Each item records the commit where it was first observed, the current status, and the evidence. Items are only closed when the code genuinely refutes them — not on pushback.

Last updated: review of `6343ff9` (covers `2dc616a`, `856bc24`, `6343ff9`).

Legend: **OPEN** = still present · **PARTIAL** = partly addressed · **CLOSED** = genuinely resolved.

* * *

## A. Gate integrity

### A1 — Unit suite depends on an undeclared `pdo_sqlite` → `test:mutate` and `test:coverage` cannot pass off-CI

-   **Status: OPEN** · first seen `385c5dd` (tests added), reconfirmed `6343ff9`
-   `tests/Feature/Party/VendorMoveTest.php:16` and `tests/Feature/Rent/LateFeeTest.php:20` do `new PDO('sqlite::memory:')`. The app is PostgreSQL-only; CI declares `extensions: pdo_pgsql, zip, curl` (no sqlite). CI is green **only** because `setup-php`'s default PHP 8.4 build on ubuntu-24.04 bundles `pdo_sqlite`/`sqlite3` (verified against the setup-php wiki).
-   Consequence: in any spec-conformant environment (this sandbox, a minimal container, a fresh dev box) the unit suite is **RED** (2 errors), which makes `bin/mutate.php` refuse to score (`MUTATE_EXIT=1`) and `bin/coverage-run.php` exit 2 (coverage is 90.39% ≥ floor, but tests failed).
-   **Fix:** drop SQLite from the unit suite and inject a fake `Statements` double (preferred — keep the app PostgreSQL-only), **or** declare `pdo_sqlite` in CI + dev requirements. Do not leave it implicit.

### A2 — `prove:tenant` is outside `composer check` and outside CI

-   **Status: OPEN** · first seen before `385c5dd`, reconfirmed `6343ff9`
-   `composer.json:38` defines `prove:tenant`; the `check` array does **not** include it, and `.github/workflows/check.yml` never calls it. A gate that never runs is not a gate.
-   **Fix:** add it to `check` (or a workflow step), or stop implying tenant isolation is proven on push.

* * *

## B. Authorization / security

### B1 — Residual owner-default fallbacks (the "missing role ≠ owner" fix is incomplete)

-   **Status: OPEN (latent, not currently exploitable)** · first seen `385c5dd`, reconfirmed `6343ff9`
-   `src/Shared/Web/layout.php:67` — `$actorRole = is_string($actorRole ?? null) ? $actorRole : 'owner';`
-   `src/Feature/Rent/rent.php:332` — `if (($actorRole ?? 'owner') === 'owner' && $rows !== []):`
-   Currently dead code: `MallApp::show()` (`MallApp.php:77`) always injects `actorRole`, so the fallback never fires today. But it is the **exact pattern `385c5dd` claimed to remove**, and it defaults an authorization input to the **most privileged** value. Any future render path outside `show()` (CLI export, mail template, template-requiring test) silently grants owner nav + owner-only late-fee form.
-   **Fix:** default to `''`, not `'owner'`, in both places.

* * *

## C. UX / product correctness

### C1 — `afterprint` wipes the sticker selection even on print-cancel

-   **Status: OPEN** · first seen `2dc616a`
-   `src/Feature/Inventory/stickers.php:58-64` removes the `sessionStorage` selection key on `afterprint`. `afterprint` fires on cancel too, so previewing/cancelling the print dialog destroys a multi-page selection. `afterprint` is also unreliable across engines. Contradicts the "grandma-friendly" bar.
-   **Fix:** clear on an explicit action, or only on a completed print, not on `afterprint`.

### C2 — `Items → Items` rename is half-done; the UI contradicts itself

-   **Status: OPEN** · first seen `2dc616a`
-   Headings say "Items" (`inventory.php:29`), but body copy and the whole vendor surface still say "item(s)": `inventory.php:39,58,66,141`; `Vendor/home.php:36`; `VendorStickers.php:59`; `InventoryStore.php:133,137,161,277,285`; `InventoryController.php:82`; `Code128.php:66`; `LabelRequest.php:79,88,93,102`. Class layer split too (`ItemWriter` vs `StickerItem`, `ItemForm`, `ItemEntry`, `ItemSale`, `ItemDates`, `ItemGuard`, `InstantItem`).
-   **Fix:** finish the rename end-to-end (table is `items` → use "item"), with a test asserting the old noun is gone from rendered output; or revert the headings.

* * *

## D. Governance / hygiene

### D1 — `.cursor/rules/*.mdc` still committed, including `do-not-run-tests`

-   **Status: OPEN** · long-standing
-   `.cursor/rules/` contains `do-not-run-tests.mdc`, `maintain-proven-level.mdc`, `prove-before-advance.mdc`, `update-demo-on-push.mdc`, `git-do-not-mention-idea-leftovers.mdc`. `do-not-run-tests` instructs the agent to skip the one thing that proves the ledger. Belongs in a personal profile, not the repo.
-   **Fix:** remove `do-not-run-tests.mdc` at minimum; ideally move agent rules out of the shipped repo.

* * *

## E. Performance / correctness hygiene

### E1 — `VendorAccount::items()` is unbounded and sorts in PHP

-   **Status: OPEN** · first seen `2dc616a`
-   `VendorAccount.php:57-79` selects all items for a vendor with no limit and `usort`/`strnatcasecmp`s in PHP. Move the natural-key sort into SQL (`ORDER BY` + collation) or bound it.

### E2 — `VendorAccount::numbers()` aggregates with no obvious supporting index

-   **Status: OPEN** · first seen `2dc616a`
-   Two aggregates over `journal_lines` ⋈ `chart_roles` filtered on `subledger_ref`/`subledger_type`. `EXPLAIN` before this page meets a vendor with long history.

### E3 — `VendorStickers::printOwn()` opens the tenant twice

-   **Status: OPEN** · first seen `2dc616a`
-   `printOwn()` calls `$this->app->open($slug, true)` at line 39, but `$this->sheet()` (line 35) already opened it via `storeName()`. Redundant `SET ROLE`/open.

### E4 — `ListTable::assetVersion()` stats the asset file every render

-   **Status: OPEN (micro)** · first seen `2dc616a`
-   `ListTable.php:138-149` `filemtime()` per render. A build-time manifest is the enterprise pattern.

* * *

## F. Closed

### F1 — Vendor account screens were absent from git

-   **Status: CLOSED** in `2dc616a`
-   Root cause: `.gitignore` unanchored `vendor/` matched every `vendor` dir, hiding `src/Feature/Vendor/`. Fixed to `/vendor/` (root-anchored). Vendor page/controller/store/view now tracked; `VendorAccountTest` passes. Fix is at the correct layer (removes the class of bug, not the instance).

### F2 — CSS cache-busted from the JS mtime

-   **Status: CLOSED** in `2dc616a`
-   `ListTable::selectionStyle()` now versions `list-datatables.css` from the CSS file's own mtime; `ListTableTest` asserts it.

* * *

## Snapshot of gate status on `6343ff9`

GREEN: `check:max-lines`, `check:phpmd`, `phpstan` L10, `test:integration` (21/146), `test:http` (48/655), `prove:obligations`, `prove:race`, `prove` (40 proofs), `prove:tenant`. RED: `test` (unit — 2 sqlite errors), `test:mutate` (unit not green), `test:coverage` (exit 2; 90.39% ≥ floor).