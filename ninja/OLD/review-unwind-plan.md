# Hostile review — Cursor's plan to unwind a payout

**Reviewed against:** the code at `ab8f6de` and spec v2.3. **Reviewer stance:** adversarial. The bar is enterprise world-class. A finding is withdrawn only when the code refutes it.

* * *

## Verdict

**Direction is right. Do not build as written.**

The plan correctly identifies the two real holes — a single payout writes no run row, and nothing links a run's journals — and the undo shape (reverse checks → settlements → rent charges, one audit record, required reason) is sound. But it rests on **one false premise about the closed-window trigger**, it **removes a declarative unique index without specifying the trigger that replaces it**, and it leaves four contract items unaddressed. Fix those and this is buildable.

I tested the one claim I could not settle by reading — whether dropping `journals_book_key` breaks the race guarantee. It does not. Details in Finding 6.

* * *

## BLOCKER 1 — The `kind = 'single'` premise is false

The plan says:

> "kind = 'single' does not close the sales window. The closed-window trigger in sql/041\_payout\_run.sql stays limited to kind = 'batch'."

That is not what the code does. `stall_reject_closed_payout_window()` (`sql/041_payout_run.sql:180–197`) filters on **status only**:

```sql
WHERE run.status IN ('settled', 'paid')          -- line 189
  AND NEW.posting_date BETWEEN run.sales_from AND run.sales_to
```

There is **no `kind` predicate anywhere in the function.** `sales_from`/`sales_to` are `NOT NULL` on `payout_runs`, so a single run carries a window. The moment a single-vendor payout ends `status='settled'`, that window closes and every later journal dated inside it is refused — a same-day sale, a same-day rent charge, anything.

So one of two things is true, and the plan must say which:

-   the trigger **must change** to add `AND run.kind = 'batch'` — which is a deliberate change to **Lock #1** ("a settled window stays closed") and has to be called out as such; or
-   the premise is simply wrong and single runs will close windows.

As written, the plan builds the single-payout path on a property the code does not have. This is the one finding that will produce a live defect.

* * *

## MAJOR 2 — `042` is not added to `ledgerFiles()`

`bin/provision-tenant.php::ledgerFiles()` (lines 10–48) is the single-sourced tenant file list, and it ends at `041_payout_run.sql`. New tenants are provisioned from exactly this list. If `042_payout_reversal.sql` is not appended, **new tenants will not have `reversed_by` / `reversal_reason`**, and the undo path that reads them fails on every fresh mall.

The plan says "`php bin/provision-tenant.php` for a new tenant" but never says to append 042 to the list. That is the one line that makes new tenants work.

## MAJOR 3 — The migration story contradicts the no-migration model

> "run sql/042\_payout\_reversal.sql inside each existing tenant schema."

The kernel has **no migration table**; tenants are drop-and-reprovisioned. Running a file by hand inside each schema is not the kernel's pattern and drifts. State the story plainly: append `042` to `ledgerFiles()` and re-provision (the model the kernel already uses), or justify a one-off script. Do not leave it as an operator instruction.

## MAJOR 4 — No reversal run; diverges from §6.6

§6.6 step 2 requires a **new `payout_runs` row** — `reverses_run_id = run_id`, `status='settled'`, same window/tender, `note='Reversal of run <run_no>'` — so that "the reversal run is itself visible in the run list and reprintable." The plan instead flips the original to `reversed` and adds `reversed_by`/`reversal_reason`. Consequences:

-   `reverses_run_id` (already on the table) is left unused; there is no reprintable reversal run.
-   The plan does not say **which `run_id`** the `role='reversal'` rows go into. §6.6 puts them in the reversal run; the simple model would put them in the original run.

Your own framing ("the undo is one record") suggests you want the simpler model. Fine — but say so explicitly, note the divergence from §6.6, and name the run the reversal rows attach to.

## MAJOR 5 — `stall_reject_run_status()` is still absent

§6.4 and §14 require a `BEFORE UPDATE ON payout_runs` guard that enforces `draft → settled → paid → reversed` with no backward edges and freezes `sales_from/sales_to/rent_month/tender/kind` once `status <> 'draft'`. The plan mutates status to `reversed` and enforces "a second undo is refused" in **app code**. Nothing at the database level stops `UPDATE payout_runs SET status = 'draft'` from reopening a closed, settled run. This plan is the natural home for that guard; it should ship with it.

## MAJOR 6 — The index replacement is sound, but only if you specify the trigger

The plan removes the declarative unique index `journals_book_key` (`sql/011_opening.sql:52`) and replaces it with a trigger, because a reversed `rent-charge:<booth>:<YYYY-MM>` key must be re-insertable. That is justified — an append-only table cannot carry a `reversed` flag for a partial unique index — but the plan under-specifies it.

**What I verified.** I provisioned a fresh tenant, confirmed `journals_book_key` present, dropped it, confirmed it gone, and re-ran the key race (session A holds a new key and sleeps; session B inserts the same key). **Session B still waited 5035 ms.** The reason is that every journal insert is already serialized per book by `journals_context` — a `BEFORE INSERT ON journals` trigger (`sql/001_kernel.sql:224`) whose function `ledger_reject_journal_context` (`:158`) locks the `book_journal_counters` row `FOR UPDATE` (`:177–179`). So the plan's claim — _"two live copies of the same key still cannot both commit"_ — **is true**, but not for the reason the plan implies.

**What the plan must therefore state:**

1.  The new key-check trigger must fire **after** `journals_context`. Trigger firing order is alphabetical; `journals_context` starts with `journals_c`, so the trigger must be named to sort after it (e.g. `journals_live_key`, `journals_posting_key`) — or the check must be folded into `ledger_reject_journal_context` itself. A trigger named `journals_book_key` would sort **before** `journals_context` and reopen the race. The plan names no trigger and states no ordering.
2.  It must preserve SQLSTATE `23505` and the message `duplicate key value violates unique constraint "journals_book_key"` — asserted by `sql/prove_opening.sql` at lines 60, 145, 253, 377 and 466.
3.  It must keep a supporting index for the lookup (the check scans `journals` by `(book_id, posting_key)`), or the check becomes a sequential scan on every insert.

## MAJOR 7 — "Update the race proofs that lock that index" is wrong

`bin/prove-race.php`'s `race()` helper asserts session B **waits ≥ 4500 ms** (line 132) and then succeeds. That wait comes from the per-book counter lock, **not** the index — proven above. So `prove_race_key_a/b` and `prove_race_payout_a/b` stay green **unchanged** once the index is dropped. There is nothing to update.

If Cursor means to relax the wait assertion, that deletes the serialization guarantee §12 calls "the backstop against double-posting" — do not. Instead **add** a proof that (a) two live copies of a posting key are refused and (b) a key whose only prior journal is reversed can be inserted again.

* * *

## MINOR 8 — `SET CONSTRAINTS ALL IMMEDIATE` omitted

§6.6 step 5 fires the deferred guards explicitly. Without it, `journals_closed_payout_window` (`041:201`), `journals_subledger_non_negative` (`028:343`) and the shape guards all evaluate at COMMIT, so a refusal surfaces as a commit error rather than inline. Add it.

## MINOR 9 — "dated today" is unnecessary and can collide

`journals_closed_payout_window` is `DEFERRABLE INITIALLY DEFERRED` (`041:201`), so it evaluates at COMMIT — by which point the original run is already `reversed` and its window is open. The reversal's date does not matter for that check. Dating reversals "today" only adds a new failure mode: if **another** settled/paid run's window covers today, the reversal is refused. Prefer the spec's approach — no special date, rely on the status flip plus `SET CONSTRAINTS ALL IMMEDIATE`.

## MINOR 10 — `chargeMissing` signature ripple

`LedgerStore::post()` already returns the journal id (`LedgerStore.php:153`), so returning ids is trivial — but the signature change touches `PayoutController.php:42` and `PayoutRunController.php:52`. Note it so it is not missed.

## MINOR 11 — "no run row" understates the limitation

Every current run — batch **and** single — has **no** `payout_run_journals` rows (the code never inserts them; grep returns nothing). So **no** payout posted before this change can be undone, not just single ones. Say "no payout posted before this change can be undone."

* * *

## What is genuinely right (verified)

-   **Fixing `PayoutController` to insert a real run row is correct.** `PayoutController.php:50` currently invents `$runId = $this->ledger->newId()` and never writes `payout_runs`; the plan fixes exactly that.
-   **Linking rent charge / settlement / disbursement in `payout_run_journals` matches §6.1/§6.2**, and the `role` CHECK already allows `'reversal'` (`041:177`).
-   **The reversal order matches §6.6** (checks → settlements → rent charges), and the shape guards already skip reversals: `stall_assert_settlement` (`041:253`), `stall_assert_disbursement` (`041:374`), `stall_assert_store_purchase` (`041:435`), `stall_assert_rent_charge` (`022:68`). `PayoutReverser` already sets `is_reversal` via `reversesJournalId` (`LedgerStore.php:172`), and `journals_one_reversal` (`001_kernel.sql:123`) enforces exactly one reversal per journal.
-   **Reopening the window after reversal is correct** — the trigger keys on status, so `reversed` reopens it.
-   **Excluding reversed rent charges from `missingRent`/`missingByVendor` is needed** — `PayoutStore.php:91` and `:111` build `$charged` from every `rent_charge` journal, so a reversed month would otherwise never be re-charged.
-   **Reusing a reversed check number is needed**, and the fix belongs in `stall_reject_duplicate_disbursement_check` (`041:206`) as a query change — `register_checks` is append-only (`029:113–131`), so the row cannot be deleted.
-   **`owner_name` is available** (`ActiveRegister::ownerName()`; `OwnerGate.php:43` sets it).

* * *

## Required before this can be built

1.  Correct the `kind='single'` premise: either add `AND run.kind = 'batch'` to `stall_reject_closed_payout_window` (and call out the Lock #1 change) or state how a single run's window cannot bite.
2.  Append `042_payout_reversal.sql` to `ledgerFiles()`; state the provisioning story.
3.  Decide the reversal model (reversal run per §6.6, or the one-record model) and name the run the `role='reversal'` rows attach to.
4.  Add `stall_reject_run_status()` with the §6.4 edges.
5.  Name the key-check trigger, place it **after** `journals_context`, preserve SQLSTATE 23505 + the message, and keep a supporting index.
6.  Keep `prove-race` green unchanged; add a proof for live-key refusal and reversed-key re-insert; add §11.7 (reversal restores exactly) to `prove_payout_run.sql`.
7.  Add `SET CONSTRAINTS ALL IMMEDIATE`; drop the "dated today" special case.