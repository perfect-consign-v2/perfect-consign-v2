# Review — `origin/master` @ `7c5d4a1` (with the mutation harness actually running)

**Commit:** `7c5d4a1` — _"Keep the equivalent rent-date mutants exempt after the charge date moved, and still charge a stay on the day it ends."_ **Range:** `d8f7da0..7c5d4a1` **Reviewed:** 2026-10-04 **Method:** hostile, execution-based. I installed Xdebug so `bin/mutate.php` runs for real, then ran it, the unit/integration suites, and the proof gates on a live tenant.

> This supersedes the earlier `review-rent-mutants.md`, which could only reason about the allow-list by hand because no coverage driver was present. With the harness running, the picture is different — and worse.

## Verdict (revised)

The commit's _content_ is correct: the retarget from `RentLines.php:226` to `RentFigure.php:35` is right, and with a one-line fix to the harness the exemption list is complete (0 survivors). But the commit does **not** achieve its stated goal on Linux, because `bin/mutate.php` never matches its exemption list on Linux at all. `php bin/mutate.php` (i.e. `composer test:mutate`) reports **13 survivors and exits 1** — before and after `7c5d4a1`. The retarget is therefore moot: it fixes a list that is never consulted successfully.

**This is the headline finding: `composer test:mutate` is red on Linux, and `7c5d4a1` does not make it green.**

## 1\. The harness now runs — and it fails

I installed Xdebug 3.5.3 (`php8.4-xdebug` from the sury repo; the sandbox had no coverage driver). `tests/bootstrap.php` only writes coverage dumps when `xdebug_start_code_coverage` exists, so Xdebug — not PCOV — is required; PCOV would leave the harness a silent no-op. With Xdebug in place:

```
$ php bin/mutate.php
...
Mutants 300, killed 287, equivalent 0, survived 13, errors 0
EXIT=1
```

Thirteen survivors, **zero** exempted. But the allow-list plainly contains entries for most of those survivors. So `equivalentReason()` is returning `null` for entries that should match.

## 2\. Root cause: a path-separator mismatch (backslash vs forward slash)

`bin/mutate.php` scores a surviving mutant with:

```php
$reason = equivalentReason(relative($root, $file), $mutant['line'], $mutant['from'], $mutant['to']);
```

and `relative()` is:

```php
function relative(string $root, string $file): string
{
    $prefix = $root . DIRECTORY_SEPARATOR;
    return str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
}
```

`$file` comes from the coverage dump, whose keys are `realpath()` output — **forward slashes on Linux** (e.g. `/workspace/repo/src/Feature/Rent/RentFigure.php`). `relative()` just strips the root prefix, so it returns `src/Feature/Rent/RentFigure.php` — still forward slashes. Verified directly:

```
$rel = "src/Feature/Rent/RentFigure.php"
$rel === "src\\Feature\\Rent\\RentFigure.php"   // false   (allow-list form)
$rel === "src/Feature/Rent/RentFigure.php"       // true
```

Every one of the **11** `equivalentReason` entries compares against a backslash literal, e.g.:

```php
if ($file === 'src\\Feature\\Rent\\RentFigure.php' && $line === 35 && $from === '&&' && $to === '||') {
```

`'src\\Feature\\Rent\\RentFigure.php'` is the string `src\Feature\Rent\RentFigure.php`. On Linux the argument is `src/Feature/Rent/RentFigure.php`. They never compare equal, so `equivalentReason()` returns `null` for **every** mutant, and every equivalent mutant is counted as a survivor. The list is written for Windows path separators; the CI platform (and this sandbox) is Linux.

## 3\. Proof that the list is otherwise complete and correct

To isolate the separator from the list contents, I copied `bin/mutate.php` and changed only the call site to normalize the path before the lookup:

```php
equivalentReason(str_replace('/', '\\', relative($root, $file)), ...)
```

Re-running:

```
Mutants 300, killed 287, equivalent 13, survived 0, errors 0
EXIT=0
```

The only change is the separator. So:

-   The exemption list is **complete and correct** — all 13 equivalent mutants are covered, including the two `RentFigure.php:35` entries that `7c5d4a1` retargeted.
-   The failure is entirely the separator mismatch.

The 13 survivors from the unpatched run are exactly the 13 equivalents from the patched run:

| Survivor (unpatched) | Exempted (patched) |
| --- | --- |
| `BackupHeadline.php:22 <= -> <` | ✔ |
| `DailyBackupFiles.php:23 || -> &&` | ✔ |
| `DailyBackupFiles.php:70 ! -> (removed)` ×2 | ✔ |
| `DailyBackupFiles.php:70 && -> ||` | ✔ |
| `DailyBackupFiles.php:143 && -> ||` ×2 | ✔ |
| `VendorPlacement.php:64 < -> <=` | ✔ |
| `BoothRoster.php:254 <= -> <` | ✔ |
| `BoothMap.php:58 <= -> <` | ✔ |
| `MoveInRent.php:18 <= -> <` | ✔ |
| `RentFigure.php:35 && -> ||` | ✔ |
| `RentFigure.php:35 > -> >=` | ✔ |

## 4\. What this means for `7c5d4a1`

The commit retargeted two `equivalentReason` entries from `RentLines.php:226` to `RentFigure.php:35` (the charge-date condition moved files in `d8f7da0`). That retarget is **correct in content** — with the separator fixed, `RentFigure.php:35`'s two mutants are exempted and there are zero survivors. But because the separator bug prevents _any_ match on Linux, the retarget changes nothing operationally: `test:mutate` is red before and after. The commit fixed the right list entry and left the reason the list is unusable untouched.

The one-line fix (Cursor's call, not mine to make in their repo): normalize in `equivalentReason`, e.g. `$file = str_replace('\\', '/', $file);` at the top and use forward-slash literals — or make `relative()` return backslashes. Either makes the harness pass on Linux.

## 5\. Why this was never caught

`composer test:mutate` is not wired into CI. `composer check` = `@check:max-lines`, `@check:phpmd`, `@phpstan`, `@test`, `@test:integration`, `@test:http`, `@prove:obligations`, `@prove:race`, `@prove` — it does **not** include `@test:mutate`. CI (`.github/workflows/check.yml`) runs `composer check` and then `composer test:coverage`; neither invokes `bin/mutate.php`. So the harness has been red on Linux for as long as the allow-list has used backslashes, and nothing failed. Adding `@test:mutate` to `composer check` (with `coverage: xdebug`) would have caught both the separator bug and the `d8f7da0` stale-line drift.

## 6\. Gates (on `7c5d4a1`)

| Gate | Command | Result |
| --- | --- | --- |
| unit | `phpunit --testsuite unit` | **OK — 78 tests / 288 assertions** |
| integration | `phpunit --testsuite integration` | **OK — 7 tests / 45 assertions** |
| prove-suite | `php bin/prove-suite.php` | **41/41 ok** |
| prove-race | `php bin/prove-race.php` | **ok race** |
| **mutate** | **`php bin/mutate.php`** | **FAIL — 13 survivors, exit 1** (see §1–§3) |
| http | `phpunit --testsuite http` | 36 tests, 1 failure — pre-existing order-dependency, see §7 |

Environment: Xdebug 3.5.3 installed; `STALL_APP_PASSWORD` set and the `stall_app` role enabled so integration/HTTP could run. The `webprobe` tenant was stale (missing the `payout_pack` view) and was re-provisioned.

## 7\. Adversarial pass — other observations (none block this commit)

### 7.1 The HTTP suite is order-dependent (pre-existing)

On a fresh tenant, `FloorCoverageTest::testTheBooksPagesPost` fails: it closes a 2019 period, but `CoverageRemainderTest` runs first (alphabetically) and posts a 2018 journal that auto-opens a 2018 period; `stall_reject_earlier_open_period` (`sql/013_period_close.sql:160`) then refuses the out-of-order close. `FloorCoverageTest` passes in isolation (5 tests / 173 assertions). The 2018 period came from `95d41d8`; the rule is from the initial kernel commit. `7c5d4a1` touches no HTTP code, but it reproduces in the exact `composer check` order (integration → http), so Cursor should confirm the CI HTTP job is actually green.

### 7.2 The boundary test and the exemptions are genuinely correct

Independently of the harness, I re-verified the substance of `7c5d4a1`:

-   `testAStayOnTheMonthBoundaryStillHoldsTheBooth` asserts `charge_date='2026-06-01'`, `chargeable=true`, `rate_minor=8000` for a stay ending `2026-06-01`. `coversStay` uses `endsOn >= day` (inclusive), and `assignment_range` is `daterange(starts_on, ends_on, '[]')` — both bounds inclusive — so `stall_assert_rent_charge()` charges the day a stay ends. App and ledger agree on the boundary.
-   The three exemptions are genuinely equivalent, not merely untested: `RentFigure.php:35 > -> >=` (same string when the start is the first); `MoveInRent.php:18 <= -> <` (the pruned case returns the same full-month figure); `RentFigure.php:35 && -> ||` (the only differing input needs a start outside the month, which `RentLines::holder()` cannot produce because `covers()` rejects `startsOn > end`). `RentFigure::posted` has exactly one caller, so the invariant holds.

So the commit's substance is sound. The problem is the harness, not the rent logic.

## 8\. Verdict

**The commit's content is correct; the gate it targets is still red.** `7c5d4a1` retargets the exemption list correctly, but `bin/mutate.php` never matches that list on Linux (backslash literals vs forward-slash `relative()`), so `composer test:mutate` reports 13 survivors and exits 1 — before and after the commit. Fix the separator (one line), wire `@test:mutate` into `composer check`, and this class of drift cannot recur silently.