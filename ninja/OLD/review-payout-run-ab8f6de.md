# Hostile review — Cursor's payout-run build (`ab8f6de`)

**Reviewed against:** `perfect-consign-payout-spec-v2.3.md` (the live contract). **Commit range:** `1d28611..ab8f6de` (4 commits), head `ab8f6de` — _"Charge missing booth rent before a payout so the check matches what the vendor still owes."_ **Reviewer stance:** adversarial. The bar is enterprise world-class. A finding is withdrawn only when the code refutes it.

* * *

## Verdict

**REJECT. Do not merge.**

The build passes every gate — `prove-obligations` ok, `prove-suite` 39/39 green, PHPUnit 102 tests / 728 assertions green on a clean tenant — and yet **the single defining contract of v2.3 is not implemented at all.** The preview/checksum model that v2.1, v2.2 and v2.3 were _entirely about_ does not exist in this build. What shipped is a working settle→disburse→reverse happy path wrapped in tables whose columns are never written, a screen that re-derives the pack in PHP, and a proof file that tests three of the twenty-five invariants the spec demands.

The green is a **false green**: the obligation gate refuses a _new symbol that nothing names_; it cannot see a symbol that was never written. This build passes by not building the contract.

* * *

## BLOCKER 1 — The entire preview/checksum contract is unimplemented

§14 names four SQL symbols that are the heart of the design. **None of them exist anywhere in the repo.** `grep -rn` across `sql/` and `src/` returns nothing for any of them:

| Symbol required by §14 | Status |
| --- | --- |
| `payout_pack` view | **ABSENT** — no such view; grep returns zero hits |
| `stall_pack_checksum()` | **ABSENT** |
| `stall_pack_checksum_frozen()` | **ABSENT** |
| `stall_reject_run_status()` | **ABSENT** |

`reviewed_checksum` exists only as a **column declaration** (`sql/041_payout_run.sql:60`) and as a name in a proof's `SELECT` list (`sql/prove_payout_run.sql:128`). It is **never computed and never stored** — `PayoutRunStore::begin()` (lines 147–161) does not write it, `remember()` does not write it, `markSettled()` does not write it. There is no code path that produces a checksum string at all.

The pack is not a SQL projection. `PayoutRunStore::pack()` (`src/Feature/Payout/PayoutRunStore.php:21–73`) is a **second, PHP-side formula** for the vendor pack — a hand-written `SELECT` with a `LEFT JOIN LATERAL` for the booth and two aggregate sub-selects, then `row()` (lines 80–110) folds `missingByVendor()` into `prior`/`charged` and computes `rent = prior + charged − paid`, `check = max(0, payable − rent)`, `owes = max(0, rent − payable)`. §4.11/§6.3 require the pack to be a single SQL projection so it can be hashed; this is the exact "second formula" the spec forbids.

**Consequence:** §11.8 (report re-render equals original), §11.15 (live hash equals frozen hash), and §11.16 (checksum drift refused) are not merely untested — they are **impossible**. There is nothing to hash, nothing to freeze, and nothing to compare. The preview the whole v2.x line was built to guarantee does not exist.

* * *

## BLOCKER 2 — The stored report is never written; every breakdown column is a zero

`payout_run_lines` carries a full breakdown — `brought_forward_minor`, `carried_payable_minor`, `carried_rent_minor`, `gross_sales_minor`, `tax_minor`, `commission_minor`, `discount_minor`, `return_minor`, `void_minor`, `check_paid_minor`, `rent_brought_forward_minor`, `rent_paid_since_minor`, `settlement_applied_minor`, `rent_charged_minor` — all `NOT NULL DEFAULT 0`. **Not one of them is ever written.** `remember()` (`PayoutRunStore.php:163–171`) inserts only:

```
INSERT INTO payout_run_lines
    (run_id, vendor_id, mode, check_minor, owes_minor, settlement_journal_id)
VALUES (?::uuid, ?::uuid, ?, ?, ?, ?::uuid)
```

A grep for every one of those breakdown columns across `src/` returns **zero hits**. Every stored line is a row of zeros around a single `check_minor`/`owes_minor` pair.

`payout_run_sales` (defined `sql/041_payout_run.sql:154`) and `payout_run_journals` (defined `sql/041_payout_run.sql:171`) are **never inserted into anywhere** — grep for `INSERT INTO payout_run_sales` / `INSERT INTO payout_run_journals` across `sql/` and `src/` returns nothing. The sale-detail table that §11.16 hashes is empty; the run↔journal link table of §4.12 is empty.

**Consequence:** §11.9 (ledger identity over the frozen columns), §11.10 (brought-forward exercised), §11.11 (all open rent swept), §11.12 (discount in the identity), §11.13 (return/void/prior check in the identity), §11.14 (rent breakdown reconciles), §11.22 (prior settlement reconciles on both sides) all fail by construction. The "stored report" is a row of zeros; there is no report.

* * *

## BLOCKER 3 — `disburse()` verifies nothing

`PayoutDisburser::disburse()` (`src/Feature/Payout/PayoutDisburser.php:18–39`) posts a `check_disbursement` journal for **any** `amount > 0`. It does **not**:

-   assert the run is `status = 'settled'` (§6.2);
-   re-verify `P == check_minor` against the stored line (§11.6);
-   verify the frozen checksum against the live pack (§11.15);
-   insert the `payout_run_journals` link (§4.12).

The only guard is `if ($amount <= 0) return '';`. A caller can disburse an arbitrary amount against a `draft` run. §6.2, §11.6, §11.15, §11.17 all fail.

## BLOCKER 4 — `settle()` verifies nothing and records nothing

`PayoutSettler::settle()` (`src/Feature/Payout/PayoutSettler.php:35–67`) computes `open = openPayout()`, `applied = min(payable, rent)`, posts `settlement:<runId>:<vendorId>`, and returns the journal id. It does **not**:

-   assert the run's checksum still matches the reviewed pack (§11.16);
-   insert the `payout_run_journals` link (§4.12);
-   insert any `payout_run_sales` row (§11.8);
-   write any breakdown column (§11.9).

§6.1, §11.2, §11.16 fail.

## BLOCKER 5 — `stall_reject_run_status()` is absent

No guard constrains `payout_runs.status` transitions. The only writers are `markSettled()` and `markPaid()`, each protected solely by a `WHERE status = ...` predicate (`PayoutRunStore.php:176–188`). Nothing refuses a move out of `settled`, a skip from `draft` straight to `paid`, or a write to `reversed`. §4.12/§14 require the guard; it does not exist.

* * *

## MAJOR 6 — `envelope.php` and `envelope.css` are missing

§9.3 and §14 require a printed envelope, one page per vendor. `ls src/Feature/Payout/` shows no `envelope.php` and no `envelope.css`. `pay-vendors.php` renders `<section class="vendor-sheet">` only per pack row, so an empty pack renders no sheet at all. §11.25's "printed envelope" for a zero-activity vendor cannot happen — there is no envelope artifact.

## MAJOR 7 — `tests/Integration/Payout/StorePurchaseTest.php` is missing

§14 names it explicitly. `ls tests/Integration/Payout/` shows only `PayoutRunTest.php`. §11.21 has no PHP test.

## MAJOR 8 — `PayoutRunTest` does not test checksum drift

§14 says `PayoutRunTest` must cover "checksum drift." It does not — no test in the file names `stall_pack_checksum`, `stall_pack_checksum_frozen`, or any drift scenario. The claim in §14 is unmet.

## MAJOR 9 — `sql/prove_payout_run.sql` tests 3 of the 25 invariants

The file is 184 lines and its own header says so: _"Locks: a settled window, net columns, and a unique disbursement check number."_ A grep of the file for the invariant vocabulary returns **no** `settle`, `disburse`, `reverse`, `credit_only`, `shortfall`, `brought_forward`, `carried`, `discount`, `return`, `void`, `safe_disbursement`, `drawer`, `register` (session), `concurrent`, or `checksum`. It hard-codes `reviewed_checksum = 'lock'` at line 128 — the literal string `'lock'`, never a computed hash. §14 requires §11 in full; the file proves §11.1 (partial), §11.2 (partial), and §11.17 (partial). **Twenty-two invariants are unproven.**

## MAJOR 10 — The gates are green because nothing names the missing symbols

§14 states the gate "refuses any new symbol (SQL function or PHP method) not named by a proof/test." That gate catches an _added_ symbol nothing references. It is structurally blind to an _absent_ symbol. `prove-obligations` ok, `prove-suite` 39/39, and PHPUnit 102/728 green are therefore **not evidence the contract is met** — they are evidence that the build avoided the contract. A gate that passes when the feature is missing is not a gate.

* * *

## MINOR 11 — Leftover `('role','payout')` chart-source rows (judgment call)

`chart_source_rules` still seeds nine `payout`\-source rows: `sql/003_vendors_booths.sql:225,229,233,239,245,251`, `sql/004_sale.sql:46`, `sql/005_rent_charge.sql:27`, `sql/009_adjustment.sql:33`. §4.5 says the `payout` source is retired. They are dead — `sql/007_payout.sql` now refuses any `payout` journal outright — but they contradict "retired" and should be removed for hygiene. Not a blocker; flagged so it is a decision, not an oversight.

* * *

## What is genuinely right (verified, not assumed)

-   **The committed spec matches my v2.3 in substance.** The diff against `ninja/perfect-consign-payout-spec-v2.3.md` is formatting only (`*` vs `**`, `---` vs `* * *`) plus the added `## Locks` section. No content was dropped or reworded.
-   **All three `[lock]` items are implemented.** `stall_reject_closed_payout_window()` (`041:180`), the net-of-reversal columns (documented in the Locks section), and `stall_reject_duplicate_disbursement_check()` (`041:206`) all exist and are proven by `prove_payout_run.sql`.
-   **`007`/`029` edits match §6.5.** `sql/007_payout.sql` refuses any non-reversal `payout` journal (SQLSTATE 23514); `sql/029_register_posts.sql` replaced `'payout'` with `'cash_disbursement'` in both the session-assert and session-reject lists — and `safe_disbursement` is correctly _not_ a drawer source.
-   **The edited proofs no longer name `payout`.** Grep across `sql/` is clean; `prove_statement.sql` carries the required `source_type NOT IN ('settlement','check_disbursement','cash_disbursement','safe_disbursement')` filter (lines 266–267).
-   **The functions that _were_ written are real:** `stall_assign_payout_run_no`, `stall_reject_closed_payout_window`, `stall_reject_duplicate_disbursement_check`, `stall_assert_settlement`, `stall_assert_disbursement`, `stall_assert_store_purchase`, `stall_reject_posted_run_line` (column-aware, includes `settlement_journal_id`), `stall_reject_posted_run_sale`, `stall_reject_run_journal_change`.
-   **The full suite is green on a clean tenant** (102 tests, 728 assertions, exit 0), and `prove-obligations` / `prove-suite` pass.

* * *

## Required before this can be re-reviewed

1.  Implement `payout_pack` as a **single SQL projection** and delete the PHP `pack()` formula; hash it in `stall_pack_checksum()` and freeze it in `stall_pack_checksum_frozen()`.
2.  Write `reviewed_checksum` at review; assert it in `settle()`; re-assert the frozen hash in `disburse()`.
3.  Populate **every** `payout_run_lines` breakdown column, and insert `payout_run_sales` and `payout_run_journals` on post.
4.  Add `stall_reject_run_status()` and the `settle()`/`disburse()` preconditions (`status='settled'`, `P == check_minor`).
5.  Ship `envelope.php` / `envelope.css` and `tests/Integration/Payout/StorePurchaseTest.php`; add the checksum-drift test to `PayoutRunTest`.
6.  Rewrite `sql/prove_payout_run.sql` to prove **all 25** §11 invariants, not the three Locks.
7.  Remove the nine dead `('role','payout')` rows.

Until 1–6 are done, the build does not implement v2.3, and the green gates are not evidence that it does.