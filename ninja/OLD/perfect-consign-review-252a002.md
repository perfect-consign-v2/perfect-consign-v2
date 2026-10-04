# Hostile review — perfect-consign-v2 @ `252a002`

Repo: `perfect-consign-v2/perfect-consign-v2` (branch `master`). Local clone `/workspace/repo`. Reviewed at `252a002256502b72dcb4625264fd4c2f05705688` ("Keep batch intake on the same consignor, with a barcode, a store-take override, and floor dates"), preceded by `56deabc` ("Record inventory pieces and a wait list").

Scope: two new feature slices — **Inventory** (`023_items.sql`, `025_item_intake.sql`) and **WaitList** (`024_wait_list.sql`) — plus the new barcode/store-take/floor-date intake path, the new schema-attack proofs, and the three new concurrency proofs. Prior findings are carried forward and re-checked against the code, not against the prior write-up.

**Bar:** enterprise, world-class. No shortcuts, no hacks, no "documented so it's fine." A finding stays open until the code closes it or the design decision is defensible on its own terms.

* * *

## Status of prior findings (`ad15e56` → `252a002`)

| # | Finding | Severity | Status now |
| --- | --- | --- | --- |
| A | Store take is stored on the piece and read by nothing; the sale posts typed commission | MEDIUM | **Open — extended.** A second authoritative-looking field (`items.commission_bps`) now exists with no consumer. |
| B | Money rules copied across layers | MEDIUM | **Open — widened.** Four parse/format copies + the proration rule in two places. |
| C | `PartyController` against the 500-line gate | MEDIUM | **Open.** 494 lines — six short. Has a deadline. |
| D | Refusal copy differs between controller and trigger | LOW | **Note.** Trigger is genuinely proven; the casing difference is cosmetic. |
| E | HTTP test proves the controller pre-check, not the trigger | LOW | **Note.** The trigger is proven by a direct `UPDATE` in `prove_vendors_booths.sql`. Layering, not a gap. |
| F | `InventoryStore::referenced()` scans the FK graph per render | LOW | **Note.** Cache when a page is actually slow. |
| G | `WaitListStore::present()` throws on an unknown status | LOW | **Open.** A read path that 500s on one bad row. |
| H | Barcode retry loop | LOW | **Verified, re-scoped.** See below — the strong claim did not survive inspection. |

* * *

## Ranked findings

### A — (MEDIUM) The store take is a stored fact with no consumer

The intake path stores a per-piece take in `items.commission_bps` (nullable), and the list marks a row `overridden` when it is non-null (`InventoryController::withUse`). `PieceWriter::takeOf` is careful: leaving the field at the vendor's percent stores **NULL** (no override); only a genuinely different percent is persisted. That is correct.

But the sale path is unchanged and still takes commission as free text:

```
src/Feature/Sale/SaleController.php:42   $commission = $this->optionalMinor('commission');
src/Feature/Sale/SaleController.php:44   $payable = $amount - $commission - $tax;
```

Nothing in `src/Feature/Sale/` reads `items.commission_bps`, `vendors.commission_bps`, or `mall.commission_bps`. `docs/business-rules.md` states this ("A sale still posts the amount entered on the sale"), so it is documented — but documentation is not a control. An operator can set a piece's take to 30% and the sale will post whatever number is typed. The form _looks_ authoritative, the value is _stored_, the list _displays_ it as an override, and the software enforces nothing. That is a loaded gun with a sticky note.

The correct end state is one of two, not a third:

-   the sale reads the take in order **piece → vendor → mall**, with the override visible on the sale form; or
-   the field comes off the piece form.

"Leave it and wire it later" is only acceptable if the wiring lands with the next sale change — not after it. If the field survives two more batches still unread, the doc line stops being a mitigation and becomes an excuse.

### B — (MEDIUM) Money rules copied across layers

The basis-points parse/format rule (`^(\d{1,3})(?:\.(\d{1,2}))?$`, `>10000` guard, `intdiv` formatting) exists in **four** near-identical implementations:

-   `src/Feature/Mall/MallController.php` — `basisPoints()` / `percent()`
-   `src/Feature/Party/PartyController.php` — `storedTake()` / `points()` / `percent()`
-   `src/Feature/Inventory/StoreTake.php` — `fromPercent()` / `format()` (new)
-   `public/assets/js/inventory-intake.js` — `points()` (new)

And the move-in proration rule is duplicated between SQL and PHP:

-   `sql/022_assignment_rent.sql` — `days_charged := (charge_end - assign_start) + 1; rate_minor := (days_charged * rate_minor) / days_in_month;`
-   `src/Feature/Party/MoveInRent.php` — `$daysCharged = $daysInMonth - $day + 1; return intdiv($daysCharged * $monthly, $daysInMonth);`

Both truncate toward zero, so they agree today. "They agree today" is the definition of latent drift, not a defense: a change to the accepted range, the rounding, or the proration boundary must be made in four (or two) places or the UI silently disagrees with the kernel. `StoreTake` is the correct unit and is named and testable. The fold is not "when a parser changes" — it is "now, because the fifth copy is already on the doorstep." Hard condition: **no sixth copy, ever.** The next percent field imports `StoreTake`; the next proration consumer calls one implementation. If a sixth copy appears before the fold, the deferral has failed and it is a defect.

### C — (MEDIUM) `PartyController` is at 494 lines against a hard 500-line gate

`bin/check-max-lines.php` fails the build at 500 lines (default). `PartyController` is 494 — six lines of headroom. The gate is a cliff: the next small vendor/booth change trips the build, and the pressure will be to raise the limit rather than split the class. The new Inventory/WaitList controllers landed at 283/247 lines, so the discipline exists elsewhere. **Action, with a deadline:** move standing (or the form) out of `PartyController` _before_ the next edit in that file, and do not raise the 500 limit. Six lines is a tripwire, not headroom.

### G — (LOW) A read path that 500s on one bad row

`WaitListStore::entries(status='all')` selects every row regardless of status, then `present()` calls `standing()`, which `throw`s `InvalidArgumentException('a wait list status is missing')` for any status outside the four. The table `CHECK` allows only the four today, so this is unreachable _now_. But a presenter is a rendering layer, not an enforcement layer: a widened constraint, a restored backup, a manual fix, or a migration mid-flight that leaves one row with a fifth status takes down the **entire list page**. Fail-loud belongs in the writer and the kernel; the read path should render the raw status with an error marker and log/alert — not invent a label, and not 500 the page.

### H — (LOW) The barcode retry is correct only by accident of autocommit

`InventoryStore::place()` catches an `items_barcode_key` violation and retries up to five times inside the same call:

```
while ($attempt < 5) {
    $barcode = $piece->barcode !== '' ? $piece->barcode : $this->allocate($today);
    try { $this->store($id, $piece, $barcode); return; }
    catch (DatabaseRefusal $error) { ... if (... 'items_barcode_key') throw; }
}
```

I checked whether this can ever succeed. In PostgreSQL a constraint violation aborts the surrounding transaction, so a retry inside a transaction would be dead on arrival **unless** it runs under a `SAVEPOINT`. `src/Shared/Database/Statements.php` has no savepoint support (`change()`/`affected()` prepare-and-execute; `transaction()` is a plain begin/commit/rollback). **However**, the inventory write path is _not_ wrapped in a transaction anywhere — the router does not begin one, `InventoryController::change()` does not, and the PDO connection is created with autocommit on (`AppDatabase::connect` sets only `ERRMODE`, `DEFAULT_FETCH_MODE`, `EMULATE_PREPARES`). So each statement is its own implicit transaction, the failed insert does not poison the connection, and **the retry works today.** The strong "dead on arrival" claim does not hold.

Two real, verified consequences remain:

1.  **Latent coupling.** The retry is correct only because nothing wraps inventory writes in a transaction. `PartyStore` already wraps some writes via `Statements::transaction()`. The day someone wraps inventory writes for consistency — a reasonable change — the retry silently breaks: the second `store()` fails with "current transaction is aborted" and the operator sees a hard error on a barcode collision. A `SAVEPOINT` per attempt (or an atomic sequence) removes the coupling. Undocumented, fragile, and invisible until it bites.
2.  **Bounded retry under burst.** Five attempts. A burst of more than five simultaneous auto-barcode intakes can exhaust the loop and throw. Unlikely, but the limit is arbitrary and unproven — no proof forces a _committed_ collision (the `prove_race_barcode` pair has session A **roll back**, so session B never actually hits the violation; it proves the wait, not the retry).

This is the kind of thing that only surfaces under real load — exactly the enterprise bar.

* * *

## What is genuinely good in this batch

The proof discipline here is the strongest of any batch so far, and it is aimed at the right target:

-   **Schema-attack proofs.** `sql/prove_items.sql` asserts the `items` table carries **no** hidden money column other than `price_minor`/`commission_bps`, and **no** `quantity`, `status`, or `booth_id`; `sql/prove_wait_list.sql` asserts `wait_list` carries **no** money column and no unexpected integer column. These prove the "catalog, not a journal" boundary by attacking the schema, not the happy path.
-   **Append-only attacked from every angle.** Both proofs try `TRUNCATE`, `DISABLE TRIGGER`, `ENABLE REPLICA TRIGGER`, `DROP TRIGGER`, `session_replication_role = replica`, and a poster-role truncate, asserting the exact refusal text. `sql/prove_append_only_guard.sql` additionally asserts the two event triggers remain `evtenabled = 'A'`.
-   **Real concurrency proofs for the new keys.** `prove_race_item` (tag), `prove_race_barcode` (barcode), and `prove_race_wait` (open email) are hold/wait pairs wired into `prove_race.ps1` with a `>4.5s` wait assertion and a clean-up assertion that no race rows are left. The wait-list case is the interesting one: the guard is a **partial** unique index (`wait_list_open_email_key ... WHERE status IN ('waiting','contacted')`), and `prove_wait_list.sql` proves the exact semantics — a second _open_ email is refused, `contacted` still counts as open, and `placed`/`declined` free the address. Correct, non-obvious, and proven rather than asserted.
-   **Barcode allocation is honest about its race.** `allocate()` reads the max sequence; the insert is guarded by `items_barcode_key` (unique on `lower(barcode)`) with a bounded retry that only re-allocates when the caller supplied no barcode. The unique index is the real guard; the retry is best-effort. (See H for the two caveats.)
-   **The store-take override records a real fact.** Storing NULL when the piece's take equals the vendor's means `overridden` is a true statement, and a later change to the vendor's percent flows through to non-overridden pieces automatically.
-   **Inventory/WaitList keep the ledger boundary.** Neither slice moves money or introduces a quantity; both are granted to `stall_poster` with `TRUNCATE, TRIGGER` revoked, and the docs state the boundary in one line each.

* * *

## Recommended priority

1.  **Close A at the next sale change.** Wire the sale to default its commission from piece → vendor → mall take (override visible), or remove the field. Do not let it survive another batch unread.
2.  **Fold B now.** One PHP `Percent` unit (used by Mall, Party, Inventory) and one JS helper from the same source; one proration implementation the PHP reads. Hard rule: no sixth copy.
3.  **Split `PartyController` before the next edit in it (C).** Move standing or the form out. Do not raise the 500 limit.
4.  **Make G a read-path fix:** render the raw status + alert; keep the throw in the writer only.
5.  **Decouple H:** add a `SAVEPOINT` per attempt (or an atomic sequence) so the retry survives a future transaction wrapper, and add a proof that forces a _committed_ barcode collision.
6.  **D/E/F:** notes only. No action.