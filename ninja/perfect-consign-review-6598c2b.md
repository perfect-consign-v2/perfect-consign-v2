# Hostile review — perfect-consign @ `6598c2b`

**Commit under review:** `6598c2b` "Record mall store take, vendor agreement facts, and a holder-style vendor page." **Scope:** the 4 commits since `5cb8535` (`d04a060` booth place + Ninja cell map, `e75c8d1` vendor directory + Active standing + booth color, `8e8f682` first/last name + contact + active standing, `6598c2b` store take + vendor agreement + holder-style vendor page). **Method:** read the full diff (55 files, +3076/−381), the new/changed SQL, the new domain classes, controllers, stores, views, JS/CSS, and the new tests. No live Postgres in this sandbox, so SQL behaviour is reviewed statically against the kernel's existing invariants.

**Verdict:** The accounting kernel is still intact — append-only holds, no money columns leaked into `vendors`, and the new fields are all identity/agreement facts with tight CHECK constraints. But the _new_ features are the weakest layer of the codebase: **the headline "store take" is a dead setting that never touches the ledger**, and **the booth floor-placement guard is not concurrency-safe** even though the repo already ships the correct pattern for the analogous assignment overlap. Both are the kind of gap the repo's own "prove-before-advance" discipline is supposed to catch.

* * *

## Findings, ranked

### 1\. HIGH — "Store take" is stored but never applied; the ledger still takes a hand-typed commission

`docs/business-rules.md` states: _"Store take is a percent of the sale kept by the mall. Settings holds the mall default. Anyone who can add or edit a vendor can save a different amount."_ The commit dutifully adds `mall.commission_bps` and `vendors.commission_bps` (both `integer`, CHECK 0–10000), a Settings field, a per-vendor field, and an "Overridden" badge.

But nothing consumes them. `SaleController::submit()` (`src/Feature/Sale/SaleController.php:42`) reads a free-text `commission` field off the sale form and posts it verbatim:

```php
$commission = $this->optionalMinor('commission');   // hand-typed
$payable    = $amount - $commission - $tax;
...
$lines[] = PostedLines::entry($this->ledger->roleAccount('commission_revenue'), 0, $commission, ...);
```

`grep -rn commission src/` confirms `commission_bps` is referenced only by the Mall/Party settings and store code — **never by the sale path**. Consequences:

-   The mall default take and the per-vendor override have **zero effect on any posted journal**. A vendor marked "Overridden" at 10% still posts whatever the cashier types.
-   The documented rule ("store take is a percent of the sale") is therefore _unimplemented_, not merely untested. The UI presents a setting that looks authoritative but is inert — the worst kind of feature, because it invites an operator to trust it.
-   The sale form's `commission` field is a raw decimal parsed by `Money::parseMinor`, with no cross-check against the vendor's stored take, no cap, and no audit trail tying the number to the agreement.

This is the single most important gap in the release. Either wire `commission_bps` into the sale posting (compute `commission = round(amount * bps / 10000)` server-side, using the vendor's override or the mall default, and stop trusting the form field), or remove the setting and the rule until it is real. Right now the codebase claims a capability it does not have.

### 2\. HIGH — Booth floor placement is not concurrency-safe (write-skew); the correct pattern already exists in-repo

`sql/020_mall.sql` guards placement with a `BEFORE INSERT OR UPDATE` trigger, `stall_reject_booth_place` → `stall_assert_booth_on_floor`. The function reads the mall defaults and scans `public.booths` with a **plain SELECT — no `FOR UPDATE`, no advisory lock, no exclusion constraint**:

```sql
SELECT 1 FROM public.booths AS other
WHERE other.id IS DISTINCT FROM booth
  AND other.x_ft IS NOT NULL
  AND NOT ( ... AABB non-overlap ... )
```

Under the default READ COMMITTED isolation, two concurrent transactions each placing a booth see the same pre-insert snapshot, both pass the AABB test, and both commit — producing **two overlapping booths on the floor**. The same write-skew applies between `stall_reject_floor_change` (the `mall_floor` trigger, which re-validates every placed booth against a shrinking floor) and a concurrent placement: a booth can land outside a floor that is being shrunk.

What makes this a finding rather than a nit is that the repo **already solves exactly this problem correctly** for the sibling invariant. `sql/003_vendors_booths.sql:96`:

```sql
CONSTRAINT booth_assignments_no_overlap EXCLUDE USING gist (
    booth_id WITH =, daterange(starts_on, ends_on, '[]') WITH &&
);
```

`btree_gist` is present (asserted in `prove_tenant.sql`), and `stall_reject_assignment_rewrite` even takes `FOR UPDATE` locks on the vendor/booth rows. So the team knows both the exclusion-constraint idiom and the lock idiom — and used neither for floor placement. The placement overlap should be an `EXCLUDE USING gist` box constraint (or the trigger should `SELECT ... FROM mall FOR UPDATE` and lock the candidate neighbourhood), otherwise the invariant is only true for single-threaded use.

### 3\. MEDIUM — "A vendor who owes the store cannot be made inactive" is enforced only in PHP, via check-then-act, with no DB guard and no test

The rule is real and important: _"A vendor who owes the store cannot be made Inactive. The vendor owes the store when the vendor payable balance is negative, or when a booth that vendor held still has a positive open rent receivable."_

It is enforced in exactly one place — `PartyController::setStanding()` (`src/Feature/Party/PartyController.php:170`):

```php
$inactive = $this->app->request()->field('standing') === 'inactive';
if ($inactive) {
    $open = $this->payouts->openPayout($id);
    if ($open['payable'] < 0 || $open['rent'] > 0) {
        ... refuse ...
    }
}
$this->parties->setActive($id, !$inactive);
```

Problems:

-   **No database guard.** `vendors.active` has no trigger; `setActive` is a bare `UPDATE vendors SET active = ...`. Every other hard invariant in this repo (append-only, non-negative subledgers, sale shape, floor bounds) lives in SQL and survives direct writes. This one does not. Any future controller, admin path, migration, or psql session can flip a vendor Inactive while they owe the store. That is inconsistent with the project's own "attack the guard" standard.
-   **TOCTOU race.** `openPayout()` runs, then `setActive()` runs as a separate statement. A rent charge posted in between (a rent charge only _increases_ the receivable, so the kernel's non-negative trigger does not stop it) lets the vendor be made Inactive while owing rent.
-   **Zero test coverage.** `grep -rn -i "owe\|inactive\|standing" tests/` returns nothing. The rule is documented and coded but never exercised — so it is one refactor away from silent deletion, and the "prove-before-advance" discipline was not applied to it.

Recommendation: put the guard in the kernel as a `BEFORE UPDATE OF active` trigger on `vendors` (refuse `false` when payable < 0 or an open rent receivable exists), so it is atomic and unbypassable, and add a `prove_vendors_booths.sql` case plus an HTTP test.

### 4\. MEDIUM — The rule's first clause is unreachable, and the vendor page's "They owe us" tile is therefore always $0.00

The kernel's `stall_assert_subledger_non_negative` (`sql/003_vendors_booths.sql:351`, deferred constraint trigger on `journals`) forbids `vendor_payable` from going below zero:

```sql
HAVING sum(line.credit_minor - line.debit_minor) < 0   -- payable below zero → reject
```

Because the ledger is append-only and every journal touching a payable line is checked, `payable >= 0` is a hard invariant. So:

-   The `$open['payable'] < 0` branch of the owes-the-store check in Finding 3 can **never fire**. The only clause that can block Inactive is `rent > 0`.
-   On the vendor page (`src/Feature/Party/vendor.php`), `$theyOwe = max(0, -$vendor['payable_minor'])` is consequently **always `$0.00`**. The "They owe us" stat is dead UI. The genuine "they owe us" figure is the separate "Open rent" tile.

This is not a money bug (the kernel is correct), but it is a documentation/UI inconsistency: `business-rules.md` describes a payable-negative state that the kernel makes impossible, and the UI renders a tile that can never be non-zero. Either drop the unreachable clause and the tile, or explain why a negative payable is contemplated when the kernel forbids it.

### 5\. LOW — God-object drift continues; `PartyController` is now within 22 lines of the 500-line guard

The prior review flagged `MallApp` and `LedgerStore`. This release grows the same way:

-   `src/Feature/Party/PartyController.php` — **478 lines** (was ~50 before this arc). It now owns the vendor directory, the vendor create/edit form, standing changes, deletion, and the vendor record page, plus private helpers `storedTake`, `points`, `percent`, `plain`, `enabled`, `postedFacts`, `form`, `showRecord`. `bin/check-max-lines.php` trips at 500; this file is one feature away from failing the gate.
-   `src/Feature/Party/PartyStore.php` — **374 lines**, mixing vendor CRUD, booth CRUD, assignments, rates, and validation.

The feature-sliced layout is the right idea, but these two files are becoming per-feature god objects. Standing/delete, store-take parsing, and the vendor page are separable.

### 6\. LOW — Percent/basis-point parsing and formatting is copy-pasted in three places

The same algorithm exists independently in:

-   `MallController::basisPoints()` + `MallController::percent()`
-   `PartyController::storedTake()` + `PartyController::points()` + `PartyController::percent()`
-   (and the same "×100 + fraction" idea again in `Money::parseMinor`)

Each re-implements the `"X.YY" → bps` parse and the `bps → "X.Y"`/`"X.YY"` format with slightly different regexes (`\d{1,3}` vs the money parser's rules). They agree today; they will drift. This belongs in one shared `Percent`/`BasisPoints` value type next to `Money`.

### 7\. LOW — "Store take = mall default" is stored as `NULL`, so an explicit choice is indistinguishable from "inherit"

`PartyController::storedTake()` returns `null` when the entered value equals the current mall default:

```php
if ($basis === $this->mall->storeTake()) { return null; }
```

So a vendor deliberately set to 30% while the mall default is 30% is persisted as `NULL` ("inherit"), not `30`. If the mall default later moves to 25%, that vendor silently follows to 25% instead of holding at 30%. This may be the intended "overridden only when different" semantics, but it means the operator's explicit entry is not durable and the "Overridden" badge cannot distinguish "chose the default" from "never chose". Worth a conscious decision and a line in `business-rules.md`.

* * *

## What is genuinely good (credit where due)

-   **Kernel untouched.** No money column was added to `vendors`; `prove_vendors_booths.sql` actively asserts that the identity tables carry no numeric/money columns (it whitelists only `width_ft`, `depth_ft`, `x_ft`, `z_ft`, `commission_bps`). Append-only and the deferred non-negative triggers are unaffected.
-   **CHECK constraints are thorough.** `commission_bps` 0–10000 (both tables), agreement end ≥ start, `tax_exempt = false OR tax_exempt_number IS NOT NULL`, trim/blank/length guards on every text field, and an email regex in the DB (`^[^@[:space:]]+@[^@[:space:]]+\.[^@[:space:]]+$`) that mirrors the PHP one. Blank resale numbers are rejected in SQL, and the prove script exercises each of these.
-   **Validation is defence-in-depth.** Time zone is checked in PHP (`timezone_identifiers_list`) _and_ in the DB (`mall_reject_zone`); store take is range-checked in PHP _and_ by CHECK. Booth coordinates/sizes are validated in `BoothController::feet/coordinate` and again by the floor trigger.
-   **Output is escaped.** Every dynamic value in `vendor.php`/`vendor-form.php` goes through `View::text()`; money through `View::money()`. `map.js` writes only via `textContent`/`setAttribute` (no `innerHTML` with user data). CSRF is enforced on every POST; `open($slug, true)` runs before each write, so tenant isolation via `search_path`/`SET ROLE` is preserved.
-   **The assignment overlap is correct.** `booth_assignments_no_overlap EXCLUDE USING gist` is the right, concurrency-safe pattern — which is precisely why its absence for booth placement (Finding 2) stands out.
-   **Tests exist for the new happy paths.** `OwnerJourneyTest` covers tax-exempt-requires-number, agreement dates, notes, and the store-take override/matched/restore flow; `VendorRosterTest` covers roster assembly. The gap is specifically the _negative_ invariants (Findings 1, 3, 4).

* * *

## Recommended priority

1.  **Decide the store take's fate** (Finding 1): wire it into the sale posting or delete it. Do not ship an inert money setting.
2.  **Make booth placement concurrency-safe** (Finding 2): `EXCLUDE USING gist` box constraint, or lock the mall row in the trigger.
3.  **Move the owes-the-store guard into SQL** (Finding 3) and add proof + HTTP coverage; then reconcile the unreachable payable-negative clause and the dead "They owe us" tile (Finding 4).
4.  Extract a shared percent/bps type (Finding 6) and split `PartyController`/`PartyStore` before they trip the 500-line gate (Finding 5).