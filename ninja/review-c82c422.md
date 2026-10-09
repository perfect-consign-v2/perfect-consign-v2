# Hostile review — `perfect-consign-v2` @ `c82c422`

**Scope:** fresh pull of `master`, re-run every gate, re-review the new commits, update the prior report (`review-570af26.md`). **HEAD reviewed:** `c82c422` ("Keep the review of the migration test with the other reviews."). **New commits since the last review (`570af26`):**

| Commit | Subject |
| --- | --- |
| `b2b162f` | Refuse a second migration and do not stamp a tenant that is missing an earlier table. |
| `84cde53` | Record color, category, size, condition, and department on a piece, and which stickers the mall prints. |
| `e5089cc` | Offer those fields at intake and the register, and print the stickers the mall chose. |
| `c82c422` | Keep the review of the migration test with the other reviews. |

**Method:** PostgreSQL 18.6 on `127.0.0.1:5436` (db `consign`, user `stall`), PHP 8.4.26, PHPUnit 13.3.5, Xdebug 3.5.3. Every gate was run against a clean working tree at `c82c422`. Docker is not available in this sandbox, so the DB was driven directly (the repo's own `bin/` scripts and `psql`). Where a gate is order- or state-dependent, both the fresh-database and steady-state results are reported.

**This report lists only what is wrong or below a world-class enterprise bar.** Everything that is correct is deliberately omitted.

* * *

## 1\. Verdict

`composer check` is **red on six of its ten gates**, and `composer test:coverage` is red. In a **fresh database (i.e. CI)** the integration suite fails **12 of 13 tests**, the HTTP suite cannot provision its tenant, and the coverage run cannot complete. Two of the failures are new regressions introduced by the commits above; the rest are carried over.

| Gate | Result | Headline |
| --- | --- | --- |
| `check:max-lines` | **RED** | `src/Feature/Pos/register-ui.php` = 587 lines (> 500) |
| `check:phpmd` | **RED** (regression) | 9 violations; **clean (exit 0) at `570af26`** |
| `phpstan` (level 10) | **RED** | 8 errors |
| `test` (unit) | GREEN | 102 tests, 393 assertions |
| `test:integration` | **RED** | 12/13 errors fresh DB; 3 errors steady-state |
| `test:http` | GREEN steady-state / **RED** fresh DB | 40 tests, 577 assertions when the tenant exists |
| `prove:obligations` | GREEN | — |
| `prove:race` | **RED** under concurrency | passes alone; collides with any second migration process |
| `prove` (suite) | **RED** | `prove_item_attributes.sql` |
| `prove:tenant` | GREEN | — |
| `test:mutate` | **RED** | 416 mutants, 396 killed, 19 equivalent, **1 survived** |
| `test:coverage` | **RED** | cannot complete; 9 errors; measured 17.37% |

Because `check:max-lines` is the first script in the `composer check` chain and exits 1, and `check:phpmd` exits 2, **CI stops before it ever reaches the database.** The red DB gates below are therefore latent on top of an already-red pipeline.

* * *

## 2\. P0 — the migration advisory lock is acquired and never released

**This is the most serious new defect. It was introduced by `b2b162f`.**

`src/Schema/MigrationRecord.php` adds a session-scoped Postgres advisory lock:

```php
private const LOCK = 814202615;

public function holdMigration(): void
{
    $query = $this->pdo->query('SELECT pg_try_advisory_lock(' . self::LOCK . ')');
    $value = $query === false ? false : $query->fetchColumn();
    if ($value !== true && $value !== 't' && $value !== 1 && $value !== '1') {
        throw new RuntimeException('a migration is already running');
    }
}

public function releaseMigration(): void
{
    $this->pdo->query('SELECT pg_advisory_unlock(' . self::LOCK . ')');
}
```

`holdMigration()` is called from three production entry points:

-   `SchemaMigrator::migratePlatform()` — line 24
-   `TenantMigrator::bringCurrent()` — line 22
-   `TenantMigrator::storeRecord()` — line 34

`releaseMigration()` is called **exactly once in the whole repository**, and only in a test:

```
$ grep -rn "releaseMigration" src bin tests
tests/Integration/Schema/SchemaMigratorTest.php:153:            $record->releaseMigration();
```

**No production code path ever releases the lock.** The lock is session-scoped, so it is held until the PDO connection is destroyed. This is not a critical-section guard; it is a permanent lock on the connection.

I verified the semantics directly:

-   `pg_try_advisory_lock` is **re-entrant within a session** — acquiring it three times on one connection returns `true` each time; a _different_ connection gets `false`.
-   The lock is freed only when the holding connection closes (confirmed with a two-connection probe).

### Consequence 1 — tenant provisioning is impossible in a fresh database (deterministic)

`tests/Support/ProbeMall.php::ensure()` (used by the integration, HTTP, and coverage suites) does this:

```php
$migrator = new SchemaMigrator(self::admin(), new SchemaCatalog($root . '/sql')); // connection A
$migrator->migratePlatform();                                                     // acquires LOCK on A
if (!self::exists()) {
    self::run($root, 'bin/provision-tenant.php', [self::SLUG, self::DISPLAY_NAME]); // subprocess -> connection B
}
```

`migratePlatform()` takes the lock on connection A. `ensure()` then spawns `bin/provision-tenant.php` **as a subprocess**; that subprocess reaches `recordTenant()` → `storeRecord()` → `holdMigration()` on **connection B** and is refused:

```
RuntimeException: bin/provision-tenant.php failed: a migration is already running
  tests/Support/ProbeMall.php:139
  tests/Support/ProbeMall.php:41
```

The tenant is never created, so **every** test that calls `ensure()` fails. Against a fresh database:

```
=== INTEGRATION (fresh webprobe) ===
Tests: 13, Assertions: 2, Errors: 12.
```

and the HTTP suite and the coverage run fail the same way (`Tests: 121, Errors: 18`).

**Proof that the lock is the cause:** the identical operation succeeds when run as a single process with no prior lock holder —

```
$ php bin/provision-tenant.php webprobe "Web Probe"
tenant_01a117937a2074009a9308910be3fe84          # exit 0
```

and a fresh provision correctly stamps all 39 migration files including `048`/`049`. So provisioning itself is fine; it is the _held lock_ that breaks it.

### Consequence 2 — two migration processes cannot run concurrently

`bin/prove-race.php` and `bin/prove-suite.php` each migrate. Run them in parallel (as I did while gathering evidence) and the race proof dies:

```
$ php bin/prove-race.php          # run concurrently with prove-suite
race tenant was not provisioned
RuntimeException: a migration is already running
  src/Schema/MigrationRecord.php:25
  src/Schema/SchemaMigrator.php:43
  bin/provision-tenant.php:94
  bin/provision-tenant.php:61
  bin/prepare-race-tenant.php:42
```

Run alone it passes. This is a genuine nondeterminism: the gate's outcome depends on whether any other migration is in flight.

### Consequence 3 — the lock leaks across test methods in one process

With a tenant already present, `ProbeMall::ensure()` no longer spawns the provisioning subprocess, so the fresh-DB failure disappears — but the integration suite is still red (3 errors), because the lock taken by an earlier `SchemaMigratorTest` method is still held when the next method runs:

```
1) SchemaMigratorTest::testAPlatformInstallRollsBackAndATenantBaselineStamps
   RuntimeException: a migration is already running
   src/Schema/MigrationRecord.php:25 -> SchemaMigrator.php:24 -> ProbeMall.php:39
2) SchemaMigratorTest::testAMissingBaselineObjectIsNotStamped   (same)
3) SchemaMigratorTest::testASecondMigrationIsRefused            (same)
```

The same sequence run outside PHPUnit passes, so the leak is specific to the shared test process — which is exactly where a lock that is never released does its damage.

### Why this is a regression

At `570af26` there was no advisory lock at all. `b2b162f` added it to "refuse a second migration", and the guard is correct in spirit but incomplete: it acquires and never releases. A correct implementation wraps the migration in `try { holdMigration(); ... } finally { releaseMigration(); }` (or uses `pg_advisory_xact_lock` inside the migration transaction), so the lock protects the critical section rather than the connection's lifetime.

* * *

## 3\. P0 — post-baseline migrations are not idempotent, so the baseline path is broken

**Introduced by `84cde53`/`e5089cc`.**

`048_item_attributes.sql` creates its tables with bare `CREATE TABLE`:

```
sql/048_item_attributes.sql:4:  CREATE TABLE colors (
sql/048_item_attributes.sql:23: CREATE TABLE categories (
sql/048_item_attributes.sql:42: CREATE TABLE sizes (
sql/048_item_attributes.sql:61: CREATE TABLE conditions (
sql/048_item_attributes.sql:80: CREATE TABLE departments (
```

No `IF NOT EXISTS` anywhere in `048` or `049`.

The migration system's baseline path assumes a tenant that _predates_ the migration system, so it stamps only files up to `044` and then re-applies everything newer:

-   `TenantMigrator::baselineTenant()` stamps `tenantBaseline()` = every tenant file `<= 044_schema_migrations.sql`.
-   `TenantMigrator::pendingTenant()` then runs every tenant file `> 044`.

At `570af26` the only file past `044` was `047_platform_schema_migrations.sql`, which is a **platform** file (`SchemaCatalog::PLATFORM`) and therefore excluded from `tenantFiles()`. So `pendingTenant()` had nothing to re-run and the path worked. `048`/`049` are the first **tenant** files past the baseline, and they are not idempotent, so re-running them fails:

```
RuntimeException: 048_item_attributes.sql was not applied:
  SQLSTATE[42P07]: Duplicate table: 7 ERROR: relation "colors" already exists
```

This is reproducible even with `--process-isolation` (which removes the lock leak entirely), so it is an independent defect:

```
=== INTEGRATION with process isolation ===
Tests: 13, Assertions: 97, Errors: 3.     # all: relation "colors" already exists
```

The failing test is `SchemaMigratorTest::testAPlatformInstallRollsBackAndATenantBaselineStamps`, which deliberately drops `schema_migrations` and expects `migrateTenant()` to re-baseline. `requireBaseline()` only checks that the _pre-`044`_ objects are **present**; it never checks that post-baseline objects are **absent**, so a tenant that already carries `048`/`049` objects sails past the baseline check and then dies re-running `048`.

**Net effect:** the migration system cannot recover any tenant that has an object from a migration newer than `044` but has lost its `schema_migrations` row — precisely the failure mode a migration system exists to survive. Either the new migrations need idempotency guards (`CREATE TABLE IF NOT EXISTS`), or the baseline point must advance to the newest file whose objects are all present.

* * *

## 4\. P0 — `check:max-lines` is red

```
PHP files exceeding 500 lines:
  587 lines  src/Feature/Pos/register-ui.php
```

`e5089cc` grew `register-ui.php` by 111 lines to 587. The repo's own `bin/check-max-lines.php 500` exits 1, and it is the **first** script in `composer check`, so the entire pipeline stops here.

* * *

## 5\. P0 — `phpstan` (level 10) is red — 8 errors

```
Feature/Inventory/InventoryStore.php
  178    Instanceof between DatabaseRefusal and DatabaseRefusal will always evaluate to true.
         instanceof.alwaysTrue

Feature/Mall/settings.php
  112    Argument of an invalid type mixed supplied for foreach, only iterables are supported.
         foreach.nonIterable
  112    Variable $stocks might not be defined.                variable.undefined
  113    Cannot access offset 'key' on mixed.                  offsetAccess.nonOffsetAccessible  (x2)
  113    Cannot access offset 'label' on mixed.                offsetAccess.nonOffsetAccessible
  113    Variable $labelStock might not be defined.            variable.undefined

Shared/Web/layout.php
  126    Binary operation "." between non-falsy-string and mixed results in an error.
         binaryOp.invalid
```

-   **`InventoryStore.php:178`** — `if (!$refusal instanceof DatabaseRefusal)` is dead per PHPStan (the variable is already narrowed to `DatabaseRefusal`), so the `RuntimeException('A barcode could not be generated.')` branch is unreachable as analysed. Either the guard is wrong or the type is.
-   **`Mall/settings.php:112-113`** — the template iterates `$stocks` and reads `$labelStock`, but neither is declared in the template's `@var` block (lines 7-20). At runtime `MallController::form()` _does_ pass both (`'stocks' => $this->stocks->choices()`, `'labelStock' => ...`), so this is a missing annotation rather than a live bug — but the level-10 gate is red all the same, and the template is the only place in the codebase that reaches into `mixed`.
-   **`layout.php:126`** — `$href = '/' . $slug . $link[0];` where `$link` is `mixed` because `$groups`/`$links` are not typed. `e5089cc` touched this file and left the annotation gap.

* * *

## 6\. P0 — `check:phpmd` is red (regression)

`phpmd` exits **0** at `570af26` and **2** at `c82c422` (verified with the exact `check:phpmd` command, including its `error_reporting` flag). Nine violations, all in code added or grown by the new commits:

```
src/Bootstrap/MallApp.php:17                     CouplingBetweenObjects  13
src/Bootstrap/MallApp.php:17                     ExcessiveClassComplexity 51
src/Feature/Inventory/InventoryController.php:14 CouplingBetweenObjects  13
src/Feature/Inventory/InventoryController.php:14 ExcessiveClassComplexity 52
src/Feature/Inventory/InventoryStore.php:14      ExcessiveClassComplexity 54
src/Feature/Inventory/PieceEntry.php:9           ExcessiveParameterList  10 params
src/Feature/Mall/MallSettings.php:99             ExcessiveParameterList  14 params
src/Feature/Pos/InstantAdd.php:9                 ExcessiveParameterList  10 params
src/Feature/Pos/PosPage.php:15                   ExcessiveParameterList  10 params
```

The repo's own ruleset header says: _"Append rules only after `composer run probe:phpmd` reports zero violations under `src/`."_ The new feature commits violate five of the enabled rules. `MallSettings::save` at 14 parameters (and `MallController::store` passing all 14 positionally) is the clearest design smell — a settings write should take a value object, not a 14-argument positional call.

* * *

## 7\. P0 — the `prove` suite is red

```
FAIL prove_item_attributes.sql
SQLSTATE[42702]: Ambiguous column: 7 ERROR: column reference "column_name" is ambiguous
1 proofs failed
```

`sql/prove_item_attributes.sql` declares a PL/pgSQL variable that shadows a column of the table it queries:

```
line 15:    column_name text;                                   -- PL/pgSQL variable
line 29:    OR column_name ~* '(amount|balance|minor|rate|price|total)'
                -- inside a SELECT ... FROM information_schema.columns,
                -- which also has column_name  ->  42702 ambiguous
```

The new proof — the one written to prove the new attribute columns — does not run at all. This is the exact class of defect the proof suite exists to catch, and it shipped broken.

* * *

## 8\. P1 — `test:mutate` is red (1 survivor)

```
Mutants 416, killed 396, equivalent 19, survived 1, errors 0
SURVIVED src\Schema\SchemaCatalog.php:137 !== -> ===
```

Line 137 is inside the new `created()` parser that `baselineObjects()` depends on:

```php
$qualifier = $match[1];
if ($qualifier !== '' && strtolower($qualifier) !== 'public') {   // line 137
    continue;
}
$found[] = strtolower($match[2]);
```

The survivor means no unit test distinguishes the correct qualifier handling from a mutated one — i.e. the branch that decides whether a schema-qualified `CREATE` counts toward the baseline is untested. Since `baselineObjects()` is what decides whether a tenant gets stamped, an untested branch here is a real gap, not a cosmetic one. `tests/Shared/SchemaCatalogTest.php` was touched by this change but does not cover the qualified-name case.

* * *

## 9\. P1 — `test:coverage` is red and cannot be measured

`bin/coverage-run.php` aggregates `unit,integration,http` and then enforces a 90% line floor. Because the integration and HTTP suites fail (sections 2 and 3), the run cannot produce a valid number:

```
Lines: 2361/13590 (17.37%)
Tests: 121, Assertions: 490, Errors: 9.
COV_EXIT=2
```

The 17.37% is an artefact of the errors (whole files such as `register-ui.php`, `pay-ui.php`, `pos.php`, `MallController.php`, `InventoryController.php` show 0/… because their tests never ran). The previous review measured 88.35% at `570af26`; the floor of 90% was already missed then and is unmeasurable now.

* * *

## 10\. Governance rules that institutionalise shipping unverified code

These are carried over from the prior review and are **still present** in `.cursor/rules/`.

### 10.1 `do-not-run-tests.mdc` — forbids running the gates

```
description: Do not run tests, proofs, or composer check unless the user asks in this message.
alwaysApply: true
...
Do not run composer check, composer test, PHPUnit, php bin/prove-suite.php, prove:race,
prove:obligations, prove:tenant, test:mutate, or sql/prove_*.sql.
```

This is the root cause of the whole report. `maintain-proven-level.mdc` and `prove-before-advance.mdc` both define "done" as _"`composer check` exits 0"_ — but `do-not-run-tests.mdc` forbids running `composer check` unless the user asks. The two rules together mean the agent can edit `src/` and `sql/`, declare the work finished, and **never once observe that six gates are red**. That is exactly the state this repository is in. A rule that says "never verify" cannot coexist with a rule that says "done means verified"; one of them has to go, and it should not be the one that runs the tests.

### 10.2 `git-do-not-mention-idea-leftovers.mdc` — instructs the agent to conceal information

```
description: Never mention JetBrains .idea files to the user.
...
Do not tell the user about .idea files. Not in a commit summary, a status report,
a leftover list, or any other reply.
```

A standing instruction to hide a class of local changes from the user is not a world-class engineering practice; it is a transparency defect. Report the working tree honestly.

* * *

## 11\. What is still green (for the record)

-   Unit suite: 102 tests, 393 assertions.
-   HTTP suite: 40 tests, 577 assertions (when the tenant already exists).
-   `prove:obligations`, `prove:tenant`.
-   `prove:race` when run in isolation.
-   The Code 128 implementation (`src/Feature/Inventory/Code128.php`): 107 patterns, correct lengths (11 modules, 13 for the stop code), correct Code-B mapping and checksum; `BarcodePng` emits a well-formed 1-bit greyscale PNG (IHDR/IDAT/IEND, CRC-32B). No defect found here.
-   `NameListStore` builds SQL from the fixed `NameList::all()` whitelist (not user input), and its `strpos(...) > 0` is PostgreSQL's 1-based `strpos`, not PHP's — so the search predicate is correct.

* * *

## 12\. Recommended order of repair

1.  **Release the migration lock.** Wrap each migration in `try/finally { releaseMigration(); }`, or move to `pg_advisory_xact_lock` inside the migration transaction. This alone clears the fresh-DB integration failure, the HTTP failure, the coverage failure, and the race flakiness.
2.  **Make `048`/`049` idempotent** (`CREATE TABLE IF NOT EXISTS`) or advance the baseline point so re-baselining never re-runs an already-applied file. This clears the steady-state integration failure.
3.  **Fix `prove_item_attributes.sql`** — rename the PL/pgSQL variable (e.g. `target_column`) so it does not shadow `information_schema.columns.column_name`.
4.  **Split `register-ui.php`** below 500 lines.
5.  **Clear the 8 PHPStan errors** (declare `$stocks`/`$labelStock` in the settings template; type `$groups`/`$links` in `layout.php`; resolve the dead `instanceof` in `InventoryStore`).
6.  **Clear the 9 PHPMD violations** (introduce a settings value object; split `MallApp`/`InventoryController`/`InventoryStore`).
7.  **Kill the mutation survivor** with a `SchemaCatalogTest` case that exercises a schema-qualified `CREATE`.
8.  **Retire `do-not-run-tests.mdc`** and `git-do-not-mention-idea-leftovers.mdc`.

Until 1 and 2 are fixed, the pipeline is red before it reaches the database, and the database gates are red after.