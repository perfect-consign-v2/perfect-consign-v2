# Hostile Review — `18bc68c` (9 commits, `9f32c16` → `18bc68c`)

**Range:** `0ddc95a` … `18bc68c` (9 commits, 192 files, +6748 / −1257) **HEAD:** `18bc68c` — _"Answer rent, today's sales, and checks from home, and keep photos, tag lists, and mall settings on the same records."_ **Env:** PHP 8.4.26 · Xdebug 3.5.3 · PHPUnit 13.3.5 · PostgreSQL 18.6 (host 5436) · PDO **pgsql only** · Docker **not** available. **Method:** fresh pull, full gate suite run by hand (composer not installed → `php bin/…` / `php vendor/bin/phpunit` directly), then line-by-line read of every new/changed file. `.cursor/rules/do-not-run-tests.mdc` deliberately ignored.

* * *

## 0\. Verdict

This push lands **two genuinely good, well-built features** (item photos, tag CSV import) and **correctly closes five carried findings** (N1, A1, A2, C1, C2). But it **breaks `composer check` at step 2** with a fresh phpmd regression, ships a **non-idempotent platform migration** that fails the platform stamp rebuild, and its new `item_photos` foreign key **breaks two SQL proofs**. Eight of the twelve gates are RED. The single most serious item is **S1/S2**: a new platform migration (`064`) that the migrator's own contract says must be re-runnable is not, and the failure cascades through a **leaked session advisory lock** that poisons the whole integration suite.

| Gate | Result on `18bc68c` | vs `9f32c16` |
| --- | --- | --- |
| `check:max-lines` | ✅ GREEN | \= |
| `check:phpmd` | ❌ **RED (exit 2, 8 violations)** | **REGRESSED** (was green) |
| `phpstan` L10 | ✅ GREEN (354 files) | \= |
| `test` (unit) | ❌ RED (1 stale test) | improved (was 3 sqlite errors) |
| `test:integration` | ❌ RED (3 errors) | **REGRESSED** (was green) |
| `test:http` | ❌ RED (5 failures) | **REGRESSED** (was green) |
| `prove:obligations` | ❌ RED | **REGRESSED** (was green) |
| `prove:race` | ✅ GREEN | \= |
| `prove` (suite) | ❌ RED (2 proofs) | **REGRESSED** (was green) |
| `prove:tenant` | ✅ **GREEN** | **FIXED** (was red — N1) |
| `test:mutate` | ❌ RED (cascade) | \= (cascade) |
| `test:coverage` | ❌ RED — **86.38%** < 90% | **REGRESSED** (was 89.51%) |
| JS (`node --test`) | ✅ GREEN (5/0) | \= |

`composer check` fails at **step 2 of 12**. The branch is not shippable as-is.

* * *

## 1\. New findings

### S1 — `064_sign_in_address.sql` is not re-runnable → the platform stamp rebuild fails · **BLOCKER**

`sql/064_sign_in_address.sql` is a **platform** migration. The platform migrator's contract is explicit, and the neighbouring file states it in a comment:

> `sql/057_owner_recovery.sql:5` — _"Re-running this file is safe: a platform stamp rebuild executes it again."_

`057` honours that by guarding every `ADD COLUMN`/`ADD CONSTRAINT` inside a `DO` block with `IF NOT EXISTS`. `064` does not:

```sql
ALTER TABLE platform.owner_sign_in_failures
    ADD COLUMN client_address text NOT NULL DEFAULT '';      -- fails 42701 on re-run

ALTER TABLE platform.owner_sign_in_failures
    ADD CONSTRAINT owner_sign_in_failures_address_chk CHECK ( -- fails 42710 on re-run
        char_length(client_address) <= 64
    );
```

On any second execution (a stamp rebuild, a re-provision, a CI re-run against a warm database) this raises `SQLSTATE[42701]: Duplicate column "client_address"`. Reproduced directly in the integration suite:

```
SchemaMigratorTest::testAPlatformInstallRollsBackAndATenantBaselineStamps
  RuntimeException: 064_sign_in_address.sql was not applied:
    SQLSTATE[42701]: Duplicate column: column "client_address" of relation
    "owner_sign_in_failures" already exists
```

**Fix:** wrap both statements in `IF NOT EXISTS` guards exactly as `057` does (or `ADD COLUMN IF NOT EXISTS` + a `pg_constraint` existence check for the CHECK).

### S2 — Session advisory lock leaks on migration failure · **BLOCKER (cascade amplifier)**

`MigrationRecord::holdMigration()` takes a **session-level** `pg_try_advisory_lock(814202615)`; `releaseMigration()` calls `pg_advisory_unlock`. Advisory locks are **not transactional**, but `SchemaMigrator` releases in a `finally` that runs _inside the aborted transaction_. When the transaction is already aborted (S1), the `pg_advisory_unlock` itself fails with `25P02` ("current transaction is aborted") and the lock is **never released for the life of the connection**.

Consequence: one bad migration poisons the connection, and every subsequent migration attempt on it reports `RuntimeException: a migration is already running`. This is exactly why S1 cascades into three integration errors:

```
testAMissingBaselineObjectIsNotStamped        → RuntimeException: a migration is already running
testAPartialLaterMigrationIsNotReplayed       → RuntimeException: a migration is already running
```

**Fix:** release the lock _outside_ the transaction (after commit/rollback), or use a transaction-scoped `pg_advisory_xact_lock`, or `ROLLBACK` before releasing. A lock that cannot be released on the error path is worse than no lock.

### S3 — `DatabaseRefusal::databaseShaped()` is now over-broad → catalog validation messages collapse to a generic string · **HIGH**

`0ddc95a`/`447694a` widened `databaseShaped()` to treat any line matching a constraint-shaped identifier as "database-shaped":

```php
if (preg_match('/\b[a-z0-9_]+_(?:chk|key|pkey|fkey|idx)\b/i', $line) === 1) {
    return true;
}
```

`shown()` calls `uniqueName()` first — but `uniqueName()` only knows `…_key` names (`colors_name_key`, `brands_name_key`, …). The catalog **name validation** is a CHECK constraint (`colors_name_chk`, `brands_name_chk`, …), which is **not** in that table. So the flow is:

`colors_name_chk` violation → `shown()` → `uniqueName()` returns null → `phrase()` → `databaseShaped()` matches `_chk` → returns `"That could not be saved."`

`NameListController::listError()` _does_ have the mapping (`…_name_chk` → `"Enter a color name."`), but it never sees it, because `OwnerGate.php:145` already ran `DatabaseRefusal::phrase()` at the choke point and handed back the generic sentence.

Evidence (both RED):

```
CatalogStickerTest:58           assertSees('Enter a color name.', $blank)  → "That could not be saved."
CoverageRemainderTest::testTheBookExportAndCatalogEdges                    → "That could not be saved."
```

This is a **UX regression against the "grandma-friendly" bar**: a blank name field now tells the user "That could not be saved." instead of "Enter a color name."

**Fix:** don't classify `_chk`/`_key` names as "database-shaped" _before_ the friendly-name tables have had a look — or add the `…_name_chk` mappings to `uniqueName()`/`listError()` and let them win. The generic fallback should be the _last_ resort, not the first match.

### S4 — `sql/041_payout_run.sql` financial logic edited **in place** · **HIGH**

`0ddc95a` removed a predicate from the `open_payable` query inside `stall_assert_settlement()` — an **already-applied** migration:

```diff
     WHERE line.journal_id <> NEW.id
-      AND posted.source_type NOT IN ('check_disbursement', 'cash_disbursement', 'safe_disbursement')
       AND role.role = 'vendor_payable'
```

This changes the settlement math for every tenant that already stamped `041`. Any such tenant now fails `MigrationRecord::verify()` with `MigrationFileChanged` on the next migrate. The team's **own convention** — commit `8c130d0`, _"Leave the applied payout migration unchanged"_ — reverts exactly this kind of edit into a new forward migration (`054_paid_check_number.sql`). That pattern was not followed here, and this one touches **money**, not a comment.

**Fix:** revert the `041` edit; ship the corrected `open_payable` predicate as a new forward migration that re-creates `stall_assert_settlement()`.

### S5 — `item_photos` FK breaks two SQL proofs · **HIGH**

`sql/063_switch_surface.sql:21` adds `item_photos.item_id uuid PRIMARY KEY REFERENCES items (id) ON DELETE CASCADE`. The proofs that `TRUNCATE items` enumerate and drop the known FKs first, but the list was not updated:

```
sql/prove_append_only_guard.sql:312-315  drops sale_lines/ticket_lines/item_returns/payout_run_sales FKs, then TRUNCATE items
sql/prove_append_only_guard.sql:374-377  same
sql/prove_items.sql:539, 578             TRUNCATE items
```

None drop `item_photos_item_id_fkey`. Result:

```
FAIL prove_append_only_guard.sql  SQLSTATE[0A000]: cannot truncate a table referenced in a foreign key constraint
FAIL prove_items.sql              SQLSTATE[0A000]: cannot truncate a table referenced in a foreign key constraint
prove:obligations                 exit 1 — 063 references items by item_photos_item_id_fkey and prove_append_only_guard.sql does not drop it before TRUNCATE items
```

**Fix:** add `ALTER TABLE item_photos DROP CONSTRAINT item_photos_item_id_fkey;` to the proofs' FK-drop blocks (and audit the obligation checker's FK list).

### S6 — `ProbeMall` now silently masks in-place-edit violations · **MEDIUM**

`tests/Support/ProbeMall.php:65`:

```php
} catch (TenantBaselineMismatch | MigrationFileChanged) {
    // wipe + re-provision webprobe
}
```

Adding `MigrationFileChanged` to the catch means a tenant whose stamped migration file changed **self-heals silently** instead of failing. The checksum guard is the mechanism that is supposed to _catch_ S4/A4 — and the harness that exercises it now swallows the signal. The only remaining signal is the integration suite, which cascades via S2. This is the wrong direction: the probe should surface the mismatch, not paper over it.

### S7 — `LateFeeDue::apply()` posts on every rent-page GET and scans the whole ledger · **MEDIUM**

`src/Feature/Rent/RentController.php:210` calls `$this->lateDue->apply($today)` inside `render()` — i.e. on **every GET of `/rent`**. `apply()` → `due()` → `moves()` runs an unbounded scan:

```sql
SELECT posted.posting_key, posted.source_type, line.subledger_ref AS booth_id, …
FROM journals AS posted
JOIN journal_lines AS line ON line.journal_id = posted.id
JOIN chart_roles AS role ON role.account_id = line.account_id AND role.role = 'booth_rent_receivable'
WHERE line.subledger_type = 'booth'
  AND NOT posted.is_reversal
  AND NOT EXISTS (SELECT 1 FROM journals AS reversal WHERE reversal.reverses_journal_id = posted.id)
ORDER BY posted.posting_date, posted.posting_key
```

plus a second scan in `postedKeys()` over all `late_fee` journals. So a **read-only page view writes to the ledger** and does an O(all rent history) scan. The posting key (`late-fee:{booth}:{period}`) makes it idempotent, so it won't double-post — but:

1.  **GET has a side effect.** It breaks the safe-method contract; a crawler, a link prefetch, or a back-button navigation can trigger ledger writes.
2.  **It is unbounded** per page view and will degrade as the ledger grows.
3.  It runs in the page's transaction; a refusal aborts the render (caught, but the page is now degraded).

**Fix:** move late-fee application to a scheduled job or an explicit "Apply late fees" action; keep the GET read-only.

### S8 — Stale tests (unit + HTTP) left behind by the rename/relabel · **MEDIUM**

The nav relabel (`'Month'` → `'This month'`, `'Dashboard'` → `'Home'`, etc.) and the sale-finished rewrite (`59525b3`) invalidated tests that were not updated:

| Test | Assertion | Reality |
| --- | --- | --- |
| `tests/Feature/Web/LayoutBranchesTest.php:45` | `assertStringContainsString('Month', $html)` | layout emits `'This month'` → **unit RED** |
| `tests/Http/OwnerJourneyTest.php:87` | `preg_match('/sale:[0-9a-f-]{36}/', …)` | `59525b3` removed the posting-key `<p>` from `sale-finished.php` |
| `tests/Http/WaveScreensTest.php:42` | `assertSees('does not post', $page)` | `18bc68c` changed `month.php`; the phrase is gone |
| `tests/Http/PayoutRunScreenTest.php:40` | `assertStringNotContainsString('This month', $body)` | the payouts nav now contains "This month" |

A rename that breaks four tests and ships them red is not finished. Either update the assertions or (better) assert on stable hooks, not on prose.

### S9 — Line coverage below the enforced floor · **MEDIUM**

`test:coverage` = **86.38%** (`16314/18886`), down from 89.51% on `9f32c16`. The floor is 90%. New code is shipping under the bar — the same pattern flagged in A5, now worse.

* * *

## 2\. Carried findings — re-verified

| ID | Finding | Status now | Evidence |
| --- | --- | --- | --- |
| **N1** | `056` disabled every event trigger for the rest of provisioning | ✅ **CLOSED** | `056` now brackets only the three `CREATE OR REPLACE FUNCTION` with narrow `SET LOCAL event_triggers = false/true`. `prove:tenant` **GREEN**. |
| **A1** | Unit suite depended on undeclared `pdo_sqlite` | ✅ **CLOSED** | `CompanyStoreTest` no longer references sqlite; 4/4 pass. |
| **A2** | `prove:tenant` outside `composer check`/CI | ✅ **CLOSED** | `composer.json` `check` chain now includes `@prove:tenant`. |
| **C1** | `afterprint` wiped the sticker selection on print-cancel | ✅ **CLOSED** | `stickers.php` no longer removes the `sessionStorage` key on `afterprint`. |
| **C2** | `Pieces → Items` rename | ✅ **CLOSED (rename)** — but still done by editing applied migrations (see S4/A4). | `grep -rin "piece"` → zero. |
| **A4** | Eight applied migrations edited in place | ❌ **STILL OPEN / WORSENED** | `041` now also has a **financial** edit (S4); `064` is a new non-idempotent platform migration (S1). |
| **A5** | Coverage below floor | ❌ **OPEN / WORSENED** | 86.38% (S9). |
| **A6** | Probe cannot recover from checksum mismatch | ⚠️ **PARTIALLY ADDRESSED — wrongly** | `ProbeMall` now catches `MigrationFileChanged`, but by _masking_ it (S6), not by surfacing it. |
| **D1** | `.cursor/rules/*.mdc` committed, incl. `do-not-run-tests` | ❌ OPEN | unchanged. |
| **E1–E4** | Vendor-account/print perf hygiene | ❌ OPEN | unchanged. |

* * *

## 3\. New code read — what is genuinely good

Credit where due — these are well-built and I could not break them:

-   **`ItemPhotoController`** (`18bc68c`) — sniffs magic bytes on **both** store and serve (`\xFF\xD8\xFF` JPEG, `\x89PNG\r\n\x1a\n` PNG, `RIFF…WEBP`), sets `Content-Type` from the **sniffed** type (not the client's), checks CSRF + `is_uploaded_file`, enforces size `1..2,000,000`, upserts via `decode(?, 'hex')`, and scopes vendor visibility to their own photo. Correct on every axis I checked.
-   **`TagImport` / `TagImportController`** — CSRF, `is_uploaded_file`, strict exact header `['code','vendor','description','price','barcode','quantity']` (with BOM strip), `LIMIT = 500`, all rows in one transaction. Sound.
-   **`clientAddress()`** (`public/index.php`) — uses `$_SERVER['REMOTE_ADDR']` only, validated `/^[A-Za-z0-9:.]{1,64}$/`. **Not** spoofable via `X-Forwarded-For`. Good.
-   **`books` role RBAC** (`RoleGate::books()`) — GET-only allowlist for `/sales,/month,/rent,/payouts,/journals,/password,/dashboard` (+ detail routes); POST limited to `/logout,/logout/confirm,/password,/password/email`. Reasonable least-privilege.
-   **Late-fee idempotency** — `posting_key = 'late-fee:{booth}:{period}'` is the right key; no double-posting.

* * *

## 4\. Recommended order of attack

1.  **S1** — make `064` re-runnable (`IF NOT EXISTS` guards, per `057`). Unblocks the platform stamp rebuild.
2.  **S2** — release the advisory lock outside the transaction (or use `pg_advisory_xact_lock`). Stops the cascade.
3.  **S5** — add the `item_photos` FK to the proofs' drop lists. Unblocks `prove`, `prove:obligations`.
4.  **S4** — revert the `041` in-place edit; ship it as a forward migration.
5.  **S3** — let the friendly-name tables win before the generic `_chk` fallback.
6.  **phpmd** — split `NightlyCopy::latest()` (CC 15 / NPath 1560) and `MallController`/`TicketStore`; pull `OwnerGate`/`InventoryController` coupling back under 13. `composer check` must be green at step 2.
7.  **S8** — fix the four stale tests; assert on hooks, not prose.
8.  **S6** — make `ProbeMall` _surface_ `MigrationFileChanged`, not swallow it.
9.  **S7** — move late-fee application off the GET path.
10.  **S9** — cover the new paths back over 90%.

* * *

_Reviewed by hand: fresh pull, all twelve gates run, every new/changed file read. No finding here rests on assumption — each cites the file, line, and the command that produced it._