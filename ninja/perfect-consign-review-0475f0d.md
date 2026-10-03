# Hostile Review — perfect-consign @ `0475f0d`

**Cycle 8.** Pulled `8e2adfd..0475f0d`: **3 commits, 61 files, +2364 / −856**.

| Commit | Subject |
| --- | --- |
| `b76f79a` | Take cash rounding only on an all-cash sale, and keep the payment window readable on a small screen. |
| `fbd97f5` | Show a finished sale in a locked window and print a customer receipt. |
| `0475f0d` | Hold a layaway from the sale payment screen and give the customer a receipt for each deposit and refund. |

This batch is mostly floor UX (a locked "sale completed" window, a printable receipt, a layaway hold from the payment screen, deposit/refund slips) plus one real money rule: **cash rounding now applies only when the entire tender is cash**. I read the SQL diffs (`004`, `029`, `031`, `036`, `037`, `038`, `040`, `prove_cash_round`, `prove_tickets`), the full current money-path PHP (`SaleCheckout`, `SaleJournal`, `SalePoster`, `SaleReceipt`, `TenderSplit`, `TenderTickets`, `TicketStore`, `LayawayBook`, `LayawaySlip`), the Register refactor, the new UI templates, and I re-ran all four declared gates.

## Verdict in one line

The kernel is still the strongest part of this repo — the new all-cash rounding rule is enforced identically in PHP and in SQL and is proven by rollback proofs, and the cycle-7 paid-ticket↔sale hole (**W**) is now closed with two opposing proofs — **but the declared quality gate is still red**: `composer check` fails at PHPMD, and this batch replaced the ten cycle-7 violations with three fresh ones of its own (`SaleCheckout::pay()`, `TicketStore`). And the new layaway hold path is the first money path in this repo that is **not atomic**.

## What I actually ran

-   `php bin/check-max-lines.php` → **exit 0** ("All PHP files under src are within 500 lines").
-   `php vendor/bin/phpstan analyse` (level 10) → **\[OK\] No errors**.
-   `php vendor/bin/phpunit --testsuite unit` → **OK (47 tests, 158 assertions)**.
-   `php vendor/bin/phpmd src text phpmd.xml` → **exit 2, 3 violations** (was 10 at `8e2adfd`).
-   The `http`/`browser` suites need a provisioned tenant schema; this sandbox has no `consign` database, so I did not execute them. The new money paths are asserted by the SQL rollback proofs and by inspection.

## Status of prior findings

| # | Finding | Last status | Now | Evidence |
| --- | --- | --- | --- | --- |
| A | Store take never applied; free-text commission | MEDIUM | **RESOLVED** | `stall_assert_sale_shape` still derives commission from the bps chain. |
| B | bps parse/format + proration duplicated across layers | MEDIUM | **OPEN — MEDIUM** | Unchanged. The `10000`/`100000` constants now span **eight** files (`StoreTake`, `MallController`, `MallSettings`, `VendorForm`, `TicketAdjustment`, `TicketQuote`, `DenominationCount`, `registers.php`). |
| C | god-classes over the 500-line gate | HIGH | **RESOLVED** | Gate exit 0. |
| D | append-only trigger tamperable | withdrawn | **withdrawn** | genuinely proven |
| E | identifier casing | withdrawn | **withdrawn** | cosmetic |
| F | read-path caching | note | **note** | cache when it is slow |
| G | `WaitListStore::present()` throws → list page 500s | LOW | **OPEN — LOW** | `WaitListStore.php:251` still throws inside the list loop (`:62`). Unchanged. |
| H | barcode retry correct only by autocommit accident | LOW | **OPEN — LOW** | `InventoryStore::place` 5-attempt loop (`:146`). Unchanged. |
| I | `register_checks` had no append-only guard | MEDIUM | **RESOLVED** | unchanged. |
| J | `tax_payment` could move drawer cash | MEDIUM | **RESOLVED** | unchanged. |
| K | the bill-and-coin grid is silently synthesized | LOW | **OPEN — LOW** | `RegisterSession::close` (`:56`–`:57`) still overwrites the grid with `forAmount($typedCash)`. |
| L | `openYear` has no retry on the period-overlap exclusion | LOW | **OPEN — LOW** | `LedgerPeriods.php:84` still a bare `INSERT`. |
| N | discount/rounding math duplicated PHP↔SQL | MEDIUM | **OPEN — MEDIUM** | `TicketQuote::halfUp/tax/shares` vs `stall_line_discount`/`stall_assert_sale_shape`. I re-verified they agree _today_; the duplication is the risk. See **Y** for the new wrinkle. |
| O | `SalePoster::attach` ignores its `FOR UPDATE` result | LOW | **OPEN — LOW** | `SalePoster.php:155` still selects `FOR UPDATE` and discards the rows. |
| P | coverage gate needs Xdebug provisioned | NOTE | **NOTE** | unchanged. |
| Q | split-tender backend not reachable from the UI | LOW | **RESOLVED** | unchanged from cycle 7. |
| R | the shifts page shows a card/checks over-short the ledger never records | MEDIUM | **OPEN — MEDIUM** | `RegisterActivity::shifts` (`:66`–`:72`) still computes `card_difference`/`checks_difference`; `shifts.php:46`–`:47` still renders them; the only over/short account is `cash_over_short`. |
| S | the shifts list runs three queries per closed shift | LOW | **OPEN — LOW** | `shifts()` still calls `$this->drawer($sessionId)` per closed row (`:61`) and throws `drops` away. |
| T | expected-drawer formula duplicated PHP↔SQL | MEDIUM | **OPEN — MEDIUM** | PHP `RegisterActivity::expectedCash` (`:148`) vs SQL `stall_register_expected_cash` (`sql/030_register_close.sql:29`). Both still live. |
| U | presentation coupled to the difference string | NOTE | **OPEN — NOTE** | `shifts.php:49`–`:53` still shades by `str_ends_with($text, 'over'/'short')`. |
| V | the declared quality gate is red | MEDIUM | **OPEN — MEDIUM** | Still `exit 2`. The ten cycle-7 violations are fixed; three new ones were introduced. |
| W | the paid-ticket↔sale match does not check quantity | LOW | **RESOLVED** | `stall_assert_ticket`'s two `EXCEPT ALL` projections now include `quantity` (`sql/040_layaway.sql:149/153/160/164`), proven by `prove_tickets.sql` (both directions). |

## New findings from this batch

### V (continued) — MEDIUM — the declared quality gate is red again, with three new violations

`composer.json` defines `check` as `["@check:max-lines","@check:phpmd","@phpstan","@test"]`. The PHPMD step **fails**:

```
php vendor/bin/phpmd src text phpmd.xml   →  exit 2, 3 violations

src/Feature/Pos/SaleCheckout.php:29  CyclomaticComplexity      pay() has a Cyclomatic Complexity of 14 (threshold 10).
src/Feature/Pos/SaleCheckout.php:29  NPathComplexity           pay() has an NPath complexity of 288 (threshold 150).
src/Feature/Pos/TicketStore.php:13   ExcessiveClassComplexity  TicketStore has an overall complexity of 52 (threshold 50).
```

Credit where due: the ten violations at `8e2adfd` are gone — `MallSettings`, `InstantPiece`, `PosPage`, and `MallApp` were all cleaned up. But the gate is not zero, and `phpmd.xml`'s own description says _"Append rules only after `composer run probe:phpmd` reports zero violations under src/."_ The intent is zero. `composer check` aborts at PHPMD before it ever reaches PHPStan or the tests, so the one gate that is declared to protect the codebase is the one that is off.

The `SaleCheckout::pay()` hit is not cosmetic — it is the symptom of a method that now does five jobs: it decides hold-vs-pay, detects all-cash, computes the rounded cash due, rewrites the tender, and posts. That is exactly the kind of method where a future edit lands in the wrong branch. Split it (hold-layaway, settle-tender, post) or extract the all-cash/rounding decision into a small value object. `TicketStore` is a 396-line, 21-method god-class that owns drawer, cart, scan, price, remove, discount, waive, discard, park, layaway-listing, and recall — it wants to be two or three classes.

### Y — NOTE — the tax formula now exists in eight places, six of them the superseded two-decimal form

The three-decimal sales tax (`(net * tax_bps + 50000) / 100000`) is the live rule. But `stall_assert_sale_shape()` is `CREATE OR REPLACE`'d once per migration that touches it, and **six of those seven copies still carry the old two-decimal formula** `(net * tax_bps + 5000) / 10000`:

```
sql/004_sale.sql:107        expected_tax := (NEW.price_minor * tax_bps + 5000) / 10000;          ← stale
sql/029_register_posts.sql:91                                                                     ← stale
sql/031_sale_lines.sql:114                                                                        ← stale
sql/036_ticket_adjustments.sql:359                                                                ← stale
sql/037_item_quantity.sql:424                                                                     ← stale
sql/038_vendor_draw.sql:181                                                                       ← stale
sql/040_layaway.sql:495     expected_tax := ((NEW.price_minor * NEW.quantity - NEW.discount_minor) * tax_bps + 50000) / 100000;  ← live
```

Only `040` is live (it is the last migration to redefine the function), so the kernel is correct **today** — I traced it and the live SQL agrees byte-for-byte with `TicketQuote::tax` in PHP. But this is the concrete cost of **N**: the rate change had to be replicated by hand across seven files, and the next author who copies a function body from `036` or `037` to make a new migration will silently reintroduce the two-decimal tax. This is the exact drift the review has been warning about, now with receipts. Collapse the formula behind one function (or one PHP constant mirrored by one SQL function) so there is nothing to copy wrong.

### Z — MEDIUM — the layaway hold/refund path is the first money path here that is not atomic, and its deposit key is a counter (a retry double-posts)

Every other money path in this repo is atomic. `SalePoster::pay()` posts **one** journal and flips the ticket inside that journal's transaction via `beforeCommit` (`SalePoster.php:56`–`:60`), so the sale and the `open → posted` transition commit together. `LedgerStore::commitJournal` opens and commits its own transaction (`LedgerStore.php:161/178`).

`LayawayBook::hold()` does not do this:

```php
foreach ($parts as $part) {
    $this->postDeposit(...);            // each post() = its own beginTransaction()/commit()
}
$updated = $this->statements->affected( // ← a bare statement, outside any transaction
    "UPDATE tickets SET status = 'layaway' WHERE id = ?::uuid AND status = 'open'", [$ticketId]
);
```

Each deposit commits independently and the status flip happens afterwards as a standalone statement. If the flip fails, or the process dies between the last deposit and the flip, the deposits are **committed** while the ticket stays `open`. `cancel()` has the same shape (each refund commits, then the `DELETE` runs outside a transaction).

Worse, the deposit posting key is a **counter**, not a deterministic key:

```php
'layaway:' . $ticketId . ':' . $this->nextKey($ticketId)   // nextKey = count(*) + 1
```

The sale path uses `'sale:' . $ticketId` — deterministic, so a retried sale collides on `journals_book_key UNIQUE (book_id, posting_key)` and is refused. The layaway key is not idempotent: after a partial failure, a retry computes the _next_ count and posts a **second, distinct** deposit. The DB cap (`stall_assert_layaway`, "a layaway deposit cannot pass the amount due") only stops the double-post once the running total reaches the due — a double hold that stays under the due is accepted, and the customer is charged twice for one intended hold. The refund side is safer (a second full refund exceeds "what was paid in" and is refused), but the partial-state risk is identical.

Two fixes, either acceptable: (a) give `LedgerStore::post()` an "already in a transaction" mode (skip `beginTransaction` when `PDO::inTransaction()`), then wrap `hold()`/`cancel()` in one `Statements::transaction`; or (b) make the deposit key deterministic per intended deposit (e.g. hash of ticket + tender + amount + a client-supplied nonce) so a retry is idempotent. Right now neither is done, and this is the one place in the money path where a crash can move money twice.

## The new money paths, on the merits

I went at these hard. The rounding rule and the ticket-quantity fix hold up; the layaway receipt work is correct; the atomicity gap above is the one real defect.

**All-cash-only rounding (`b76f79a`).** The rule is now: round to the nearest nickel **only** when the whole tender is cash. It is enforced in two places that agree. In SQL, every copy of `stall_assert_sale_shape` now guards with `IF cash_debit = 0 OR cash_debit <> tender_sum OR round_cash IS DISTINCT FROM true THEN` (`004`, `029`, `031`, `036`, `037`, `038`, `040`) — i.e. rounding is only legal when the cash debit equals the total tender. In PHP, `SaleCheckout::pay()` computes `$allCash = $tender['cash'] > 0 && $nonCash === 0 && $deposits === 0`, `SalePoster::pay()` recomputes `$cashOnly = cash > 0 && cash === sum`, and `SaleJournal::sale()` re-checks `onlyCash($tenders, $cash)` before applying `CashRound::rounded($due) - $due`. The client mirror in `pay-ui.php` (`nickel()`) matches `CashRound::adjustment()` exactly (remainder 1/2 → down, 3/4 → up), and the server hands it `data-round=1` whenever deposits exist (`pos.php:70`), so the displayed change cannot diverge from the posted change. `prove_cash_round.sql` is thorough: rounding off rejects a rounded sale; rounding on accepts a round-down (1002→1000) and a round-up (1003→1005); an unrounded all-cash sale is refused ("a cash sale rounds to the nearest nickel"); a mixed cash+card sale is refused ("a sale debits the sold prices plus tax"); and cash+card on the cent is accepted. Correct and proven.

**Paid-ticket↔sale quantity (`W`, closed).** `stall_assert_ticket`'s two `EXCEPT ALL` projections now include `quantity` on both sides (`sql/040_layaway.sql:149`–`:164`), and `prove_tickets.sql` proves both directions — a ticket of qty 2 against a sale line of qty 1 is refused, and a ticket of qty 1 against a sale line of qty 2 is refused, both with "a paid ticket must match its sale". The database is the last line of defence again.

**Layaway from the payment screen (`0475f0d`).** `SaleCheckout::pay()` gained a `hold=1` branch that refuses a hold with no buyer ("Choose a customer to hold a layaway.") and otherwise calls `LayawayBook::hold()`. `hold()` splits the tender into parts by role (`parts()`), refuses anything that is not cash/card/check, refuses a deposit that would reach the amount due (so a full payment is a sale, not a hold), posts each part as a `layaway_deposit`, and flips the ticket `open → layaway`. The DB trigger `stall_assert_layaway` independently forces exactly two lines per deposit journal, requires the tender to equal the deposit, requires the ticket to be open/layaway, and caps the deposit at the amount due net of prior deposits. `LayawayBook::payments()` and `LayawaySlip` render the deposit/refund history, and `cancel()` refunds each open deposit and deletes the ticket. The logic is right; the atomicity (finding **Z**) is the gap.

**Register refactor.** `RegisterController` was split into `RegisterHub` + `RegisterShiftController`, and the three Register classes swapped `MallSettings` for a narrower `MallClock` (`localTime`/`localDate`) — a genuine tightening of dependencies. `RegisterActivity::shifts()` still computes the card/checks difference the ledger never posts (**R**, unchanged).

## Recommended priority

1.  **V** — make `composer check` green: split `SaleCheckout::pay()` and break up `TicketStore`. A declared gate that does not pass is the discipline failure that keeps this short of world class.
2.  **Z** — make the layaway hold/refund path atomic (one transaction) and give the deposit key an idempotent shape. This is the only place a crash can move money twice; it is worth fixing before the next floor feature lands on top of it.
3.  **R** — decide whether card/checks over-short is real accounting. If it is, post it to real accounts and show it on the tape; if it is not, stop rendering a difference the ledger does not carry.
4.  **N / Y / B / T** — collapse the duplicated money math behind one module each. The tax formula now exists in eight places, six of them stale; the next rate change will make it worse again.
5.  **G / H / K / L / O / S / U** — the standing low-severity list; none are money bugs.

## Trajectory

Upward, and the kernel stayed clean: the all-cash rounding rule is enforced in both languages and proven, the paid-ticket quantity hole is closed with proofs, and the layaway receipts are correct. The two things that keep this short of "world class" are still about discipline, not design. First, a quality gate that is declared and does not pass (**V**) — the ten old violations were fixed, which is progress, but three new ones replaced them. Second, the habit of writing the same money formula twice in two languages (**B/N/T**), which this batch made concrete: the tax formula is now copied across seven migrations, six of which are wrong. The genuinely new concern is **Z**: the layaway hold is the first money path in this repo that is not atomic, and its posting key is a counter, so a retry double-posts. Fix the gate, make the hold atomic, and collapse the duplicated formulas, and the process will finally match the kernel.