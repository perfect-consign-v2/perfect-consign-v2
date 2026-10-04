# Pull and review — `origin/master` @ `8055f36` (the unwind build)

**Scope.** Three commits since the last review: `4c19fd1` (floor/coverage fix), `ac3391d` ("Undo a settled payout and show who reversed it, when, and why"), `8055f36` (copies my review notes into `ninja/`). This is the build of `corrected-unwind-plan.md` rev 2, including both of Cursor's accepted changes. I provisioned a fresh tenant with the new file list, ran the whole proof suite (41 pass), the race harness (pass), and three probes I wrote for this review.

**Verdict: REJECT — one blocker ships a defect.** The plan is followed almost to the letter and most of it is right; the parts I doubted hold up under test. But the feature's own promise — _undo a payout and run it again_ — is broken: **a reversed run's replacement cannot be paid.** The cause is one missing predicate in `payout_pack`.

* * *

## 1\. What the build got right (verified, not assumed)

These are the things I came in hostile about and could not break:

-   **Lock #1 is narrowed correctly.** `stall_reject_closed_payout_window()` (`sql/041_payout_run.sql:191–217`) now filters `run.kind = 'batch' AND run.reverses_run_id IS NULL`. A single payout no longer freezes a month, and a reversal run no longer re-closes the window it just voided. Matches plan §1.
-   **`stall_reject_run_status()` exists and is `ENABLE ALWAYS`** (`sql/041_payout_run.sql:1075–1120`). Forward-only edges (`draft→settled`, `settled→paid`, `settled|paid→reversed`), no backward edge, `DELETE` only from `draft`, and the window columns (`sales_from/sales_to/rent_month/tender/kind/book_id/run_no`) frozen once `status <> 'draft'`. Matches plan §5.
-   **`journals_live_key` is built to the letter.** Named so it sorts _after_ `journals_context`; `BEFORE INSERT`; raises SQLSTATE `23505` with the exact `duplicate key value violates unique constraint "journals_book_key"` message (`sql/011_opening.sql:74–90`); `ENABLE ALWAYS` (`:98`); supporting non-unique index `journals_book_key_lookup` (`:52–53`). Matches plan §6.
-   **`journals_opening_key` is also `ENABLE ALWAYS`** (`sql/011_opening.sql:164`) — the bonus finding from the plan (§10), closed.
-   **`042_payout_reversal.sql` is on the provision list** (`bin/provision-tenant.php:44`), and `ledgerFiles()` now ends at `042`.
-   **The reversal model is right.** `stall_reverse_payout_run` (`sql/042_payout_reversal.sql`) validates actor/reason (23514), refuses a run that is not `settled|paid` or is already a reversal, creates a **reversal run** (`reverses_run_id`, `status='settled'`, `reversed_by`, `reversal_reason`), reverses the linked journals in order `disbursement → settlement → rent_charge`, attaches each to the **reversal run** with `role='reversal'`, and flips the original to `reversed`. The audit CHECK (`sql/041_payout_run.sql:74–83`) makes both fields mandatory on a reversal run and forbidden on a normal one. Matches plan §4/§4a.
-   **The proofs cover the hard cases.** `prove_payout_reversal.sql` asserts two live keys refused, a **replica-mode** second key refused, a full reversal restoring payable/rent, a reversed key re-inserted, and a second undo refused.

**And the thing I most expected to break did not.** Replacing a unique index with a trigger is the classic way to reintroduce a committed-duplicate race (a trigger's `EXISTS` uses the statement snapshot; a concurrent _committed_ insert can be invisible). The race harness rolls session A **back**, so it never tests that. I wrote the test it doesn't have (`probe_concurrency.php`): session A inserts a key, holds, then **commits**; session B inserts the same key concurrently. Result:

```
[hold] inserted adjustment:probe, holding 5s...
[hold] COMMITTED
[wait] INSERT REFUSED after 4.02s: SQLSTATE[23505]: ... "journals_book_key"
CONTEXT:  PL/pgSQL function ...stall_reject_live_posting_key() line 25 at RAISE
[after] live rows with the key: 1
```

The committed duplicate is refused. A near-simultaneous phantom insert (`probe_phantom.php`, two sessions, brand-new key, 5 ms apart) also lands 1 row. **The trigger holds.**

**The advisory lock is a justified addition, not gratuitous.** Cursor added `pg_advisory_xact_lock(hashtextextended(book_id || chr(31) || posting_key, 0))` to both key triggers (`sql/011_opening.sql:66, 114`) — it is **not** in the plan. It is _needed_: in a replica session `journals_context` does not fire (it is not `ENABLE ALWAYS`), so the per-book counter lock is absent, and a bare `FOR UPDATE` cannot block two concurrent **new** keys because there is no row to lock. The advisory lock serializes them. Good call.

* * *

## 2\. BLOCKER — a reversed payout cannot be re-paid

**The pack and the settler disagree about whether a reversed rent charge is "missing", and the disagreement moves the checksum after it has been frozen.**

### The two halves

`PayoutStore::missingRent()` (`src/Feature/Payout/PayoutStore.php:87–100`) and `missingByVendor()` (`:113–126`) exclude reversed rent charges:

```sql
SELECT posting_key FROM journals AS posted
WHERE posted.source_type = 'rent_charge'
  AND NOT EXISTS (
      SELECT 1 FROM journals AS reversal WHERE reversal.reverses_journal_id = posted.id
  )
```

So after a reversal the month is **missing again** and gets re-charged. That is the intent — plan §6: _"A reversed `rent-charge:<booth>:<YYYY-MM>` key must be re-insertable so a re-run can re-charge the month."_

`payout_pack`'s `missing_month` CTE (`sql/041_payout_run.sql:839–844`) does **not** exclude reversed charges:

```sql
WHERE NOT EXISTS (
    SELECT 1 FROM journals AS journal
    WHERE journal.source_type = 'rent_charge'
      AND journal.posting_key = 'rent-charge:' || assignment.booth_id::text || ':' || to_char(series.month_start::date, 'YYYY-MM')
)
```

So the pack says the month is **not** missing and omits the re-charge. The two layers disagree.

### Why that is fatal, not cosmetic

`PayoutRunController::close` (`src/Feature/Payout/PayoutRunController.php:113–130`) runs `ensureDraft → review → settler->close`:

1.  `review()` (`src/Feature/Payout/PayoutRunStore.php:115–123`) sets `reviewed_checksum = stall_pack_checksum(id)` — the live pack **before** any re-charge.
2.  `PayoutSettler::close` (`src/Feature/Payout/PayoutSettler.php:43–64`): `guard($runId, false)` passes (the pack has not moved yet), then `chargeMissing()` (`:25–41`) posts the re-charge that PHP believes is owed, then `materialize()` (`:51`) stores the pack — **now with the re-charge**.
3.  `PayoutDisburser::pay` (`src/Feature/Payout/PayoutDisburser.php:29`) calls `guard($runId, true)` → `stall_assert_frozen_checksum` (`sql/041_payout_run.sql:1057–1073`), which compares `reviewed_checksum` (pre-charge) to `stall_pack_checksum_frozen` (the stored lines, post-charge). They differ → `RAISE 'the stored report does not match the review'`.

**So the run settles, then every check is refused. The operator undoes a payout, re-runs it, and the replacement is stuck in `settled` and cannot be paid.**

In the _normal_ case this is invisible because `chargeMissing` is checksum-neutral: the amount simply moves from the `missing` CTE to `rent_posted`/`own_rent`, and the totals the checksum hashes are unchanged. The reversal is the one case where the pack's `missing` and PHP's `missingRent` disagree, and it is exactly the case the feature exists for.

### Proof

`probe_missing.sql` (one transaction, rolled back) sets up a vendor, a booth, a March rent charge, and a settled run that carries it; undoes the run; then reads the pack for a fresh draft run, re-charges the rent as `close()` would, and re-reads:

```
     phase     | owes | rent | carried_rent_minor
---------------+------+------+--------------------
 REVIEWED pack |    0 |    0 |                  0          <- what the operator approves
 pack after re-charge | 1000 | 1000 | 1000                  <- what close() then books

        checksum_at_review        |     checksum_after_recharge      | moved
----------------------------------+----------------------------------+-------
 69fea0427838f397dc18dfe604da11d8 | 742b6b27dc6c0ab679bed4c2354391b7 | t
```

The reviewed pack says the vendor owes nothing; the settlement charges 1000; the checksum moves. `stall_assert_frozen_checksum` will reject the check.

### The fix

Make `missing_month` agree with `missingRent()` — one predicate (`sql/041_payout_run.sql:839–844`):

```sql
WHERE NOT EXISTS (
    SELECT 1 FROM journals AS journal
    WHERE journal.source_type = 'rent_charge'
      AND journal.posting_key = 'rent-charge:' || assignment.booth_id::text || ':' || to_char(series.month_start::date, 'YYYY-MM')
      AND NOT EXISTS (
          SELECT 1 FROM journals AS reversal WHERE reversal.reverses_journal_id = journal.id
      )
)
```

With this, the re-charge is checksum-neutral again (the amount moves from `missing` to `own_rent`; `rent_minor`, `carried_rent_minor`, `net_minor`, `settled_minor`, `owes_minor`, `check_minor` are all preserved), the reviewed report matches the settlement, and `pay()` passes. Add a proof that a reversed rent charge shows as missing in `payout_pack` and that a re-run after an undo can be closed **and** paid.

* * *

## 3\. Minor

-   **The reversal function leaves the caller's transaction in `IMMEDIATE` mode.** `stall_reverse_payout_run` ends with `SET CONSTRAINTS ALL IMMEDIATE` (`sql/042_payout_reversal.sql:99`). That setting is transaction-scoped, so it persists after the function returns. In the shipped flow the undo is its own request and `LedgerStore::post()` resets to `DEFERRED` (`src/Ledger/LedgerStore.php:165`), so it is benign today — but a raw caller that inserts a journal header and then its lines in the same transaction after calling the function trips `ledger_assert_journal_balance` ("a journal must have at least two lines") on the header insert. I hit exactly this writing the probe. Scope it (savepoint) or document it.
-   **The `FOR UPDATE` in the key triggers is now redundant.** `sql/011_opening.sql:72` and `:120` do `PERFORM 1 … FOR UPDATE` after the advisory lock already serialized same-key inserts. Harmless belt-and-suspenders, but know it is not doing independent work.
-   **`SET CONSTRAINTS ALL DEFERRED` at the top of the reversal function is redundant** (`sql/042_payout_reversal.sql:57`) — the constraints are `INITIALLY DEFERRED`. Defensive; harmless.
-   **README doc drift.** `README.md:17` still says the list runs "through `sql/040_layaway.sql`"; `ledgerFiles()` now ends at `042_payout_reversal.sql`. `README.md:30` does not mention `042` either.
-   **The single-vendor path is outside the checksum contract.** `PayoutSingle::pay` (`src/Feature/Payout/PayoutSingle.php:26–66`) sets no `reviewed_checksum` and calls no `guard()`. If a one-vendor payout is deliberately exempt, say so in the spec; if not, it is a hole. (Note: because it does not guard, the blocker in §2 does **not** reach the single path — only the batch path.)

* * *

## 4\. What I ran

| Check | Result |
| --- | --- |
| `php bin/prove-suite.php` (fresh tenant, new file list) | **41 pass** |
| `php bin/prove-race.php` | **ok race** |
| `probe_concurrency.php` — committed duplicate under concurrency | **refused** (23505, `journals_book_key` message), 1 live row |
| `probe_phantom.php` — near-simultaneous new key | **1 live row** |
| `probe_missing.sql` — reversed rent vs pack vs checksum | **BLOCKER** (checksum moves) |

* * *

## 5\. Bottom line

The plan is implemented faithfully and the parts I doubted — the index→trigger swap, the advisory lock, the replica hole, the status guard, the audit fields — are correct and, where it matters, proven. Cursor even went past the plan to close the replica phantom with the advisory lock. **Do not rip this up.**

But it ships one defect that defeats the feature: **a reversed payout cannot be re-paid**, because `payout_pack.missing_month` does not exclude reversed rent charges, so the re-charge moves a checksum that was frozen before it ran. One predicate in `sql/041_payout_run.sql:839–844` fixes it. Fix that, add the proof, and this is a pass.