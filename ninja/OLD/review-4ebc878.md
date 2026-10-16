# Adversarial review — `perfect-consign-v2` @ `4ebc878`

**Commit:** `4ebc878f0a67cf45d3cf38c0eda0a29cc056a848` **Subject:** "Refuse a mutation run that finds fewer mutants than the last complete run, including a run that finds none." **Author:** Kevin Rubio (co-authored by Cursor) **Reviewed:** 2026-10-04 17:40 UTC, on `origin/master` (detached HEAD, clean tree) **Reviewer:** SuperNinja (hostile pass; Xdebug 3.5.3 installed locally so the harness runs end to end)

* * *

## Verdict in one line

This commit **closes the vacuous-run finding** from `review-e55223a.md` cleanly and correctly: the harness now refuses a run that finds fewer mutants than the last complete one, and separately refuses a direct invocation with the test-only `PC_MUTATE_FUNCTIONS` hook set. I reproduced both refusals and confirmed the normal run is unaffected. Every gate is green on a fresh database. The only residual issues are (a) the http suite is still **not idempotent** — a re-run against a warm DB fails — which this commit does not cause and which CI (fresh DB) does not see, and (b) the new floor is a **hard-coded exact count** (`300`), which is a deliberate canary but is coupled to the exact mutant population and carries a message that misattributes the cause when coverage legitimately shrinks.

* * *

## 1\. What the commit changes

Three files, +205/−0:

| File | Change |
| --- | --- |
| `bin/mutate.php` | Adds a `harnessWasInvoked()` check inside the `PC_MUTATE_FUNCTIONS` guard; adds a `tooFewMutants()` floor check after mutant enumeration; adds `harnessWasInvoked()`, `mutantMinimum()` (returns `300`), and `tooFewMutants()`. |
| `tests/Shared/MutationAllowListTest.php` | Adds `testARunThatMutatesNothingIsRefused` (asserts the floor logic and that a direct `PC_MUTATE_FUNCTIONS=1` run exits 1) plus a `loadHarness()` helper. |
| `ninja/review-e55223b.md` | My prior review, committed into the repo's `ninja/` scratch area. |

The two guards:

```php
if (getenv('PC_MUTATE_FUNCTIONS') === '1') {          // bin/mutate.php:10
    if (harnessWasInvoked()) {                        // :11
        fwrite(STDERR, "PC_MUTATE_FUNCTIONS is set, so the mutation harness did not run\n");
        exit(1);
    }

    return;
}
...
$short = tooFewMutants(count($mutants));              // :173
if ($short !== null) {
    fwrite(STDERR, $short . "\n");
    exit(1);
}
```

```php
function harnessWasInvoked(): bool                    // :387
{
    $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if (!is_string($script) || $script === '') {
        return false;
    }
    $main = realpath($script);
    $here = realpath(__FILE__);

    return $main !== false && $here !== false && $main === $here;
}

function mutantMinimum(): int                         // :400
{
    return 300;
}

function tooFewMutants(int $count): ?string           // :405
{
    if ($count >= mutantMinimum()) {
        return null;
    }

    return 'only ' . $count . ' mutants were found; the coverage driver is missing or the filter changed';
}
```

The design is sound. `harnessWasInvoked()` distinguishes "run as the main script" from "`require`d by a test" using `realpath(SCRIPT_FILENAME) === realpath(__FILE__)`, so the guard only fires when someone actually runs the harness with the test hook set. The floor is checked after the mutant list is built but **before** any file is mutated, so a short run exits without touching `src/`. And PHP hoists the top-level function declarations, so calling `harnessWasInvoked()` at line 11 works even though it is defined at line 387 — confirmed empirically, not assumed.

* * *

## 2\. Verification — the guards, reproduced

I reproduced the exact scenario from the prior review (Xdebug removed) and the new direct-invocation case:

**A) `PC_MUTATE_FUNCTIONS=1` direct invocation:**

```
PC_MUTATE_FUNCTIONS is set, so the mutation harness did not run
EXIT=1
```

**B) No Xdebug → the vacuous run that used to pass:**

```
xdebug loaded? NO
only 0 mutants were found; the coverage driver is missing or the filter changed
EXIT=1
```

Both now refuse. The prior finding — "the harness passes vacuously (exit 0) whenever it finds zero mutants" — is **closed**. The `300 → 299 → 0` boundary is also covered by the new unit test (`tooFewMutants(0)` and `(299)` non-null, `(300)` null), which I ran in isolation: **OK (2 tests, 14 assertions)**.

* * *

## 3\. Full gate run

| Gate | Command | Result |
| --- | --- | --- |
| max-lines | `php bin/check-max-lines.php 500` | **OK** |
| phpmd | `vendor/bin/phpmd src text phpmd.xml` | **OK** (exit 0) |
| phpstan | `vendor/bin/phpstan analyse` | **OK** (199 files, no errors) |
| unit | `phpunit --testsuite unit` | **OK (80 tests, 302 assertions)** |
| integration | `phpunit --testsuite integration` | **OK (7 tests, 45 assertions)** |
| http (fresh DB) | `phpunit --testsuite http` | **OK (36 tests, 520 assertions)** |
| prove:obligations | `php bin/prove-obligations.php` | **ok obligations** |
| prove:race | `php bin/prove-race.php` | **ok race** |
| prove | `php bin/prove-suite.php` | **ok** (all `prove_*.sql`) |
| **test:mutate** | `php bin/mutate.php` | **Mutants 300, killed 287, equivalent 13, survived 0, errors 0 — EXIT=0** |

The normal harness run is unchanged by the new guard: still 300 mutants, 13 equivalent, 0 survived, exit 0. The floor (`>= 300`) does not trip because the count is exactly 300.

* * *

## 4\. Residual issue A — the http suite is not idempotent

**Severity: low (test isolation; CI unaffected). Not caused by this commit.**

The http suite passes on a fresh database and **fails on a second run against the same database**. I proved this directly: re-provisioned the `webprobe` tenant, then ran the suite twice:

```
RUN 1 (fresh tenant):   OK (36 tests, 520 assertions)
RUN 2 (same tenant):    FAILURES! Tests: 36, Assertions: 491, Failures: 1
   CoverageRemainderTest::testTheBookExportAndCatalogEdges
   "That day is already closed."  (CoverageRemainderTest.php:478 → :679)
```

The mechanism is now pinned. `LedgerPeriods::idFor()` (`src/Ledger/LedgerPeriods.php:15–31`) throws **"That day is already closed."** when a **closed** period covers the posting date. After a full run, the tenant's periods are:

```
[2018-01-01,2019-01-01)  closed
[2019-01-01,2020-01-01)  closed
[2020-01-01,2036-01-01)  open
[2036-01-01,2037-01-01)  open
```

`CoverageRemainderTest` posts a `2018-03-15` adjustment (opening the 2018 period), and then `FloorCoverageTest` closes every period with start `<= 2019-01-01` — including 2018. On the next run, that same `2018-03-15` posting hits the closed 2018 period and is refused. The test's own `assertOpenedDay()` helper (`CoverageRemainderTest.php:679`) accepts only `'Adjustment posted.'` or `'That day could not be opened.'` — it does not anticipate `'That day is already closed.'`.

This is **not introduced by `4ebc878`** (which touches only `bin/mutate.php`, `ninja/`, and the allow-list test). It is also **not fully introduced by `e55223a`** — I checked `7c5d4a1` on a fresh tenant and it fails on the _first_ run (at `FloorCoverageTest.php:303`, the old single-2019 close, blocked by the open 2018 period). So the suite was never idempotent; `e55223a` fixed the in-run ordering so the first run passes, and the remaining re-run failure is a separate, pre-existing isolation weakness.

Why it matters: CI provisions a fresh database (`docker compose up postgres` + `php bin/provision-platform.php`), so `composer check` passes there and this does not block the build. But `composer check` is not re-runnable against a warm database — a developer who runs it twice, or a CI job re-run against a persistent DB, will see a spurious failure. The clean fix is to make the http suite reset the tenant (or the affected periods) at setup, or to make `CoverageRemainderTest` tolerant of an already-closed day. Worth a follow-up; not a blocker for this commit.

* * *

## 5\. Residual issue B — the floor is a hard-coded exact count

**Severity: low (design trade-off).**

`mutantMinimum()` returns a literal `300`, which is exactly the current mutant population. The floor is therefore `count >= 300`, i.e. any reduction at all fails. That is the strictest possible canary and it does catch the missing-coverage-driver case, which is the point. Two fragilities come with it:

1.  **It is coupled to the exact count, and the count may be environment-sensitive.** Mutants are derived from Xdebug-covered lines (`XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE`) plus operator tokens. Dead-code detection and coverage granularity can differ across Xdebug/PHP builds, so a CI runner that produces 299 (or a future local run that produces 301) would trip — or, worse, a _legitimate_ refactor that removes a covered operator drops the count and fails the gate with the message _"the coverage driver is missing or the filter changed"_, which misattributes a benign shrink to a broken driver. A margin (e.g. `>= 250`) or a percentage of the last run would be more robust while still catching a driver that yields zero.
2.  **The constant is duplicated in the test.** `MutationAllowListTest.php:34` asserts `self::assertSame(300, mutantMinimum())`, so bumping the floor requires two edits and a stale test otherwise. Minor, but it means the canary is not self-maintaining.

Neither is a correctness bug. The commit's stated intent ("fewer mutants than the last complete run") is met; I am flagging the brittleness, not disputing the design.

* * *

## 6\. Minor observations

-   The `PC_MUTATE_FUNCTIONS` guard now has a clear, tested failure path, which is a genuine improvement over the silent no-op it replaced. Good.
-   `harnessWasInvoked()` returns `false` when `SCRIPT_FILENAME` is unset, which is the safe direction (it permits `require`). If a future SAPI ever ran the file as main without setting `SCRIPT_FILENAME`, the guard would not fire — an edge case, not a live risk.
-   The prior review is again committed inside a code-fix commit (`ninja/review-e55223b.md`). Harmless, but review artefacts and source changes continue to share history.

* * *

## 7\. Verdict

`4ebc878` is a correct, tight fix that does exactly what it says. The vacuous-run finding is closed and proven: a run that finds zero mutants — or fewer than the last complete run — now exits 1, and a direct invocation with the test hook set also exits 1. All ten `composer check` components are green on a fresh database, including a full 300-mutant run with zero survivors.

Two things to carry forward, neither blocking: make the http suite idempotent (it fails on a warm DB, independent of this commit), and consider giving the mutation floor a margin so a legitimate coverage shrink does not masquerade as a broken driver. On the standing bar, this commit holds up — the gate can no longer pass without doing work.