# Hostile review — `origin/master` @ `d8f7da0`

**Range reviewed:** `0c8971e..d8f7da0` (two commits: `26bd33f`, `d8f7da0`) — 15 files, +709 / −53. **Reviewer stance:** adversarial; the previous review (`review-rent-page.md`, `ninja/review-rent-page.md`) rejected `c08651a` for a proven blocker. This review re-tests that blocker against the fix and looks for fresh defects.

## Verdict

**The production fix is correct — the `c08651a` blocker is genuinely closed, and I verified it by execution, not by reading.** I am accepting the rent-charge fix and the payout-path fix.

**One regression rides along and must be corrected before this is clean:** `d8f7da0` moved `RentLines::chargeDate()` into a new `RentFigure` class, but did **not** move the two `bin/mutate.php` allow-list entries that exempt its equivalent mutants. They still name `RentLines.php` line 226, which after the refactor is `return false;`. The equivalent mutants now live at `RentFigure.php:35`, are no longer exempted, and **survive the unit suite** — so `composer test:mutate` now exits 1. I proved the survival directly (see §5). This is a tooling regression, not a production defect, and it does not gate `composer check` or CI — but it breaks a gate the repo built and the earlier review praised.

* * *

## 1\. The blocker this commit was written to close

The prior review proved that the rent page's **Charge** button posted the full stored rate, which `stall_assert_rent_charge()` refuses in three cases:

1.  **mid-month move-in** — the trigger pro-rates the first month (`(days_charged * rate) / days_in_month`), so posting the full rate raises `23514`;
2.  **`assignment_rent` override** — the trigger replaces the stored rate with the override, so posting the base rate raises `23514`;
3.  **rated-but-vacant booth** — the trigger requires an assignment covering the charge date, so any charge raises `23514`.

The page posted the raw `booth_rent_rates.amount_minor` in all three cases, so the button was dead.

## 2\. What I verified by execution (the fix actually works)

I provisioned a fresh tenant and drove the **real** classes — `RentStore::chargeAmount()`, `RentFigure::posted()`, `MoveInRent::charge()`, `LedgerStore` — then posted the figures they produce. Output (`bin/probe-rent-fix.php`):

```
A figure date=2026-06-20 chargeable=true amount=4800
A chargeAmount(2026-06-15) = 4800  (expect 4800)
A post 4800 -> ACCEPTED
A2 post 9000 (full rate) -> REFUSED: a rent charge debits that booth receivable and credits rent revenue for the stored rate
B figure date=2026-03-20 chargeable=true amount=3400
B chargeAmount(2026-03-15) = 3400  (expect 3400)
B post 3400 -> ACCEPTED
B2 post 3100 (base rate) -> REFUSED: a rent charge debits that booth receivable and credits rent revenue for the stored rate
B3 post 6200 (raw override) -> REFUSED: a rent charge debits that booth receivable and credits rent revenue for the stored rate
C figure date=2026-06-20 chargeable=false amount=7700  (expect chargeable=false)
C chargeAmount throws: a rent charge requires an assignment that covers the charge date
D figure date=2026-06-20 chargeable=false amount=5000  (expect chargeable=false)
D chargeAmount throws: a rent charge requires an assignment that covers the charge date
E page row: rate_minor=4800 chargeable=true charge_date=2026-06-20 charged=true
E vacant row: rate_minor=7700 chargeable=false
```

Read it plainly:

-   **Mid-month move-in** (rate 9000, start `2026-06-15`): the page computes **4800** and the ledger **accepts** it. Posting the full 9000 is refused by the guard. ✅
-   **Override** (rate 3100, override 6200, start `2026-03-15`): the page computes **3400** (6200 × 17/31) and the ledger **accepts** it. Posting the base rate 3100 _or_ the raw override 6200 is refused. ✅
-   **Rated-but-vacant booth**: `chargeable=false` (no Charge form) and `chargeAmount` throws the exact guard message. ✅
-   **Ended assignment**: `chargeable=false`, `chargeAmount` throws. ✅

The arithmetic matches `stall_assert_rent_charge()` exactly: `MoveInRent::due()`'s `intdiv(daysCharged * monthly, daysInMonth)` equals the trigger's `(days_charged * rate_minor) / days_in_month`, and both apply the override _before_ pro-rating. I also confirmed `booth_assignments_no_overlap` (an `EXCLUDE USING gist` on `booth_id` + `assignment_range`) guarantees a single covering assignment, so `RentStore::covering()`'s `ORDER BY starts_on DESC LIMIT 1` is equivalent to the trigger's `assignment_range @> posting_date` lookup.

## 3\. The fix, line by line

-   **`src/Feature/Rent/RentFigure.php` (new)** — `posted()` (line 17) is the single source of truth for the charge date and the ledger-accepted amount. `chargeDate()` (line 29) is the old `RentLines::chargeDate` moved verbatim; `coversStay()` (line 42) is the new "does the stay cover this date" gate. `chargeable = $rate !== null && coversStay(...)` (line 20); the amount is `MoveInRent::charge(...)` only when chargeable (line 22–23).
-   **`src/Feature/Party/MoveInRent.php`** — `charge(int $monthly, ?int $override, string $startsOn, string $month)` (line 31) returns `$override ?? $monthly`, then pro-rates via `due()` only when `substr($startsOn,0,7) === $month`. This is the exact trigger order (override, then move-in days).
-   **`src/Feature/Rent/RentStore.php`** — `chargeAmount()` (line 42) reads the stored rate, throws the two guard messages when the rate is missing or no assignment covers the date, and returns `MoveInRent::charge(...)` (line 53). `covering()` (line 124) joins `assignment_rent` and returns the covering stay's `starts_on` + `override_minor`. `facts()` now `LEFT JOIN assignment_rent` so the page sees overrides.
-   **`src/Feature/Rent/RentLines.php`** — `present()` (line 158) now stores `rate_minor => $figure['amount']` (the accepted figure, not the raw rate) and adds `chargeable`. `stays()` carries `override`.
-   **`src/Feature/Rent/RentBook.php`** — `due()` (line 193) is now `$row['chargeable'] && $row['charged'] !== true`, so the "Still to charge" stat and the `due` filter use the real chargeability, not `rate_minor !== null`.
-   **`src/Feature/Rent/rent.php`** — a new branch at line 153 (`elseif (!$row['chargeable'])`) renders a blank cell before the Charge form, so a rated-but-vacant booth (or a stay that starts later) no longer offers a dead button.
-   **`src/Feature/Rent/RentController.php`** — `charge()` (line 44) posts `$this->rent->chargeAmount($boothId, $postingDate)` instead of the raw rate.

## 4\. The payout path is now consistent with the rent page (good catch by Cursor)

The same override gap existed on the payout side and is fixed:

-   **`sql/041_payout_run.sql`** — the `missing_month` lateral rate (lines 831–842) now `SELECT COALESCE(override.amount_minor, listed.amount_minor) ... LEFT JOIN assignment_rent AS override ON override.assignment_id = assignment.id`, then the existing `CASE` pro-rates the move-in month. This matches `MoveInRent::charge` and the trigger.
-   **`src/Feature/Payout/PayoutStore.php`** — `monthCharge()` (line 230) now calls `MoveInRent::charge($monthly, $assignment['override'], $assignment['starts'], $month)`; `missingByVendor()`/`assignments()` carry the override.

I checked the two rate-selection paths against each other and against the trigger: `sql/041`'s `listed` picks the most recent rate ≤ `GREATEST(month_start, starts_on)`, and `PayoutStore::rate()` picks the most recent rate ≤ the posting date — they agree, and both apply the override before pro-rating. Note the "older rate" fallback (a month with no stored rate falls back to an earlier one) is **pre-existing** and unchanged by this commit; it is not a new defect.

## 5\. The regression: the mutation allow-list was left behind

`bin/mutate.php` scores operator mutants on the unit suite and **exits 1 on any survivor** (`exit($survived === [] && $escaped === [] ? 0 : 1);`). It exempts semantically-equivalent survivors through `equivalentReason()`. Two entries were added in `26bd33f` for the `chargeDate` condition:

```
bin/mutate.php:362   if ($file === 'src\\Feature\\Rent\\RentLines.php' && $line === 226 && $from === '&&' && $to === '||') {
bin/mutate.php:365   if ($file === 'src\\Feature\\Rent\\RentLines.php' && $line === 226 && $from === '>'  && $to === '>=') {
```

At `0c8971e`/`26bd33f`, `RentLines.php:226` **was** that condition. In `d8f7da0` the method moved to `RentFigure.php`, where the condition is now **line 35**:

```
RentFigure.php:35    if ($startsOn !== null && substr($startsOn, 0, 7) === $month && $startsOn > $first) {
```

and `RentLines.php:226` is now `return false;` (inside `covers()`). So the allow-list no longer matches anything the harness generates.

I did not take this on faith. I applied each mutation at `RentFigure.php:35` by hand and ran `vendor/bin/phpunit --testsuite unit` (baseline: **OK, 78 tests, 285 assertions**):

| mutation at `RentFigure.php:35` | unit suite | verdict |
| --- | --- | --- |
| first `&&` → `||` | 2 errors, 2 failures | **KILLED** |
| second `&&` → `||` | green | **SURVIVED** |
| `>` → `>=` | green | **SURVIVED** |

`RentFigure.php:35` is covered by the unit suite — `RentBookTest::testChargeDateFollowsTheMonth` views `2026-06` with `today = 2026-10-03`, so `substr($today,0,7) !== $month` and the second `if` executes. So under Xdebug (the environment the harness is built for, and the one CI installs via `coverage: xdebug`), the harness generates both surviving mutants, calls `equivalentReason('src\Feature\Rent\RentFigure.php', 35, …)`, gets `null`, records them as **SURVIVED**, and exits 1.

**I could not reproduce the harness failure end-to-end here** because this sandbox has neither Xdebug nor PCOV, so `tests/bootstrap.php` writes no coverage dumps and `bin/mutate.php` reports `Mutants 0 … EXIT=0` (a silent no-op). But the line drift is unambiguous and the mutant survival is directly demonstrated, so the conclusion holds in any Xdebug environment.

**Required fix:** retarget both entries to `src\Feature\Rent\RentFigure.php` line 35 (or, better, key the allow-list on a stable identifier rather than a line number, since this is the second time a refactor has moved a line out from under it).

## 6\. Gate results on `d8f7da0`

| gate | command | result |
| --- | --- | --- |
| unit | `vendor/bin/phpunit --testsuite unit` | **OK — 78 tests, 285 assertions** |
| proofs | `php bin/prove-suite.php` | **PASS — 41/41 `ok`, EXIT=0** |
| race | `php bin/prove-race.php` | **PASS — `ok race`, EXIT=0** |
| rent-charge probe | `php bin/probe-rent-fix.php` | **PASS** (see §2) |
| mutation | `php bin/mutate.php` | **no-op here (no Xdebug); would FAIL on the two survivors of §5** |
| integration / http | `--testsuite integration` / `http` | **not run here** — `STALL_APP_PASSWORD` is unset, so `ProbeMall::ensure()` cannot provision `webprobe`; these run in CI |

## 7\. The new tests

The tests now cover the exact cases the last review said were arranged to miss:

-   **`tests/Integration/Rent/RentChargeTest.php` (new)** drives the real `RentStore::chargeAmount` against a provisioned tenant and asserts 4800 (move-in), 3400 (override), and the vacant-booth refusal — the same figures my probe produced. Correct.
-   **`tests/Http/CoverageRemainderTest.php::testAMidMonthMoveInAndAnOverrideCharge`** is a genuine end-to-end check: it asserts the page shows `Charge $48.00` / `Charge $34.00`, that posting each yields `Rent charged.`, and that a vacant rated booth shows its code but **not** `Charge $77.00`. (It distinguishes the Rate cell `$77.00` from the button `Charge $77.00` — a nice touch.) Correct.
-   **`tests/Feature/Rent/RentBookTest.php`** now asserts `rate_minor` = 4800 for the move-in and `due_minor` = 12800 (was the wrong `17000`), plus `chargeable` on the vacant and future-start rows. Correct.
-   **`tests/Feature/Party/MoveInRentTest.php`** pins `charge()` at 8000 / 4800 / 3400 / 6200. Correct.

No gaps worth blocking on. The one thing untested end-to-end is a booth whose stay has already **ended** before the charge date (`RentBookTest` covers the future-start case; my probe covered the ended case at scenario D and it behaves correctly), but that is a nicety, not a hole.

## 8\. Bottom line

The commit does what it says: the rent page now posts the override and the mid-month amount the books accept, and only when a stay covers that date — verified by execution against the live trigger, not by reading. The payout path is brought into line with it. I withdraw the previous blocker.

The only thing standing between this and a clean merge is the stale mutation allow-list in §5: it is a real, demonstrated regression in a gate the repo owns, and it should be retargeted to `RentFigure.php:35` in the same commit that moved the line.