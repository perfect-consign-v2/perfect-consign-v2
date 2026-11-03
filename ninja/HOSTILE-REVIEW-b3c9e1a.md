# Perfect Consign — end-to-end hostile review

**Reviewed revision:** `b3c9e1a1ce0822f347c943809a5fa9a33520bd23` (`master`, 2026-11-01)  
**Review type:** repository-wide static review, architecture and security boundary review  
**Result:** one confirmed high-severity money-control defect; one confirmed high-impact operational resilience gap; other previously reported headline defects are closed in current code by inspection.

## Executive summary

The application has a notably strong accounting core for a small PHP application: integer minor-unit money, PostgreSQL-owned posting logic, append-only journal tables, tenant-specific database roles, forward migration checksums, explicit role/session abstractions, and several SQL proof scripts. Its main risk is the boundary between that carefully controlled ledger and its browser-facing offline register. The offline sync route currently lets an authenticated register user submit arbitrary item prices and tax amounts, and the database posts those values. Commission is now recomputed in SQL, but price authorization and tax enforcement are not.

The platform administration path can irreversibly drop a tenant schema. It has CSRF, typed-slug confirmation, audit-before-action, and a restricted `SECURITY DEFINER` function, which protects against accidental clicks and ordinary tenant credentials. There is no recovery window or required pre-wipe backup, so compromise or misuse of the single platform admin remains a large availability/data-loss event.

This review did not run tests, provision a database, send mail, or exercise live HTTP/SQL requests. It does not claim current runtime test status. The prior review at `8c913c1` is useful historical execution evidence, but its findings and gate results predate HEAD and were not copied forward as current facts.

## Scope and method

Reviewed the current tree, repository guidance and architecture documents; mapped the PHP application, SQL baseline/migrations, public entry point, CI workflows, test suites and existing hostile-review history; traced authentication/authorization, tenant role switching, offline POS posting, vendor portal isolation, public-origin construction, admin lifecycle and destructive tenant management. Used targeted source and grant searches and inspected the relevant code paths directly.

The application is a PHP 8.5/PostgreSQL 18 server-rendered app. `public/index.php` initializes request policy and dispatch; `src/Bootstrap` provides tenant/auth/role wiring; `src/Feature` contains the web features; `src/Ledger` contains money and journal primitives; `src/Schema` provisions/migrates schemas; `sql/000_platform.sql` and `sql/001_tenant.sql` are frozen baselines, followed by numbered forward migrations. The tree contains roughly 500 PHP files under `src` (about 51k lines), 155 SQL files, and PHPUnit, HTTP, integration, browser and Codeception suites.

The checkout already had user-local modifications and untracked `.idea/`, `ninja/NEW/` and `tmp/` content. Those were left untouched. The `.env` file was not opened. Vendor bundles and generated/minified assets were not treated as application source.

## Findings

### F-01 — Offline register accepts a caller-chosen sale price and tax (High)

**Confidence: confirmed by direct code trace.** An authenticated user with a valid register session can call the snap endpoint with a syntactically valid item ID, any positive price, and any nonnegative tax amount. `SnapController::save()` parses these request fields and passes them to `SnapSync::save()`; the insert persists them as the snap’s `price_minor` and `tax_minor` ([SnapController.php](/C:/laragon/www/perfect-consign/src/Feature/Pos/SnapController.php:25), [SnapSync.php](/C:/laragon/www/perfect-consign/src/Feature/Pos/SnapSync.php:31)). The schema only checks that price is positive and tax is nonnegative ([001_tenant.sql](/C:/laragon/www/perfect-consign/sql/001_tenant.sql:7907)).

`stall_sync_snap()` recalculates commission from the stored item/rule, which closes the older commission-rate manipulation. It does **not** verify that snap price equals an authorized ticket line or an allowed discount/override. It computes expected tax but records the difference as `tax_variance_minor`; it still posts the supplied `snap.tax_minor` into the journal and sale line. The debit total, vendor payable, and sale lines are then derived from the caller-controlled price/tax ([001_tenant.sql](/C:/laragon/www/perfect-consign/sql/001_tenant.sql:7305), [001_tenant.sql](/C:/laragon/www/perfect-consign/sql/001_tenant.sql:7315), [001_tenant.sql](/C:/laragon/www/perfect-consign/sql/001_tenant.sql:7339)).

**Impact:** a cashier or someone with that cashier’s session can tamper with the offline request/local storage and sell an item at an arbitrary positive price, bypassing ticket discount ceilings, signatures, offers and ordinary checkout validation. They can also understate or omit sales tax; the expected/actual variance is recorded but this code path does not block or correct it. This creates direct inventory/revenue/vendor-payable misstatement and tax-liability exposure. CSRF does not mitigate a malicious authenticated cashier controlling their own browser.

**Recommended correction:** persist a server-authorized immutable sale snapshot when the ticket is priced, bind it to session/ticket/item/quantity and the permitted override evidence, and make sync consume that snapshot. Derive tax from authoritative rules and documented exemptions/waivers; reject an unexplained variance instead of booking it. Keep the offline flow idempotent. Add a hostile integration proof that changes price/tax in the posted request and asserts no journal can be posted with those values.

### F-02 — Platform admin can irreversibly destroy tenant data in one operation (High impact, Medium likelihood)

The admin wipe path calls `platform.wipe_tenant(?)` after the admin action and confirmation logic ([AdminTenantWipe.php](/C:/laragon/www/perfect-consign/src/Feature/Admin/AdminTenantWipe.php:22), [AdminTenantWipe.php](/C:/laragon/www/perfect-consign/src/Feature/Admin/AdminTenantWipe.php:63)). Current safeguards include authenticated admin access, CSRF, slug confirmation, audit-before-act and a function granted to `stall_app`. The database function drops tenant resources rather than placing the tenant in a reversible deleted state ([000_platform.sql](/C:/laragon/www/perfect-consign/sql/000_platform.sql:632)). There is no mandatory pre-wipe backup, recovery grace period, or second approver in this flow.

**Impact:** a phished or misused platform-admin account can permanently remove a store’s records, including its accounting history. Audit evidence helps forensics but does not restore data. This is a material single-account availability and retention risk for production.

**Recommended correction:** make deletion a staged operation: require a verified recent backup and successful restore check, soft-delete/quarantine first, and require delayed finalization or a second independent approver. Keep the current explicit confirmation and append-only audit.

## Important controls that are working in current code

- **Public reset/magic-link origin:** `RequestOrigin` prefers `STALL_PUBLIC_ORIGIN`; host fallback is gated to explicitly enabled development/debug environments. The Host-header poisoning finding in the October review is closed by inspection ([RequestOrigin.php](/C:/laragon/www/perfect-consign/src/Shared/Http/RequestOrigin.php:9)).
- **Vendor portal database role:** `VendorPortal` resets role state and assumes `stall_vendor`, whose access is through vendor-filtered security-barrier views. The earlier “portal uses poster” finding is closed in current code ([VendorPortal.php](/C:/laragon/www/perfect-consign/src/Feature/Vendor/VendorPortal.php:89), [001_tenant.sql](/C:/laragon/www/perfect-consign/sql/001_tenant.sql:9892)).
- **Offline snap mutation:** final grants revoke poster update/delete and grant only select/insert; the earlier ability to rewrite/delete posted snap evidence is closed in the baseline ([001_tenant.sql](/C:/laragon/www/perfect-consign/sql/001_tenant.sql:9962)).
- **Admin expiry:** `AdminSession::stale()` enforces an eight-hour absolute and one-hour idle lifetime, and `AdminApp::requireAdmin()` checks it; the earlier “admin never expires” claim is stale ([AdminSession.php](/C:/laragon/www/perfect-consign/src/Feature/Admin/AdminSession.php:69), [AdminApp.php](/C:/laragon/www/perfect-consign/src/Feature/Admin/AdminApp.php:50)).
- **Request edge:** the entry point applies CSP, frame, MIME-sniffing and referrer headers; session cookies are HttpOnly/SameSite and Secure when TLS is trusted. Proxy protocol is trusted only for configured peers ([index.php](/C:/laragon/www/perfect-consign/public/index.php:53)).
- **Tenant and ledger design:** application role switching is explicit; journal and journal-line mutation guards are in the SQL baseline; role grants are narrowed after baseline creation. This materially limits damage from routine web SQL access, subject to the F-01 posting function surface.

## Architecture and assurance notes

The strongest architectural choice is to put accounting invariants in database posting functions/triggers instead of trusting PHP calculations. The offline path is the exception: it accepts an amount snapshot from the browser and is precisely where authoritative server-side policy must be restored. A durable audit log does not compensate for an invalid journal that was accepted as valid.

The project has broad automated coverage machinery and `check.yml` invokes `composer check` on pushes and pull requests. This review did not execute that gate. The latest saved hostile review’s pass/fail counts, coverage percentage, and specific CI failures describe commit `8c913c1`, not current `b3c9e1a`; they should not be used as a current release verdict. The nightly mutation workflow is separate from the required check workflow. Whether branch protection requires the check is repository-host configuration and was not observable from this checkout.

Some controls remain application-level rather than database-enforced: staff role/permission decisions are made in the PHP actor/feature layer, while database access for mall operations is generally the shared `stall_poster` role. That is a reasonable layered design, but a compromised authenticated web process has broader tenant capability than a per-actor database role would. Keep CSRF, route authorization and database constraints independently tested, especially on new POST routes.

## Historical review reconciliation

The October 24 hostile review (`8c913c1`) reported six new findings N31–N36. Current-code inspection shows N32’s commission subfinding fixed, N33 fixed, N34 fixed, N35 fixed, and the expiry half of N36 fixed. N32 remains open in the more consequential forms described as F-01: price authorization and tax enforcement. N36’s shared PHP session between admin “enter as” and tenant work remains a design coupling, but the previous never-expiring-session claim is no longer accurate. The wipe reversibility concern remains (F-02). Historical test execution and its other open findings were not revalidated against HEAD and are intentionally not presented as current findings.

## Review limits

No unit/integration/HTTP/browser/Codeception suite or SQL proof was run; no live database role matrix was queried; no dynamic HTTP attack was executed; no deploy/backup restore was performed; and production infrastructure settings (TLS termination, secret rotation, database role ownership, backup retention, branch protection) were not available. Accordingly F-01 is confirmed by code and SQL-path inspection, not a newly executed exploit; F-02 is confirmed by the destructive call path and schema function, not a restore drill. Those are the highest-value next verification steps.
