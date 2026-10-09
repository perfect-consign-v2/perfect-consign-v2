# Hostile review — `perfect-consign-v2` @ `cf58c51`

**Scope:** fresh pull of `master`, re-run every gate, re-review the new commit, update the prior report (`review-c82c422.md`). **HEAD reviewed:** `cf58c51` ("Release the migration lock, stamp tables that are already there, and cover the catalog and sticker pages."), co-authored by Cursor. **New commit since the last review (`c82c422`):**

| Commit | Subject | Delta |
| --- | --- | --- |
| `cf58c51` | Release the migration lock, stamp tables that are already there, and cover the catalog and sticker pages. | 33 files changed, 1115 insertions(+), 364 deletions(−) |

**Method:** PostgreSQL 18.6 on `127.0.0.1:5436` (db `consign`, user `stall`), PHP 8.4.26, PHPUnit 13.3.5, Xdebug 3.5.3. Every gate was run against a clean working tree at `cf58c51`. Docker is not available in this sandbox, so the DB was driven directly (the repo's own `bin/` scripts and `psql`). The local `consign` database is polluted with eleven stale tenants, so every DB-dependent gate was re-run against freshly created databases (`consign_fresh`, `consign_ci`, `consign_ci2`) to obtain CI-equivalent results. Where a gate is order- or state-dependent, both the fresh-database and steady-state results are reported.

**This report lists only what is wrong or below a world-class enterprise bar.** Everything that is correct is deliberately omitted.

* * *

## 1\. Verdict

`composer check` is **still red**, but the failure surface has moved. The three static-analysis gates that were red at `c82c422` (`check:max-lines`, `check:phpmd`, `phpstan` level 10) are now **green**, and the P0 advisory-lock leak is genuinely fixed. However, **two new P0 defects** were introduced by this commit — the `prove` suite is now red **in CI** (it was only red on one proof before), and mutation coverage collapsed from **1 survivor to 80**. `test:coverage` is still under the floor.

| Gate | `c82c422` | `cf58c51` | Headline |
| --- | --- | --- | --- |
| `check:max-lines` | RED | **GREEN** | all files ≤ 500 lines |
| `check:phpmd` | RED | **GREEN** | exit 0 |
| `phpstan` (level 10) | RED (8) | **GREEN** | `[OK] No errors` |
| `test` (unit) | GREEN | **GREEN** | 106 tests, 429 assertions (with env sourced) |
| `test:integration` | RED | **GREEN fresh / RED steady-state** | 14/108 fresh; 13 errors on the polluted local DB |
| `test:http` | GREEN steady / RED fresh | **GREEN fresh** | 42 tests, 613 assertions |
| `prove:obligations` | GREEN | **GREEN** | `ok obligations` |
| `prove:race` | RED under concurrency | **GREEN alone** | `ok race` |
| `prove` (suite) | RED (1 proof) | **RED (4 proofs)** | 3× `mall tenant is missing` + 1× FK/TRUNCATE |
| `test:mutate` | RED (1 survivor) | **RED (80 survivors)** | 552 mutants, 453 killed, 19 equivalent, **80 survived** |
| `test:coverage` | RED (17.37%, artifact) | **RED** | line coverage **89.51%** < 90% floor |

**The headline: this commit fixed the loud, easy-to-see failures and, in doing so, shipped a large block of new sticker/label code that is almost entirely untested — and broke the proof suite in CI.** The pipeline is red for _different_ reasons than before, not for fewer.

* * *

## 2\. P0 — the `prove` suite is red **in CI** (3 proofs depend on a tenant nothing creates)

At `c82c422` the proof suite failed on exactly one proof (`prove_item_attributes.sql`). At `cf58c51` it fails on **four**:

```
FAIL prove_append_only_guard.sql
FAIL prove_item_attributes.sql
FAIL prove_owner.sql
FAIL prove_owner_backup.sql
4 proofs failed
```

Three of those four are the **same defect class the prior review flagged in the integration suite** — a proof that hard-depends on a `mall` tenant that no automated step ever creates. I verified this directly:

```
$ grep -l "slug = 'mall'" sql/prove_*.sql
sql/prove_app.sql
sql/prove_append_only_guard.sql
sql/prove_owner.sql
sql/prove_owner_backup.sql

$ grep -n "mall" bin/prove-suite.php        # (no output)
$ grep -n "mall" bin/provision-platform.php # (no output)
```

Each of the three failing proofs does:

```sql
SELECT id INTO mall_id FROM platform.tenants WHERE slug = 'mall';
IF mall_id IS NULL THEN
    RAISE EXCEPTION 'mall tenant is missing';
END IF;
```

`prove_app.sql` is diverted to a different harness (`bin/prove-suite.php::harnessed()` sends `prove_app.sql` and `prove_tenant.sql` elsewhere), so it does not fail here. But `prove_owner.sql`, `prove_owner_backup.sql`, and `prove_append_only_guard.sql` are **not** diverted, and they run inside the `proofsuite` harness — which provisions a fresh `proofsuite` tenant and **no `mall` tenant**. Nothing in the CI pipeline (`docker compose up -d postgres` → `php bin/provision-platform.php` → `composer check`) seeds a `mall` tenant. On the sandbox's long-lived `consign` database these proofs pass only because a `mall` tenant happens to exist there from earlier manual seeding.

**Consequence:** `composer check` is red in CI on the `prove` gate. This is a **latent CI-red that has existed since the proofs were written**, but it is now three times larger and it is the gate that is supposed to be the project's correctness backstop. A proof that cannot run in CI is not a proof.

**Fix:** the proofs must provision the tenant they need (a `SETUP`/`teardown` that inserts a `mall` row, or a shared fixture in the harness), or `prove-suite.php` must seed a `mall` tenant before running the suite. Depending on ambient database state is exactly the non-hermetic defect the prior review raised for the integration test — and that one _was_ fixed this commit (`SchemaMigratorTest` now uses `ProbeMall::SLUG`). The proofs were left behind.

* * *

## 3\. P0 — `prove_item_attributes.sql` now dies on a foreign key it created itself

The prior review flagged `prove_item_attributes.sql` for an ambiguous-column bug. That specific bug is fixed (the PL/pgSQL variable was renamed `column_name` → `item_column`). But the proof now fails at a **different** point, and this is a **genuine new bug introduced by `048_item_attributes.sql`**:

```
FAIL prove_item_attributes.sql
SQLSTATE[0A000]: Feature not supported: 7 ERROR:  cannot truncate a table referenced in a foreign key constraint
```

The proof loops over the catalog tables and asserts each is append-only by attempting `TRUNCATE` and expecting the append-only trigger to raise `SQLSTATE 25006`:

```sql
BEGIN
    EXECUTE format('TRUNCATE %I', catalog);
    RAISE EXCEPTION '% truncate was accepted', catalog;
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%' || catalog || ' is append-only and cannot be truncated%' THEN
            RAISE;
        END IF;
END;
```

But `048_item_attributes.sql` added:

```sql
ALTER TABLE items
    ADD COLUMN color_id uuid REFERENCES colors (id),
```

So when `catalog = 'colors'`, PostgreSQL raises `0A000` (`cannot truncate a table referenced in a foreign key constraint`) **before** the `BEFORE TRUNCATE` trigger ever fires. I confirmed the ordering with a throwaway repro: Postgres rejects the `TRUNCATE` at parse/plan time on the FK dependency, so the `WHEN SQLSTATE '25006'` handler never matches and the exception propagates out of the block. The proof's own `RAISE EXCEPTION '% truncate was accepted'` is never reached, and the proof aborts.

**This is not a test-only problem.** It is the proof correctly detecting that the append-only invariant for `colors` is no longer independently exercisable via `TRUNCATE` once a child FK exists — but the proof was not updated to account for the new FK. Either the proof must truncate the child first (or use `TRUNCATE ... CASCADE` with the expectation adjusted), or the assertion must be restructured. As written, the proof is broken and the gate is red.

* * *

## 4\. P0 — mutation coverage collapsed: 1 survivor → **80 survivors**

At `c82c422` the mutation harness reported **1 surviving mutant**. At `cf58c51`:

```
Mutants 552, killed 453, equivalent 19, survived 80, errors 0
MUTATE_EXIT=1
```

That is an **80× regression in the quality signal**, and it is concentrated entirely in the code this commit added:

| Survivors | File |
| --: | --- |
| 32 | `src/Feature/Inventory/LabelFace.php` |
| 19 | `src/Feature/Inventory/LabelSheet.php` |
| 9 | `src/Feature/Inventory/BarcodePng.php` |
| 6 | `src/Feature/Inventory/LabelStock.php` |
| 6 | `src/Feature/Inventory/Code128.php` |
| 3 | `src/Feature/Inventory/LabelRequest.php` |
| 2 | `src/Feature/Inventory/InchMeasure.php` |
| 1 | `src/Schema/SqlObjects.php` |
| 1 | `src/Feature/Inventory/StickerFace.php` |
| 1 | `src/Feature/Inventory/LabelCatalog.php` |

I did **not** take the harness's word for it — I verified the survivors are genuine by hand. I mutated `LabelFace.php:35`, flipping the operator in `getCodeHeight() - $stock->getBarMin()` from `-` to `+`, and ran the unit suite:

```
OK (106 tests, 429 assertions)
```

The suite passed. The mutation harness is correct: this code has no behavioral test coverage. A barcode height calculation that silently flips its arithmetic is exactly the class of defect a mutation gate exists to catch, and it is invisible to the current tests.

**Root cause:** the new tests added this commit (`tests/Feature/Inventory/StickerLayoutTest.php`, `tests/Http/CatalogStickerTest.php`) assert only shape, not values — `assertNotSame`, `assertStringStartsWith`, and similar. They confirm that _something_ was produced, not that the _right_ thing was produced. See §6.

**Consequence:** the mutation gate — one of the strongest correctness signals in the suite — is red, and the commit that added the most new logic in months also added the least verification for it. This is the opposite of "no shortcuts, no hacks."

* * *

## 5\. P1 — `test:coverage` is still under the floor (89.51% < 90%)

```
line coverage 89.51% is below 90%
COV_EXIT=1
```

The prior report measured 17.37%, but that number was an artifact of the run erroring out. Now that the suites run cleanly, the true figure is **89.51%** — close, but still below the 90% floor, so `composer test:coverage` exits 1. The newly added `LabelFace`/`LabelSheet`/`BarcodePng`/`Code128` code is the main uncovered mass (consistent with the mutation survivors above). The floor is not met.

* * *

## 6\. P1 — the new sticker/label tests assert shape, not behaviour

The 80 mutation survivors are explained by the tests themselves. `tests/Feature/Inventory/StickerLayoutTest.php` and `tests/Http/CatalogStickerTest.php` were added in this commit and are the only coverage for the new label pipeline, yet they assert almost nothing about the computed output:

-   `assertNotSame` (two things differ)
-   `assertStringStartsWith` (a prefix matches)
-   presence/absence of a key

None of these pin the _value_ of a bar height, a code width, a page position, or an encoded barcode. A test suite that cannot distinguish `getCodeHeight() - $stock->getBarMin()` from `getCodeHeight() + $stock->getBarMin()` is not testing the layout engine; it is testing that the layout engine returns an array. For a system that prints physical price stickers a mall hands to customers, the exact geometry is the product. This is a world-class-bar failure, not a nitpick.

* * *

## 7\. P1 — the re-baseline recovery path still has a hole (`stampPresent()` only runs when the table is absent)

This commit added `TenantMigrator::stampPresent()`, which is meant to stamp tables that already exist so a partially-migrated tenant can be re-baselined. It is a real improvement — but it is **only reached when `schema_migrations` is missing**:

```php
if (! $this->record->ready($schema)) {   // i.e. schema_migrations table does not exist
    $this->baselineTenant(...);
    $this->stampPresent($slug, $schema);
}
```

I confirmed the residual gap empirically. The sandbox's stale `webprobe` tenant has `colors` present (from `048_item_attributes.sql`) but `048`/`049` **not** stamped, and it _does_ have a `schema_migrations` table (37 rows, max `044`). Because `ready()` is true, `stampPresent()` is skipped, `pendingTenant()` re-applies `048`, and the integration suite dies with:

```
relation "colors" already exists   (×13)
```

This does **not** break the CI path (CI starts from a fresh database), so it is not the P0 the prior review's lock leak was. But it is exactly the failure mode `stampPresent()` was written to prevent, and it does not prevent it for the case that actually occurs in the field: a tenant that already has the post-baseline objects _and_ a migrations table. The recovery path needs to run `stampPresent()` (or an equivalent presence check) whenever a pending file's objects are already present — not only when the whole migrations table is absent.

* * *

## 8\. P1 — governance rules that institutionalise shipping unverified code are still in the repo

Four `.cursor/rules/*.mdc` files remain, unchanged:

```
.cursor/rules/do-not-run-tests.mdc
.cursor/rules/git-do-not-mention-idea-leftovers.mdc
.cursor/rules/maintain-proven-level.mdc
.cursor/rules/prove-before-advance.mdc
```

`do-not-run-tests.mdc` (`alwaysApply: true`) forbids running the tests, proofs, and `composer check` unless the user explicitly asks. `git-do-not-mention-idea-leftovers.mdc` instructs the agent to keep certain information out of commit messages. These are the mechanism by which red gates go unnoticed between reviews: the agent is _instructed not to run the gates_. A world-class engineering process does not ship a rule that suppresses its own verification. The commit that fixed the visible red gates left the rule that hides them in place.

* * *

## 9\. What was fixed (for the record — no action needed)

So the delta is unambiguous, these prior findings are **genuinely resolved** and I am withdrawing them:

-   **P0 advisory-lock leak — FIXED.** `SchemaMigrator::migratePlatform()`, `TenantMigrator::bringCurrent()`, and `TenantMigrator::storeRecord()` now wrap their work in `try { … } finally { $this->record->releaseMigration(); }`. The lock is released on every exit path. This was the single most serious defect in the prior report and it is closed.
-   **`check:max-lines` — FIXED.** `register-ui.php` was split (`register-names.php` extracted); all `src` files are ≤ 500 lines.
-   **`phpstan` level 10 — FIXED.** `[OK] No errors` (the `instanceof.alwaysTrue` and `binaryOp.invalid` errors are resolved).
-   **`check:phpmd` — FIXED.** Exit 0; the 9 violations are gone.
-   **Non-hermetic `mall` integration test — FIXED.** `tests/Integration/Schema/SchemaMigratorTest.php` now uses `ProbeMall::SLUG` instead of the literal `'mall'`. (The _proofs_ still have the same defect — see §2.)
-   **`prove_item_attributes.sql` ambiguous-column bug — FIXED** (renamed to `item_column`, qualified `listed.`), though the proof now fails for a new reason (§3).

* * *

## 10\. Recommended order of repair

1.  **`prove` gate (§2, §3).** Seed the `mall` tenant inside the proof harness (or make each proof self-provisioning), and fix `prove_item_attributes.sql` so the `colors` append-only assertion accounts for the new `items.color_id` FK. Until this is green in CI, the correctness backstop does not exist.
2.  **Mutation survivors (§4).** Add value-pinning tests for `LabelFace`, `LabelSheet`, `BarcodePng`, `Code128`, `LabelStock`, `LabelRequest`, `InchMeasure`. Assert exact geometry and exact encoded bytes, not shapes. Re-run `bin/mutate.php` until the survivor count returns to the single digits.
3.  **Coverage (§5).** Drive line coverage back over 90% by covering the same label code.
4.  **Re-baseline hole (§7).** Run the presence check whenever a pending file's objects already exist, not only when `schema_migrations` is absent.
5.  **Governance rules (§8).** Remove `do-not-run-tests.mdc` and `git-do-not-mention-idea-leftovers.mdc`. A pipeline that is forbidden from running itself is not a pipeline.

* * *

_Report generated against `cf58c51b59f23c45eb1a517ca6538aebb14e72df`. All gate results were produced in this sandbox on the dates shown; DB-dependent gates were run against freshly created databases to remove the effect of stale tenants in the long-lived local `consign` database._