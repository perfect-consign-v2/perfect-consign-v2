# Hostile review — `perfect-consign-v2` @ `bb9a395`

**Reviewer:** SuperNinja (adversarial) **Range:** `094c2c9..bb9a395` (2 commits, `652f04f` + `bb9a395`) **Diffstat:** 130 files changed, 5512 insertions(+), 362 deletions(-), 53 new files **Environment:** PHP 8.4.26, PHPUnit 13.3.5, Xdebug 3.5.3, PostgreSQL 18.6 (host port 5436). Composer is not installed; all scripts run as `php bin/...` / `php vendor/bin/phpunit`. **Fresh databases:** `consign_r3` (platform-provisioned this session), `consign_r4` (brand-new, for the `prove:tenant` reproduction). Long-lived `consign` used only for the "has mall" contrast.

* * *

## 1\. Gate results (fresh DB)

| Gate | Result | Notes |
| --- | --- | --- |
| `check:max-lines` (500) | **GREEN** |   
 |
| `check:phpmd` | **GREEN** |   
 |
| `phpstan` (level 10) | **GREEN** | `[OK] No errors` |
| `test` (unit) | **GREEN** | 172 tests, 850 assertions |
| `test:integration` | **GREEN** | 19 tests, 140 assertions (fresh `consign_r3`) |
| `test:http` | **GREEN** | 46 tests, 634 assertions |
| `prove:obligations` | **GREEN** |   
 |
| `prove:race` | **GREEN** |   
 |
| `prove` (suite) | **GREEN** |   
 |
| `prove:tenant` | **RED** | `role "stall_poster" already exists` on a clean DB |
| `test:mutate` | **GREEN** | 811 mutants, 774 killed, 37 equivalent, 0 survived, 0 errors |
| `test:coverage` | **RED** | line coverage 89.87% < 90% |

Nine of twelve gates are green. The two red gates (`prove:tenant`, `test:coverage`) are the two the previous review already flagged, and **neither has actually been fixed** — see §2 and §3.

* * *

## 2\. HIGH — the `prove:tenant` "fix" does not work; the gate is still red on a clean database

`review-094c2c9.md` flagged that `prove:tenant` was red on a clean DB because `sql/prove_app.sql` hard-codes `WHERE slug = 'mall'` (the "missed fifth call site" — the other four proofs were made hermetic with `schema_name = current_schema()`).

Commit `652f04f` added 11 lines to `bin/prove-tenant.php`:

```php
public function run(): void
{
    $this->ensureMall();          // <-- new
    $this->runFile('prove_app.sql');
    ...
}

private function ensureMall(): void
{
    $existing = $this->pdo->prepare('SELECT 1 FROM platform.tenants WHERE slug = ?');
    $existing->execute(['mall']);
    if ($existing->fetchColumn() !== false) {
        return;
    }
    $this->provisioner->provision('mall', 'Mall');   // <-- always throws on a clean DB
}
```

This is a workaround, not a fix, and it is **broken**. `TenantProvisioner::roleName()` maps the `mall` slug to the **shared** `stall_poster` role (`bin/provision-tenant.php:135`):

```php
if ($slug === 'mall') {
    return 'stall_poster';
}
```

`stall_poster` is created during _platform_ provisioning (`sql/008_statement.sql:33`), and `provision()` runs `CREATE ROLE %s` **without** `IF NOT EXISTS` (`bin/provision-tenant.php:47`). So on any database where the platform exists but the `mall` tenant does not, `ensureMall()` throws:

```
SQLSTATE[42710]: Duplicate object: 7 ERROR:  role "stall_poster" already exists
```

**Reproduced three ways:**

| Database | `mall` tenant present? | `prove:tenant` |
| --- | --- | --- |
| `consign` (long-lived) | yes | **exit 0 (green)** |
| `consign_r3` (fresh this session) | no | **exit 1** — `role "stall_poster" already exists` |
| `consign_r4` (brand-new, platform-only) | no | **exit 1** — `role "stall_poster" already exists` |

The proof is therefore still non-hermetic: it is only green when a `mall` tenant already exists — precisely the precondition the fix was supposed to remove. Worse, on a clean DB the "fix" now **mutates the database under test** (it tries to create a tenant as a side effect of running a proof) and fails doing so.

The correct fix is the one already applied to the other four proofs: point `prove_app.sql` at the tenant under test (`current_schema()`), not a magic `mall` slug. Note the same latent defect exists in `bin/seed-mall.php` (it calls `provision('mall')` directly; `wipeTenant` only drops `stall_poster` when a `mall` tenant already exists, so on a clean DB `seed-mall.php` also dies with the same duplicate-role error — reproduced: `SEED_EXIT=255`).

* * *

## 3\. HIGH — the `vendor` role is dead: its landing page and its permitted paths are not routes

The new RBAC layer introduces three roles: `owner`, `cashier`, `vendor`. The `vendor` role is non-functional end to end.

`RoleGate::vendor()` permits exactly four paths (`src/Bootstrap/RoleGate.php`):

```php
private static function vendor(string $rest): bool
{
    return in_array($rest, ['/vendor', '/vendor/labels', '/logout', '/logout/confirm'], true);
}
```

`Actor::path()` sends a signed-in vendor to `/{slug}/vendor` (`src/Bootstrap/Actor.php`):

```php
return match ($this->role()) {
    'cashier' => '/' . $slug . '/pos',
    'vendor'  => '/' . $slug . '/vendor',
    default   => '/' . $slug . '/dashboard',
};
```

But **no route matches `/{slug}/vendor` or `/{slug}/vendor/labels`.** I enumerated all 145 `#[Route]` paths in `src/Feature` and replayed the router's exact matching regex (`vendor/krubio/perfect-router/src/Routing/Router.php`: `'@^' . str_replace('/', '\/', $path) . '$@'`):

```
/mall/vendor        -> []
/mall/vendor/labels -> []
/mall/vendors       -> ['/([a-z][a-z0-9-]{0,62})/vendors']
/mall/pos           -> ['/([a-z][a-z0-9-]{0,62})/pos']
```

`RegisterFeatures::register()` only registers `*Controller.php` files, and no controller defines a `/vendor` route (the only `labels` route is `/{slug}/inventory/labels`, owner-only). So:

-   A vendor who signs in (via `OwnerGate::signIn` → `StaffSignIn::attempt`, which sets `actor_role = 'vendor'`) is redirected to `/{slug}/vendor` → **404**.
-   Even if the vendor navigates manually, the only routes they are permitted are ones that do not exist.
-   `RoleGateTest::testAVendorSeesOnlyTheirOwnPage` asserts `RoleGate::allows('vendor', '/vendors/<uuid>', 'GET')` is **false** — so a vendor cannot even view their own vendor page at the real `/vendors/{uuid}` route.

The vendor role can therefore reach nothing. The defect is invisible to the suite because the only coverage is a **pure-function unit test** (`RoleGateTest`) that never touches the router, and no HTTP test signs in as a cashier or vendor (see §4).

* * *

## 4\. MEDIUM — coverage floor still red (89.87% < 90%), and the new surface is the least covered

`test:coverage` is red: **89.87% < 90%** (up from 89.55% last review, still under the floor). The newest files are the least covered — a direct measure of how little of this push is exercised:

| File | Coverage |
| --- | --- |
| `Feature/Pos/BoothFixController.php` | **0.0%** (0/16) |
| `Feature/Party/VendorSignInController.php` | **0.0%** (0/15) |
| `Feature/Party/VendorMove.php` | 3.6% (1/28) |
| `Feature/Rent/LateFee.php` | 5.9% (1/17) |
| `Feature/Staff/StaffStore.php` | 25.0% (8/32) |

Every HTTP test signs in with `ProbeMall::USERNAME` / `PASSWORD` — the **owner**. There is no end-to-end test that signs in as a cashier or a vendor and drives a request through `MallApp::open($slug, true)` → `Actor::permitted()` → `RoleGate`. That is exactly why the dead vendor role in §3 shipped undetected. An enterprise RBAC layer needs at least one HTTP test per role that signs in and asserts the allow/deny boundary against real routes.

* * *

## 5\. MEDIUM — `Actor::role()` fails open to `owner`

```php
public function role(): string
{
    $role = $this->session->get('actor_role');
    if ($role === 'cashier' || $role === 'vendor' || $role === 'owner') {
        return $role;
    }
    return 'owner';        // fail-open default
}
```

If the session's `actor_role` is missing or holds any unrecognised value, `role()` returns `'owner'`, and `RoleGate::allows('owner', ...)` returns `true` for **every** path. Both sign-in paths currently set `actor_role` (`OwnerGate::signIn` → `'owner'`; `StaffSignIn::openSession` → the staff role, and `attempt()` refuses any role that is not exactly `cashier`/`vendor`), so this is **not directly exploitable today**. But it is a fail-open authorization default: any future code path that sets `owner_slug` without also setting `actor_role` (a session carried across a deploy, a refactor, a partial sign-in) silently grants full owner rights. An authorization default should fail **closed** (deny, or fall back to the least-privileged role), not open.

* * *

## 6\. MEDIUM — governance anti-pattern rules are still committed

Both rules flagged in the previous review remain in the tree:

-   `.cursor/rules/do-not-run-tests.mdc` — instructs the agent _not_ to run `composer check`, PHPUnit, `prove-suite`, `prove:race`, `prove:obligations`, `prove:tenant`, `test:mutate`, or any `sql/prove_*.sql` "unless this message asks for that run", and explicitly overrides `maintain-proven-level` and `prove-before-advance`.
-   `.cursor/rules/git-do-not-mention-idea-leftovers.mdc` — instructs the agent never to mention `.idea` files to the user, in any report.

For a repository whose entire value proposition is proof-backed correctness, a committed rule that discourages running the proofs and a rule that instructs the agent to hide information from the user are both actively counterproductive. They should be removed. (The new `.cursor/rules/update-demo-on-push.mdc` is fine — it correctly keeps credentials in the gitignored `.cursor/demo-server.local`.)

* * *

## 7\. LOW — `MonthStore::figures()` mixes a stock balance into a month-scoped page

`src/Feature/Month/MonthStore.php` computes four tiles. `sales`, `tax`, and `checks` are month-windowed flows (they filter `posting_date BETWEEN ? AND ?` and exclude reversals). The `rent` tile is an **all-time** receivable balance:

```php
'rent' => $this->sum(
    "SELECT COALESCE(sum(line.debit_minor - line.credit_minor), 0) AS total
     FROM journal_lines AS line
     JOIN chart_roles AS role ON role.account_id = line.account_id
     WHERE role.role = 'booth_rent_receivable'",
    []
),
```

No date filter, no reversal exclusion. The label ("Rent still owed") makes the balance reading defensible, but the page header is the month name and three of four tiles are month flows, so a reader can reasonably misread the fourth as the month's rent. Either scope it to the month or move it off the month page.

* * *

## 8\. Audited and acceptable (with a caveat)

-   **New mutation allow-list entries (`bin/mutate.php`, +9 lines).** Three new `equivalentReason()` entries, all for `src/Feature/Backup/BackupWorkspace.php`: line 21 `!`→removed, line 21 `&&`→`||`, and lines 48/49/50 `&&`→`||`. I truth-tabled each against the harness output. Line 21 has three `!` and two `&&`; the harness kills the non-equivalent siblings (`KILLED ...:21 ! -> (removed)` and `KILLED ...:21 && -> ||`) and marks only the genuinely equivalent ones `EQUIV`. The `48/49/50` entries hold because `addFile` is only reached for a path already checked by `is_file()` (line 40). **All three are genuinely equivalent.** Caveat: they rely on "end-to-end equivalent" reasoning (the failure moves from `make()` to `write()`), which is a defensible but softer standard than local equivalence — worth watching as more of these accumulate.
-   **New SQL `050`–`053`.** `050_staff.sql` (CHECK constraints for username/display name/password length/role/vendor pairing, unique lower(username) index, `GRANT`/`REVOKE`, `ENABLE ALWAYS` TRUNCATE trigger), `051_customers.sql` (CHECK constraints, TRUNCATE trigger), `052_late_fee.sql` and `053_booth_correction.sql` (idempotent `WHERE NOT EXISTS` seeds). All sound and consistent with the existing ledger conventions.
-   **New proofs are hermetic.** `prove_booth_correction.sql`, `prove_gift_card.sql`, `prove_late_fee.sql`, `prove_store_credit.sql` contain no `mall` dependency (only `prove_app.sql`, `prove_chart.sql`, `prove_mall.sql` reference `mall`, and the suite is green).
-   **RBAC path handling has no normalization bypass.** The router (`Router::dispatch`) and `RoleGate` both consume the identical `$request->path` string; `requestFromGlobals()` only runs `parse_url(..., PHP_URL_PATH)` and `rtrim(..., '/')`, and the router does no percent-decoding. So `RoleGate`'s string/regex matching sees exactly what the router matched — there is no encoded/dot-segment mismatch to exploit.
-   **Cross-tenant access is blocked.** `OwnerAccess::isForeign()` returns true when a session slug differs from the URL slug, yielding `MISS_HIDDEN`.
-   **`getRowGap()` is not a dead stub** — it is used at `src/Feature/Inventory/LabelSheet.php:132`. The prior "dead stub" note is resolved.
-   **`.cursor/demo-server.local` is gitignored** (`.gitignore:2`).

* * *

## 9\. Informational

-   Cursor committed the reviewer's own reports into the product tree: `ninja/review-094c2c9.md`, `ninja/review-c82c422.md`, `ninja/review-cf58c51.md`, plus a `ninja/OLD/` directory. Not a defect, but the adversarial review docs now ship with the app.
-   Commit `bb9a395` ("Include the migrator and schema files in the demo deploy so tenant-all can run on the server") widens the demo deploy to ship `bin/` + `sql/`. That is reasonable for the stated goal, but it means the demo host now carries the full migrator and every proof/migration script.

* * *

## 10\. Bottom line

The push is large (130 files) and mostly green: static analysis, unit, integration, HTTP, the proof suite, obligations, race, and mutation all pass. The ledger-side features (late fee, gift/store credit, booth correction) are proof-backed and the new SQL is clean.

But two of the three headline claims are not actually delivered:

1.  **`prove:tenant` was not fixed.** It is still red on a clean database, now failing inside the new `ensureMall()` with `role "stall_poster" already exists`. The proof remains non-hermetic and only passes when a `mall` tenant pre-exists.
2.  **The new `vendor` role is dead.** Its landing path and its only permitted paths are not routes, so a vendor can reach nothing — and the coverage floor is still red precisely because no test drives the RBAC layer end to end.

Add the fail-open `role()` default, the two governance rules that discourage verification, and the still-red coverage floor, and the honest summary is: **green gates, two shipped features that do not work, and a security default that fails open.** None of these are acceptable at an enterprise bar.