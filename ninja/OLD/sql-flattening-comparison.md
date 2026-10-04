# SQL Flattening — Comparison of the Two Documents

**Purpose:** compare the reviewer's decision document ("Doc A") with the repo author's document ("Doc B", "Flatten the tenant SQL") against the actual code at revision `efbfaea`, and identify the path to take.

**Method:** every load-bearing claim in both documents was checked against the repository. Line numbers and command evidence are cited so each claim can be re-verified.

* * *

## 0\. Bottom line

The two documents **agree on the framing** and **diverge on the goal**. More importantly, Doc B identifies a **deeper defect than Doc A did**, and the code confirms it. Doc A treated the numbered files as "bounded contexts to be merged by domain." They are not. They are a **layered redefinition fold**: the same function is `CREATE OR REPLACE`d across up to seven files, with the last definition winning. That has two real consequences Doc A missed:

1.  **Behaviour is order-dependent.** Reordering the files silently changes the schema.
2.  **The append-only guard is disabled during provisioning.** Because the later files redefine guard functions, they must `SET event_triggers = false` to get past `platform.reject_guard_function_replace()`. Provisioning therefore runs with the tamper-proofing guard switched off for **7 of the 41 files**.

Doc B's rule — _each object is created exactly once_ — fixes both. On the substance, **Doc B is the stronger document and its approach should be adopted.** Doc A's contribution is orthogonal: it covers the proof sprawl and the portability constraints of a `pg_dump`, which Doc B omits, and it proposes a permanent determinism gate rather than a one-time diff.

Doc B also contains several factual errors that would break execution if followed literally; these are listed in §5.

* * *

## 1\. Where the two documents agree

| Point | Doc A | Doc B | Verified |
| --- | --- | --- | --- |
| The `sql/` files are templates; `rewrite()` stamps `public.` and `stall_poster` per mall | Yes | Yes | `TenantProvisioner::rewrite()` — confirmed |
| A `pg_dump` is the wrong source of truth | Yes (§4) | Yes | Confirmed by measurement (§4 of Doc A) |
| The dump is useful as an oracle/diff | Yes (§8, permanent gate) | Yes (one-time before/after diff) | — |
| Single-source the ordered file list | Yes (§3.1, §7.2) | Yes (`TenantProvisioner::ledgerFiles()`) | Duplicated today in `provision-tenant.php` + `prove-tenant.php` — confirmed |
| Rewrite the README load section; remove the "025 through 037 in place" sentence | Yes (§7.3) | Yes | README line 23 — confirmed |
| `004_sale.sql` keeps `stall_half_up` / `stall_tax_minor` | No — Doc A moves them to the kernel | Yes — keeps them in 004 | Both defined in `004_sale.sql` — confirmed |
| Proofs must stay independently runnable | Yes | Yes | `prove-suite.php` globs `prove_*.sql` — confirmed |

The only substantive disagreement in this table is the money primitives (Doc A moves them to the kernel; Doc B keeps them in `004`). This is minor and is addressed in §7.

* * *

## 2\. The core divergence: goal

|   
 | Doc A | Doc B |
| --- | --- | --- |
| **Goal** | Fewer files (~10), grouped by domain | Each object defined exactly once |
| **File count** | ~10 tenant files | ~38 (deletes 025, 035, 036) |
| **Structure** | `sql/global/` + `sql/tenant/`, order from the directory | Flat numbered list, single-sourced |
| **Mechanism** | Merge files by bounded context | Fold each redefinition to its canonical body; delete the emptied files |
| **Proofs** | Move to `sql/prove/` | Leave in place |
| **New gate** | Two-tenant determinism diff | `bin/prove-obligations.php` lint + one-time catalog diff |

These are **not mutually exclusive**, but they are **not equal in priority**. Doc B's goal is a **correctness** property (each object once; no order dependence; guard never disabled). Doc A's goal is a **presentation** property (fewer, more navigable files). Correctness first.

A concrete illustration of why Doc A alone is insufficient: Doc A proposed merging `004 + 031 + 032 + ... + 040` into one `060_sales.sql`. If you did that naively, you would concatenate **seven definitions of `stall_assert_sale_shape`** into one file and leave six of them dead. That is arguably _worse_ than today, because the dead definitions would now be interleaved with the live one in a single file. Doc B's procedure — pick the last body, place it once, move its triggers with it, delete the earlier copies — is the correct mechanical precondition for any merge. **Doc B must run first.**

* * *

## 3\. What Doc B got right that Doc A under-weighted (the decisive finding)

### 3.1 The schema is a redefinition fold, not a set of bounded contexts

Measured: **16 functions are created in more than one numbered file.** The worst offenders:

| Function | Files that create it |
| --- | --- |
| `stall_assert_sale_shape` | **004, 029, 031, 036, 037, 038, 040** (7) |
| `stall_assert_ticket` | 032, 035, 036, 040 |
| `stall_assert_ticket_line` | 032, 037, 040 |
| `stall_assert_sale_not_on_ticket` | 032, 037, 040 |
| `stall_assert_register_session` | 029, 034, 040 |
| `stall_reject_session_post` | 029, 034, 040 |
| `stall_assert_piece_return` | 034, 036, 037 |
| `stall_line_discount` | 036, 037, 039 |
| `stall_refuse_ticket_discount` | 036, 037, 039 |
| `stall_assert_rent_charge` | 005, 022 |
| `stall_assert_subledger_non_negative` | 003, 028 |
| `stall_reject_unknown_subledger` | 003, 028 |
| `stall_assert_booth_on_floor` | 020, 021 |
| `stall_reject_booth_place` | 020, 021 |
| `stall_reject_floor_change` | 020, 021 |
| `ledger_assert_journal_balance` | **001, 038** (kernel redefined by a tenant file) |

In a drop-and-reprovision model this is not _incorrect_ — the last definition wins and the database is right. But it is a maintainability and safety defect: to know what `stall_assert_sale_shape` does, you must read seven definitions and know which is last, and any reordering changes behaviour silently.

### 3.2 The reason it matters most: the guard is disabled during provisioning

`platform.reject_guard_function_replace()` (in `018_owner_backup.sql`) raises `25006` if you `CREATE` (replace) any function that backs a trigger named `*_append_only`, `*_no_truncate`, or `*_no_delete`:

```sql
IF EXISTS (
    SELECT 1 FROM pg_trigger
    WHERE tgfoid = command.objid
      AND NOT tgisinternal
      AND tgname ~ '(_append_only|_no_truncate|_no_delete)$'
) THEN
    RAISE EXCEPTION 'a guard function cannot be replaced' USING ERRCODE = '25006';
END IF;
```

And the guard triggers do point at the functions the later files redefine. For example:

-   `sale_lines_append_only` → `stall_assert_sale_shape` (`031`)
-   `tickets_no_delete` → `stall_assert_ticket` (`032`)
-   `ticket_lines_no_delete` → `stall_assert_ticket_line` (`032`)
-   `register_session_posts_append_only` / `_no_delete` → `stall_reject_session_post` (`029`)

So every later file that wants to _extend_ one of these guard functions must first `SET event_triggers = false`. That is exactly why the blocks exist:

```
sql/034_piece_return.sql:5        SET event_triggers = false;   ... :58   = true
sql/035_park_ticket.sql:13        SET event_triggers = false;   ... :121  = true
sql/036_ticket_adjustments.sql:141 SET event_triggers = false;  ... :714  = true
sql/037_item_quantity.sql:82,326  SET event_triggers = false;   ... :805  = true
sql/038_vendor_draw.sql:22        SET event_triggers = false;   ... :422  = true
sql/039_line_discount.sql:38      SET event_triggers = false;   ... :162  = true
sql/040_layaway.sql:57            SET event_triggers = false;   ... :883  = true
```

**Consequence:** provisioning runs with the append-only tamper guard switched off for seven files. Doc B's rule eliminates the redefinitions, which eliminates the need for `SET event_triggers = false` in ledger files entirely, so the guard is **never** disabled during provisioning. Doc B's proposed gate ("the file contains `SET event_triggers` → fail") enforces this permanently.

**This is the strongest argument in either document, and it is fully verifiable in the code.** Doc A did not identify it. It is the reason Doc B's approach should lead.

* * *

## 4\. Doc B's specific claims — verified true

| Doc B claim | Evidence |
| --- | --- |
| Ticket status check: 032 is two-value, 040 is four-value | `032:10` `CHECK (status IN ('open','posted'))`; `040:50` `CHECK (status IN ('open','posted','parked','layaway'))` — **true** |
| 040 drops `stall_apply_line_discount` and never recreates it | `040:828` `DROP FUNCTION IF EXISTS stall_apply_line_discount();`; created only in `039:132` — **true** |
| `stall_assert_sale_shape`'s live body reads `sale_lines`, `items`, `tickets`, `ticket_lines`, `mall.cash_round_nickel`, `register_session_posts`, `register_checks`, and calls `stall_line_discount` | `040` body references all of these (`040:149,795,814` for register tables; `stall_line_discount(` in body) — **true** |
| 025 has a backfill `UPDATE` for pre-existing malls | `025:10` and `025:25` `UPDATE items …` — **true** |
| 023 is created without `barcode`, `commission_bps`, `intake_on`, `expires_on`, `quantity` | `023` `CREATE TABLE items` has none of them; added in `025:5-8` and `037:4` — **true** |
| 031 / 032 / 034 are created without quantity/discount/buyer/line-discount columns | grep for those columns in `031`/`032`/`034` returns nothing; added in `036`/`037`/`039` — **true** |
| 030 adds close columns and rewrites the close check | `030:5-6` adds `counted_card_minor`, `counted_checks_minor`; `030:8-10` drops and re-adds `register_sessions_close_shape_chk` — **true** |
| Subledger guard triggers live in 003 and must move to 028 | `003:188` `journal_lines_subledger_identity`, `003:346` `journal_lines_control_source`; `028` redefines both functions but creates neither trigger — **true** |
| Floor guard triggers live in 020 and must move to 021 | `020:137` `booths_on_floor`, `020:167` `mall_floor`; `021` redefines the functions — **true** |
| `prove-tenant.php` repeats the provisioner list | Confirmed: 37-entry inline array at `bin/prove-tenant.php:49-85` — **true** |

* * *

## 5\. Doc B's claims that are wrong or imprecise (verified)

These matter because Doc B will be used as an execution plan.

1.  **`composer prove:race` and `composer prove:tenant` do not exist.** `composer.json` defines exactly one proof script: `"prove": "php bin/prove-suite.php"`. There is no `prove:race` and no `prove:tenant`. The cross-tenant proof is `php bin/prove-tenant.php`; the race check is `sql/prove_race.ps1` (PowerShell only — there is no Linux/shell runner, itself a gap). Doc B's acceptance criterion "composer prove:race and composer prove:tenant must also exit 0" is **not runnable as written**.
2.  **`bin/prove-obligations.php` does not exist.** It is a **proposal**, but Doc B phrases it as if describing existing behaviour ("fails when any of these is true"). It must be written from scratch. (The idea is good — see §7.)
3.  **`stall_seed_export_accounts()` does not exist.** Doc B says the chart inserts are "each followed by `SELECT stall_seed_export_accounts();`." There is no such function. The seed is an **inline `INSERT … WHERE NOT EXISTS` copy-pasted in five files** (`012`, `013`, `028`, `033`, `040`, byte-identical, MD5 `b22399bf468b4f4a86c025b02dae924e`). Doc B's line is a proposed refactor, not current state — and it is the same defect Doc A flagged as AE.
4.  **"`sql/001_kernel.sql` stays generic" is contradicted by the code.** The kernel function `ledger_assert_journal_balance` is **redefined in `038_vendor_draw.sql:24`**, inside a `SET event_triggers = false` block. The two bodies differ functionally: the 038 body adds
    
    ```sql
    AND line.account_id NOT IN (
        SELECT role.account_id FROM public.chart_roles AS role
        WHERE role.role = 'vendor_payable'
    )
    ```
    
    so the last-wins body references `chart_roles` (a tenant table created in `002`). Doc B's own placement rule ("the earliest file that runs after every relation the body names") would therefore move this **kernel** function — and the `journals_rent_charge`\-style constraint trigger that calls it in `001:524` — **out of the kernel into a tenant file**. That directly contradicts the "001 stays generic / untouched" framing. Doc B does not mention this redefinition at all.
    
5.  **"the journal trigger that calls it \[`stall_assert_rent_charge`\], in sql/022"** is imprecise. The trigger that calls it, `journals_rent_charge` (a deferred constraint trigger on `journals`), is created in **`005:181`**, not `022`. Doc B is instructing a _move_ to 022, but the phrasing reads as a statement of current location.
6.  **"`sql/027_register_sessions.sql` gains the close columns and the close check from 030"** is imprecise. `027` already has `closed_at`, `counted_cash_minor`, and `register_sessions_close_shape_chk` (`027:9,13,21`). `030` adds `counted_card_minor` / `counted_checks_minor` and _extends_ the check. It is an extension, not a wholesale gain.

None of these are fatal to Doc B's thesis — the thesis (each object once; guard never disabled) stands. But items 1–4 must be corrected before the plan is executed, and item 4 is a genuine hole in Doc B's placement rules.

* * *

## 6\. What Doc A adds that Doc B omits

1.  **The proof sprawl.** 42 `prove_*.sql` + 42 `prove_race_*.sql` = **84 files** sharing the flat `sql/` directory with the 41 schema files. Doc B says "every `prove_*.sql` stays" and never addresses the directory noise, which is the majority of the clutter the user is reacting to. Doc A proposes `sql/prove/` and `sql/prove/race/`.
2.  **The portability/completeness constraints of the dump.** Doc B says "the dump is the oracle" but does not state _why_ it cannot be the source: a `--schema-only` dump of one tenant contains **0** `INSERT`/`COPY` (seed data lost), **0** `CREATE EXTENSION` (`btree_gist` lives outside the tenant), and **0** event triggers (the append-only guards live in `platform`), plus 1,196 hard-coded schema references. This is what makes the template files non-negotiable.
3.  **A permanent determinism gate.** Doc B's catalog diff is a **one-time** before/after proof for this refactor (excellent for proving behaviour-preservation). Doc A's two-tenant determinism diff is a **permanent** gate that proves no mall drifts over time. They are complementary; both should exist.

* * *

## 7\. The path to take (synthesis)

Adopt **Doc B's substance** and **Doc A's structure and gates**, in this order:

**Phase 1 — Fold each object to a single definition (Doc B, corrected).**

-   Apply the "created once" rule to all 16 duplicated functions. Pick the last body in current `LEDGER_FILES` order; place it in the earliest file that runs after every relation and function it names; move its triggers with it; delete the earlier copies.
-   Delete the emptied files (`025`, `035`, `036`) once nothing remains.
-   **Correct Doc B item 4:** decide explicitly where `ledger_assert_journal_balance` lives. Its last body references `chart_roles`, so it cannot stay in `001` under the "created once" rule. Either (a) move it and its constraint trigger to `002_chart.sql` and accept that the journal-balance guard is a tenant-level object, or (b) keep it in the kernel and move the `vendor_payable` exception into a kernel-visible mechanism. Pick one and document it.
-   Result: no ledger file contains `SET event_triggers = false`; the guard is never disabled during provisioning.

**Phase 2 — Single-source the file list and add the gate (Doc B, corrected).**

-   Add `TenantProvisioner::ledgerFiles()`; point both `provision-tenant.php` and `prove-tenant.php` at it; drop the deleted files from the list.
-   Write `bin/prove-obligations.php` (it does not exist): fail if a ledger file creates the same function twice (in one file or across files), contains `SET event_triggers`, contains `ADD COLUMN IF NOT EXISTS`, or contains `DROP CONSTRAINT IF EXISTS`.
-   Wire it into `composer check`.

**Phase 3 — Prove the catalog did not move (Doc B).**

-   Provision `fold-before` and `fold-after`; dump columns, constraints, indexes, triggers, and `pg_get_functiondef` for each; diff with the schema name stripped. The diff must be empty.
-   Then run the real gates. **Corrected commands:** `composer check`, `php bin/prove-tenant.php`, and the race runner (`sql/prove_race.ps1`). There is no `composer prove:race`/`prove:tenant`.

**Phase 4 — Presentation and ongoing safety (Doc A).**

-   Optionally group the folded files into `sql/global/` + `sql/tenant/` bounded contexts and move the proofs into `sql/prove/`. This is now purely cosmetic, because Phase 1 removed the order-dependence that made the file layout dangerous.
-   Add the two-tenant determinism gate to `composer check`.
-   Decide the money-primitive home (Doc A: kernel; Doc B: `004`). Either is fine once each object is defined once; the kernel is marginally cleaner because it removes the "must run after 004" ordering note.

* * *

## 8\. Corrected acceptance criteria

Doc B's acceptance criteria, with the non-existent commands fixed:

| Doc B as written | Corrected |
| --- | --- |
| `composer prove:race` exits 0 | `sql/prove_race.ps1` exits 0 (and note: no Linux runner exists) |
| `composer prove:tenant` exits 0 | `php bin/prove-tenant.php` exits 0 |
| `composer check` exits 0 | `composer check` exits 0 (correct as written) |
| `bin/prove-obligations.php` fails on violations | Write `bin/prove-obligations.php` first; then it fails on violations |
| chart inserts followed by `SELECT stall_seed_export_accounts();` | First refactor the five inline seed copies into `stall_seed_export_accounts()` |

* * *

## Appendix — verification commands

```bash
# duplicate function definitions across numbered files
for f in $(ls sql/[0-9][0-9][0-9]_*.sql|sort); do
  grep -oE 'CREATE (OR REPLACE )?FUNCTION [a-z_.]+' $f | sed -E 's/.*FUNCTION //' | sed "s|\$| @$(basename $f)|"
done | sort | uniq -c | awk '$1>1'

# SET event_triggers in ledger files
grep -rn 'SET event_triggers' sql/[0-9][0-9][0-9]_*.sql

# the guard that forbids replacing guard functions
awk '/FUNCTION platform.reject_guard_function_replace/,/^\$\$;/' sql/018_owner_backup.sql

# the five identical export seeds
for f in 012 013 028 033 040; do awk '/INSERT INTO export_accounts/,/;$/' sql/${f}_*.sql | md5sum; done

# composer scripts that actually exist
python3 -c "import json;print(json.load(open('composer.json'))['scripts'])"
```