# Hostile review — perfect-consign @ `ad15e56`

**Previous review:** `6598c2b`. **Now re-pulled to:** `ad15e56` "Let the stall poster read assignment rent." **New commits since the last review (6):**

| Commit | Subject |
| --- | --- |
| `7082e84` | Lock booth placement on the mall row and refuse Inactive while open rent remains. |
| `78f7bd1` | Color an available booth green so it is distinct from an incoming one. |
| `b69f8ba` | Show each booth's holder, rent, and whether it sits on the map. |
| `401ff32` | Give the booth map floors, a one-foot editor, and status counts, and record a stay's own rent. |
| `79f6bed` | Give brands a searchable list, dialogs for new and rename, and a refusal when a name is in use. |
| `ad15e56` | Let the stall poster read assignment rent. |

**Diff since last review:** 50 files, +3435 / −490. New SQL `021_map_floors.sql`, `022_assignment_rent.sql`; new race proofs; new domain classes (`BoothRoster`, `BoothMeasures`, `MapStore`, `BoothListStore`, `MoveInRent`, `AssignmentOffer`, `PartyRecord`); a brand-list rework.

**Verdict:** This is a strong response to the last review. **Three of my five findings are fixed** — the two HIGH ones and the concurrency one — and the fixes use the right idioms (row lock on the mall row + race proofs; a DB-level `ENABLE ALWAYS` trigger for the Inactive rule). The kernel remains intact and the new rent-proration path is enforced in SQL with a frozen-after-charge guard. The remaining issues are a now-_documented_ inert setting, a duplicated money formula across the PHP/SQL boundary, and god-object files that are about to trip the repo's own 500-line gate.

* * *

## Status of the previous findings

| # | Previous finding | Status now |
| --- | --- | --- |
| 1 | HIGH — Store take stored but never applied | **Open, but reclassified.** Now explicitly documented as intended ("The sale still posts the commission amount entered on that sale."). See Finding A. |
| 2 | HIGH — Booth placement not concurrency-safe | **FIXED.** `SELECT … FROM mall FOR UPDATE` in both placement and floor-change triggers, plus `prove_race_place_*` / `prove_race_floor_*` proofs that assert the wait. |
| 3 | MEDIUM — Inactive-while-owing enforced only in PHP | **FIXED.** New `stall_reject_inactive_with_open_rent` trigger (`vendors_open_rent`, `ENABLE ALWAYS`) + prove cases. |
| 4 | MEDIUM — Unreachable payable<0 clause / dead "They owe us" tile | **FIXED (by reconciliation).** `business-rules.md` now says "A vendor with open rent cannot be made Inactive", dropping the impossible clause. |
| 5 | LOW — God-object drift | **Worsened.** `PartyController` is now 491 lines — 9 short of the 500-line gate. See Finding C. |
| 6 | LOW — Percent/bps parsing duplicated | **Partially addressed.** `PartyRecord` extracted the duplicated record-DL rendering; the bps/percent parsing is still duplicated. See Finding B. |
| 7 | LOW — store-take==default stored as NULL | **FIXED (documented).** `business-rules.md`: "A saved amount equal to the mall default is the default, and it follows a later change to that default." |

* * *

## Findings, ranked

### A. MEDIUM — The store take is now _documented_ as inert, but the sale form still trusts a free-text commission with no link to the vendor's agreement

The good news: the previous HIGH is no longer a silent lie. `business-rules.md` now states plainly, _"The sale still posts the commission amount entered on that sale."_ So the mall default and the per-vendor override are a **saved reference**, not an enforced rate — and that is a deliberate decision, not an oversight.

The residual problem is that the reference and the posting are completely disconnected:

-   `SaleController::submit()` (`src/Feature/Sale/SaleController.php:42`) still reads a free-text `commission` field and posts it verbatim. `grep -rn commission_bps src/Feature/Sale/ src/Ledger/` returns nothing.
-   A vendor marked "Overridden" at 10% can be rung up at 0% or 90% with no warning, no cap, and no audit link between the posted commission and the stored agreement. The only guard is `$payable = $amount - $commission - $tax; if ($payable < 1) throw …` — i.e. the commission may be anything up to the sale total.
-   Because the setting is now documented as advisory, an operator who trusts it has no way to tell, from the ledger, whether a given sale honoured the agreement.

This is no longer a "claimed capability that doesn't exist" bug, but it is still a footgun. The cheapest honest fix is to pre-fill the sale form's commission from `commission_bps` (vendor override else mall default) and to record, on the journal or a side table, which rate was in force — or to label the setting clearly in the UI as "default for the sale form" rather than "the mall's take".

### B. MEDIUM — The move-in proration money formula is implemented twice, in two languages, and the two are only coincidentally equal

`401ff32` introduced move-in proration. It lives in **two** places:

-   **Enforcement (SQL):**`stall_assert_rent_charge` in `sql/022_assignment_rent.sql` (and the updated `sql/005_rent_charge.sql`) computes, when the assignment starts mid-month:
    
    ```sql
    days_in_month := (charge_end - charge_month) + 1;
    days_charged  := (charge_end - assign_start) + 1;
    rate_minor    := (days_charged * rate_minor) / days_in_month;   -- integer division
    ```
    
-   **Display (PHP):**`MoveInRent::due()` (`src/Feature/Party/MoveInRent.php`), used only in `vendor.php:181`:
    
    ```php
    $daysCharged = $daysInMonth - $day + 1;
    return intdiv($daysCharged * $monthly, $daysInMonth);
    ```
    

Today they agree (both truncate, both count the start day). But the _posted_ amount is decided by SQL and the _shown_ amount by PHP, and nothing ties them together. If either is edited — a rounding change, a leap-year edge, a different definition of "days charged" — the vendor page will display one number and the ledger will post another, silently. The repo's own "prove-before-advance" standard would put the formula in one place (a SQL function the PHP calls, or a shared `Money`/proration helper) and prove the two agree.

Two secondary notes on the same formula:

-   **Truncation is the rounding policy** (`(days*rate)/days` truncates toward zero). That is a legitimate choice, but it is undocumented and it means the prorated first month is always rounded _down_ in the mall's favour by up to a cent. Worth a line in `business-rules.md`.
-   **Proration is asymmetric.** A mid-month _move-in_ is prorated; a mid-month _move-out_ is not — the vendor is charged the full month regardless of `ends_on`. That may be intended (the class is named `MoveInRent`), but combined with the "open rent blocks Inactive" rule it means a vendor who leaves on the 5th still owes a full month and cannot be made Inactive until it is paid. If that is the intent, say so.

### C. MEDIUM — `PartyController` is 491 lines; the next feature trips the 500-line god-class gate

`bin/check-max-lines.php` fails at 500 lines. Current counts:

| File | Lines |
| --- | --- |
| `src/Feature/Party/PartyController.php` | **491** |
| `src/Feature/Party/PartyStore.php` | 426 |
| `src/Feature/Party/VendorRoster.php` | 388 |
| `src/Feature/Party/BoothController.php` | 294 |
| `src/Feature/Party/MapController.php` | 280 |
| `src/Feature/Party/BoothRoster.php` | 284 |

`PartyController` grew by another 13 lines in this batch and now carries the vendor directory, the vendor create/edit form, standing changes, deletion, the vendor record page, _and_ the private helpers (`storedTake`, `points`, `percent`, `plain`, `enabled`, `postedFacts`, `form`, `showRecord`). It is one small feature away from failing CI. `PartyStore` (426) is close behind.

Credit where due: this batch did real decomposition — `BoothMeasures`, `BoothRoster`, `MapStore`, `BoothListStore`, `MoveInRent`, `AssignmentOffer`, `PartyRecord` are all clean, single-purpose classes with `declare(strict_types=1)`, typed return shapes, and no SQL string interpolation. That is exactly the right direction. The fix is to apply the same split to `PartyController` (e.g. a `VendorForm`/`VendorPage` collaborator and a shared `Percent` value type) before it hits the wall.

### D. LOW — The Inactive refusal message drifted from the rule it enforces

The rule was reworded in `business-rules.md` to _"A vendor with open rent cannot be made Inactive"_, and the DB trigger raises _"a vendor with open rent cannot be made inactive"_. But the controller still shows the old copy, and the new HTTP test asserts it:

-   `src/Feature/Party/PartyController.php:440` → `'A vendor who owes the store cannot be made inactive.'`
-   `tests/Http/OwnerJourneyTest.php` → `assertSees('A vendor who owes the store cannot be made inactive.', …)`

Since the enforceable clause is now specifically _open rent_, the UI copy should match ("A vendor with open rent cannot be made inactive"), otherwise the operator is told a rule broader than the one actually enforced.

### E. LOW — The HTTP test exercises only the controller's pre-check, not the DB trigger

The new `testAVendorWithOpenRentCannotBeMadeInactive` asserts the _controller's_ message, which is produced by the PHP check-then-act path before the UPDATE is attempted. The DB trigger (`vendors_open_rent`) is the real, unbypassable guard — and it is proven in `sql/prove_vendors_booths.sql`, but **not** through the HTTP layer. That is acceptable (the SQL proof is the stronger evidence), but note the two are independent implementations of the same rule: the controller checks `payable < 0 || rent > 0`, the trigger checks `rent > 0` only. They agree in effect today because `payable < 0` is unreachable, but the duplication is another place for drift.

* * *

## What is genuinely good in this batch

-   **The concurrency fix is textbook.** Both `stall_reject_booth_place` and `stall_reject_floor_change` now take `FOR UPDATE` on the `mall` row, and the new `prove_race_place_hold/wait` and `prove_race_floor_hold/wait` scripts assert both that the second writer _waits_ (>4.5 s) and that it is _refused_ with the right message. `MapStore::savePlacements` also takes the lock explicitly before its bulk re-layout. This is the repo's "attack the guard" discipline applied correctly.
-   **The Inactive rule is now a kernel invariant.** `vendors_open_rent` is `BEFORE UPDATE OF active`, `ENABLE ALWAYS`, and proven both ways in `prove_vendors_booths.sql` (a vendor the store still owes _can_ be made inactive; a vendor with open rent _cannot_; once rent is paid they _can_). This is exactly what the previous review asked for.
-   **Rent proration is enforced, not trusted.** `stall_assert_rent_charge` recomputes the expected debit/credit from the stored rate (or the assignment override), requires exactly two lines, and refuses if the posted amount does not match. `assignment_rent` is frozen after a charge is posted (`assignment_rent_frozen_after_charge`), is `CHECK (amount_minor > 0)`, and `stall_poster` is denied DELETE/TRUNCATE/TRIGGER.
-   **Floors are modelled properly.** `map_floors` with a per-floor overlap check (`other.floor_id IS NOT DISTINCT FROM place_floor`), a `booths_floor_chk` tying `floor_id` to placement, an insert cap (20) and a delete guard (keep ≥1, refuse if booths reference it), all `ENABLE ALWAYS`. The one-foot map editor re-validates through the same trigger.
-   **Provenance is maintained.** `bin/provision-tenant.php` and `bin/prove-tenant.php` both learned `021` and `022`, so the migration list stays in lockstep.
-   **Output escaping and CSRF remain solid.** Every new controller route calls `checkCsrf()`; `PartyRecord` routes all values through `View::text()`; the new JS uses `textContent`/`setAttribute`, never `innerHTML` with user data.

* * *

## Recommended priority

1.  **Decide the store take's contract** (Finding A): either pre-fill the sale commission from `commission_bps` and record the rate in force, or relabel the setting in the UI as a form default. Do not leave an advisory rate that looks authoritative.
2.  **Unify the proration formula** (Finding B) so display and posting cannot drift, and document the truncation and the move-in-only symmetry.
3.  **Split `PartyController`** (Finding C) before it trips the 500-line gate — the collaborators introduced this batch show the pattern.
4.  **Align the refusal copy** (Finding D) with the rule, and consider whether the controller's pre-check and the DB trigger should be one implementation (Finding E).