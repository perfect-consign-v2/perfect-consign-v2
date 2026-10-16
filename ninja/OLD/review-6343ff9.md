# Hostile Review — `6343ff9` (covers `2dc616a`, `856bc24`, `6343ff9`)

Repo: `perfect-consign-v2/perfect-consign-v2` · branch `master` · reviewed at `6343ff9` Reviewer: adversarial pass (SuperNinja). Tests were **run by me**, not taken on faith. Prior review baseline: `385c5dd` (`review-385c5dd.md`).

* * *

## 0\. Verdict

This push is **mostly a repair job, and a good one**, on top of a broken prior push. The single most serious defect I raised against `385c5dd` — the vendor account screens were **not in git at all** — is genuinely fixed, and the fix is at the correct layer (the `.gitignore` pattern), not a band-aid. The page-scroll + selection work is careful, well-specified (`docs/inventory-list-selection-handoff.md` is unusually good), and covered by a real browser store with node tests.

But the push is **not clean**, and two of the problems are exactly the kind of thing this repo's own "prove-before-advance" governance claims to prevent:

1.  **The unit suite is red in any environment that installs the extensions the project declares.** Two unit tests need `pdo_sqlite`; the project declares itself PostgreSQL-only and CI declares only `pdo_pgsql, zip, curl`. CI is green **by accident** (the `setup-php` default build bundles sqlite), which is worse than being red — the dependency is invisible. This cascades: `test:mutate` refuses to score and `test:coverage` exits non-zero.
2.  **The "do not treat a missing role as the owner" fix from `385c5dd` is incomplete.** Two owner-default fallbacks (`layout.php:67`, `rent.php:332`) still exist. They are currently latent (not exploitable) because `MallApp::show()` always injects a real role — but they are the exact pattern the previous commit claimed to have eliminated.

Plus a half-finished `Items → Items` rename that leaves the UI self-contradictory, and a `afterprint`\-clears-on-cancel behaviour that is a real footgun for the "grandma-friendly" bar.

None of these are catastrophic. All of them are the difference between "works" and "world class."

* * *

## 1\. What the commits claim

-   **`2dc616a`** — "Keep sticker checks when the list pages, and call the stock items." Fixes the gitignore that hid the vendor screens; adds vendor account page; adds a sessionStorage selection store so sticker checkboxes survive client-side paging; renames `ItemWriter`→`ItemWriter` and switches the staff list headings to "Items"; fixes CSS cache-busting to use the CSS file's own mtime.
-   **`856bc24`** — "Reject an empty sticker back link and a non-text storage key." Adds 5 unit tests.
-   **`6343ff9`** — "Keep the review of the staff and vendor commit with the other reviews." Commits my own prior review into `ninja/review-bb9a395.md`.

* * *

## 2\. Gate results — run by me on `6343ff9`

Environment: PHP 8.4.26, Xdebug 3.5.3, PHPUnit 13.3.5, PostgreSQL 18.6 on port 5436, `pdo_pgsql` only (no `pdo_sqlite`). Composer is not installed; scripts run directly via `php bin/...` / `php vendor/bin/phpunit`.

| Gate | Result | Evidence |
| --- | --- | --- |
| `check:max-lines` (500) | **GREEN** | — |
| `check:phpmd` | **GREEN** | — |
| `phpstan` level 10 | **GREEN** | — |
| `test` (unit) | **RED** | 181 tests, **2 errors**, 894 assertions |
| `test:integration` | **GREEN** | 21 tests, 146 assertions |
| `test:http` | **GREEN** | 48 tests, 655 assertions — `VendorAccountTest` now **passes** |
| `prove:obligations` | **GREEN** | `ok obligations` |
| `prove:race` | **GREEN** | `ok race` |
| `prove` (suite) | **GREEN** | 40 SQL proofs, `SUITE_EXIT=0` |
| `prove:tenant` | **GREEN** | `TENANT_EXIT=0` |
| `test:mutate` | **RED** | `unit suite is not green, so mutants cannot be scored` (`MUTATE_EXIT=1`) |
| `test:coverage` | **RED (exit 2)** | **90.39% (13007/14390)** ≥ 90% floor, but exit 2 from the 2 unit errors |

Node store tests: `node tests/js/inventory-selection-store.test.cjs` → **5 pass, 0 fail**.

The unit-suite errors, verbatim:

```
1) PerfectConsign\Tests\Feature\Party\VendorMoveTest::testAMoveNeedsADateAndABooth
   PDOException: could not find driver        tests/Feature/Party/VendorMoveTest.php:16
2) PerfectConsign\Tests\Feature\Rent\LateFeeTest::testALateFeeNeedsABoothAndADate
   PDOException: could not find driver        tests/Feature/Rent/LateFeeTest.php:20
```

* * *

## 3\. Findings

### F1 — HIGH (gate integrity) — Unit suite depends on an **undeclared** `pdo_sqlite`; green CI is accidental

Two unit tests construct domain objects against SQLite:

```php
// tests/Feature/Party/VendorMoveTest.php:16
$move = new VendorMove(new Statements(new PDO('sqlite::memory:')));
// tests/Feature/Rent/LateFeeTest.php:20
$pdo = new PDO('sqlite::memory:');
$fee = new LateFee(new LedgerStore($pdo, new Statements($pdo), ...));
```

The project is a **PostgreSQL-only** double-entry ledger. It declares no SQLite anywhere. CI declares `extensions: pdo_pgsql, zip, curl` (`.github/workflows/check.yml:39`) — **no sqlite**. Yet the unit suite cannot go green without it.

I verified the CI outcome rather than guessing. `ubuntu-latest` is `ubuntu-24.04`, and the `shivammathur/setup-php` wiki's "Php extensions loaded on ubuntu 24.04" page lists, for **PHP 8.4**, both `pdo_sqlite (shared)` and `sqlite3 (shared)` as **loaded by default**. So:

-   **CI is green — but only because the runner's PHP build happens to bundle sqlite.** The declared contract (`pdo_pgsql, zip, curl`) does not produce a green suite. A minimal container, a fresh developer box, or this sandbox built to the declared spec is **red**.
-   Because the unit suite is red, `test:mutate` refuses to run (`bin/mutate.php` guards on a green unit suite) and `test:coverage` exits non-zero. The mutation gate — a core "proven level" mechanism — is therefore **unavailable in any spec-conformant environment**.

This is a hidden dependency masquerading as a green build. The failure mode is the worst kind: nothing in the repo tells you sqlite is required, so the day someone tightens the runner image, the whole `composer check` chain goes red with no obvious cause.

**Deeper smell.** These two tests use SQLite as a _null database_ purely to satisfy a constructor — the assertions throw on input validation **before any SQL executes**. That is a category error: the test exercises a **different SQL dialect than production**. Today it is harmless because no SQL runs. The moment anyone adds an assertion that reaches the query, the test validates PostgreSQL behaviour against SQLite. The right fix is a null/fake `Statements` double (or the real Postgres), not a second database engine pulled in for one line of plumbing.

**Fix:** either (a) add `pdo_sqlite` to CI's `extensions:` and to the documented dev requirements, or (b) — better — drop SQLite from the unit suite and inject a fake `Statements`. (b) is the enterprise answer: the app is PostgreSQL-only; the tests should be too.

### F2 — MEDIUM (authorization residual) — The "missing role ≠ owner" fix from `385c5dd` is incomplete

`385c5dd` was titled "…and do not treat a missing role as the owner." Two owner-default fallbacks survive:

```php
// src/Shared/Web/layout.php:67
$actorRole = is_string($actorRole ?? null) ? $actorRole : 'owner';
// src/Feature/Rent/rent.php:332
<?php if (($actorRole ?? 'owner') === 'owner' && $rows !== []): ?>
```

**Honest impact assessment:** these are **latent, not currently exploitable**. `MallApp::show()` (`src/Bootstrap/MallApp.php:77`) always sets `$data['actorRole'] = $this->door->actor()->role()`, so `$actorRole` is always a string by the time these templates render. The `?? 'owner'` branch is dead code _today_. I am not going to inflate it into a live vulnerability — that would be dishonest.

But it is still a real defect on two counts:

1.  It is **the exact pattern the previous commit claimed to have removed**. Leaving it means the claim in `385c5dd` is false, and the repo now has a _false sense of having fixed it_.
2.  It is a **trap for the next render path**. The safe default for an authorization input is the _most restrictive_ value (`''`), never the _most privileged_ (`'owner'`). Any future code path that renders `layout.php` or `rent.php` outside `show()` — a CLI export, a mail template, a test harness that `require`s the template directly — silently grants the owner nav and the owner-only late-fee form.

For the rent page specifically: the `POST /rent/late-fee` route is **not** in the cashier allowlist (`RoleGate::rentPage()` permits `/rent`, `/rent/collect`, `/rent/payment`, `/rent/receipt/*` only), so a cashier cannot actually _post_ a fee. The exposure is the rendered form, not the action. Still wrong.

**Fix:** default to `''`, not `'owner'`, in both places. One-character-class change, and it makes the prior commit's claim true.

### F3 — MEDIUM (UX / robustness) — `afterprint` wipes the batch selection even when the operator cancels

`src/Feature/Inventory/stickers.php` clears the saved selection on `afterprint`:

```js
window.addEventListener('afterprint', function () {
    window.sessionStorage.removeItem(key);
});
```

The handoff doc states this is intentional ("closing the dialog ends the batch"). It is a defensible design **if** the only exit from the print dialog is "printed successfully." It is not. `afterprint` fires on **cancel** too — wrong printer, wrong paper, a mis-click, or just previewing. In every one of those cases the operator's entire multi-page selection is silently destroyed and must be rebuilt.

This directly contradicts the "grandma-friendly" bar: a user who opens the print preview to _check_ something loses their work. `afterprint` support is also inconsistent across engines (historically unreliable in Safari; some engines fire it before the dialog is truly dismissed), so the behaviour is not even deterministic across browsers.

**Fix:** don't clear on `afterprint`. Clear on an explicit "Done" / "Clear" action, or clear only when the print actually completed (best-effort), or leave the selection until the user clears it. A destructive, non-deterministic, cancel-triggered wipe is not enterprise behaviour.

### F4 — MEDIUM (consistency) — The `Items → Items` rename is half-done and the UI now contradicts itself

The commit renames the staff list headings to "Items" (`inventory.php:29` "Items", "New item", "One row is one item on the floor.") and renames `ItemWriter`→`ItemWriter`. But the body copy and the entire vendor surface still say "item(s)":

-   `inventory.php:58` — "Check the **items** in the list."
-   `inventory.php:66` — "No **items** match."
-   `inventory.php:141` — "Delete this **item**?"
-   `inventory.php:39` — "No **items** yet."
-   `src/Feature/Vendor/home.php:36` — "Check your own **items**."
-   `src/Feature/Vendor/VendorStickers.php:59` — "That **item** is not yours."
-   `src/Feature/Inventory/InventoryStore.php:133,137,161,277,285` — "That **item** is in use / not in this mall."
-   `src/Feature/Inventory/InventoryController.php:82` — "**Item** deleted."
-   `src/Feature/Inventory/Code128.php:66` — "This **item** has no barcode."
-   `src/Feature/Inventory/LabelRequest.php:79,88,93,102` — "Choose a **item**." / "Print fewer **items**…"

The class layer is likewise split: `ItemWriter`/`items` table vs `StickerItem`, `ItemForm`, `ItemEntry`, `ItemSale`, `ItemDates`, `ItemGuard`, `InstantItem`, `InventoryStore::$item`.

So the same screen says "Items" in the header and "No items match" two lines down. That is exactly the kind of internal inconsistency a world-class product does not ship. A rename is either done or it is not; a half-rename is worse than not renaming, because it signals the change was never verified end-to-end.

**Fix:** finish it (or revert the headings). Pick one noun — the table is `items`, so "item" — and sweep the user-facing strings, the class names, and the local variables in one commit with a test that asserts the old noun no longer appears in rendered output.

### F5 — LOW (gate coverage) — `prove:tenant` is still outside `composer check` and outside CI

`prove:tenant` exists as a script (`composer.json:38`) and passes, but it is **not** in the `check` array and CI never invokes it. A gate that is not run is not a gate. Either fold it into `check` (or a `check:tenant` step in the workflow) or delete the claim that tenant isolation is proven on every push.

### F6 — LOW (governance hygiene) — `.cursor/rules/*.mdc` still committed, including `do-not-run-tests`

`.cursor/rules/` still contains `do-not-run-tests.mdc`, `maintain-proven-level.mdc`, `prove-before-advance.mdc`, `update-demo-on-push.mdc`, `git-do-not-mention-idea-leftovers.mdc`. The `do-not-run-tests` rule is actively harmful in a repo whose entire value proposition is a proven, mutation-tested ledger — it instructs the agent to skip the one thing that proves the kernel. Rules that tell the tooling _not_ to verify belong in a personal profile, not in the shipped repo. This is a standing item (see `unfinished-changes.md`).

### F7 — LOW (performance / correctness hygiene)

-   `VendorAccount::items()` loads **all** of a vendor's items with no limit and sorts in PHP (`usort`/`strnatcasecmp`). Fine for a small consignor; unbounded for a large one. The natural-key sort belongs in SQL (`ORDER BY` with a collation) or at least behind a limit.
-   `VendorAccount::numbers()` runs two aggregates over `journal_lines` joined to `chart_roles`, filtered on `subledger_ref`/`subledger_type` with no obvious supporting index. Worth an `EXPLAIN` before this page is hit by a vendor with a long history.
-   `VendorStickers::printOwn()` calls `$this->app->open($slug, true)` at line 39, but `$this->sheet()` (line 35) already opened the tenant via `storeName()`. Redundant `SET ROLE`/open. Harmless, sloppy.

### F8 — LOW (micro)

-   `ListTable::assetVersion()` stats the asset file on every render. Negligible, but it is a filesystem call in the hot path; a build-time manifest is the enterprise pattern.
-   `layout.php:67`'s `is_string($actorRole ?? null)` guard is unreachable dead code given `show()` always injects the role (see F2).

* * *

## 4\. Resolved findings (credit where due)

-   **Prior CRITICAL — vendor screens absent from git — RESOLVED, at the right layer.** Root cause was `.gitignore`'s unanchored `vendor/` matching _every_ directory named `vendor`, including `src/Feature/Vendor/`. Fix is `/vendor/` (root-anchored) — `.gitignore:3`. The vendor account page, controller, store and view are now tracked, and `VendorAccountTest` passes. This is the correct fix: it removes the class of bug rather than the instance.
-   **Prior finding — CSS versioned from the JS mtime — RESOLVED.** `ListTable::selectionStyle()` now versions `list-datatables.css` from the CSS file's own mtime (`ListTable.php:128-129`), and `ListTableTest` asserts it. A CSS-only change is no longer stuck in the browser.
-   **Selection survives paging — verified by construction and by test.** The store keeps an ordered id list in `sessionStorage`; on submit it disables the live `item[]` boxes and injects hidden `item[]` from the full snapshot, so off-page rows are posted (`inventory-selection-store.js:278-296`). The `selection_storage_key` is allowlisted server-side (`InventorySelectionKey::PATTERN`) before it is echoed onto the print button — good defence against reflecting an attacker-controlled key, on top of `View::text()` escaping.

* * *

## 5\. What is genuinely good here (briefly — you already know)

The handoff document is the best artefact in this push: it names the exact failure, the exact files, the exact browser checks, and the complexity caps to respect. The selection store is clean, dependency-free, and unit-tested in node. The page-scroll branch is surgical (only `#list-inventory`; every other table keeps its inner scroll). The allowlist is strict and tested for the `.extra` suffix and non-string input. The `.gitignore` fix is at the correct layer. This is a competent repair.

* * *

## 6\. Reproduce

```bash
# unit (RED here; green in CI only because setup-php bundles sqlite)
php vendor/bin/phpunit --testsuite unit
# integration / http
php vendor/bin/phpunit --testsuite integration
php vendor/bin/phpunit --testsuite http
# proofs
php bin/prove-obligations.php && php bin/prove-race.php && php bin/prove-suite.php
php bin/prove-tenant.php
# gates that go red because unit is red
php bin/mutate.php            # "unit suite is not green, so mutants cannot be scored"
php bin/coverage-run.php      # exit 2 (coverage 90.39% >= floor)
# node
node tests/js/inventory-selection-store.test.cjs
```

* * *

## 7\. Bottom line

The repair is real and the prior CRITICAL is closed. But the push ships a **hidden sqlite dependency** that makes the "proven" gate unavailable outside CI, leaves **two owner-default fallbacks** the previous commit claimed to have removed, wipes the user's selection on print-cancel, and leaves the UI calling the same thing both "Items" and "items." Fix F1 and F2 and this is a solid push; leave them and the "prove-before-advance" governance is decoration.