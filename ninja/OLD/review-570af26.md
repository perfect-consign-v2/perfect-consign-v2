# Adversarial review — `perfect-consign-v2` @ `570af26`

**Repo:** `perfect-consign-v2/perfect-consign-v2` (branch `master`) **Commit reviewed:** `570af264e6aaaaa767808cf617bf428fb8a6cb6e` (2026-10-05 22:48:25 -0700) **Prior baseline:** `2c8d512` (report `review-2c8d512.md`) **New commits since baseline:** `aba60e3` (migration system), `570af26` (commit my prior report) **Method:** fresh clone, full gate re-run (`composer check` equivalent run by hand, since Composer is not installed), plus a _fresh-database_ reproduction of the CI provisioning path. **Scope of this document:** only what is wrong or below a world-class enterprise bar. Correct behaviour is deliberately not catalogued.

* * *

## 0\. Verdict

`composer check` is **RED**, and `composer test:coverage` is **RED**. The single reason `composer check` fails is a **new, non-hermetic integration test** that depends on a `mall` tenant which the CI pipeline never creates. The migration system that `aba60e3` introduces is a genuine, well-built improvement, but the test that is supposed to prove it cannot pass in CI, and the new code it exercises is consequently almost uncovered.

| Gate | Result |
| --- | --- |
| `check:max-lines` (500) | ✅ OK |
| `check:phpmd` | ✅ OK (exit 0) |
| `phpstan` (level 10) | ✅ OK — `[OK] No errors` |
| `test` (unit) | ✅ GREEN — 102 tests, 390 assertions |
| `test:integration` | ❌ **RED** — 11 tests, 1 error |
| `test:http` | ✅ GREEN — 40 tests, 577 assertions |
| `prove:obligations` | ✅ OK |
| `prove:race` | ✅ OK |
| `prove` (`prove-suite.php`) | ✅ OK |
| `prove:tenant` | ✅ OK |
| `test:mutate` | ✅ GREEN — 410 mutants, 391 killed, 19 equivalent, **0 survived** |
| `test:coverage` | ❌ **RED** — line coverage **88.35%** (floor 90%) |

Because `composer check` fails, the CI workflow (`.github/workflows/check.yml`) stops before `composer test:coverage` ever runs. So the build is red on the first failing step, and the coverage failure is latent behind it.

* * *

## 1\. Findings still open

### P0-1 — `test:integration` is red in CI; the build is broken

`tests/Integration/Schema/SchemaMigratorTest.php:64` calls:

```php
$migrator->migrateTenant('mall');
```

`mall` is the demo tenant. It is created **only** by `bin/seed-mall.php`, a manual developer script (`bin/seed-mall.php:45` → `$provisioner->provision('mall', 'Mall')`). Nothing in the automated pipeline creates it:

-   `.github/workflows/check.yml` runs `docker compose up -d postgres`, then `php bin/provision-platform.php`, then `composer check`. `provision-platform.php` only calls `SchemaMigrator::migratePlatform()` — it never creates a tenant.
-   `tests/Support/ProbeMall::ensure()` provisions the `webprobe` tenant, not `mall`.
-   No other integration test provisions `mall`; there is no `setUp`/`setUpBeforeClass` that does.
-   `composer.json` has no `post-install-cmd` / `post-autoload-dump` lifecycle hook that could seed it.

**Reproduction (exactly the CI path).** I created an empty database, ran only `php bin/provision-platform.php` against it, and ran the integration suite:

```
$ createdb consign_fresh
$ STALL_DB_NAME=consign_fresh php bin/provision-platform.php
platform current
$ STALL_DB_NAME=consign_fresh php vendor/bin/phpunit --testsuite integration
...
1) SchemaMigratorTest::testAPlatformInstallRollsBackAndATenantBaselineStamps
   RuntimeException: tenant mall is not provisioned
   src/Schema/TenantMigrator.php:46
   tests/Integration/Schema/SchemaMigratorTest.php:64
ERRORS! Tests: 11, Assertions: 92, Errors: 1.
```

On the shared sandbox database it fails differently but for the same root cause — `mall` there predates the closed-payout-window trigger, so the baseline sentinel refuses it:

```
PerfectConsign\Schema\TenantBaselineMismatch:
  tenant mall does not match the closed payout window and was not stamped
```

So the test passes **only** on a developer machine where `seed-mall.php` has previously been run (which stamps `mall`'s `schema_migrations`, so the test never even reaches the baseline branch it is named for). It cannot pass in CI. This is a classic "green on my machine, red in CI" defect, and it means the whole `composer check` gate is not actually green.

**What a world-class version looks like:** the test must provision its own fixture. It should create a throwaway tenant (or wipe + re-provision `webprobe`) through the same code path a real deployment uses, assert the baseline stamping on _that_ tenant, and drop it afterwards — never depend on a slug that only a manual script creates. `ProbeMall` already knows how to provision and to recover from `TenantBaselineMismatch`; the test should reuse that instead of hard-coding `'mall'`.

### P1-2 — Coverage is below the floor (88.35% < 90%); the new migration code is largely untested

`composer test:coverage` exits non-zero:

```
Lines: 10768/12188 (88.35%)
line coverage 88.35% is below 90%
```

This is not a new regression in absolute terms — the prior review measured 88.87% at `2c8d512` — but it is still red, and `aba60e3` made it worse by adding a large block of new `src/Schema/*` code that is barely exercised. The worst-covered files:

| File | Coverage |
| --- | --- |
| `src/Schema/SchemaMigrator.php` | **20.8%** (11/53) |
| `src/Schema/TenantMigrator.php` | 68.8% (55/80) |
| `src/Feature/Backup/BackupWorkspace.php` | 25.0% |
| `src/Feature/Backup/TenantBackup.php` | 31.9% |
| `src/Feature/Payout/envelope.php` | 18.2% |
| `src/Feature/Backup/BackupController.php` | 40.5% |
| `src/Feature/Backup/PlatformSnapshot.php` | 50.0% |

`SchemaMigrator.php` at 20.8% is the direct consequence of P0-1: the one integration test that drives `migratePlatform()` / `installPlatform()` / `finishPlatform()` throws at its fourth line, so none of that code is executed. Fixing P0-1 will lift this materially, but the underlying shortfall (a whole feature — backup — sitting in the 25–50% band) is real and independent. The floor is enforced but not met; the suite is not at the bar it claims.

### P1-3 — The repository still ships a rule that tells the agent not to run the checks

`.cursor/rules/do-not-run-tests.mdc` is still present and still `alwaysApply: true`:

> Do not run tests, proofs, or check commands unless this message asks for that run. This overrides `maintain-proven-level` and `prove-before-advance`.
> 
> -   Do not run `composer check`, `composer test`, PHPUnit, `php bin/prove-suite.php`, `prove:race`, `prove:obligations`, `prove:tenant`, `test:mutate`, or `sql/prove_*.sql`.

This is the same governance anti-pattern flagged at `2c8d512`. It is the reason a broken build can be committed with a message that says "bring the checks back to green": the agent is instructed _not_ to verify. A world-class repository does the opposite — it makes the checks cheap to run and expects them to run before every commit. This file should be deleted, and `maintain-proven-level.mdc` / `prove-before-advance.mdc` should stand on their own.

### P2-4 — A rule instructs the agent to hide information from the user

`.cursor/rules/git-do-not-mention-idea-leftovers.mdc` (`alwaysApply: true`) tells the agent never to mention `.idea` files in any reply, commit summary, or status report. This is minor in impact but wrong in principle: an engineering tool should not be instructed to conceal repository state from the operator. Untracked files are exactly the kind of thing a status report exists to surface. Recommend removing it (adding `.idea/` to `.gitignore` is the correct fix if the goal is to keep IDE files out of commits).

### P2-5 — Migration system: design weaknesses worth recording (not blockers)

The new `src/Schema/*` machinery is sound in its core idea (record applied files + sha256, refuse changed bytes, baseline pre-existing schemas, forward-only). Four points keep it short of world-class:

1.  **The baseline sentinel is a single heuristic.** `TenantMigrator::baselineTenant()` decides a pre-existing tenant is "current" by checking for exactly one non-deferrable trigger (`journals_closed_payout_window` on `journals`). If a legacy tenant happens to carry that one trigger but is missing objects from other files (`042`, `043`, `044`, …), the migrator stamps every file ≤ `044` as applied **without running any of them**, and `pendingTenant()` then sees nothing to do. The tenant is silently declared current while remaining broken. A sentinel is a reasonable _fast path_, but "we could not prove the baseline" should be a hard stop (as `TenantBaselineMismatch` already is), not a silent stamp when the sentinel happens to be present.
2.  **No concurrency guard.** `bin/migrate.php tenant-all` iterates tenants and applies files with no advisory lock. Two concurrent migrators (or a migrator racing a deploy) can interleave `schema_migrations` inserts. `prove:race` now takes an advisory lock for the race proof; the migrator itself does not.
3.  **Forward-only, no down path.** Acceptable for this product, but it should be stated explicitly in the schema docs rather than left implicit.
4.  **`MigrationRecord::relation()` resolves the tenant table through `search_path`.** It returns the unqualified name `schema_migrations` for tenants and relies on the caller having run `SET search_path`. That is correct today because `bringCurrent()`/`storeRecord()` set the path first, but it is a latent foot-gun: any future caller that forgets the `search_path` write will read or write the wrong table. Qualifying with the schema name would remove the class of bug entirely.

* * *

## 2\. What `aba60e3` + `570af26` fixed (verified, for the record)

These were open at `2c8d512` and are now genuinely closed — I re-ran each against `570af26`:

-   **Migration system exists (was P0-A).** `src/Schema/{SchemaCatalog,SchemaMigrator,TenantMigrator,MigrationRecord,TenantBaselineMismatch}.php`, `sql/044_schema_migrations.sql`, `sql/047_platform_schema_migrations.sql`, and `bin/migrate.php` are a real, checksummed, forward-only migrator. `provision-platform.php`/`provision-tenant.php` were rewritten to go through it, and the hard-coded tenant file list was replaced by `SchemaCatalog::tenantFiles()` (a glob that correctly adds only `044_schema_migrations.sql` over the old list; the old gaps `025/035/036/039` are non-existent files). This is a large, correct improvement.
-   **`test:http` lock-test idempotency (was P0-B/1).** `ReviewCoverageTest::testFiveBadPasswordsLockTheUsername` now uses a random username and cleans up via `ProbeMall::forgetSignIns()` in a `finally`. The HTTP suite is green and re-runnable.
-   **Mutation survivor (was P0-B/2).** `bin/mutate.php` now reports **410 mutants, 0 survived** (floor 313). The `PostgresTools.php` survivor is gone.
-   **Source-grep padding test (was P1-C).** `BackupConnectionTest` no longer reads `src/` files with `file_get_contents()` and asserts on substrings; the live-DB `SELECT 1` was removed from the unit suite too (was P2-A).
-   **`proc_open` warning (was P2-B).** `CommandProcess` now resolves the binary on `PATH` (`present()`/`filePresent()`/`extensions()`) before `proc_open`, so a missing tool throws `BackupException` cleanly instead of emitting a PHP warning. Side effect: the unit suite is now green in the sandbox **without** any docker stub (102 tests, 390 assertions).
-   **Duplicate message (was P2-C).** `PartyController::refuseInactive()` and `vendor-form.php` now carry the same sentence.
-   **`prove:race` shared-tenant wipe (was P2-F).** `bin/prove-race.php` takes `pg_try_advisory_lock(814202614)` so concurrent runs fail fast instead of clobbering the shared tenant.

* * *

## 3\. Reproduction appendix

```bash
# 1. Static + fast gates (Composer is not installed; run the scripts directly)
php bin/check-max-lines.php 500          # OK
php vendor/bin/phpstan analyse --memory-limit=512M   # [OK] No errors
php -d error_reporting="E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED" \
    vendor/bin/phpmd src text phpmd.xml  # exit 0

# 2. Unit / HTTP / proofs
php vendor/bin/phpunit --testsuite unit        # OK (102, 390)
php vendor/bin/phpunit --testsuite http        # OK (40, 577)
php bin/prove-obligations.php                  # ok obligations
php bin/prove-race.php                         # ok race
php bin/prove-suite.php                        # ok (all sql/prove_*.sql)
php bin/prove-tenant.php                       # exit 0

# 3. Mutation
php bin/mutate.php                             # 410 mutants, 0 survived

# 4. The CI break — integration on a fresh DB (the CI path)
createdb consign_fresh
STALL_DB_NAME=consign_fresh php bin/provision-platform.php
STALL_DB_NAME=consign_fresh php vendor/bin/phpunit --testsuite integration
#   -> RuntimeException: tenant mall is not provisioned  (SchemaMigratorTest.php:64)

# 5. Coverage
php bin/coverage-run.php                       # 88.35% < 90% -> exit 2
```

* * *

## 4\. Bottom line

`aba60e3` is the right idea executed well: a real migration system that closes the biggest structural gap in the codebase. But the commit is not actually green. Its own proof — `SchemaMigratorTest` — cannot run in CI because it borrows a `mall` tenant that only a manual script creates, so `composer check` is red, the new `SchemaMigrator` code sits at 20.8% coverage, and `test:coverage` is red behind it. Fix the fixture (make the test provision and tear down its own tenant), lift the backup-feature coverage, and delete the `do-not-run-tests` / `do-not-mention-idea` rules, and this would be a genuinely enterprise-grade drop. As it stands, the build does not pass.