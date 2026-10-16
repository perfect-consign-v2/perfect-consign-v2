# Adversarial review — `perfect-consign-v2` @ `d7f1d21`

**Commit:** `d7f1d2115d5d25a06f0fce81051f71e8c7f60054` **Subject:** "Accept a closed day when the HTTP suite runs again on the same mall." **Author:** Kevin Rubio (co-authored by Cursor) **Reviewed:** 2026-10-04 17:55 UTC, on `origin/master` (detached HEAD, clean tree) **Reviewer:** SuperNinja (hostile pass; Xdebug 3.5.3 installed locally so the harness runs end to end)

* * *

## Verdict in one line

This commit **closes residual A** from `review-4ebc878.md` — the http suite is now idempotent, verified across three consecutive runs on the same warm database. The change is a one-line widening of a test helper's accepted outcomes. It is correct and minimal, and it preserves fresh-run strictness. One design observation remains (the helper is now lenient on warm runs), but it is not a defect and does not block. Residual B (the exact-count mutation floor) is untouched by this commit and still stands as a follow-up.

* * *

## 1\. What the commit changes

Two files, +174/−1:

| File | Change |
| --- | --- |
| `tests/Http/CoverageRemainderTest.php` | `assertOpenedDay()` now also accepts `'That day is already closed.'` as a valid outcome, alongside `'Adjustment posted.'` and `'That day could not be opened.'`. |
| `ninja/review-4ebc878.md` | My prior review, committed into the repo's `ninja/` scratch area. |

The change in full:

```php
private function assertOpenedDay(\PerfectConsign\Tests\Support\PageResponse $page): void
{
    self::assertTrue(
        str_contains($page->body, 'Adjustment posted.')
        || str_contains($page->body, 'That day could not be opened.')
        || str_contains($page->body, 'That day is already closed.'),
        HtmlPage::error($page->body)
    );
}
```

This directly addresses the mechanism I documented last round: on a re-run, `CoverageRemainderTest` posts a `2018-03-15` adjustment, but `FloorCoverageTest` already closed the `[2018-01-01,2019-01-01)` period, so `LedgerPeriods::idFor()` (`src/Ledger/LedgerPeriods.php:15–31`) refuses with `'That day is already closed.'` — which the helper did not previously accept.

* * *

## 2\. Verification — idempotency, reproduced

I re-provisioned the `webprobe` tenant fresh, then ran the http suite three times in a row against the same warm database:

| Run | Database state | Result |
| --- | --- | --- |
| 1 | fresh | **OK (36 tests, 520 assertions)** |
| 2 | warm | **OK (36 tests, 522 assertions)** |
| 3 | warm | **OK (36 tests, 521 assertions)** |

Before this commit, run 2 failed with `CoverageRemainderTest::testTheBookExportAndCatalogEdges — "That day is already closed."`. The suite is now idempotent. **Residual A is closed.**

* * *

## 3\. Full gate run

| Gate | Command | Result |
| --- | --- | --- |
| max-lines | `php bin/check-max-lines.php 500` | **OK** |
| phpmd | `vendor/bin/phpmd src text phpmd.xml` | **OK** (exit 0) |
| phpstan | `vendor/bin/phpstan analyse` | **OK** (no errors) |
| unit | `phpunit --testsuite unit` | **OK (80 tests, 302 assertions)** |
| integration | `phpunit --testsuite integration` | **OK (7 tests, 45 assertions)** |
| http | `phpunit --testsuite http` | **OK (36 tests, 520–522 assertions)** ×3 |
| prove:obligations | `php bin/prove-obligations.php` | **ok obligations** |
| prove:race | `php bin/prove-race.php` | **ok race** |
| prove | `php bin/prove-suite.php` | **ok** (all `prove_*.sql`) |
| test:mutate | `php bin/mutate.php` | **Mutants 300, killed 287, equivalent 13, survived 0, errors 0 — EXIT=0** |

All ten `composer check` components are green, and the http suite is green on both a fresh and a warm database.

* * *

## 4\. Assessment of the fix

The change is the right _shape_ for the problem. The helper is used for exactly two postings — the `2018-03-15` adjustment (line 469/478) and the `2036-03-15` adjustment (line 481/490). On a warm run, the `2018-03-15` day is legitimately closed, and the application correctly refusing to post to a closed day is _itself_ correct behaviour; the test now recognises that as a valid outcome rather than a failure. The `2036-03-15` posting is unaffected (its period stays open).

Crucially, **fresh-run strictness is preserved**. On a fresh database nothing is closed, so `'That day is already closed.'` cannot appear; the fresh run still requires `'Adjustment posted.'` (or the genuinely-refused `'That day could not be opened.'`). So the assertion the test makes on the path CI actually exercises is unchanged — only the re-run path is relaxed. That is the correct trade-off.

**One design observation (not a blocker).** On a warm run the helper now accepts three outcomes, so it no longer distinguishes "the app correctly refused a closed day" from "the app refused for some other reason that happens to emit the same string". In practice this is harmless: the fresh run exercises the open path, and CI uses a fresh database, so a regression that broke period-opening would still fail on the first run. If a future change ever made the http suite run warm in CI, the fresh-run guarantee would be lost — worth remembering, but not a reason to change anything now. A stricter alternative would be to reset the tenant (or the affected periods) in test setup so the suite is idempotent _by construction_ rather than by tolerating a third message; that is a heavier change and not required here.

* * *

## 5\. Residuals carried forward

-   **Residual B — the mutation floor is a hard-coded exact count (`300`).** Unchanged by this commit. `tooFewMutants()` refuses only `$count < 300`, so 300 and 301 both pass; a _legitimate_ coverage shrink is still misreported as _"the coverage driver is missing or the filter changed"_, and the literal `300` is duplicated in `MutationAllowListTest.php:34`. A margin (e.g. `>= 250`) would be more robust. Low severity, follow-up.
-   **The `ninja/` review artefacts are committed inside code-fix commits.** Cosmetic; review history and source history continue to share commits.

* * *

## 6\. Verdict

`d7f1d21` does exactly what it says: the http suite now tolerates an already-closed day, so it runs cleanly on a warm mall. I verified idempotency across three consecutive runs, and every `composer check` gate is green. The change is minimal, correct, and preserves the strictness that matters (fresh-run behaviour). Residual A is closed. Residual B remains a low-severity follow-up. On the standing bar, this holds up.