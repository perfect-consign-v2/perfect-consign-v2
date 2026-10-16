# Hostile review — `perfect-consign-v2` @ `094c2c9`

**Scope:** fresh pull of `master`, re-run every gate, re-review the new commit, update the prior report (`review-cf58c51.md`). **HEAD reviewed:** `094c2c9` ("Point the proofs at the tenant under test, stamp a later file that is already present, and pin the sticker measurements."), co-authored by Cursor. **New commit since the last review (`cf58c51`):**

| Commit | Subject | Delta |
| --- | --- | --- |
| `094c2c9` | Point the proofs at the tenant under test, stamp a later file that is already present, and pin the sticker measurements. | 8 files changed, 244 insertions(+), 16 deletions(−) |

**Method:** PostgreSQL 18.6 on `127.0.0.1:5436` (db `consign`, user `stall`), PHP 8.4.26, PHPUnit 13.3.5, Xdebug 3.5.3. Every gate was run against a clean working tree at `094c2c9`. Docker is not available in this sandbox, so the DB was driven directly (the repo's own `bin/` scripts and `psql`). DB-dependent gates were run against a freshly created database (`consign_r2`, only `provision-platform.php` applied) to obtain CI-equivalent results, and the polluted long-lived `consign` database was used to test the field-recovery path.

**This report lists only what is wrong or below a world-class enterprise bar.** Everything that is correct is deliberately omitted — except where I explicitly confirm a prior finding is genuinely fixed, so the delta is unambiguous.

* * *

## 1\. Verdict

**This is the first commit in this series where `composer check` — the full ten-gate chain — is green.** Every finding from the prior report that this commit set out to fix is genuinely fixed, and I verified each one against the code and by reproducing the failure scenario, not by reading the commit message. Two gates remain red: `composer test:coverage` (the second CI step, still under the floor) and `prove:tenant` (not in the CI chain, but red on a clean database for the same non-hermetic reason Cursor fixed everywhere else).

| Gate | `cf58c51` | `094c2c9` | Headline |
| --- | --- | --- | --- |
| `check:max-lines` | GREEN | **GREEN** | all files ≤ 500 lines |
| `check:phpmd` | GREEN | **GREEN** | exit 0 |
| `phpstan` (level 10) | GREEN | **GREEN** | `[OK] No errors` |
| `test` (unit) | GREEN | **GREEN** | 106 tests, **491** assertions (was 429) |
| `test:integration` | GREEN fresh / RED polluted | **GREEN both** | 15 tests, 110 assertions; polluted DB now green too |
| `test:http` | GREEN | **GREEN** | 42 tests, 611 assertions |
| `prove:obligations` | GREEN | **GREEN** | `ok obligations` |
| `prove:race` | GREEN | **GREEN** | `ok race` |
| `prove` (suite) | **RED (4 proofs)** | **GREEN** | all proofs pass, `wipe-backup` ok |
| `test:mutate` | **RED (80 survivors)** | **GREEN (0 survivors)** | 553 mutants, 522 killed, 31 equivalent, **0 survived** |
| `prove:tenant` (not in `check`) | GREEN\* | **RED on clean DB** | `mall tenant is missing` |
| `test:coverage` | RED (89.51%) | **RED (89.55%)** | still below the 90% floor |

\* `prove:tenant` was reported green at `cf58c51` only because it was run against the polluted `consign` database, which happens to contain a `mall` tenant. On a clean database it has always been red (see §4).

**The headline: the correctness backstop is back. `composer check` passes end to end for the first time, and the mutation gate — the strongest signal in the suite — is green with zero survivors. What remains is the coverage floor, one non-hermetic gate that was missed in the cleanup, and two governance rules that still instruct the agent not to run any of this.**

* * *

## 2\. What was fixed — verified, not taken on faith

Each of these was a finding in `review-cf58c51.md`. I re-ran the failing scenario for each.

### 2.1 The `prove` suite is green in CI (was 4 failures)

The three proofs that hard-depended on a `mall` tenant now resolve the tenant **under test** instead of a literal slug:

```sql
-- sql/prove_owner.sql, sql/prove_owner_backup.sql, sql/prove_append_only_guard.sql
SELECT id INTO mall_id FROM platform.tenants WHERE schema_name = current_schema();
IF mall_id IS NULL THEN
    RAISE EXCEPTION 'the tenant for this schema is missing';
END IF;
```

`prove-suite.php` provisions a `proofsuite` tenant and sets the search path to it, so `current_schema()` resolves to the tenant under test. I ran the full suite against a clean database:

```
ok prove_wait_list.sql
ok wipe-backup
PROVE_EXIT=0
```

**All proofs pass.** This is the correct fix: the proof now depends on the harness's own tenant, not on ambient database state.

### 2.2 `prove_item_attributes.sql` no longer dies on its own foreign key

The prior failure was `0A000 cannot truncate a table referenced in a foreign key constraint` — `048_item_attributes.sql` added `items.color_id REFERENCES colors(id)`, so `TRUNCATE colors` was rejected before the append-only trigger could fire. The fix drops the child FK inside the proof's (rolled-back) transaction:

```sql
EXECUTE format('ALTER TABLE items DROP CONSTRAINT %I', 'items_' || item_column || '_fkey');
BEGIN
    EXECUTE format('TRUNCATE %I', catalog);
    RAISE EXCEPTION '% truncate was accepted', catalog;
EXCEPTION
    WHEN SQLSTATE '25006' THEN
        IF SQLERRM NOT LIKE '%' || catalog || ' is append-only and cannot be truncated%' THEN
            RAISE;
        END IF;
END;
```

I checked whether this weakens the proof. It does not. The FK dependency is a _separate_ invariant that the proof still exercises directly (lines 79–85 assert that deleting a referenced catalog row raises `SQLSTATE 23503`). Dropping the FK here only removes the _dependency_ so the append-only `TRUNCATE` invariant on the catalog table can be tested in isolation — and the whole block runs inside the proof's transaction, which is rolled back. This is correct isolation, not a shortcut. The proof passes.

### 2.3 The re-baseline recovery path now recovers the real field case

The prior report's residual finding was that `stampPresent()` only ran when `schema_migrations` was _absent_, so a tenant with post-`044` objects present but unstamped **and** a migrations table was never recovered. The fix moves the call outside the guard:

```php
// src/Schema/TenantMigrator.php
if (! $this->record->ready($schema)) {
    $this->baselineTenant($slug, $schema);
}
$this->stampPresent($slug, $schema);   // now unconditional
$this->record->verify($schema);
$this->pendingTenant($slug, $schema);
```

I reproduced the exact field scenario rather than trusting the diff. I provisioned a tenant, deleted the `048`/`049` stamps while leaving the objects in place, and ran the migration:

```
$ psql -c "DELETE FROM tenant_...schema_migrations WHERE filename IN ('048_item_attributes.sql','049_label_stock.sql');"
DELETE 2
$ psql -tAc "SELECT to_regclass('tenant_....colors');"
tenant_....colors          # objects still present, stamps gone

$ php bin/migrate.php tenant rebase
rebase current             # exit 0
$ psql -tAc "SELECT filename FROM tenant_....schema_migrations ORDER BY filename DESC LIMIT 3;"
049_label_stock.sql
048_item_attributes.sql
044_schema_migrations.sql  # stamps restored
```

And the integration suite — which produced **13 errors** on the polluted database at `cf58c51` — is now green there too:

```
OK (15 tests, 110 assertions)
```

**Genuinely fixed, including the field case.**

### 2.4 Mutation coverage restored — 80 survivors → 0

```
Mutants 553, killed 522, equivalent 31, survived 0, errors 0
MUTATE_EXIT=0
```

The survivor count is zero and the unit suite grew from 429 to 491 assertions, all of it value-pinning. See §5 for my audit of the equivalent-mutant allow-list — I did not accept the jump in "equivalent" (19 → 31) on faith.

* * *

## 3\. P1 — `test:coverage` is still below the floor (89.55% < 90%)

```
Lines: 12045/13450 (89.55%)
line coverage 89.55% is below 90%
COV_EXIT=1
```

This is the **second step of CI** (`composer check` then `composer test:coverage`), so the pipeline is still red even though the check chain is now green. The figure moved from 89.51% to 89.55% — the new tests added assertions but not enough executed lines to cross the floor. The uncovered mass is concentrated in the payout and backup features (`Feature/Payout/envelope.php` 18.2%, `Feature/Backup/BackupWorkspace.php` 25.0%, `Feature/Backup/TenantBackup.php` 31.9%, `Feature/Payout/PosLayawayController.php` 48.3%, `Feature/Payout/PayoutSettler.php` 49.0%). The floor is not met; the gate is red.

* * *

## 4\. P1 — `prove:tenant` is red on a clean database (the missed half of the mall-tenant fix)

This commit fixed the `mall`\-tenant dependency in three proofs and in the integration test. It **missed the fourth proof**, `prove_app.sql`, which is the one the `prove:tenant` gate runs:

```sql
-- sql/prove_app.sql, still unchanged
SELECT schema_name INTO mall_schema FROM platform.tenants WHERE slug = 'mall';
IF mall_schema IS NULL THEN
    RAISE EXCEPTION 'mall tenant is missing';
END IF;
```

`bin/prove-tenant.php::run()` calls `runFile('prove_app.sql')` first, so on a clean database the gate dies immediately:

```
$ STALL_DB_NAME=consign_r2 php bin/prove-tenant.php
SQLSTATE[P0001]: Raise exception: 7 ERROR:  mall tenant is missing
TENANT_EXIT=1

$ STALL_DB_NAME=consign php bin/prove-tenant.php      # polluted DB, has a mall tenant
TENANT_EXIT=0
```

This is the **exact defect class** the commit set out to eliminate — a proof that passes only because ambient database state happens to contain a `mall` tenant. It is the same non-hermetic bug that was fixed in `prove_owner.sql`, `prove_owner_backup.sql`, `prove_append_only_guard.sql`, and `SchemaMigratorTest`. The fix was applied to four of five call sites; the fifth was left behind. `prove_app.sql` should resolve the tenant the same way (`schema_name = current_schema()`) or be given a `mall` fixture by the harness.

**Mitigating context, stated fairly:** `prove:tenant` is **not** in the `composer check` chain (`@check:max-lines && @check:phpmd && @phpstan && @test && @test:integration && @test:http && @prove:obligations && @prove:race && @prove && @test:mutate`), and CI never invokes it separately. So this does not block CI. But it is a gate that exists, is documented as a gate, and is red the moment the database is clean — which is the definition of a non-hermetic test. It should be fixed with the same one-line change as its siblings.

* * *

## 5\. Audit of the mutation allow-list — the equivalences are genuine (no suppression)

The mutation gate went green, but the "equivalent" count rose from 19 to 31 — twelve mutants were reclassified as equivalent rather than killed. That is precisely the move a reviewer must not accept on faith, so I read each of the twelve new `equivalentReason()` entries against the code:

| Entry | Claim | Verdict |
| --- | --- | --- |
| \`SqlObjects.php:102 |   
 | → &&\` |
| `LabelSheet.php:132 + → -` | `getHeight() + getRowGap()`, and `getRowGap()` returns literal `0` | **Genuine** (verified in `LabelStock.php`) |
| `LabelSheet.php:173 < → <=` | clamp `if ($ppm < 2) $ppm = 2` — at `$ppm == 2` both branches leave `2` | **Genuine** |
| `LabelSheet.php:176 > → >=` | clamp `if ($ppm > 6) $ppm = 6` — at `6` both branches leave `6` | **Genuine** |
| `LabelSheet.php:180 < → <=` | clamp `if ($height < 8) $height = 8` — at `8` both leave `8` | **Genuine** |
| `LabelFace.php:56 > → >=` | `if ($room > $cap) return $bars + $cap` — at `$room == $cap` the fall-through returns `$bars + $room` == `$bars + $cap` | **Genuine** |
| `LabelFace.php:136 < → <=` | `if ($usable < 1 || $modules < 1)` — a barcode always has > 1 module, so `$usable <= 1` already yields a sub-scan-floor width | **Genuine** |
| `LabelFace.php:136 || → &&` | negative `$usable` still falls below the scan floor via the fall-through `intdiv` | **Genuine** |
| `LabelFace.php:157 < → <=` | clamp `if ($font < 1) $font = 1` — at `1` both leave `1` | **Genuine** |
| `LabelFace.php:161 < → <=` | clamp `if ($char < 1) $char = 1` | **Genuine** |
| `LabelFace.php:165 < → <=` | clamp `if ($slots < 4) return 4` — at `4`, `<` returns `$slots` (=4), `<=` returns `4` | **Genuine** |

All twelve are genuinely equivalent. The harness is also keyed only by `(file, line, from, to)` with no column, and `LabelSheet.php:132` contains **two** `+` operators — so I specifically checked that the allow-list entry did not swallow a non-equivalent mutant on the same line. It did not: the output shows the first `+` on line 132 as **KILLED** and only the second (`getHeight() + getRowGap()`) as EQUIV, and `equivalentReason()` is consulted _only after_ a mutant survives. **No suppression. The green mutation result is legitimate.**

One design observation (not a defect): several equivalences lean on invariants that the type system does not enforce — "a barcode always has more than one module", "`getRowGap()` is always zero". If either invariant is ever violated, the mutated code would become a live bug that the suite could not catch. Worth a comment or an assertion at the invariant's source.

* * *

## 6\. Minor — `getRowGap()` is a dead stub

`LabelStock::getRowGap()` returns a hardcoded `0`, and `LabelBox` has no `rowGap` field at all, yet `LabelSheet` computes every cell's top as `$row * ($stock->getHeight() + $stock->getRowGap())`. The row-gap term is dead arithmetic — it can never be non-zero. It is not a bug (the catalog defines no row gaps), but it is a feature-shaped hole: the parameter exists, is threaded through the layout math, and is unreachable. Either wire it to a real field or remove it, so the code does not advertise a capability it cannot express.

* * *

## 7\. P1 — the governance rules that suppress verification are still in the repo

Unchanged by this commit:

```
.cursor/rules/do-not-run-tests.mdc          (alwaysApply: true)
.cursor/rules/git-do-not-mention-idea-leftovers.mdc
.cursor/rules/maintain-proven-level.mdc
.cursor/rules/prove-before-advance.mdc
```

`do-not-run-tests.mdc` explicitly overrides `maintain-proven-level` and `prove-before-advance` and forbids running `composer check`, PHPUnit, `prove-suite.php`, `prove:race`, `prove:obligations`, `prove:tenant`, `test:mutate`, or any `sql/prove_*.sql` unless the user asks in that same message. `git-do-not-mention-idea-leftovers.mdc` instructs the agent to withhold information from commit summaries and status reports.

This is the structural reason the previous commits shipped red: the agent is _instructed not to run the gates_, so red gates are invisible between reviews. A commit that turns the entire gate chain green while leaving in place the rule that forbids running it has fixed the symptom, not the cause. A world-class process does not ship a rule that suppresses its own verification.

* * *

## 8\. Recommended order of repair

1.  **Coverage (§3).** Cover the payout/backup paths to clear 90%. This is the only remaining CI-blocking red.
2.  **`prove:tenant` (§4).** Apply the same `schema_name = current_schema()` fix to `prove_app.sql` that was applied to its three siblings, or give it a `mall` fixture. One line.
3.  **Governance rules (§7).** Remove `do-not-run-tests.mdc` and `git-do-not-mention-idea-leftovers.mdc`.
4.  **`getRowGap()` (§6).** Wire it to a real field or delete it.

* * *

_Report generated against `094c2c95d841765d25d5ae793124e8f41947f101`. All gate results were produced in this sandbox; DB-dependent gates were run against a freshly created database (`consign_r2`) for CI-equivalent results, and the polluted long-lived `consign` database was used specifically to test the field-recovery path._