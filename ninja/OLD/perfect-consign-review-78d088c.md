# Hostile Review — perfect-consign @ `78d088c`

**Cycle 9.** Pulled `0475f0d..78d088c`: **1 commit, 29 files, +542 / −229**.

| Commit | Subject |
| --- | --- |
| `78d088c` | Keep a layaway hold and its deposits in one transaction, and share one half-up for commission and tax. |

This is a **remediation batch**, and it is the strongest one yet. It goes straight at three of the review's open findings: it collapses the duplicated money math into one PHP class and two SQL functions (**Y**), makes the layaway hold/refund path atomic (**Z**), and — for the first time in this project — turns the declared quality gate green (**V**). It also quietly closes a long tail of low-severity findings (**O, L, K, U, G, H, T, S, B**). I read the full SQL diff (`004, 029, 031, 036, 037, 038, 039, 040`), the new `BasisPoints` and `TicketQueue`, the changed PHP (`LedgerStore`, `Statements`, `LedgerPeriods`, `LayawayBook`, `SaleCheckout`, `SalePoster`, `TicketStore`, `TicketQuote`, `TicketAdjustment`, the Register trio, `WaitListStore`, `InventoryStore`, `StoreTake`, `MallController`, `MallSettings`, `VendorForm`), re-ran all four declared gates, and — because this sandbox finally has a live PostgreSQL — **provisioned a fresh tenant and ran the entire `sql/prove_*.sql` suite for real.**

## Verdict in one line

The kernel work in this batch is correct and the gate is finally green — the money math now lives in exactly one place per language, the layaway hold is atomic, and the sale poster checks the lock it takes — **but actually running the proofs, which no prior cycle did, shows the SQL proof suite is not green**: `prove_chart` still asserts a 10-role chart that is now 18, `prove_export` catches that the export account map is missing `cash_rounding` and `layaway_deposit` (so a QuickBooks/Xero export _refuses_ any period containing a cash-rounded sale or a layaway deposit), and two truncate-guard proofs fail. The safety net the review has been trusting does not itself pass.

## What I actually ran

**The four declared gates — all green for the first time:**

-   `php bin/check-max-lines.php` → **exit 0** ("All PHP files under src are within 500 lines").
-   `php -d error_reporting="E_ALL & ~E_DEPRECATED & ~E_USER_DEPRECATED" vendor/bin/phpmd src text phpmd.xml` → **exit 0, zero violations** (was exit 2 with 3 violations at `0475f0d`).
-   `php vendor/bin/phpstan analyse --memory-limit=512M` (level 10) → **\[OK\] No errors**.
-   `php vendor/bin/phpunit --testsuite unit` → **OK (47 tests, 158 assertions)**.

`composer check` therefore now passes end to end. That is a real milestone: the ten violations at `8e2adfd` and the three at `0475f0d` are all gone, and the batch that removed them did not add new ones.

**The SQL proof suite — run for the first time, and it is not green.** This sandbox has a live PostgreSQL 18.6 on port 5436. I provisioned a fresh tenant at the current code (`php bin/provision-tenant.php review9 Review9`) and ran every standalone `sql/prove_*.sql` against it with `search_path` set to the tenant schema. (The sandbox's `consign` database was itself stale — it was missing the `platform.reject_guard_function_*` event triggers from `018`, so the first pass reported spurious "replacing the X function was accepted" failures; I re-applied `018_owner_backup.sql` to install them and re-ran.)

```
42 proofs run → 34 PASS, 8 FAIL

FAIL  prove_app.sql               cross-tenant proof hard-codes the mall schema (harness)
FAIL  prove_append_only_guard.sql  TRUNCATE items hits the FK check before the guard trigger
FAIL  prove_chart.sql             asserts count(chart_roles) = 10; the chart is now 18
FAIL  prove_export.sql            asserts export_accounts = chart_roles * 2; it is 32, not 36
FAIL  prove_tenant.sql            needs a demo tenant schema that does not exist here (harness)
FAIL  prove_tenant_race_hold.sql  race pair run sequentially (harness)
FAIL  prove_tenant_race_wait.sql  race pair run sequentially (harness)
FAIL  prove_vendors_booths.sql    TRUNCATE hits the FK check before the guard trigger
```

Every **money-path** proof passes: `prove_sale`, `prove_cash_round`, `prove_layaway`, `prove_tickets`, `prove_sale_lines`, `prove_line_discount`, `prove_ticket_adjustments`, `prove_vendor_draw`, `prove_register_close`, `prove_registers`, `prove_sessions`, `prove_tenders`, `prove_item_return`, `prove_account_balances`, `prove_tax_payment`, `prove_payout`, `prove_rent_*`. The new `stall_half_up` / `stall_tax_minor` functions are exercised by those proofs and hold. The four cross-tenant/race failures are harness artifacts (they need a specific demo tenant or two concurrent sessions). The two genuinely interesting failures are `prove_chart` and `prove_export` — see **AA/AB**.

## Status of prior findings

| # | Finding | Last status | Now | Evidence |
| --- | --- | --- | --- | --- |
| A | Store take never applied; free-text commission | MEDIUM | **RESOLVED** | unchanged. |
| B | bps parse/format + proration duplicated across layers | MEDIUM | **RESOLVED** | New `src/Ledger/BasisPoints.php` owns `percent()`, `taxRate()`, `halfUp()`, `tax()`; `StoreTake`, `MallController`, `MallSettings`, `VendorForm`, `TicketAdjustment`, `TicketQuote` all delegate. No bps literal remains outside `BasisPoints` (the surviving `10000`/`100000` in `src/` are cash denominations, not bps). |
| C | god-classes over the 500-line gate | HIGH | **RESOLVED** | gate exit 0. |
| D | append-only trigger tamperable | withdrawn | **withdrawn** | — |
| E | identifier casing | withdrawn | **withdrawn** | — |
| F | read-path caching | note | **note** | — |
| G | `WaitListStore::present()` throws → list page 500s | LOW | **RESOLVED** | `unknown()` now logs a warning and returns a fallback label (`WaitListStore.php`); the list loop can no longer throw on an unexpected status. |
| H | barcode retry correct only by autocommit accident | LOW | **RESOLVED** | `InventoryStore::place` now wraps each attempt in `Statements::isolated()`, which uses a `SAVEPOINT` when inside a transaction and runs plainly otherwise — so the retry is safe whether or not a caller has opened a transaction. |
| I | `register_checks` had no append-only guard | MEDIUM | **RESOLVED** | unchanged. |
| J | `tax_payment` could move drawer cash | MEDIUM | **RESOLVED** | unchanged. |
| K | the bill-and-coin grid is silently synthesized | LOW | **RESOLVED** | `RegisterSession::close` now refuses when a non-empty grid does not equal the typed cash ("the bills and coins must add up to the counted cash"); it only synthesizes when the grid total is 0. `open()` got the same guard. |
| L | `openYear` has no retry on the period-overlap exclusion | LOW | **RESOLVED** | `LedgerPeriods::openYear` now retries up to 3 times on `23P01`/`23505` and `insertPeriod` uses a `SAVEPOINT` when joined to an outer transaction. |
| N | discount/rounding math duplicated PHP↔SQL | MEDIUM | **OPEN — MEDIUM** | The _tax and half-up_ half of this is now centralised (Y). The _proration_ half is not: `TicketQuote::shares/percentShares/amountShares` still re-implements the last-line-takes-the-remainder rule that `stall_line_discount`/`stall_assert_ticket_line` enforce in SQL. |
| O | `SalePoster::attach` ignores its `FOR UPDATE` result | LOW | **RESOLVED** | `attach()` now throws "a posted ticket cannot change" when the `SELECT … FOR UPDATE` returns no rows. |
| P | coverage gate needs Xdebug provisioned | NOTE | **note** | — |
| Q | split-tender backend not reachable from the UI | LOW | **RESOLVED** | unchanged. |
| R | the shifts page shows a card/checks over-short the ledger never records | MEDIUM | **OPEN — MEDIUM** | `RegisterActivity::shifts` still computes `card_difference`/`checks_difference` (`:68`–`:69`) and `shifts.php` still renders them with over/short shading (`:57`–`:59`). The numbers are now derived from the real posted tenders (an improvement), but `cash_over_short` is still the only variance account, so a card/checks "over/short" is still a figure the ledger never books. |
| S | the shifts list runs three queries per closed shift | LOW | **RESOLVED** | `shifts()` now computes all tenders in one `shiftTenders()` query instead of calling `drawer()` per closed row. |
| T | expected-drawer formula duplicated PHP↔SQL | MEDIUM | **RESOLVED** | `RegisterActivity::expectedCash` now calls `stall_register_expected_cash(session.id)`; the PHP copy is gone. |
| U | presentation coupled to the difference string | NOTE | **RESOLVED** | `DenominationCount::mark()` returns `diff-over`/`diff-short`/`diff-even` server-side; the `str_ends_with($text, 'over')` shading in `shifts.php` is gone. |
| V | the declared quality gate is red | MEDIUM | **RESOLVED** | PHPMD exit 0; all four gates green. `SaleCheckout::pay()` was split into `entered`/`hold`/`settle`/`roundOn`/`cashDue`; `TicketStore` shed `park`/`layaways`/`recall` into the new `TicketQueue`. |
| W | the paid-ticket↔sale match does not check quantity | LOW | **RESOLVED** | unchanged from cycle 8. |
| Y | the tax formula exists in eight places, six stale | NOTE | **RESOLVED** | The formula now exists exactly twice: `stall_half_up`/`stall_tax_minor` in `sql/004_sale.sql` (SQL) and `BasisPoints::halfUp`/`tax` (PHP). Every call site delegates. I grepped: no inline `+5000)/10000` or `+50000)/100000` survives anywhere outside those two definitions. |
| Z | the layaway hold/refund path is not atomic; the deposit key is a counter | MEDIUM | **RESOLVED** | `LayawayBook::hold`/`cancel` now run the deposits and the ticket flip inside one `Statements::transaction`; `LedgerStore::commitJournal` gained an "already in a transaction" mode (`if (!$joined) begin/commit`) so `post()` joins the outer transaction. A rollback now un-posts every deposit with the flip. |

## New findings from this batch

### AA — MEDIUM — the SQL proof suite is not green, and the review has been trusting it

The whole point of this project's kernel discipline is that every money rule is asserted by a `sql/prove_*.sql` that rolls back. Cycle after cycle I have written "proven by `prove_*.sql`" on the strength of the file _existing_. Running them shows two of them have been **failing**, silently, for several commits:

```
prove_chart.sql:132   SELECT count(*) INTO role_count FROM chart_roles;
                      IF role_count <> 10 THEN RAISE EXCEPTION 'chart roles changed after rollback';
                      -- actual: 18 roles

prove_export.sql:250  SELECT count(*) INTO map_count FROM export_accounts;
                      IF map_count <> (SELECT count(*) FROM chart_roles) * 2 THEN
                          RAISE EXCEPTION 'export account map does not cover both systems';
                      -- actual: 32 map rows vs 36 expected
```

`prove_chart.sql` was last touched in the **initial commit** (`eb0bc81`); the chart has grown from 10 roles to 18 since, and the assertion was never updated. That one is a stale test. `prove_export.sql` is different: it is **correct**, and the schema is wrong — see **AB**. Two further proofs (`prove_append_only_guard`, `prove_vendors_booths`) fail because `TRUNCATE items` now hits the foreign-key-reference check before the append-only guard trigger fires, so they never reach the assertion they were written to make.

This matters because it is the same discipline failure as **V**, one layer down. The four PHP gates are now green — good — but the SQL proofs, which are the _actual_ line of defence for the ledger, are not run by anything (`composer check` does not include them, there is no CI, and the README describes running them by hand). A proof that is never executed and does not pass is documentation, not a proof. Add the proof suite to the gate (it needs a provisioned tenant, which the sandbox now demonstrates is possible) so a stale assertion fails loudly instead of quietly.

### AB — MEDIUM — the export account map omits `cash_rounding` and `layaway_deposit`, so the export refuses those periods

`export_accounts` (the role → external-code map that QuickBooks/Xero exports read) is seeded by an idempotent `INSERT … WHERE NOT EXISTS` in exactly three places — `012_export.sql`, `013_period_close.sql`, and `028_tenders.sql` — each of which only covers the chart roles that existed at that point in the file order. Two roles were added _after_ the last seeder:

```
cash_rounding    added in sql/033_cash_round.sql
layaway_deposit  added in sql/040_layaway.sql
```

Neither is in the map. I verified this directly against the fresh tenant: `chart_roles` has 18 rows, `export_accounts` has 32 (16 roles × 2 systems), and the two uncovered roles are exactly `cash_rounding` and `layaway_deposit`. This is not cosmetic: `stall_export_journal_csv` (`sql/014_export_files.sql:57`) raises

```
a posted line has no export account
```

whenever any line in the requested date range has no mapped external code, and the export query itself inner-joins `export_accounts`. So **the export refuses any period that contains a cash-rounded sale or a layaway deposit** — i.e. most real periods. The fix is to re-seed `export_accounts` in the migration that adds a role (or, better, fold the seeding into a single function that runs after the chart is complete), and the repo's own `prove_export.sql` already encodes the invariant that would have caught it.

This is pre-existing rather than introduced by `78d088c` (both roles predate it), but it is the concrete money defect behind AA, and it is the kind of thing that only surfaces when the proofs are actually run.

### AC — NOTE — the two headline fixes ship with no new proof and no new test

The unit suite is unchanged from cycle 8 — still 47 tests, 158 assertions — and no `prove_*.sql` was added or changed. So the atomicity fix (**Z**) and the centralisation (**Y**) are verified only by inspection. The centralisation is easy to trust (it is a pure refactor and the existing proofs exercise it), but the atomicity guarantee — "a failed flip un-posts the deposits" — is exactly the kind of thing that wants a proof: force the ticket flip to fail inside `hold()` and assert that no `layaway_deposit` journal survived. Right now nothing asserts it. Given how much of this project's credibility rests on the proof suite, the fix that closes the last non-atomic money path should come with the proof that it is atomic.

## The new work, on the merits

I went at the centralisation and the atomicity hard, and both hold up.

**One half-up, two languages, two functions (`Y`).** The SQL side is now `stall_half_up(amount, bps) = (amount*bps + 5000)/10000` and `stall_tax_minor(net, tax_bps) = (net*tax_bps + 50000)/100000`, both defined once in `sql/004_sale.sql` and called as `public.stall_half_up(...)` / `public.stall_tax_minor(...)` from every `stall_assert_sale_shape`, `stall_line_discount`, and `stall_assert_ticket_line` copy. The PHP side is `BasisPoints::halfUp`/`tax`, byte-identical to the SQL, with every caller (`TicketQuote`, `TicketAdjustment`, `StoreTake`, `MallController`, `MallSettings`, `VendorForm`) delegating. The provisioning model makes the single definition site safe: the README is explicit that "a schema change is an edit to that file; drop the mall and run `provision-tenant.php` again", so `004` is always applied before its callers and there is no incremental-migration ordering hazard. I confirmed the fresh tenant has both functions and that the sale/rounding/layaway proofs pass through them.

**The layaway hold is atomic (`Z`).** `LayawayBook::hold()` now wraps the per-part deposits and the `open → layaway` flip in one `Statements::transaction`, and `cancel()` does the same for the refunds and the delete. The enabling change is in `LedgerStore::commitJournal`, which now detects `PDO::inTransaction()` and, when joined, skips its own `beginTransaction`/`commit`/`rollBack` and simply participates. `Statements::transaction` additionally forces `SET CONSTRAINTS ALL IMMEDIATE` before commit, so the deferred shape triggers fire at a known point rather than at commit. The deposit posting key is still a counter (`'layaway:' . $ticketId . ':' . nextKey()` where `nextKey = count(*) + 1`), but inside a single transaction that is now harmless: a rollback restores the count, so a retry reuses the same key instead of posting a second, distinct deposit — which was the actual double-post risk in cycle 8. The `open → layaway` flip also blocks a second hold on the same ticket. The one residual is that the key is still not _idempotent_ against a duplicate submission that reaches the database as a fresh request after a successful commit; the status flip covers that today, but a deterministic key would be sturdier. Not a defect — just the next increment.

**The register refactor.** `RegisterActivity::expectedCash` now delegates to the SQL function (**T**), the per-shift tender totals are computed in one query (**S**), the over/short shading is computed server-side via `DenominationCount::mark` (**U**), and `RegisterSession::close` refuses a grid that does not match the typed cash instead of silently overwriting it (**K**). `shifts.php` is correspondingly simpler. The one thing left is **R** — the page still presents a card/checks over-short that the ledger does not post.

**`TicketQueue`.** `park`, `layaways`, and `recall` moved out of `TicketStore` into a new `TicketQueue` (`Statements` + `TicketPricing`), which is what took `TicketStore` under PHPMD's class-complexity threshold. The container is reflection-based (`PerfectApp\Container\Container::build`), so the new class and the new `LoggerInterface` dependency on `WaitListStore` auto-wire without registration; `LoggerInterface` is bound in `public/index.php`. No wiring gap.

## Recommended priority

1.  **AB** — re-seed `export_accounts` so it covers `cash_rounding` and `layaway_deposit`. Until then the export refuses any period with a cash-rounded sale or a layaway deposit, which is most of them. This is the one live money defect in the repo.
2.  **AA** — put the SQL proof suite behind a gate and fix `prove_chart` (and the two truncate proofs). A safety net that is never run and does not pass is worse than none, because it is trusted.
3.  **R** — decide whether card/checks over-short is real accounting. If it is, post it and show it on the tape; if it is not, stop rendering a difference the ledger does not carry.
4.  **AC** — add a proof that the layaway hold is atomic, and a unit test for `BasisPoints`, so the two fixes in this batch are pinned.
5.  **N** — collapse the last duplicated money rule (the discount proration) the way this batch collapsed the tax/half-up rule.

## Trajectory

Sharply upward. For the first time the declared quality gate is green end to end, the duplicated money math is gone from both languages, and the last non-atomic money path is now atomic — all three of the review's standing headline findings (**V**, **Y**, **Z**) are closed in one commit, and eight low-severity findings (**B, G, H, K, L, O, S, T, U**) went with them. The kernel was never the problem; the problem was always the process around it, and this batch is the first that fixed process rather than features. What it also revealed, by finally making the proofs runnable, is that the process has one more layer to go: the PHP gates are green but the SQL proofs are not, and one of those failing proofs is a real export defect. Fix the export map, put the proofs behind a gate, and this stops being a build-off and starts being a system.