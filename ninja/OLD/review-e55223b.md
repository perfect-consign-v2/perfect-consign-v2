# Adversarial review — `perfect-consign-v2` @ `e55223a`

**Commit:** `e55223aa563c3941e1a66323248c5f95e17df9d4` **Subject:** "Match mutation exemptions on Linux coverage paths, and close an earlier open year before the next one." **Author:** Kevin Rubio (co-authored by Cursor) **Reviewed:** 2026-10-04 17:25 UTC, on `origin/master` (detached HEAD, clean tree) **Reviewer:** SuperNinja (hostile pass; Xdebug 3.5.3 now installed locally so the harness runs end to end)

* * *

## Verdict in one line

This commit **genuinely closes all three findings** from `review-rent-mutants.md` — the Linux path-separator bug, the HTTP earlier-open-year order-dependency, and the fact that `test:mutate` was not wired into the gate. Every gate in `composer check` is green here, including a full mutation run of **300 mutants / 287 killed / 13 equivalent / 0 survived**. It introduces **one new finding**: wiring `@test:mutate` into `composer check` promoted a latent flaw in `bin/mutate.php` into a live gate defect — **the harness passes vacuously (exit 0) whenever it finds zero mutants**, which happens the moment the coverage driver is absent. That is the only thing standing between this and a clean pass.

* * *

## 1\. What the commit actually changes

The diff is small and surgical (5 files, +190/−7):

| File | Change |
| --- | --- |
| `bin/mutate.php` | `relative()` rewritten to normalize both sides of the comparison to forward slashes, strip the root, then return backslashes; plus a `PC_MUTATE_FUNCTIONS` early-return guard so the file can be `require`d in a test. |
| `composer.json` | `"@test:mutate"` appended to the `check` script array (after `"@prove"`). |
| `tests/Shared/MutationAllowListTest.php` | New unit test asserting the Linux and Windows coverage paths both normalize to `src\Feature\Rent\RentFigure.php` and both match the allow-list. |
| `tests/Http/FloorCoverageTest.php` | `testTheBooksPagesPost` now collects **all** open periods, sorts them by start date, and closes every one whose start is `<= '2019-01-01'` in order. |
| `ninja/review-rent-mutants.md` | The prior review, committed into the repo's `ninja/` scratch area. |

The new `relative()` is the correct shape:

```php
function relative(string $root, string $file): string
{
    $prefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
    $normalized = str_replace('\\', '/', $file);
    if (str_starts_with($normalized, $prefix)) {
        $normalized = substr($normalized, strlen($prefix));
    }

    return str_replace('/', '\\', $normalized);
}
```

Normalizing _both_ operands (not just the file) is what the old version got wrong, and returning backslashes keeps the 11-entry allow-list literals valid verbatim. This is the right fix, not a workaround.

* * *

## 2\. Verification — every gate, actually run

I ran the full `composer check` set by hand (Composer itself is not installed in this sandbox, but every script it invokes is present):

| Gate | Command | Result |
| --- | --- | --- |
| max-lines | `php bin/check-max-lines.php 500` | **OK** — all `src` files under 500 lines |
| phpmd | `vendor/bin/phpmd src text phpmd.xml` | **OK** — exit 0 |
| phpstan | `vendor/bin/phpstan analyse` | **OK** — 199 files, no errors |
| unit | `phpunit --testsuite unit` | **OK (79 tests, 293 assertions)** |
| integration | `phpunit --testsuite integration` | **OK (7 tests, 45 assertions)** |
| http | `phpunit --testsuite http` | **OK (36 tests, 524 assertions)** |
| prove:obligations | `php bin/prove-obligations.php` | **ok obligations** |
| prove:race | `php bin/prove-race.php` | **ok race** |
| prove | `php bin/prove-suite.php` | **ok** (all `prove_*.sql`) |
| **test:mutate** | `php bin/mutate.php` | **Mutants 300, killed 287, equivalent 13, survived 0, errors 0 — EXIT=0** |

The mutation run is the headline: the exact same harness that reported **13 survivors / EXIT=1** on `7c5d4a1` now reports **13 equivalent / 0 survived / EXIT=0** on `e55223a`, with no change to the mutant population (still 300). That is the separator bug, fixed and proven.

* * *

## 3\. Prior findings — closure evidence

**Finding A (Linux path-separator bug) — CLOSED.** On `7c5d4a1`, `relative()` returned forward-slash paths (`src/Feature/Rent/RentFigure.php`) while every `equivalentReason()` entry compared backslash literals, so no exemption ever matched on Linux and all 13 equivalent mutants were scored as survivors. The new `relative()` (line 376) normalizes both sides and emits backslashes; `equivalentReason()` (line 337) is unchanged. The harness now prints `EQUIV` for all 13 and exits 0. `tests/Shared/MutationAllowListTest.php` locks the behaviour: it asserts the Linux path normalizes to `src\Feature\Rent\RentFigure.php` and matches both `RentFigure.php:35` exemptions, and does the same for a Windows root. I ran it in isolation: **OK (1 test, 5 assertions)**.

**Finding B (HTTP earlier-open-year order-dependency) — CLOSED.** `FloorCoverageTest.php:297–311` now matches every open-period row with `preg_match_all`, sorts by the start-date capture (`usort(..., $left[1] <=> $right[1])`), and closes each period whose start is `<= '2019-01-01'` using that period's own `ends_on`. This respects `stall_reject_earlier_open_period()` (an earlier period must close before a later one), so a leftover 2018 period no longer makes the 2019 close fail. The http suite, which previously carried one order-dependent failure, is now **green (36/524)**.

**Finding C (`test:mutate` not in the gate) — CLOSED.** `composer.json:54` now lists `"@test:mutate"` in the `check` array, and `.github/workflows/check.yml` runs `composer check` (line 52) on a runner configured with `coverage: xdebug` (line 40). So the mutation harness now runs in CI with a real coverage driver.

* * *

## 4\. New finding — the harness passes vacuously when it finds zero mutants

**Severity: medium (gate integrity).** This is the one thing I would not let through.

`bin/mutate.php` scores mutants by first running the unit suite under coverage and reading the dumps the bootstrap writes. If the coverage driver is missing, `tests/bootstrap.php` clears the dump directory and returns **without writing anything** (it guards on `function_exists('xdebug_start_code_coverage')`). The harness then does:

```php
$dumps = glob($coverageDir . DIRECTORY_SEPARATOR . '*.php');   // line 60 → []
...
exit($survived === [] && $escaped === [] ? 0 : 1);             // line 269
```

With no dumps, `$hits` is empty, `$mutants` is empty, the scoring loop never runs, and the final condition is trivially true — **exit 0**. There is no guard on `count($mutants)`.

I reproduced this exactly by moving the Xdebug ini aside and running the harness:

```
xdebug loaded? NO
Mutants 0, killed 0, equivalent 0, survived 0, errors 0
EXIT=0
```

So a machine without Xdebug — or any future regression in the coverage filter, the bootstrap guard, or the `XDEBUG_MODE`/`PC_COVERAGE` plumbing that silently yields zero covered lines — gets a **green `composer check` that mutated nothing**. That is worse than no gate, because it manufactures confidence. The new `MutationAllowListTest` does not catch it: it asserts that the allow-list _matches_, never that the harness _produces mutants_.

The fix is a three-line floor at the top of the scoring section, e.g.:

```php
if (count($mutants) === 0) {
    fwrite(STDERR, "no mutants were found; the coverage driver is missing or the filter changed\n");
    exit(1);
}
```

A stronger version asserts a floor (say, `>= 250`) so that a _partial_ coverage regression — coverage silently dropping from 300 to 40 mutants — also fails the gate instead of passing as long as those 40 are killed. Right now the gate has no lower bound at all.

There is a second, narrower vacuous path: the `PC_MUTATE_FUNCTIONS=1` guard (line 10) makes the whole script `return` early. If that variable were ever set in the CI environment, `@test:mutate` would no-op and exit 0. It is a test-only hook, so the risk is low, but it is the same failure class and worth a comment noting it must never be set outside tests.

* * *

## 5\. Minor observations (not blockers)

**The allow-list test is narrow.** `MutationAllowListTest` exercises only the two `RentFigure.php:35` entries out of the 11 in the allow-list. A typo introduced into any of the other nine entries (for example, a forward-slash path) would slip through the test and only surface as a survivor on the next full mutation run. A loop over the whole allow-list — or an assertion on its size — would make the guard comprehensive.

**Windows case-sensitivity in `relative()`.** `str_starts_with` is case-sensitive, so a root whose drive letter or path case differs from the coverage dump's `realpath()` would fail to strip the prefix. The test uses matching case (`C:\laragon\...`), and the bootstrap keys dumps by `realpath()`, so this is unlikely to bite in practice — but the primary target is Linux CI, and the Windows branch is untested against a real Windows coverage dump.

**`ninja/review-rent-mutants.md` is committed inside a code-fix commit.** The prior review now lives in the repo's `ninja/` scratch area alongside the payout specs. Harmless, but it does mean review artefacts and source changes share history.

* * *

## 6\. Verdict

`e55223a` is a correct, well-scoped fix. It closes the separator bug at the root (normalize both operands), proves it with a regression test, closes the HTTP ordering flaw properly (close earlier periods first, to their own end dates), and promotes the mutation harness into the gate where it belongs. All ten `composer check` components are green, including a full 300-mutant run with zero survivors.

The remaining gap is that the gate can still pass without doing any work. Given the standing bar — enterprise, world-class, no shortcuts — a mutation gate that reports success when it mutated nothing should not ship. Add the zero-mutant floor (and ideally a minimum-count floor), and this commit is clean.