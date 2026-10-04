# Review — `perfect-consign-v2` `origin/master` @ `0c8971e`

**Range reviewed:** `8055f36..0c8971e` (three commits) **Stance:** adversarial. Enterprise bar. No finding is withdrawn unless the code refutes it. **Verdict: REJECT.** The `0c8971e` fix closes the previous blocker — that part is accepted and proven. But the new rent page (`c08651a`) ships a **Charge** button that the database refuses for any mid-month move-in, for any booth carrying an `assignment_rent` override, and for any rated booth with no assignment. The page's primary action is broken for the ordinary case of a vendor who moves in on any day other than the first.

* * *

## 1\. What landed in this range

| Commit | Subject | Shape |
| --- | --- | --- |
| `c08651a` | "Show each booth's rent for the month on one page…" | New `src/Feature/Rent/*` (RentBook, RentLines, RentStore, RentController, rent.php) + `tests/Feature/Rent/RentBookTest.php` |
| `2646b31` | "Recall the suspended sale the floor test just parked…" | Test-only: `FloorCoverageTest::lastMatch()` |
| `0c8971e` | "Treat a reversed rent charge as missing so the next batch can be closed and paid." | The prior blocker fix + three minor fixes + a new proof + two spec edits |

I re-ran the proof tooling on this checkout: `php bin/prove-suite.php` → all 41 proofs `ok`, including the new `prove_payout_reversal.sql`; `php bin/prove-race.php` → `ok race`.

* * *

## 2\. The previous blocker is genuinely closed — accepted

The prior review's blocker was: a reversed rent charge is treated as _missing_ by `PayoutStore::missingRent()` but as _present_ by `payout_pack.missing_month`, so `close()` re-charges the month and moves a checksum that `review()` had already frozen, and `pay()`'s `stall_assert_frozen_checksum` then refuses the check.

`0c8971e` fixes it exactly as recommended. In `sql/041_payout_run.sql` the `missing_month` CTE (around line 839) now reads:

```sql
WHERE NOT EXISTS (
    SELECT 1
    FROM journals AS journal
    WHERE journal.source_type = 'rent_charge'
      AND journal.posting_key = 'rent-charge:' || assignment.booth_id::text || ':' || to_char(series.month_start::date, 'YYYY-MM')
      AND NOT EXISTS (
          SELECT 1 FROM journals AS reversal
          WHERE reversal.reverses_journal_id = journal.id
      )
)
```

That is the same predicate `PayoutStore::missingRent()` uses (`src/Feature/Payout/PayoutStore.php:92-96`) and the same one the new rent page uses (`src/Feature/Rent/RentStore.php:84-90`). The pack, the store, and the page now agree that a reversed charge is absent. The new `sql/prove_payout_reversal.sql` re-charges a reversed month, asserts the pack checksum does **not** move, and drives the replacement run all the way to `paid`. The three minor findings I raised (the `SET CONSTRAINTS ALL IMMEDIATE` leak in `042`, the redundant `PERFORM … FOR UPDATE` in both `011` triggers, the README line) are also fixed. Credit where due — this is the right fix, in the right place, with a proof.

* * *

## 3\. BLOCKER — the rent page's **Charge** button is refused by the database

`RentController::charge()` posts the **full stored rate**:

```php
// src/Feature/Rent/RentController.php:44
$amount = $this->rent->rate($boothId, $postingDate);
...
// :54-55
PostedLines::entry($this->ledger->roleAccount('booth_rent_receivable'), $amount, 0, 'booth', $boothId),
PostedLines::entry($this->ledger->roleAccount('rent_revenue'), 0, $amount, null, null),
```

`RentStore::rate()` returns the raw `booth_rent_rates.amount_minor` for the month (`src/Feature/Rent/RentStore.php:26-38`). It never pro-rates and never reads `assignment_rent`.

But the database's rent-charge guard, `stall_assert_rent_charge()` in `sql/022_assignment_rent.sql`, does **both**:

-   It applies the assignment override: `IF override_minor IS NOT NULL THEN rate_minor := override_minor;` (`:70-76`).
-   It pro-rates a move-in month: `IF assign_start > charge_month AND assign_start <= charge_end THEN … rate_minor := (days_charged * rate_minor) / days_in_month;` (`:119-123`).
-   Then it requires the posted debit to equal that (possibly overridden, possibly pro-rated) figure, or it raises `23514` "a rent charge debits that booth receivable and credits rent revenue for the stored rate" (`:146`).

The page posts the pre-override, pre-pro-ration figure. So the guard refuses it. I proved all three facets directly against a freshly provisioned tenant (`bin/probe-rent-charge.php`, which posts exactly what `RentController::charge()` posts):

```
move-in month, rent page posts the FULL stored rate (9000): REFUSED: SQLSTATE[23514] … a rent charge debits that booth receivable and credits rent revenue for the stored rate
move-in month, the DB wants the PRO-RATED rate (4800):      ACCEPTED
rated booth with no assignment, rent page posts the rate:   REFUSED: SQLSTATE[23514] … a rent charge requires an assignment that covers the charge date
assignment_rent override, rent page posts the base rate:    REFUSED: SQLSTATE[23514] … a rent charge debits that booth receivable and credits rent revenue for the stored rate
assignment_rent override, the DB wants the override (5000): ACCEPTED
```

### 3.1 Mid-month move-in (the ordinary case)

The page is explicitly built for mid-month starts: `RentLines::chargeDate()` returns the assignment's `starts_on` when it falls inside the month (`src/Feature/Rent/RentLines.php:220-232`), and `RentBookTest::testChargeDateFollowsTheMonth` asserts `charge_date === '2026-06-15'` for a booth that started on the 15th. So the page offers "Charge 90.00" for a booth that moved in on the 15th — and the database wants `(16 × 9000) / 30 = 4800`. The button fails. A mall vendor moves in on whatever day they move in; this is not an edge case.

The database's own proof, `sql/prove_rent_charge.sql`, documents this contract and would have caught the page: it sets up booth B3 starting `2026-03-15` with a `6200` override (`:267-279`), asserts that charging the **full** `6200` is refused (`RAISE EXCEPTION 'a mid-month move-in was charged the full override'`, `:323`), and asserts that the pro-rated `3400` is accepted (`:336-367`). The page does the thing the proof says must be refused.

### 3.2 `assignment_rent` override

`assignment_rent` is a real, wired feature — the assignment form writes it (`src/Feature/Party/PartyStore.php:244`) and the booth page shows "Overridden" (`tests/Http/CoverageRemainderTest.php:171`). The guard requires the override amount. `RentStore::rate()` ignores it, so the page posts the base rate and the guard refuses it. (The payout path shares this gap — `PayoutStore::rate()` at `src/Feature/Payout/PayoutStore.php:236-262` also reads only `booth_rent_rates` — so this facet is pre-existing, not new. It is still wrong, and the rent page inherits it.)

### 3.3 Rated booth with no assignment

`RentLines::blank()` (`src/Feature/Rent/RentLines.php:120-123`) keeps a booth on the book whenever it has a rate, even with no holder. `RentBook::due()` (`src/Feature/Rent/RentBook.php:190-193`) then reports it as "still to charge", and `rent.php` renders a Charge form for it. Clicking it posts a charge with no assignment covering the date, and the guard refuses it: "a rent charge requires an assignment that covers the charge date" (`sql/022_assignment_rent.sql:113`). The page offers an action that can never succeed.

### 3.4 The operator sees a confusing error

The refusal is caught (`DatabaseRefusal::fromPdo`) and rendered, so it is not a 500 — but the message the operator reads is the raw database text: _"a rent charge debits that booth receivable and credits rent revenue for the stored rate."_ The operator charged exactly the rate the page displayed. The message blames them for a figure the page got wrong.

* * *

## 4\. Why the tests did not catch it

This is the part that bothers me most, because the suite is otherwise good.

-   The HTTP test that exercises the button, `tests/Http/CoverageRemainderTest.php:252-270`, assigns the booth with `'starts_on' => $month` — the **first** of the month (`:254`) — and gives it no override. `assign_start > charge_month` is false, so no pro-ration runs, the full rate is accepted, and the test passes. The test is structured to walk around the exact code path that is broken.
-   The unit test `RentBookTest::testChargeDateFollowsTheMonth` asserts `due_minor === 17000` (`tests/Feature/Rent/RentBookTest.php:164`) for a booth that started on the 15th — i.e. it asserts the **full** rate is due for a move-in month, encoding the wrong behaviour as the expected behaviour.
-   `RentBookTest` is pure PHP with hand-built fixtures; nothing posts a charge through `RentController` against a database. So the guard that would have refused the charge never runs in the unit tests, and the one HTTP test that does run it never reaches the failing branch.

A test suite that avoids the pro-ration path and asserts the wrong amount is not coverage of this feature.

* * *

## 5\. Minor findings

1.  **Dead branch in `RentLines::chargeBooth()`.** It rejects a row whose `reversed` flag is truthy (`src/Feature/Rent/RentLines.php:305-307`), but `RentStore::facts()` never selects a `reversed` column (`src/Feature/Rent/RentStore.php:80-90`), so `$row['reversed'] ?? false` is always false. The production filter is the SQL `NOT EXISTS` alone. The unit test passes `reversed` in its fixture, so the test exercises a branch production never takes — a trap for whoever later "simplifies" the SQL.
2.  **`rent-payment:<reference>` omits the booth** (`src/Feature/Rent/RentController.php:80`). Two booths paid with the same receipt number collide on `journals_book_key` and the second is refused. Probably intended (a receipt number should be unique), but it is worth a deliberate decision rather than an accident.
3.  **The payout path shares the override gap** (§3.2). Not introduced here, but if the rent page is fixed and the payout path is not, the two surfaces will disagree about overridden booths.
4.  **`charges` matches by posting-key suffix, not by posting-date month** (`src/Feature/Rent/RentStore.php:84-90`). It is safe only because the guard forces the key's month to equal the posting date's month (`sql/022_assignment_rent.sql:80`). That coupling is undocumented; if the key convention ever loosens, this silently diverges.

* * *

## 6\. What the fix has to do

-   **Post the amount the database will accept.** Reuse `MoveInRent::due()` (already in the tree, `src/Feature/Party/MoveInRent.php`) for the move-in month, and read `assignment_rent` for the override, exactly as `PayoutSettler::chargeMissing()` does. The page and the payout path must compute the same figure.
-   **Only offer Charge when the booth can actually be charged.** Gate the button on an assignment that covers `charge_date`, and stop showing a rated-but-vacant booth as "still to charge" (or show it without a Charge action).
-   **Add the integration proof that was missing.** A test that posts `/rent/charge` for a booth whose assignment starts mid-month, and for a booth with an `assignment_rent` override, and asserts the posted amount equals what `stall_assert_rent_charge()` demands.
-   **Correct the unit test.** `testChargeDateFollowsTheMonth` currently asserts the wrong amount; it should assert the pro-rated figure.

* * *

## 7\. Verdict

**REJECT.**

-   `0c8971e` — **accepted.** The prior blocker is closed with the correct predicate, the minors are fixed, and the new proof plus `prove-suite`/`prove-race` back it up.
-   `2646b31` — **accepted.** A test-robustness fix; no production impact.
-   `c08651a` — **rejected.** The rent page's Charge button is refused by the database for mid-month move-ins, for overridden booths, and for rated-but-vacant booths. The page computes the amount one way and the ledger demands another. The tests are arranged so this never shows. Fix the amount (and the button's visibility), add the integration proof, and correct the unit test, and this flips to accept.

* * *

### Appendix — evidence

-   Probe: `bin/probe-rent-charge.php` (provisions a fresh tenant, posts exactly what `RentController::charge()` posts, rolls back, drops the tenant). Output quoted in §3.
-   Contracts: `sql/022_assignment_rent.sql` (`stall_assert_rent_charge`, `:70-76` override, `:119-123` pro-ration, `:113`/`:146` refusals); `sql/prove_rent_charge.sql` (B3 mid-month move-in, `:267-367`).
-   Page: `src/Feature/Rent/RentController.php:44-55`; `src/Feature/Rent/RentStore.php:26-38, 80-90`; `src/Feature/Rent/RentLines.php:120-123, 220-232, 305-307`; `src/Feature/Rent/RentBook.php:190-193`.
-   Payout path: `src/Feature/Payout/PayoutSettler.php:24-42`; `src/Feature/Payout/PayoutStore.php:87-110, 236-262`; `src/Feature/Party/MoveInRent.php`.
-   Tests: `tests/Http/CoverageRemainderTest.php:252-270`; `tests/Feature/Rent/RentBookTest.php:134-164`.
-   Fix under review: `sql/041_payout_run.sql` `missing_month` (`~:839`); `sql/prove_payout_reversal.sql`; `sql/042_payout_reversal.sql`; `sql/011_opening.sql`.