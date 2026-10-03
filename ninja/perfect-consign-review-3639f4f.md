# Hostile Review — perfect-consign @ `3639f4f`

**Commit:** `3639f4f` — "Let a missed page and the front door send an owner back into the mall." **Delta since last review (`ac36f30`):** 5 commits, 112 files, +11287 / −1829. **Commits in this batch:**

-   `fdc1ce8` Show a signed-in dashboard of sales, payables, and booth occupancy.
-   `21797fa` Replace the books sale form with a floor register.
-   `702ddcc` Raise measured line coverage past 80% and split the classes that exceeded the complexity gate.
-   `d3d077a` Show over or short when closing a register, and let an open ticket be finished, discounted, or tax-waived first.
-   `3639f4f` Let a missed page and the front door send an owner back into the mall.

**Scope of this batch:** the POS floor register (open cart / ticket, scan / price / park / recall / discard, split tender, cash rounding to the nickel, void, piece return), ticket discounts and tax waivers, the 80 % line-coverage gate, the `LedgerStore`/`RegisterStore` god-class splits, the owner dashboard, and the front-door miss handling. **Method:** read the new SQL kernel (`031`–`036`, plus diffs to `004`, `029`), the whole `Feature/Pos` slice, the `Ledger` refactor (`JournalDraft`, `JournalSource`, `CashRound`, `LedgerPeriods`, `LedgerStore`), the `Register` split (`RegisterActivity`, `RegisterSession`, `ActiveRegister`), the `Dashboard` slice, the front-door (`MallApp`, `TenantLookup`, `RootController`, `not-found.php`, `root.php`, `layout.php`), the new proofs (`prove_sale_lines`, `prove_tickets`, `prove_ticket_adjustments`, `prove_piece_return`, `prove_cash_round`, `prove_park`, `prove_race_sale_*`, `prove_race_ticket_*`), the coverage tooling, and the tests. Nothing below is inferred; every claim cites code I read.

* * *

## Verdict in one line

This is the **best batch yet** and it closes two of the three findings that were blocking the "enterprise" claim: the **500-line gate is green** (Finding C resolved) and the **store take is finally wired** end-to-end in the database (Finding A resolved). The one-piece-sold-once invariant, cash rounding, discounts, tax waivers, returns, and parking are all enforced in SQL with `ENABLE ALWAYS` triggers and proven by race harnesses. **But** the store-take/rounding math is now **duplicated in PHP and SQL with no shared source of truth** (a divergence refuses every discounted sale), the `register_checks` append-only hole and the `tax_payment` drawer leak from last cycle are **still open**, and the split-tender backend is **not reachable from the UI**. Enterprise bar: close, but not met.

* * *

## Status of prior findings

| # | Finding | Last status | Now | Evidence |
| --- | --- | --- | --- | --- |
| A | Store take stored on piece/vendor/mall but never applied; sale posts free-text commission | MEDIUM | **RESOLVED** | `sql/036_ticket_adjustments.sql` `stall_assert_sale_shape` now computes `expected_commission := (price*commission_bps + 5000)/10000` from the `COALESCE(item, vendor, mall)` chain and refuses any mismatch (`a sale commission does not match the store take`); the free-text commission form is gone (the books sale form was replaced by the POS register). Proven in `prove_sale_lines.sql` and `prove_ticket_adjustments.sql`. |
| B | bps parse/format + proration duplicated across layers | MEDIUM | **OPEN — MEDIUM (worsened)** | bps parsing now in **5** sites: `VendorForm.php:40`, `MallController.php:179`, `MallSettings.php:127`, `StoreTake.php:19`, `TicketAdjustment.php:63`. Half-up rounding now in **both** `TicketQuote::halfUp` (PHP) and `stall_line_discount`/`stall_assert_sale_shape` (SQL). Rent proration still in `MoveInRent::due` (PHP) and `sql/022_assignment_rent.sql` (SQL). See new finding **N**. |
| C | god-classes over the 500-line gate (incl. the ledger kernel) | HIGH | **RESOLVED** | `php bin/check-max-lines.php` → "All PHP files under src are within 500 lines.", exit 0. Longest: `LedgerStore.php` 377 (was 504), `PartyController.php` 366, `MallApp.php` 365, `registers.php` 335, `VendorRoster.php` 329, `TicketStore.php` 326. `RegisterStore.php` (608) was split into `RegisterActivity.php` (read model) + `RegisterSession.php` (write model). |
| D | append-only trigger tamperable | withdrawn | **withdrawn** | genuinely proven |
| E | identifier casing | withdrawn | **withdrawn** | cosmetic |
| F | read-path caching | note | **note** | cache when it is slow |
| G | `WaitListStore::present()` throws → whole list page 500s | LOW | **OPEN — LOW** | `WaitListStore.php:251` `default => throw new \InvalidArgumentException('a wait list status is missing')`; `present()` is called in the list loop (`:62`). One bad row still 500s the list. |
| H | barcode retry correct only by autocommit accident | LOW | **OPEN — LOW** | `InventoryStore::place` still loops 5 attempts (`:145`–`:155`) catching `items_barcode_key`; still correct only because autocommit is left on. |
| I | `register_checks` has no append-only UPDATE/DELETE guard | MEDIUM | **OPEN — MEDIUM** | `sql/029_register_posts.sql` still gives `register_checks` **only** `register_checks_no_truncate`. No `_append_only` (BEFORE INSERT OR UPDATE) and no `_no_delete`. |
| J | `tax_payment` can move drawer cash outside the register session | MEDIUM | **OPEN — MEDIUM** | `TaxController::submit` still posts `tax_payment` crediting `roleAccount($credit)` where `$credit` ∈ {`cash`,`bank`}, with **no `sessionId`**. A cash tax payment still reduces the `cash` role without being linked to a shift, so the drawer expected figure does not subtract it. |
| K | the bill-and-coin grid is silently synthesized | LOW | **OPEN — LOW** | `RegisterSession::close` (`:55`–`:57`) still does `if ($fromBills !== $typedCash) { $counts = forAmount($typedCash); }`, overwriting the operator's grid with a synthetic breakdown. `open` does the same when the grid totals zero. |
| L | `openYear` has no retry on the period-overlap exclusion | LOW | **OPEN — LOW (downgraded)** | `LedgerPeriods::openYear` still does a bare `INSERT` with no retry. It is no longer a 500: `LedgerStore::commitJournal` catches `PDOException` and converts it via `DatabaseRefusal::fromPdo`, so the loser of a concurrent first-of-year race gets a **raw Postgres message** ("conflicting key value violates exclusion constraint "accounting\_periods\_no\_overlap"") instead of succeeding or getting a friendly refusal. |

* * *

## New findings from this batch

### N — MEDIUM — the discount split and the half-up rounding now live in two languages with no shared source of truth

This is the sharpest new risk in the batch, and it is the same class as B but with teeth. The **ticket discount** algorithm — percent split per line, amount split by price with the last line absorbing the remainder, and half-up rounding — is implemented **twice**:

-   in PHP, `TicketQuote`:

```php
// src/Feature/Pos/TicketQuote.php
public static function halfUp(int $price, int $bps): int {
    return intdiv($price * $bps + 5000, 10000);
}
private static function amountShares(array $prices, int $value): array {
    ...
    $share = intdiv($price * $value + intdiv($total, 2), $total);
    ...
    $shares[] = $value - $running;   // last line absorbs the remainder
}
```

-   and in SQL, `stall_line_discount` / `stall_assert_sale_shape` (`sql/036_ticket_adjustments.sql`), which recompute the **same** per-line share and **refuse the sale** if the `sale_lines.discount_minor` the PHP wrote does not match (`a sale discount does not match the ticket`).

The PHP is only a quote; the SQL is the authority. But the two are independent re-implementations of a non-trivial integer algorithm (half-up, integer `total/2` midpoint, last-line remainder). Any divergence — a rounding mode, an off-by-one in the midpoint, a change to one side only — does not degrade gracefully: it makes **every discounted sale fail at commit** with a refusal the cashier cannot act on. This is exactly the "no single source of truth" problem the platform has otherwise been disciplined about (the money kernel is one place, the ledger shape is one place). The fix is to have one side own the algorithm: either compute the shares in SQL only and have PHP read them back, or expose the SQL function and call it from PHP — not two copies.

The same duplication now exists for the **half-up commission/tax** (`TicketQuote::halfUp` vs `(price*bps + 5000)/10000` in `stall_assert_sale_shape`) and for **cash rounding** (`CashRound::adjustment` in PHP vs the `CASE exact_cash%5 ...` in `stall_assert_sale_shape`). Cash rounding in particular is proven correct on both sides today (`prove_cash_round.sql` and `CashRoundTest.php` agree), but they are two copies of the same table.

### O — LOW / NOTE — `SalePoster::attach` takes a row lock it never checks

```php
// src/Feature/Pos/SalePoster.php
$this->statements->bound(
    'SELECT id FROM tickets WHERE id = ?::uuid AND status = ? FOR UPDATE',
    ...
);
$this->samePrices($ticketId, $pieces);
```

The `FOR UPDATE` is the right instinct — it serializes two payers racing the same open ticket. But the returned row is discarded, so if the ticket vanished (discarded by another tab) the `SELECT` simply returns nothing, the lock is a no-op, and the code proceeds to `samePrices`. The subsequent re-read is what actually protects correctness, so this is not a bug today; it is a lock whose result is ignored, which is the kind of thing that reads as "we locked it" while the invariant is really enforced elsewhere. Either check the row (refuse if null) or drop the lock and say the re-read is the guard.

### P — NOTE — the coverage gate is honest, but only reproducible with Xdebug provisioned

`bin/coverage-run.php` sets `XDEBUG_MODE=coverage` and `PC_COVERAGE`, runs `phpunit --testsuite unit,http`, then `bin/coverage-report.php`. The report is **not** vacuous: with no dumps it writes `no coverage dumps` and `exit(1)` (`:34`), and it also `exit(1)` if `percent + 0.001 < 80.0` (`:86`). So an environment without Xdebug fails the gate loudly rather than passing it silently — that is the correct design and worth crediting. The caveat is operational: the ">80 %" claim is only reproducible where the Xdebug extension is installed and `tests/bootstrap.php` can call `xdebug_start_code_coverage` (it returns early otherwise, `:26`). Whoever runs the gate in CI must provision Xdebug, or the gate is red for the wrong reason.

### Q — LOW / NOTE — the split-tender backend is not reachable from the floor UI

`TenderSplit::read` correctly builds one debit line per non-zero `tender_<role>` and the SQL enforces the split (`tender_lines = tender_roles`, `tender_sum = debit_total`, per-role subledger typing). But the POS charge modal exposes a **single** radio (`pos.php:207`–`212`, `name="charge_tender"`) and the JS copies the chosen tender into exactly one hidden `tender_<role>` field (`pos.php:257`–`268`). So a real customer paying half cash, half card cannot be rung at the counter even though the kernel supports it. This is a capability gap, not a correctness bug — but the batch's headline feature ("split tender") is only half-shipped: the hard part (the ledger shape) is done and the easy part (the form) is not.

* * *

## What is genuinely good in this batch

-   **The store take is finally real, and it is enforced in the database.** `stall_assert_sale_shape` (replaced in `036`) computes commission from the `COALESCE(item.commission_bps, vendor.commission_bps, mall.commission_bps)` chain, computes tax from `mall.tax_bps` (zero for a tax-exempt vendor or a waived ticket), and refuses any `sale_lines` row that disagrees. Finding A is closed by construction, not by convention. `prove_sale_lines.sql` even proves the store-keeps-100 % case and the exempt-vendor case.
-   **One piece sells once — at three independent layers, race-proven.** `sale_lines_item_key UNIQUE (journal_id, item_id)` stops a double line; `stall_assert_sale_shape` rejects a piece already sold (`a piece is already sold`); `stall_assert_ticket_line` rejects a piece already on another open **or parked** ticket (`a piece is already on a ticket`), taking `FOR UPDATE OF item` to serialize the check. `prove_race_sale_a/b.sql` and `prove_race_ticket_a/b.sql` assert the second session **waits >4.5 s** and then **succeeds after the first rolls back** — the correct proof shape, wired into `prove_race.ps1`.
-   **The open ticket is explicitly _not_ a journal, and the model is honest about it.** `tickets`/`ticket_lines` are mutable while open (scan/price/remove), and `stall_assert_ticket` pins the open→posted transition to a journal whose `(item_id, price_minor)` set matches the ticket via `EXCEPT ALL`. A ticket cannot post at a price the journal did not charge (`a paid ticket must match its sale`). `prove_tickets.sql` proves the price-override-while-open path and the wrong-price refusal.
-   **Cash rounding to the nickel is computed in SQL and proven.** `stall_assert_sale_shape` derives `exact_cash`, `round_delta := CASE exact_cash%5 WHEN 1 THEN -1 WHEN 2 THEN -2 WHEN 3 THEN 2 WHEN 4 THEN 1 ELSE 0 END`, and refuses an unrounded cash sale (`a cash sale rounds to the nearest nickel`) and a rounding line while the mall setting is off. `prove_cash_round.sql` covers off / down / up / already-a-nickel / card-stays-on-the-cent / split.
-   **Discounts and waivers are constrained, not free.** `stall_refuse_ticket_discount` rejects a discount larger than the store take; `net_commission = commission − discount`; tax is computed on `(price − discount)`; per-vendor payable is derived by `FULL OUTER JOIN` on `(price − commission)`. `prove_ticket_adjustments.sql` proves the percent split (100 on the hat, 300 on the coat), the missing-share refusal, and the posted-ticket immutability.
-   **A return must reverse that exact piece, and the piece can be sold again.** `piece_returns_sale_item_key UNIQUE (sale_journal_id, item_id)` blocks a double return; `stall_assert_piece_return` requires `tender_credit = price − discount + tax`, `commission_debit = commission − discount`, `payable_debit = price − commission`; and `prove_piece_return.sql` proves the coat returns and re-sells while the lamp on the same ticket stays sold.
-   **The new append-only tables get the full treatment.** `sale_lines`, `piece_returns`, `tickets`, `ticket_lines` all carry `ENABLE ALWAYS` guards and are covered by the event-trigger that rejects `ALTER … DISABLE TRIGGER`, `DROP TRIGGER`, `CREATE OR REPLACE FUNCTION`, `session_replication_role = replica`, and `event_triggers = false`. `prove_sale_lines.sql` / `prove_piece_return.sql` / `prove_tickets.sql` exercise every one of those attacks, including the `stall_poster` role.
-   **The god-class gate is green without cheating.** `LedgerStore` was split from 504 → 377 and `RegisterStore` (608) into `RegisterActivity`/`RegisterSession`; the gate was not raised. Finding C is genuinely closed.
-   **The front door does not leak across tenants.** `MallApp::notFound` distinguishes `MISS_HIDDEN` / `MISS_MALL` / `MISS_RECORD` / `MISS_ROUTE`; a signed-in owner who hits a missing page gets the dashboard and sidebar (`SmokeTest` "recovers from missing page"), an unknown mall address stays on the door, and the miss page is `noindex`. `TenantLookup` validates both the slug and the `tenant_<32hex>` schema before use.
-   **The coverage gate fails loudly, not silently** (see P) — `no dumps → exit 1`, `below floor → exit 1`.

* * *

## Recommended priority

1.  **N (MEDIUM)** — collapse the discount/rounding math to one source of truth. Right now `TicketQuote` (PHP) and `stall_line_discount`/`stall_assert_sale_shape` (SQL) are two copies of the same integer algorithm; a divergence refuses every discounted sale. Pick the SQL function as the authority and have PHP call it, or compute in PHP and store the result. Do not keep both.
2.  **I (MEDIUM)** — add `register_checks` append-only `UPDATE`/`DELETE` triggers (`ENABLE ALWAYS`), matching `register_session_posts`. This was flagged last cycle and is unchanged.
3.  **J (MEDIUM)** — decide and enforce: a cash tax payment must name an open shift, or it must not touch the `cash` drawer role. Update `docs/business-rules.md` so the rule set and the code agree. This was flagged last cycle and is unchanged.
4.  **B (MEDIUM)** — one bps parse/format helper and one rent-proration helper. The batch added two more bps sites and a fresh PHP-vs-SQL rounding pair, so this is trending the wrong way.
5.  **Q (LOW)** — expose split tender in the charge modal; the kernel already supports it.
6.  **G, H, K, L, O (LOW)** — presenter must not 500 a list page; document or remove the barcode-retry autocommit coupling; make the synthesized count visible; add a retry to `openYear` (and a friendly message instead of the raw exclusion text); check or drop the ignored `FOR UPDATE` in `SalePoster::attach`.

* * *

## Trajectory

Two cycles ago the blockers were the store take and the god-classes. Both are now genuinely closed, and the POS slice is the most rigorously proven work in the repo: the money invariants live in the database, the append-only discipline is uniform on the new tables, and the race harness actually runs. What remains is the **duplication debt** (N, B) — the same arithmetic in two languages — and two carried MEDIUM holes (I, J) that have now survived a full cycle of pushback. The bar is enterprise world class; the arithmetic must have one home, and a check number and a drawer must be as immutable and as accounted-for as everything else.

* * *

_Standing instruction honored: no finding was withdrawn to accommodate pushback. A and C are marked RESOLVED only because the code refutes them (the gate exits 0; the commission is computed from the bps chain in SQL). D and E remain withdrawn because the code refutes them. B, G, H, I, J, K, L stand on the code cited above, and N/O/P/Q are new._