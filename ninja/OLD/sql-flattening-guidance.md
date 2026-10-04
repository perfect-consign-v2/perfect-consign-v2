# SQL Layout: Decision Document

**Subject:** Consolidating / "flattening" the `sql/*.sql` ledger files, and the related question of whether to export the schema from a live database instead of maintaining hand-written files.

**Repo under review:** `perfect-consign-v2/perfect-consign-v2` **Branch:** `master` **Revision reviewed:** `efbfaea6a94ddbf2e3527ccf63d63d7793c29d9c` **Author of this document:** independent reviewer (the "second agent") **Purpose:** state the problem, the constraints, the evidence, and a recommended path so that this document and the counterpart document (from the repo author) can be compared side by side to select the path forward.

* * *

## 1\. Executive summary

The `sql/` directory currently holds **41 numbered schema files** (`000`–`040`, 9,652 lines total), **42 standalone proof files** (`prove_*.sql`), and **42 two-session race files** (`prove_race_*.sql`) — 125 files in one flat directory. The request is to reduce that.

The single most important fact is this: **provisioning is drop-and-reprovision with no migration table.** The provisioner replays files in filename order into a fresh schema. Therefore **file boundaries have zero runtime effect** — 41 files or 1 file produces a byte-identical database. Consolidation is therefore a pure _code-organization_ decision, and the only thing at stake is **reviewability and maintainability**, not correctness.

Given that, my recommendation is:

1.  **Do not** collapse the schema to a single `schema.sql`, and **do not** replace the hand-written files with a `pg_dump` export. Both destroy portability or reviewability.
2.  **Do** consolidate the tenant schema from 37 files to **~10 bounded-context files**, split structurally into `sql/global/` (applied once per database) and `sql/tenant/` (applied per mall), with the apply order derived from the directory rather than a hand-maintained constant.
3.  **Do** fix the real duplication first — it is a defect independent of file count.
4.  **Do** move the proofs into `sql/prove/` so the schema and the proofs stop competing for the same flat namespace.
5.  **Do** add a `pg_dump` **determinism test** (dump two tenants, normalize, diff) — this is the _correct_ use of `pg_dump` in this architecture and it strengthens the current design rather than replacing it.

* * *

## 2\. The deciding constraint

From `README.md`:

> "A schema change is an edit to that file. Drop the mall and run `php bin/provision-tenant.php` again. **Do not add an alter file for a mall that already exists.**"

And confirmed by inspection: there is **no migration table** anywhere in the codebase (no `migrations`, no `schema_version`, no Flyway/Liquibase/Doctrine-Migrations). The provisioner (`TenantProvisioner`) simply reads a fixed ordered list of files and executes them:

```php
private const LEDGER_FILES = [
    '001_kernel.sql', '002_chart.sql', ... '040_layaway.sql',
];
```

Consequences that drive every recommendation below:

-   **Order is by filename** (the list mirrors numeric order).
-   **A file boundary is invisible to the database.** Merging or splitting files cannot change the resulting schema.
-   **Portability is achieved by textual placeholders**, substituted at provision time by `TenantProvisioner::rewrite()`:
    
    ```php
    return str_replace(
        ['public.', 'SCHEMA public', 'stall_poster'],
        [$schema . '.', 'SCHEMA ' . $schema, $role],
        $sql
    );
    ```
    
    The source files are written against the placeholder names `public` and `stall_poster`; the provisioner rewrites them to the concrete `tenant_<32hex>` schema and the concrete `poster_<hex>` role. **The placeholders are a feature, not a wart.** They are the entire mechanism by which one source tree provisions N malls.
    

* * *

## 3\. "Too many files" is three separate problems

These are being conflated. They have different severities and different fixes.

### 3.1 Real duplication (a defect — fix regardless of granularity)

-   **The `export_accounts` seed is copy-pasted in five files.** Verified: the block `INSERT INTO export_accounts …` appears once each in `012_export.sql`, `013_period_close.sql`, `028_tenders.sql`, `033_cash_round.sql`, and `040_layaway.sql`, and the five copies are **byte-identical** (identical MD5 `b22399bf468b4f4a86c025b02dae924e`). Adding a sixth export system (e.g. Sage) requires editing five files in lockstep; missing one silently produces a mall whose export map is incomplete for that system.
-   **The ordered file list is duplicated verbatim** in `bin/provision-tenant.php` (`LEDGER_FILES`, 37 entries) **and** `bin/prove-tenant.php` (an inline array, same 37 entries). They can drift.
-   **`017_poster_grants.sql` is hard-coded by name** in `bin/pull-tenant.php` (`file_get_contents(... '017_poster_grants.sql')`). Any renumber breaks the pull path.
-   **The shared money primitives live in a feature file.** `stall_half_up()` and `stall_tax_minor()` are defined in `004_sale.sql` but called by `029`, `031`, `036`, `037`, `038`, `039`, and `040`. A file named "sale" silently owns the money math for half the schema, and every caller must be numbered _after_ `004` — a non-obvious ordering dependency.

### 3.2 Granularity (a taste/maintainability question)

41 numbered files, but the sizes are wildly inconsistent:

| Smallest |   
 | Largest |   
 |
| --- | --- | --- | --- |
| `017_poster_grants.sql` | 8 lines | `040_layaway.sql` | 884 lines |
| `016_owners.sql` | 16 lines | `037_item_quantity.sql` | 820 lines |
| `019_brands.sql` | 21 lines | `036_ticket_adjustments.sql` | 714 lines |
| `015_stall_app.sql` | 30 lines | `001_kernel.sql` | 632 lines |

This spread is the tell that the "one feature = one file" rule is **not actually being followed** — files were added ad hoc as features landed. That inconsistency is why the directory _feels_ like clutter even though 41 files is not objectively a large number.

### 3.3 The proof explosion (the actual visual noise)

42 `prove_*.sql` files plus 42 `prove_race_*.sql` files share the **same flat `sql/` directory** as the schema. The schema is 41 files; the proofs are 84. The proofs are the majority of the directory and the main reason it feels unmanageable.

* * *

## 4\. Evidence: why "just export the schema" does not work here

The proposal "I'm only at dev — why not export the DB schema as-is?" was tested directly. A `--schema-only` dump of one provisioned tenant (`r10`, `tenant_01a0f50d1b297f8db3d84a1e9045d5f3`) was produced with PostgreSQL 18.6 `pg_dump`:

```
pg_dump -h 127.0.0.1 -p 5436 -U stall -d consign \
        --schema-only -n tenant_01a0f50d1b297f8db3d84a1e9045d5f3
```

Result: **6,243 lines.** Measured contents:

| Property | Count | Consequence |
| --- | --- | --- |
| Concrete schema name `tenant_01a0f50d…` | **1,196** | The file is bound to one mall; not portable. |
| Concrete poster role `poster_01a0f50d…` | **38** | Grants baked to one role. |
| `OWNER TO stall` | **99** | Every object owned by the dumping superuser. |
| `GRANT` / `REVOKE` statements | **74** | All bound to the concrete role. |
| `INSERT` statements | **0** | Seed **data** is not captured. |
| `COPY` blocks | **0** | Seed **data** is not captured. |
| `CREATE EXTENSION` | **0** | `btree_gist` lives outside the tenant. |
| Event triggers (append-only guards) | **0** | Guards live in `platform`, outside the tenant. |
| Tables / functions / triggers / constraint-triggers captured | 29 / 66 / 71 / 18 | The tenant-local objects _are_ captured. |

### 4.1 The portability problem

The provisioner's `rewrite()` exists precisely to substitute the placeholders `public.` → `<schema>` and `stall_poster` → `<role>`. A dump has **already resolved** those placeholders to one concrete tenant. To reuse a dump for a second mall you would have to `str_replace` **1,196 + 38** occurrences inside a 6,243-line blob, with no placeholder left to anchor on. That is strictly more fragile than rewriting 41 small, placeholder-bearing files.

### 4.2 The completeness problem (the dangerous part)

A `--schema-only` dump silently omits three things this design depends on:

1.  **Seed data.** The chart accounts, the default mall book, and the `export_accounts` rows are **data**, and the numbered files deliberately interleave data with DDL (`002_chart.sql`, `012_export.sql`). A schema-only dump rebuilds **empty** tables. (A data dump would capture them, but then you are shipping a snapshot of one mall's live rows, which is worse.)
2.  **Extensions.** `btree_gist` is installed into the `extensions` schema, outside the tenant, so `CREATE EXTENSION` never appears in the tenant dump. The dump cannot stand alone.
3.  **The append-only guards.** The event triggers `platform.reject_append_only_trigger_change` / `platform.reject_guard_function_replace` live in `platform`, outside the tenant, so **0 event triggers** appear in the dump. A dump-restore therefore rebuilds the tables **without the machinery that makes them tamper-proof**. Compounding this, `pg_dump` emits `SET check_function_bodies = false`, so the function bodies it _does_ load are not even validated. For an append-only double-entry ledger, the restore path and the incremental path would not enforce the same invariants — the one thing that cannot be hand-waved.

### 4.3 The environment-noise problem

The dump also carries session state that does not belong in source control:

```
SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;
```

plus the pg\_dump-18 `\restrict <token>` / `\unrestrict <token>` wrapper and `ALTER … OWNER TO stall` for 99 objects.

### 4.4 Why "I'm at dev" does not change the verdict

Being at dev makes **restructuring** cheap — there are no production malls to migrate, so now is exactly the right moment to change the layout. But it does not make a non-portable artifact portable. The 1,196 hard-coded schema references are a property of the **dump**, not of the lifecycle stage. Dev buys the freedom to reorganize; it does not buy a dump that can provision mall #2.

### 4.5 When a dump _is_ the right tool

A dump is the correct source of truth when: the schema name is fixed (single-tenant `public`), or a migration tool generates the dump as a **build artifact**, or you want a **read-only reference** of a deployed database. None of those apply here: the system is multi-tenant with a per-mall schema **and** a per-mall role, and portability is the entire design.

* * *

## 5\. Options considered

| # | Option | Verdict | Reason |
| --- | --- | --- | --- |
| A | Leave 41 files as-is; only fix duplication | Viable | Lowest risk, but keeps the inconsistent granularity and the flat proof sprawl. |
| B | Collapse tenant schema to one `schema.sql` | **Reject** | A ~9,650-line file destroys `git blame` and makes diffs unreviewable. It is the shortcut that _lowers_ the bar. |
| C | Replace source with a `pg_dump` export | **Reject** | Non-portable (1,196 hard-coded refs), incomplete (no seed data, no extensions, no append-only guards), and noisy. See §4. |
| D | Consolidate to ~10 bounded-context files, split `global/` vs `tenant/`, order from the directory | **Recommend** | Reviewable file sizes, removes the numbering holes and the duplicated constant, fixes the money-primitive ordering trap. |
| E | Keep hand-written files but as a single portable `schema.sql` (still placeholder-bearing) | Acceptable fallback | One artifact, still portable and rewritable — but diffs get noisy. Better than B or C. |

* * *

## 6\. Recommended layout (Option D)

```
sql/
  global/                     # applied ONCE per database `consign`
    000_platform.sql          # was 000_platform.sql
    010_stall_app.sql         # was 015_stall_app.sql
    020_owners.sql            # was 016_owners.sql
    030_owner_backup.sql      # was 018_owner_backup.sql
  tenant/                     # applied PER MALL, in filename order
    010_kernel.sql            # was 001_kernel.sql (+ money primitives moved here)
    020_chart.sql             # was 002_chart.sql
    030_identity.sql          # was 003,019,020,021,022
    040_inventory.sql         # was 023,024,025,037
    050_registers.sql         # was 026,027,028,029,030
    060_sales.sql             # was 004,031,032,033,034,035,036,039,040
    070_money_in.sql          # was 005,006,007,008,009,010,011,038
    080_reporting.sql         # was 012,013,014
    090_grants.sql            # was 017_poster_grants.sql
    095_export_seed.sql       # NEW — the single, idempotent export_accounts seed
  prove/
    *.sql                     # the 42 standalone proofs
    race/                     # the 42 two-session pairs
```

Rationale for each decision:

-   **`global/` vs `tenant/` makes the split structural.** Today the numbering has holes (`015`, `016`, `018` are global; `017` is tenant) and a hand-maintained constant decides who is who. Directory placement removes both the holes and the constant: the apply order becomes a directory listing, so there is nothing to keep in sync.
-   **Each file lands at ~300–1,500 lines** — large enough to be a genuine bounded context, small enough to review in one sitting. This is the sweet spot the current 8-line/884-line spread misses.
-   **Money primitives move to the kernel.** `stall_half_up` / `stall_tax_minor` are kernel-level, not sale-level. Moving them to `010_kernel.sql` removes the latent "must be numbered after 004" ordering trap.
-   **The export seed becomes one file applied last.** Because the seed is idempotent (`WHERE NOT EXISTS`), a single `095_export_seed.sql` at the end is correct and kills the five-copy duplication permanently.

* * *

## 7\. Constraints and risks to respect if Option D is executed

1.  **`rewrite()` is a naive `str_replace`** on `public.`, `SCHEMA public`, and `stall_poster`. Merges are safe **only** if (a) every cross-file reference keeps its `public.` prefix, and (b) no `global/` file accidentally contains the token `stall_poster`. Note that `public.` also appears inside comments.
2.  **Three hard-coded filename references must change together**, or be collapsed to one source: the `LEDGER_FILES` constant in `bin/provision-tenant.php`, the inline array in `bin/prove-tenant.php`, and the `017_poster_grants.sql` reference in `bin/pull-tenant.php`.
3.  **`README.md` enumerates every filename** (the provisioning paragraph) and contains the "apply `025` through `037` in place" note. Both must be rewritten.
4.  **The proof suite is agnostic to schema filenames** — `bin/prove-suite.php` globs `prove_*.sql` and drives the provisioner — so the proofs survive a renumber **as long as the provisioner's list is correct**. This is what makes the change safe.
5.  **Verification per step.** Each step must be validated against a fresh tenant and the full `composer check` (which now includes the `prove` gate) before proceeding.

* * *

## 8\. The correct use of `pg_dump`: a determinism test

Rather than using a dump as the source of truth, use it as a **test**:

1.  Provision two throwaway tenants (`A`, `B`).
2.  `pg_dump --schema-only` each.
3.  Normalize the concrete schema name and role name out of both (replace `tenant_<hex>` → `<schema>` and `poster_<hex>` → `<role>`).
4.  Diff. They must be identical.

This **proves** that provisioning is deterministic and that no mall has drifted — a genuine, world-class guarantee that the current architecture _can_ provide and that a dump-as-source design cannot. It is a natural addition to `composer check`.

* * *

## 9\. Trade-off, stated honestly

Consolidation trades **diff locality** for **navigability**.

-   Today: a new feature touches one small file → excellent, localized diffs.
-   After merging: a feature edits a ~1,500-line file → noisier diffs.

That is the real cost, and it is why the recommendation stops at **~10 files** and does not go lower. If the priority is "a reviewer can hold one domain in their head," merge. If it is "every commit is a tiny localized diff," keep 41 files and fix only the duplication.

* * *

## 10\. Recommended execution order (three reviewable steps)

1.  **Dedupe + single-source.** Fold the five-copy `export_accounts` seed into one idempotent file; collapse the two `LEDGER_FILES` lists and the `pull-tenant.php` reference into one source of truth. (Pure defect fix; no layout change.)
2.  **The `global/` + `tenant/` split** with the domain merge to ~10 files; move the money primitives into the kernel; update `README.md`.
3.  **Relocate the proofs** into `sql/prove/` and `sql/prove/race/`; update `prove-suite.php`, `prove_race.ps1`, and the README.

Each step verified against a fresh tenant and the full `composer check` before the next.

* * *

## 11\. Open questions for the counterpart document

To make the side-by-side comparison productive, the counterpart should address:

1.  **Portability:** how does your proposal provision mall #2 from the same source? If the answer is "rewrite the concrete names," how many substitutions, and how are they anchored?
2.  **Seed data:** where do the chart accounts and the `export_accounts` rows live in your proposal, given a schema-only dump does not capture them?
3.  **Append-only guards:** how are the `platform` event triggers reapplied after a dump-restore, and how is `check_function_bodies = false` compensated for?
4.  **The duplicated constant:** how is the apply order kept single-sourced?
5.  **Diff quality:** what is the largest file in your proposal, and is a change to a single domain still reviewable as a localized diff?
6.  **The proofs:** where do the 84 proof files live, and how does the suite find them?

* * *

## Appendix A — measured facts (revision `efbfaea`)

-   Numbered schema files: **41** (`000`–`040`), **9,652** lines total.
-   Standalone proofs: **42**; race proofs: **42**; total `sql/` files: **125**.
-   Largest schema file: `040_layaway.sql` (**884** lines).
-   Smallest schema file: `017_poster_grants.sql` (**8** lines).
-   `export_accounts` seed: **5** byte-identical copies (`012`, `013`, `028`, `033`, `040`), MD5 `b22399bf468b4f4a86c025b02dae924e`.
-   Money primitives defined in `004_sale.sql`; called by `029`, `031`, `036`, `037`, `038`, `039`, `040`.
-   Migration table present: **no**.
-   `pg_dump` of one tenant (`--schema-only`): **6,243** lines; concrete schema name appears **1,196** times; concrete role **38** times; `OWNER TO` **99** times; `INSERT`/`COPY` **0**; `CREATE EXTENSION` **0**; event triggers **0**.