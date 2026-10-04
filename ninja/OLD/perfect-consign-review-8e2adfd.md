# Hostile Review — perfect-consign @ `8e2adfd`

**Cycle 7.** Pulled `65683a1..8e2adfd`: **3 commits, 85 files, +7562 / −444**.

| Commit | Subject |
| --- | --- |
| `d572155` | Split store take on the price after a discount, and finish the floor register. |
| `b028a83` | Let the mall set a three-decimal sales tax and keep register notices on the cart. |
| `8e2adfd` | Score operator mutants on the unit suite and kill the ones that still pass. |

This is the largest functional batch so far. It adds a real POS floor (split tenders, vendor draw, gift issue, layaway, instant add, quantity/on-hand, line discounts), moves sales tax to three decimals, and adds a mutation-testing gate. I read the new SQL kernel (`037`–`040`), the diffs to `031`/`036`, the new proofs, the Pos/Inventory/Mall slices, and `bin/mutate.php`, and I re-ran the gates.

## Verdict in one line

The accounting kernel is still the strongest part of this repo — the new money paths (layaway, vendor draw, on-hand, line discounts) are enforced in the database with deferred constraint triggers and proven by rollback proofs — **but the declared quality gate is now red**: `composer check` fails at PHPMD, and this batch added 7 of the 10 violations.

## What I actually ran

-   `php bin/check-max-lines.php` → **exit 0** ("All PHP files under src are within 500 lines").
-   `php vendor/bin/phpstan analyse` (level 10) → **\[OK\] No errors**.
-   `php vendor/bin/phpunit --testsuite unit` → **OK (47 tests, 158 assertions)**.
-   `php vendor/bin/phpmd src text phpmd.xml` → **exit 2, 10 violations** (was 3 at `65683a1`).
-   The `http`/`browser` suites need a provisioned tenant schema; this sandbox has no `consign` database, so I did not execute them. The new money paths are asserted by the SQL rollback proofs and by inspection.

## Status of prior findings

| # | Finding | Last status | Now | Evidence |
| --- | --- | --- | --- | --- |
| A | Store take never applied; free-text commission | MEDIUM | **RESOLVED** | `stall_assert_sale_shape` still derives commission from the bps chain. |
| B | bps parse/format + proration duplicated across layers | MEDIUM | **OPEN — MEDIUM (worsened)** | Now a _third_ decimal is threaded through the same duplicated sites (`MallController.php:207/:224`, `MallSettings.php:133/:136`, `TicketQuote.php:11/:16`, `VendorForm.php:40`, `StoreTake.php:19`). |
| C | god-classes over the 500-line gate | HIGH | **RESOLVED** | Gate exit 0; longest `src/Feature/Pos/pos.php` 421. |
| D | append-only trigger tamperable | withdrawn | **withdrawn** | genuinely proven |
| E | identifier casing | withdrawn | **withdrawn** | cosmetic |
| F | read-path caching | note | **note** | cache when it is slow |
| G | `WaitListStore::present()` throws → list page 500s | LOW | **OPEN — LOW** | `WaitListStore.php:251` still throws inside the list loop (`:62`). Unchanged. |
| H | barcode retry correct only by autocommit accident | LOW | **OPEN — LOW** | `InventoryStore::place` 5-attempt loop (`:145`). Unchanged. |
| I | `register_checks` had no append-only guard | MEDIUM | **RESOLVED** | unchanged from last cycle. |
| J | `tax_payment` could move drawer cash | MEDIUM | **RESOLVED** | unchanged from last cycle. |
| K | the bill-and-coin grid is silently synthesized | LOW | **OPEN — LOW** | `RegisterSession::close` (`:56`–`:57`) still overwrites the grid with `forAmount($typedCash)`. |
| L | `openYear` has no retry on the period-overlap exclusion | LOW | **OPEN — LOW** | `LedgerPeriods.php:84` still a bare `INSERT`. |
| N | discount/rounding math duplicated PHP↔SQL | MEDIUM | **OPEN — MEDIUM** | `TicketQuote::halfUp/tax/shares` vs `stall_line_discount`/`stall_assert_sale_shape`. I verified they agree _today_; the duplication is the risk. |
| O | `SalePoster::attach` ignores its `FOR UPDATE` result | LOW | **OPEN — LOW** | `SalePoster.php:151` still selects `FOR UPDATE` and discards the rows. |
| P | coverage gate needs Xdebug provisioned | NOTE | **NOTE** | unchanged. |
| Q | split-tender backend not reachable from the UI | LOW | **RESOLVED** | `pay-modal.php` now renders one form per tender role (cash/card/checks/vendor\_draw/gift/store credit), each "Apply", with "Still due" remaining. The backend is now reachable. |
| R | the shifts page shows a card/checks over-short the ledger never records | MEDIUM | **OPEN — MEDIUM** | `RegisterActivity::shifts` (`:66`–`:72`) still computes `card_difference`/`checks_difference`; `shifts.php:69`–`:70` still renders them; `RegisterSession::close` still posts variance for **cash only** (`:107`), and the only over/short account is `cash_over_short` (`sql/028_tenders.sql:29`). |
| S | the shifts list runs three queries per closed shift | LOW | **OPEN — LOW** | `shifts()` still calls `$this->drawer($sessionId)` per closed row and throws `drops` away. |
| T | expected-drawer formula duplicated PHP↔SQL | MEDIUM | **OPEN — MEDIUM** | PHP `RegisterActivity::expectedCash` vs SQL `stall_register_expected_cash` (`sql/030_register_close.sql:29`). Both still live. |
| U | presentation coupled to the difference string | NOTE | **OPEN — NOTE** | `shifts.php:49`–`:53` still shades by `str_ends_with($text, 'over'/'short')` and rebuilds the closure per row. |

## New findings from this batch

### V — MEDIUM — the declared quality gate is red, and this batch made it worse

`composer.json` defines `check` as `["@check:max-lines","@check:phpmd","@phpstan","@test"]`. The PHPMD step **fails**:

```
php vendor/bin/phpmd src text phpmd.xml   →  exit 2, 10 violations
```

At `65683a1` it was **3** violations; this batch added **7**:

```
src/Feature/Mall/MallSettings.php:12   TooManyPublicMethods     (11 public methods)
src/Feature/Mall/MallSettings.php:113  CyclomaticComplexity 11 / NPath 324
src/Feature/Pos/InstantPiece.php:20    ExcessiveParameterList   (11 parameters)
src/Feature/Pos/InstantPiece.php:64    CyclomaticComplexity 10 / NPath 288
src/Feature/Pos/PosPage.php:24         BooleanArgumentFlag      ($reopenInstant)
```

`phpmd.xml`'s own description says _"Append rules only after `composer run probe:phpmd` reports zero violations under src/."_ — the intent is zero. The gate is not zero, and `composer check` aborts here before it ever reaches PHPStan or the tests. The correctness gate (PHPStan level 10) is green, so this is a maintainability/process failure rather than a money bug — but a gate that is declared and does not pass is exactly the kind of corner the bar here says not to cut. The two `InstantPiece` hits are easy to fix (pass a small value object instead of 11 scalars; split `finish()`).

### W — LOW — the paid-ticket↔sale match does not check quantity

`stall_assert_ticket` (the `open → posted` transition, `sql/040_layaway.sql:141`–`:170`) proves the ticket matches its sale with two `EXCEPT ALL` queries over **`(item_id, price_minor)`** only:

```sql
SELECT item_id, price_minor FROM ticket_lines WHERE ticket_id = NEW.id
EXCEPT ALL
SELECT item_id, price_minor FROM sale_lines  WHERE journal_id = NEW.posted_journal_id
```

Quantity is not in the comparison, and nothing else in the kernel cross-checks the cart's quantity against `sale_lines.quantity`. Under the app this is fine — `SalePoster::samePrices()` compares quantity before posting — but the whole design premise is that the database is the last line of defence, and here it is not. A cart that says two and a sale line that says one would pass the kernel. Add `quantity` to both `EXCEPT ALL` projections.

### X — NOTE — dead entries in the mutation tool's replacement table, and a hand-maintained equivalence allowlist

`bin/mutate.php` is a genuinely useful addition (it scores operator mutants on the unit suite and fails on survivors). Two small smells:

-   The string-token path only fires for `! < > + - * / %` (see `isOperatorToken`), so the `$replacements` entries for `&& || == != === !== <= >= and or` can never be applied — those are handled by the `T_*` token-name swaps instead. Harmless dead data, but it reads as if more mutants are generated than really are.
-   `equivalentReason()` is a hand-maintained allowlist of 8 `(file, line, from, to)` tuples that whitelist survivors as "equivalent". Each has a written justification, which is the right way to do it, but it is a list a future author can quietly extend to silence a real survivor. Consider asserting the count, or moving the justifications next to the code.

## The new money paths, on the merits

I went at these hard because they are the largest surface added so far. They hold up.

**On-hand quantity (`037_item_quantity.sql`).** `items.quantity` defaults to 1 and is `>= 0`; `ticket_lines`/`sale_lines`/`piece_returns` carry `quantity > 0`. `stall_assert_ticket_line` takes `FOR UPDATE OF item` and refuses `NEW.quantity + held > on_hand`, where `held` sums every open/parked/layaway line for that piece. `stall_assert_sale_shape` decrements `items.quantity` by the sold quantity and refuses a sale past on hand. `stall_restore_on_hand` (a new `ENABLE ALWAYS` trigger on `journals`) puts units back on a void and on a return. `prove_on_hand.sql` walks the whole cycle (sell 2 → 3 left; sell 4 → refused; return 1 → 4; void → 5) and `prove_race_on_hand.ps1` proves the second seller **waits > 4.5 s** on the row lock and then rolls back. This is correct and it is proven.

**Per-unit return proration.** `stall_assert_piece_return` prorates `(sale_price * sale_quantity * NEW.quantity) / sale_quantity` for a partial return and lets the last return absorb the remainder (`sale_price * sale_quantity - prior_price`), so the sum of returns is exactly the sale. Commission, tax, and discount prorate the same way. The payable/commission/tax debits are re-derived and matched. Solid.

**Store take on the post-discount price (`036`).** `expected_commission := ((price*qty − discount) * commission_bps + 5000) / 10000` and vendor payable is `price*qty − discount − commission`. The "discount larger than the store take" refusal is gone, replaced by "the discount is more than the price". That is the correct model — the store can discount into its own take — and the docs were updated to match. Good.

**Three-decimal sales tax (`031`, `b028a83`).** `tax_bps` is now in millis (`<= 100000`), and the migration multiplies by 10 **only if the old `mall_tax_bps_chk` still exists**, so re-running is idempotent. `TicketQuote::tax` and the SQL both use `(net * tax_bps + 50000) / 100000`. Consistent.

**Vendor draw (`038`).** A sale may debit `vendor_payable` (subledger `vendor`) once, capped at `sum(prior credit − prior debit)` for that vendor — i.e. the payable that existed _before_ this sale, not including the payable this sale is about to create. `prove_vendor_draw.sql` seeds a payable, refuses a draw above it, and accepts a draw within it. Correct.

**Layaway (`040`).** New account `2230 Layaway deposits` (liability, control, subledger `ticket`), source types `layaway_deposit`/`layaway_refund`. `stall_assert_layaway` forces exactly two lines (the tender and the deposit), refuses a deposit above the amount due (net of prior deposits) and a refund above what was paid in, and requires the ticket to be open/layaway. The sale debits the accumulated deposits as a tender. `LayawayBook` posts deposits/refunds and moves the ticket `open → layaway`; `cancel` refunds each open deposit and deletes the ticket. The PHP and SQL agree on the caps.

**Line discounts (`039`).** A line discount is stored on the line with a required reason code from a fixed set; `stall_line_discount` splits a ticket-level amount across lines by post-line-discount price with the last line taking the remainder — byte-for-byte the same algorithm as `TicketQuote::amountShares`. One thing I chased and cleared: `039` creates a standalone `ticket_lines_line_discount` trigger that is **not** `ENABLE ALWAYS`, which looked like a hole — but `040` **drops** it and folds the same logic into `stall_assert_ticket_line`, which runs under `ticket_lines_no_delete` (an `ENABLE ALWAYS` trigger). So there is no hole. That is good hygiene.

The business-rules doc was rewritten to match all of the above, and the new `FloorCoverageTest` cases (`testTheCartSplitsACertificateAndAVendorDraw`, `testADiscountAndATaxWaiverStayOnTheReceipt`, the layaway recall flow) exercise them over HTTP.

## Recommended priority

1.  **V** — make `composer check` green: fix the two `InstantPiece` smells and the `MallSettings`/`PosPage`/`MallApp` ones, or explicitly drop the rules and say so. Do not leave a declared gate red.
2.  **W** — add `quantity` to the paid-ticket↔sale `EXCEPT ALL` match. Cheap, and it restores the database as the last line of defence.
3.  **R** — decide whether card/checks over-short is real accounting. If it is, post it to real accounts and show it on the tape; if it is not, stop rendering a difference the ledger does not carry.
4.  **B / N / T** — collapse the duplicated money math (bps parse/format, discount shares, expected drawer) behind one module each. Three-decimal tax made B worse; the next rate change will make it worse again.
5.  **G / H / K / L / O / S / U** — the standing low-severity list; none are money bugs.

## Trajectory

Upward, and materially so. The kernel absorbed four new money paths without a single correctness hole I could find, and the proofs kept pace (`prove_on_hand`, `prove_vendor_draw`, `prove_layaway`, `prove_line_discount`, plus the on-hand race). The `open → posted` ticket pinning and the deferred `stall_assert_sale_not_on_ticket` ordering are subtle and correct. The two things that keep this short of "world class" are both about discipline rather than design: a quality gate that does not pass (**V**), and the habit of writing the same money formula twice in two languages (**B/N/T**). Fix those and the process matches the kernel.