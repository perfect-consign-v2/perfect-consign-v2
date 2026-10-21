# Perfect Consign v2 — Completion Plan to a World-Class Vendor Mall & Consignment POS

**Repository reviewed:** `perfect-consign-v2/perfect-consign-v2` at `bcc55ba` (master, 156 commits) **Live demo reviewed:** [https://v2.perfectconsign.com/mall](https://v2.perfectconsign.com/mall) **Prepared:** October 2026

* * *

## 1\. Executive summary

Perfect Consign v2 is not a prototype. It is roughly 43,000 lines of PHP 8.5, 31,000 lines of PostgreSQL 18 schema and PL/pgSQL, and 29,000 lines of tests, built around a conviction that almost no competitor shares: that the money must be owned by an append-only, double-entry ledger that the application cannot corrupt, and that every business rule about a sale, a rent charge, a payout, or a return must be refused by the database itself rather than merely validated by the screen. That conviction is already realised. Journals and journal lines are append-only under `ENABLE ALWAYS` triggers, event-trigger guards stop even the table owner from disabling them, balances are the sum of lines rather than a stored column, every mistake is a dated reversal, every post carries a unique posting key, and the repository ships race proofs that run two sessions against each other to demonstrate that none of this can be defeated by concurrency. The quality bar enforced in CI is similarly unusual for a product of this size: PHPStan level 10, PHPMD at cyclomatic complexity 10, a 500-line file cap, an obligation scanner that ties each schema change to a proof, and a nightly mutation run. This is the single strongest accounting foundation in the consignment software market, and it is the asset this plan is built to protect.

What the product lacks is the layer that store owners compare when they shop. Card payments are a hand-keyed tender rather than an integrated terminal. Vendors are paid by printed check or cash, not ACH. There is no e-commerce channel, no public API, no webhooks, no background job runner, no object storage, no multi-location model, no custom roles, no audit trail for non-ledger changes, no report builder, and no mobile or offline story. Every action at the register is a full page reload. The commission model is a single percentage, and tax is a single rate. These are not small gaps; they are precisely the features SimpleConsign, ConsignCloud, Ricochet, and Quail advertise on their front pages, and their absence is why a store owner would not yet switch.

This plan takes the product from where it is to a system that an owner would leave a competitor for, in seven phases over approximately twelve months with a team of five to six. It keeps the PHP-and-Postgres core, keeps the ledger as the sole owner of money, and extends outward without a single shortcut. Each phase has exit criteria that are verifiable by running a command, not by reading a claim. The plan opens with a stabilisation phase because the current `master` carries open findings from prior hostile reviews that would be found again by any auditor, and no amount of new feature work should be stacked onto a foundation that has a side-effecting GET request in it.

* * *

## 2\. What exists today — a faithful inventory

The application is a single PHP front controller at `public/index.php` that builds a PSR-11 container, registers every `*Controller.php` under `src/Feature/` via attribute routes, and dispatches. Tenancy is one PostgreSQL schema per mall, selected by the first URL segment (`/{slug}/...`), with a `platform` schema that holds tenants, owners, sign-in failure counters, backups, and migration stamps. Four database roles enforce a privilege boundary: `stall` owns the tables, `stall_poster` may only insert journals and lines, `stall_app` is the web login, and `stall_backup` may only read. The test harness provisions a `webprobe` mall and drives the site over HTTP on port 8097 and in headless Chrome.

The feature surface is wider than the demo's modest navigation suggests. There are roughly 180 routes. Vendors (called "parties" in code) have standing, contact details, commission overrides, agreement dates, tax-exempt numbers, and booth assignments with start and end dates; vendors can be moved between booths and placed on a wait list; a floor map editor supports multiple floors on a one-foot grid. Items carry a code, a barcode (auto-generated when blank), a vendor, a brand, a description, a price in minor units, a quantity, intake and expiry dates, a commission override, five attribute lists (color, category, size, condition, department), one photo stored as `bytea`, and a placement of booth, consignment, or store stock. Items can be bought by the store, returned, donated, and marked down on a schedule. A CSV import loads up to 500 tagged items at once, and stickers print on five stock formats through the browser's print dialog. The register supports multiple registers and shifts with bill-and-coin opening counts, cash drops to the safe, close with over/short, and a Z-tape. The point of sale handles scan and search, instant add, line and ticket discounts, tax-free tickets with a reason, a buyer who is a vendor drawing against their own payable, a customer on the ticket, park and recall, layaway with deposits and refunds, tenders of cash, card, check, gift certificate, gift card, store credit, and vendor draw, nickel cash rounding, receipt view, print, and email, void of a whole sale, and return of a single line. Rent is charged per booth on a configurable cycle day with proration on move-in, collected at the register, assessed late fees, waived, and receipted. Payout runs settle payable against rent with no cash movement, then disburse by printed check with check-number control, by drawer cash, or from the safe, and can be reversed. The books side offers adjustments, sales-tax payments, an accounts view, gift certificate balances, a journal browser with void, opening balances, year-end close, and a QuickBooks Online and Xero CSV export. There is a vendor portal with a check view, sales, inventory, label printing, and deals. The owner can download a `pg_dump` backup. All of this is covered by 647 unit tests, 76 HTTP tests, 29 integration tests, and 9 browser tests.

The architecture has a clear and consistent shape that any new engineer can learn in a day: a controller gathers input, a store or service class builds a `JournalDraft`, `LedgerStore` posts it, and the PL/pgSQL assertion for that `source_type` (for example `stall_assert_sale_shape`, now 509 lines in `sql/067_item_placement.sql`) refuses anything that does not balance or does not match the item, vendor, tax, and commission facts at that moment. The PHP layer never calculates a balance; it reads `account_balances` or a purpose-built view.

* * *

## 3\. What is wrong today — defects that must be fixed before anything is added

The repository has a tradition of hostile self-review, recorded under `ninja/`, and the latest review of `18bc68c` tabulated twelve gates with eight of them red. Twenty-seven commits later, several items were fixed: `064_sign_in_address.sql` is now idempotent, the `item_photos` foreign key is now dropped in the truncate proofs, `prove:tenant` is now in `composer check`, and the SQLite dependency in unit tests is gone. The following remain open at `bcc55ba` and are verified in this review.

**A read request writes to the ledger.** `src/Feature/Rent/RentController.php:211` calls `$this->lateDue->apply($today)` inside the render path of `GET /rent`. The late-fee posting key (`late-fee:{booth}:{period}`) makes it idempotent so it cannot double-post, but a GET with a side effect violates the HTTP contract, means a crawler or a back-button can post journals, and performs an unbounded scan of all booth-rent lines on every page view. This must become a scheduled job (see §6.2) with an explicit "Assess late fees now" action for the owner.

**The probe harness hides the signal it was built to raise.** `tests/Support/ProbeMall.php:69` catches `MigrationFileChanged` alongside `TenantBaselineMismatch` and silently wipes and re-provisions. `MigrationFileChanged` exists precisely to detect an already-applied migration whose bytes changed. Swallowing it in the test harness means the one place that would catch an in-place edit no longer does. The catch must be narrowed and the integration suite must fail loudly on a checksum mismatch.

**Applied migrations have been edited in place more than once.** Commits `cb2db5a` (Pieces → Items rename across eight files) and `0ddc95a` (a financial predicate removed from `stall_assert_settlement()` in `041_payout_run.sql`) both changed files that tenants had already stamped. The project's own convention (commit `8c130d0`, "Leave the applied payout migration unchanged") was not followed. Because `MigrationRecord::verify()` hashes raw bytes, any tenant that stamped the old bytes will throw on its next migration. The demo tenant survives only because it is wiped and re-seeded. This must be made structurally impossible: a CI step that diffs `sql/0*.sql` against the merge base and fails if any file older than the newest stamped baseline has changed.

**Agent rules ship in the product repository.** `.cursor/rules/do-not-run-tests.mdc` instructs an AI assistant not to run tests or proofs. Whatever its purpose in a solo workflow, a public repository that tells tooling to skip verification is a finding any auditor will raise. The rules should move to a personal, untracked location, and `ninja/` review artefacts should move to `docs/reviews/`.

**The micro-framework is a bus-factor risk.** `composer.json` depends on `krubio/perfect-router ^0.2.0`, `perfect-session ^0.1.2`, `perfect-logger ^0.1.0`, `perfect-database ^0.1.0`, and `perfect-container-psr-11 ^2.0`. These are pre-1.0 packages by a single author with no visible test coverage of their own in this repository. An enterprise buyer's due diligence will flag this. The plan does not recommend a rewrite; it recommends bringing these packages into the monorepo under `packages/` with their own test suites and semantic versioning, or replacing them one at a time with the equivalent Symfony components behind the same interfaces. The decision is recorded as ADR-2 in §9.

**Large PL/pgSQL functions are redefined wholesale across migrations.** `stall_assert_sale_shape()` appears in full in `032_tickets.sql` (359 lines), `056_quantity_offers.sql` (400 lines), and `067_item_placement.sql` (509 lines). Each is a complete replacement. Reviewing the change between versions means diffing two 400-line bodies by hand, and there is no single canonical copy. The forward-only migration rule is right and must stay; what is missing is a `sql/functions/` directory holding the current body of every function, with a CI check asserting that the body in the most recent migration that touched a function is byte-identical to the canonical file. That gives reviewers a real diff and keeps the migration history honest.

**Performance hygiene items** recorded as E1 to E4 in `ninja/unfinished-changes.md` remain open: `VendorAccount` sorts and aggregates in PHP without bounds or supporting indexes, `VendorStickers::printOwn()` opens the tenant connection twice, and `ListTable::assetVersion()` stats the asset file on every render. There are 27 indexes across the whole schema, which is adequate for a demo and inadequate for a mall with 300 vendors and 200,000 items. `account_balances` is an unmaterialised aggregate over all journal lines.

**Tests assert on prose.** Several HTTP and layout tests assert on strings such as `'This month'` and `'does not post'`. A relabel breaks them without any behavioural change. Tests should assert on stable `data-testid` hooks.

**Documentation is internal-facing.** `README.md` still references `C:\laragon\www\stall`, the internal code name "Stall", and ports on a developer's machine. There is no customer-facing documentation, no API documentation, and no operations runbook.

None of these is a feature. All of them are what a competitor's engineer, or an acquirer's auditor, or another AI reviewer would list first. They are Phase 0.

* * *

## 4\. The competitive bar

The market was checked live in October 2026. SimpleConsign (Traxia) serves over 3,100 shops at $159 to $379 per month, ships SimpleACH consignor payouts, Shopify sync, AI item entry from photos, a QuickBooks Online integration that syncs ACH payouts per location, a merchant surcharge program, and a managed onboarding team; its 2026 roadmap adds a price pole, cash rounding, a merge tool, and consignor archiving. Its most frequent complaint on Capterra, repeated in reviews from October 2025 through February 2026, is that reporting is weak and custom reports are hard. ConsignCloud at $139 to $189 per location offers Stripe or Gravity integrated processing or non-integrated processing at no penalty, Shopify and Square integrations on every plan, Checkbook ACH at $1.79 per transfer, automated email notifications, a browser consignor portal with item submission, fully custom labels, bulk edit, auto-discount schedules, CSV export everywhere, and, uniquely, a public API with webhooks on its Pro plan. Ricochet at $199 per location now charges $100 per month to stores that decline Ricochet Pay, which ConsignCloud has turned into a marketing campaign. Quail at $40 to $135 by vendor count is processor-agnostic, ships iOS and Android vendor apps with per-sale push notifications, sends every vendor an automatic weekly email summary, offers fine-grained clerk permissions, built-in email marketing, and hourly backups. Liberty REACT remains a legacy desktop lineage with strong label printing. A new entrant, Vintique, is already shipping AI photo research and dealer scheduling at $29 to $169.

The table stakes a store owner will check, in order, are: integrated card processing without a lock-in fee; ACH payouts to vendors; a vendor portal with live sales and notifications; flexible, automatic rent; layaway and gift certificates; tax-exempt customers; label printing to real label printers; bulk inventory editing and automatic markdowns; CSV export on every list; date-range reports by vendor, category, department, and cashier; a QuickBooks sync; fine-grained staff permissions; multi-location; a Shopify channel; continuous backups; human support; and a painless import from the system they are leaving. Perfect Consign has roughly half of these and none of the integrations.

The differentiators Perfect Consign can own, because no competitor has the foundation for them, are: a provably correct ledger that lets the product say "your books cannot be wrong, and here is the proof suite that shows it"; best-in-class reporting built on that ledger, which attacks the market leader's most-cited weakness; processor-agnostic payments with no penalty; a vendor experience with per-sale notifications and weekly digests delivered in the browser as a PWA rather than requiring an app download; a register that is fast because it talks to an API rather than reloading the page, and that keeps working when the store's internet drops; and an open API with webhooks and competitor importers that make switching toward Perfect Consign easier than switching away from it.

* * *

## 5\. Guiding principles for every change in this plan

The README states the project's law in one sentence: "No hacks, no shortcuts, no workarounds, and no patches. Fix the design that is wrong." This plan adopts that law and adds five operating rules that follow from it.

Money is owned by the ledger. Every new financial event — a card capture, an ACH transfer, a Shopify order, a loyalty redemption, a tiered commission — is a journal with a source type, a posting key, and a PL/pgSQL assertion that refuses the wrong shape. No PHP class, no job, no integration ever stores a balance or recalculates one. A reconciliation job compares every derived figure to the ledger and alerts on a one-cent difference.

Schema changes are forward-only, idempotent where they touch platform tables, and byte-frozen once stamped. A CI check enforces the freeze. Function bodies live in `sql/functions/` and migrations carry the full body with a CI equality check.

Every feature ships with its proof. The definition of done for any work item in this plan is: a `sql/prove_*.sql` for each new or changed database rule; unit, integration, and HTTP tests; a browser test for any new screen; a mutation score at or above the project floor; a sentence in `docs/business-rules.md`; a page in the customer documentation; an accessibility pass with axe; a performance budget assertion; a security review note; a feature flag so it can be enabled per tenant; and a rollback plan. A pull request that lacks any of these is not done.

Reads do not write. GET requests never post journals or change state. Scheduled work runs in the job runner. Owner-initiated actions are POSTs with CSRF.

Nothing is re-implemented in a second language or second place. The product stays PHP 8.5 and PostgreSQL 18. The POS client introduced in Phase 3 is a thin TypeScript client over the JSON API; it holds no business rules, only an outbox of posting intents.

* * *

## 6\. The phased roadmap

The phases below overlap where their dependencies allow. Durations assume the team in §10. Each phase ends with a demonstrable exit gate.

### Phase 0 — Stabilise and harden (weeks 1–4)

The purpose of this phase is to make `composer check` honest and green, remove the findings an auditor would raise on day one, and lay the operational foundations every later phase needs.

Fix the open review findings. Move late-fee assessment out of `RentController::render()` into a job (`AssessLateFees`) scheduled daily at the mall's local midnight plus an owner POST action; the GET becomes read-only. Narrow `ProbeMall`'s catch to `TenantBaselineMismatch` only. Delete `.cursor/rules/` from the repository and add it to `.gitignore`; move `ninja/*.md` to `docs/reviews/`. Fix E1 through E4. Replace prose assertions in tests with `data-testid` hooks.

Freeze migrations structurally. Add `bin/check-migration-freeze.php` to `composer check` and CI: it lists `sql/0*.sql` files changed versus the merge base and fails if any changed file is older than the highest-numbered file at the merge base. Introduce `sql/functions/` with the canonical body of every PL/pgSQL function, and `bin/check-function-canon.php` asserting the latest migration body equals the canonical file. Ship the `041` correction that was made in place as a new forward migration (`069_settlement_open_payable.sql`) re-creating `stall_assert_settlement()`, so tenants stamped on the old bytes can migrate.

Add the index coverage the schema needs. Run `EXPLAIN (ANALYZE, BUFFERS)` against the seeded `mall` tenant for every list page and every assertion function's queries; add indexes in a new migration for every sequential scan on `journal_lines`, `items`, `tickets`, `ticket_lines`, and `sale_lines` that exceeds a 5 ms budget. Add a `pg_stat_statements`\-based performance test to the nightly run that fails if any query's mean exceeds its recorded budget by more than 50 %.

Introduce the job runner. Add a `platform.jobs` table (id, tenant, kind, payload, run\_at, attempts, locked\_by, locked\_at, finished\_at, error) and `bin/work.php`, a long-running worker that claims rows with `FOR UPDATE SKIP LOCKED`, sets the tenant `search_path`, and runs the handler in a transaction. Jobs are idempotent by construction because every money-moving job posts under a deterministic posting key. This runner is deliberately Postgres-backed so the system has one durable store and the proofs can cover it. Late fees, rent charges on the cycle day, markdown steps, expiry notices, and outbound mail all move here across this and the next phase.

Containerise and observe. Add a multi-stage `Dockerfile` for the app (PHP-FPM + Caddy), a `docker-compose.yml` that brings up app, worker, and Postgres for local development, and `/healthz` (process up) and `/readyz` (database reachable, migrations current) endpoints. Replace file logging with structured JSON to stdout, add OpenTelemetry tracing with the tenant slug and request id on every span, and send errors to Sentry. Stand up a staging environment that deploys from `master` and promote to production by tag.

Rewrite the README for a new engineer on any operating system, and add `docs/ops/runbook.md` covering provisioning, migration, backup, restore, incident response, and rotation of every credential.

_Exit gate:_ `composer check` exits 0 on a clean clone with no skipped step; nightly coverage ≥ 90 % and mutation score at or above the prior floor; `bin/check-migration-freeze.php` and `bin/check-function-canon.php` are in CI; no GET route writes; the demo deploys from the container image; `/readyz` is probed by the deploy script; Sentry receives a test event from staging.

### Phase 1 — Platform foundation (weeks 5–12)

This phase builds the structures every customer-facing feature will depend on: permissions, audit, locations, the API, object storage, and a real mail pipeline.

Roles and permissions. Replace the four hard-coded roles in `src/Bootstrap/RoleGate.php` with a permission model: `roles` (per tenant, named), `role_permissions` (role × permission key), and `staff.role_id`. Permission keys are fine-grained verbs — `sale.post`, `sale.void`, `sale.discount.line`, `sale.discount.ticket_over_pct:10`, `sale.tax_free`, `item.create`, `item.edit_price_after_sale`, `vendor.edit`, `payout.close`, `payout.disburse`, `rent.waive`, `register.open`, `register.close`, `report.view.sales`, `settings.edit`, and so on. The four existing roles become seeded templates (Owner, Cashier, Vendor, Books) so no current tenant changes behaviour. Add manager override: a cashier lacking a permission can request it in-line and a manager approves with a PIN; the approval is recorded. Permissions are checked in one choke point (`OwnerGate`) by permission key, never by path.

Audit log. Add an append-only `audit_events` table (actor, role, action, entity type, entity id, before, after as JSONB, request id, client address, at) with the same `ENABLE ALWAYS` guards as the ledger, and a `prove_audit_events.sql`. Every non-ledger write — vendor edits, item edits, settings, staff changes, role changes, overrides — writes one row inside the same transaction. The owner gets an "Activity" screen with filters and export.

Locations. Introduce `locations` (per tenant: name, address, time zone, tax defaults) and add `location_id` to `registers`, `booths`, `floors`, `items`, `tickets`, and `journals`. The ledger stays one book per tenant — one legal entity, one set of books — and location is a dimension on journals and lines, which is how accountants model it (QuickBooks "class" or "location"). Every existing tenant gets one location created from its `mall` row by migration. Reports gain a location filter; payouts can be run per location or consolidated. Vendors can hold booths at more than one location.

Public API v1 and webhooks. Add `/api/v1/` with bearer tokens (per tenant, scoped by permission keys, hashed at rest, revocable, last-used tracked) and an OpenAPI 3.1 document generated from attributes and published at `/api/docs`. Resources in v1: vendors, booths, assignments, items, item photos, customers, sales (read), payouts (read), rent (read), reports (read), and a `POST /api/v1/sales` that accepts a complete ticket and returns the posted journal or the refusal. Add `webhook_endpoints` and `webhook_deliveries` with HMAC signatures, retries with exponential backoff from the job runner, and events for `item.created`, `item.sold`, `item.returned`, `sale.posted`, `sale.voided`, `payout.closed`, `payout.disbursed`, `rent.charged`, `rent.paid`, `vendor.created`. Rate-limit by token.

Object storage. Add `photos` as a first-class table (owner type, owner id, position, object key, sha256, bytes, width, height, content type) backed by S3-compatible storage (AWS S3 or MinIO locally) with signed URLs and server-side thumbnail generation in a job. Migrate existing `item_photos.bytea` rows with a one-time job and drop the column in a later migration once every tenant reports zero rows. Items may have many photos; vendors, booths, and the mall may have a photo.

Mail. Replace the raw `SmtpClient` with a provider adapter (Postmark or SES) behind the existing `OutboundMail` interface, add HTML and text templates with a shared layout, a `mail_messages` table recording every send with provider id and status, and bounce and complaint webhooks that mark addresses undeliverable. Receipts, password resets, vendor notices, and reports all use this pipeline from the job runner.

Two-factor authentication. Add TOTP enrolment and WebAuthn passkeys for owner and staff accounts, recovery codes, and a per-tenant setting to require 2FA for roles with money permissions. Add `Content-Security-Policy`, `Strict-Transport-Security`, `X-Frame-Options`, `Referrer-Policy`, and `Permissions-Policy` headers, and rate limiting on sign-in, password reset, and the API.

_Exit gate:_ a new tenant provisioned by `bin/provision-tenant.php` has one location, four template roles, and an empty audit log; every write route records an audit row (asserted by an HTTP test that walks the route table); a Cashier template still passes the entire existing HTTP suite; the OpenAPI document validates and a contract test exercises every endpoint; photos are served from object storage on the demo; a bounce webhook marks an address; TOTP is required on the demo owner.

### Phase 2 — Money (weeks 9–20)

This phase delivers the integrations and models that make the product commercially viable: integrated card payments, ACH payouts, flexible commissions, flexible tax, customer store credit, statements, and live accounting sync. Every item here is a ledger change first and a screen second.

Integrated card payments. Adopt Stripe Terminal as the first processor because it is server-driven, supports BBPOS WisePOS E and Stripe Reader S700 with no card data touching the server (PCI SAQ-A), and has a mature idempotency model; keep the existing manual "Card" tender as the processor-agnostic path at no surcharge, and add Square Terminal API as a second processor in Phase 6. Model it as a `card_payments` table (ticket id, provider, provider intent id, amount, currency, status, last4, brand, authorised at, captured at, failure code) and a state machine: the register creates a payment intent with an idempotency key equal to the ticket id plus tender sequence; the terminal collects; the server confirms; only on `succeeded` does the server post the sale journal with the card tender; if the journal is refused after a successful capture (for example an item was sold on another register in the same second), the server immediately refunds the intent and records both in the audit log. Refunds and voids reverse the journal and refund the intent in one transaction-plus-compensation unit. Add Stripe webhooks to reconcile any intent whose final state the register did not observe. Surcharging and cash discounting are a tenant setting that adds a `card_surcharge` journal line under its own role, legal in jurisdictions where Stripe allows it. Tips are a line that credits a `tips_payable` control account per cashier.

ACH payouts. Add `vendor_payout_methods` (vendor, kind: check | cash | ach | paypal | venmo | store\_credit, provider, provider account id, verified at) and `payout_transfers` (payout run, vendor, method, amount, provider transfer id, status, initiated at, settled at, failed at, failure code). Use Stripe Connect Express accounts for ACH (onboarding by hosted link from the vendor portal; the mall never sees bank details) with Checkbook.io as a fallback adapter for malls that do not want Stripe. Disbursement posts an `ach_disbursement` journal when the transfer is created, mirroring the existing `check_disbursement`; a failed transfer posts a reversal and returns the amount to the vendor's payable. The existing `PayoutDisburser` grows one adapter; the settlement logic in `stall_assert_settlement()` is untouched. Fees are charged to the mall's expense account or to the vendor as a tenant setting.

Commission plans. Replace the single percentage with `commission_plans` (name, kind: percent | tiered\_by\_price | flat\_per\_item | percent\_plus\_flat, minimum, maximum) and `commission_plan_tiers` (plan, from price, to price, percent, flat). The mall default, a vendor override, and an item override each reference a plan rather than a bps value; existing values migrate to single-tier percent plans so no sale changes amount. Add "vendor pays card fee" as a plan attribute that moves a share of the card processing cost to the vendor's payable. The commission expected by `stall_assert_sale_shape()` is computed by a new `stall_commission_for(item, price, plan)` SQL function and the assertion refuses any line whose commission does not match it. This is the one place the shape function must grow, and the change ships as a new migration with the canonical body updated.

Tax. Replace the single `mall.tax_bps` with `tax_rates` (name, bps, jurisdiction, active) and `tax_rules` (location, department or category or item, rate, tax-inclusive flag). A ticket's tax is the sum over lines of the rule that most specifically matches, computed in SQL by `stall_tax_for(location, item, price)`, and the shape assertion refuses a mismatch. Customers may be marked tax-exempt with a certificate number and expiry, and the exemption applies automatically at the register (closing a gap Quail advertises). Add an Avalara AvaTax adapter behind the same function for malls that need multi-jurisdiction compliance; it is optional and the default stays local.

Customer store credit. Store credit today is a stored-value number; add a `customer_credits` ledger subledger so a customer's credit is a control-account balance per customer, issued by return or by purchase and redeemed at the register, visible in the customer record with history. Loyalty follows in Phase 4 on this same subledger.

Statements and 1099. Generate a vendor statement PDF for any period from the settlement and disbursement journals (never from a stored figure) using a server-side HTML-to-PDF renderer in a job; email it automatically after each payout run if the vendor opts in; keep every rendered statement in object storage with the hash of the journals it summarises. Produce a 1099-NEC export (CSV in the IRS FIRE layout and a PDF per vendor) from calendar-year disbursements; expose the W-9 fields on the vendor record.

QuickBooks Online and Xero live sync. Keep the CSV export and add OAuth connections that push each posted journal as a journal entry (or sales receipt where the mapping is one-to-one) with the posting key in the memo, map accounts through the existing `export_accounts`, and reconcile nightly by pulling the period's entries and asserting equality. A sync failure is a visible alert, never a silent skip.

_Exit gate:_ a sale paid on a Stripe test terminal posts a journal whose tender equals the captured amount, and a forced post-capture refusal results in an automatic refund (integration test with Stripe test clocks); a payout run disburses one vendor by ACH on Stripe test mode and a failed transfer reverses; a tiered plan, a flat plan, and a percent-plus-flat plan each have a `prove_commission_plans.sql` that enumerates boundary prices; two tax rules on one ticket produce the right lines and `prove_tax_rules.sql` covers inclusive and exempt cases; a statement PDF reproduces exactly the figures of `VendorCheck`; QuickBooks test company receives a journal and nightly reconciliation passes.

### Phase 3 — The register, rebuilt as a client (weeks 13–26)

This phase addresses the biggest everyday friction: every action at the register is a full page round-trip. The fix is not a JavaScript sprinkle; it is a proper client with a proper contract.

The POS client. Build `apps/pos/` as a TypeScript progressive web app (Preact or Lit; no heavy framework) that talks only to `/api/v1/`. It renders the ticket, the search, the tender screen, the receipt, and the register functions, and it holds exactly one piece of state the server does not: an outbox of posting intents. Every money action — add line, change price, apply discount, tender, pay — is an API call that returns the server's authoritative ticket; nothing is computed in the browser except display formatting. The back office (vendors, items, rent, payouts, books, settings) stays server-rendered PHP, which is the right tool for forms and lists and is already well tested.

Offline operation. Because every post carries a client-generated UUIDv7 posting key and the ledger refuses duplicates, the register can queue sales while the network is down and replay them in order when it returns. The client caches the item catalogue (codes, barcodes, prices, vendors, tax rules, commission plans) in IndexedDB with a version stamp; offline, it builds tickets from the cache, takes cash and manual card tenders, prints receipts, and stores the completed ticket in the outbox. On reconnect, it replays; a refusal (for example the item sold elsewhere while offline) surfaces to the cashier with the refused ticket and a guided resolution. Card payments through a terminal require connectivity by nature and the client says so.

Hardware. Receipt printing moves from the browser print dialog to ESC/POS over Web Serial or WebUSB where the browser supports it, with a small cross-platform print agent (Go or Node, signed, auto-updating) for environments that do not; the agent also fires the cash-drawer kick and drives the customer-facing display. Label printing gains ZPL (Zebra) and Dymo drivers through the same agent, and label templates become fully editable (fields, fonts, barcode symbology, sizes) with live preview — matching ConsignCloud and removing the last reason to print stickers from a browser dialog. Barcode scanners work as keyboard wedges today and gain a camera-scan fallback on tablets. A hardware compatibility list is published.

Register ergonomics. Keyboard-first operation with discoverable shortcuts; a tablet layout with large touch targets; a price pole mode for a second screen; manager override flows from Phase 1 surfaced in-line; quick keys for departments and common prices; mixed barcode and hand-tag workflows for malls whose vendors do not print tags; and a "find by vendor and price" path for the antique-mall case of a smeared handwritten tag. Returns and exchanges handle multiple lines and cross-ticket exchanges. The cash-rounding model already in the ledger (`033_cash_round.sql`) is generalised from the nickel to a configurable denomination, which makes Perfect Consign ready for the end of the US penny before the market leader ships it.

_Exit gate:_ the POS client passes a Playwright suite covering every flow the HTTP suite covers today; a sale is completed with the network disabled and replays correctly; a duplicate replay is refused and surfaced; p95 for add-line is under 150 ms on the demo; receipt, drawer, and ZPL label print on at least two devices each from the compatibility list; axe reports zero serious violations; the server-rendered POS routes are removed once the client is default for all tenants.

### Phase 4 — Vendor and customer experience (weeks 20–32)

Vendors are the mall's supply side and the reason they stay. Customers are its demand side and the reason it grows.

The vendor portal becomes a PWA with install prompt, so it works on any phone without an app store, and gains: a live sales feed with per-sale push notifications (Web Push with VAPID) and optional SMS (Twilio) and email; the automatic weekly digest Quail popularised, sent by a job each Sunday evening in the mall's time zone; rent reminders before the cycle day and a "pay rent online" button that takes a card or ACH and posts a `rent_payment` journal; an item-submission flow in which the vendor enters items with photos and the mall approves or edits before they go live, with a configurable "vendors may create items directly" setting for malls that trust their dealers; booth-level sales history and sell-through; downloadable statements and 1099s; a profile where the vendor manages contact details, payout method, and notification preferences; and a contract flow in which the mall uploads its agreement PDF, the vendor signs on screen, and the signature event, IP, and document hash are stored. A "preview as vendor" mode already exists for owners; it stays.

Customers gain a real record: purchase history across tickets, store credit from Phase 2, tax-exempt certificates, notes, and consent flags for email and SMS. Add a loyalty program as a subledger: points accrue by rule (per dollar, per visit, double-point days) and redeem at the register as a tender under its own control account, so the liability is on the books and auditable. Add built-in campaigns: segment customers by spend, recency, department affinity, or vendor affinity, and send an email campaign from the mail pipeline with open and click tracking, so the mall does not need Mailchimp for the basics.

Also in this phase: the wait list gains an offer flow (offer a booth, accept or decline, auto-create the vendor and assignment on accept), the floor map shows live occupancy and sales heat, and the merge tool for duplicate vendors and customers that SimpleConsign is only now building.

_Exit gate:_ a vendor receives a push notification within five seconds of a sale on the demo; the Sunday digest reproduces the vendor's `VendorCheck` figures to the cent; a vendor-submitted item is approved and sold end-to-end; loyalty points accrue and redeem with `prove_loyalty.sql` covering over-redemption refusal; a campaign is sent to a segment and tracked; a contract is signed and its hash verified.

### Phase 5 — Reporting and analytics (weeks 24–36)

The market leader's most-cited weakness is reporting. Perfect Consign's ledger is the best possible source for reporting. This phase connects the two.

Build a reporting layer as derived facts, verifiable against the ledger. Add `fact_sale_lines` (one row per sold or returned line with every dimension denormalised: date, hour, location, register, cashier, vendor, booth, item, brand, department, category, size, color, condition, placement, tender mix, price, discount, commission, tax, net to vendor, net to store), `fact_rent`, `fact_payouts`, and `fact_inventory_daily` (on-hand and value per vendor per day), maintained by jobs that follow the journal sequence and are idempotent on posting key. A nightly reconciliation job asserts that the sum of facts equals the ledger for every account and every day and alerts on any difference; this is the "your reports cannot disagree with your books" promise, and it is unique in the category.

Deliver a report builder: choose a fact, choose dimensions and measures, filter by any dimension, group by any dimension, pick a date range or compare two ranges, and save the result as a named report with a schedule (daily, weekly, monthly) that emails a PDF or CSV. Ship the canonical reports pre-built: daily sales and tenders (the Z-report across registers), sales by vendor, by department, by category, by brand, by cashier, by hour; inventory aging and sell-through by vendor and department; markdown effectiveness; rent roll and arrears; payout summary and detail; sales-tax by jurisdiction for filing; gift certificate and store credit liability; loyalty liability; layaway open balances; vendor scorecards (sell-through, average days to sell, return rate, rent coverage); and booth productivity per square foot from the floor map. Every list in the application gains server-side CSV export with the same filters.

Add an owner insights dashboard that replaces the current fixed dashboard with configurable cards from saved reports, and an optional mobile "store insights" view.

_Exit gate:_ reconciliation passes nightly for thirty consecutive days on the seeded tenant; every pre-built report has a snapshot test against the seeded data; a scheduled report arrives by email; report queries meet a two-second p95 on a tenant seeded with 500 vendors and 500,000 sale lines.

### Phase 6 — Omnichannel and integrations (weeks 30–44)

Shopify. Build a bidirectional Shopify integration: items flagged "list online" push as products with photos, price, vendor, and quantity; quantity changes (sale, return, markdown) push within seconds from the webhook queue; Shopify orders pull as sales posted under an `online` location and `shopify` tender with the shipping fee as its own line, crediting the vendor exactly as an in-store sale would; refunds pull as returns. Square's online store follows via the same adapter interface. A hosted public catalogue per mall (`/{slug}/shop`) with search, vendor pages, and "reserve for pickup" gives malls without Shopify an online presence with no extra subscription.

Marketplaces. Export listing drafts to eBay, Etsy, and Facebook Marketplace formats; full cross-listing is deferred until there is customer demand.

Second processor and accounting. Add Square Terminal as a second integrated processor, keeping the non-integrated path free. Add QuickBooks Desktop IIF export per `docs/PLAN.md`'s deferred item.

Importers. Build importers from SimpleConsign, ConsignCloud, Ricochet, Quail, Liberty, ConsignPro, and Mall Sales Manager exports, each with a dry run that reports what will be created, a mapping screen for vendors, categories, and tax rates, and an opening-balance journal for vendor payables and rent receivables so the mall's first payout run on Perfect Consign is correct to the cent. Publish a switching guide per competitor.

AI item entry. Integrate a vision model (via an adapter; OpenAI or Anthropic) that suggests description, brand, category, condition, and a price range from one or two photos, with every suggestion editable and none of them trusted for money. Usage is metered per plan.

_Exit gate:_ a Shopify test store round-trips an item, a sale, and a refund with journals that match an in-store equivalent; an import of a SimpleConsign sample export yields a correct first payout run; a photo produces an editable item draft.

### Phase 7 — SaaS operations and scale (weeks 36–52)

Self-serve. Add a signup flow that provisions a tenant in under a minute, Stripe Billing subscriptions with plans by active vendor count and location count, plan limits enforced by permission (never by hiding data), trial, dunning, suspension that preserves data and allows export, and a data-deletion workflow with a hold period. Build a platform administration console for the operator: tenants, usage, health, impersonation with audit, feature flags per tenant, and announcements.

Scale. Introduce PgBouncer in transaction mode with `SET LOCAL search_path` per transaction (the current per-connection `search_path` is incompatible with transaction pooling and must change in the connection layer); add read replicas for reporting; move backups to continuous WAL archiving with point-in-time recovery and a weekly automated restore test that extends `bin/prove-backup.php`; run a load test (k6) at fifty concurrent registers and a thousand vendors per tenant and fix what breaks; set an SLO of 99.9 % for the register API and alert on error budget burn.

Security and compliance. Commission an external penetration test; adopt SOC 2 Type I controls (access reviews, change management evidence from CI, vendor management, incident response) with Type II to follow; publish a security page, a status page, and a responsible-disclosure policy; complete the PCI SAQ-A attestation; add GDPR and CCPA data export and erasure for customers and vendors.

Internationalisation groundwork. Externalise strings, format currency and dates by locale, generalise rounding to any denomination, support cycle days beyond 28 where a calendar permits, and support multiple currencies per tenant only where the ledger kernel's per-book currency already allows it (one currency per book; a second currency is a second book).

Launch. Publish customer documentation, hardware guide, API documentation, switching guides, and pricing; onboard a cohort of design-partner malls; and declare general availability when the SLO has held for ninety days.

_Exit gate:_ a stranger signs up, provisions a mall, adds a vendor and an item, sells it on a test terminal, and pays the vendor by ACH, with no human involvement; load test passes; penetration test findings are closed; restore test runs weekly and is green; SOC 2 Type I report issued.

* * *

## 7\. Phase summary

| Phase | Weeks | Theme | Headline deliverables |
| --- | --- | --- | --- |
| 0 | 1–4 | Stabilise | Open findings closed; migration freeze + function canon in CI; indexes; job runner; container; observability; staging |
| 1 | 5–12 | Foundation | Permissions + overrides; audit log; locations; API v1 + webhooks; object storage; mail pipeline; 2FA |
| 2 | 9–20 | Money | Stripe Terminal; ACH payouts; commission plans; tax rules; customer credit; statements + 1099; QBO/Xero live sync |
| 3 | 13–26 | Register | POS PWA on API; offline outbox; ESC/POS, drawer, ZPL, display; keyboard + tablet; generalised rounding |
| 4 | 20–32 | People | Vendor PWA, push/SMS/email, weekly digest, online rent, item submission, contracts; customers, loyalty, campaigns; merge |
| 5 | 24–36 | Reporting | Verified facts; report builder; scheduled reports; canonical reports; CSV everywhere; insights |
| 6 | 30–44 | Channels | Shopify; public catalogue; Square; importers; AI item entry; QBD IIF |
| 7 | 36–52 | SaaS | Self-serve billing; platform console; PgBouncer + replicas + PITR; load test; pen test; SOC 2; i18n; GA |

* * *

## 8\. Definition of done and the gate matrix

A work item in any phase is complete only when every row below is satisfied and demonstrated in the pull request.

| Gate | Evidence required |
| --- | --- |
| Database rule | New or changed rule has a `sql/prove_*.sql` that rolls back, exits 0, and includes the attack vectors from `prove-before-advance` (two sessions, truncate, disable trigger, replica mode) |
| Migration discipline | Forward-only; idempotent if platform; freeze check green; function canon check green |
| PHP correctness | PHPStan L10, PHPMD, max-lines green; unit + integration + HTTP tests; mutation score ≥ floor for touched files |
| Browser | Playwright/Chrome test for any new or changed screen; axe zero serious |
| Performance | Query budgets recorded; p95 asserted in the HTTP suite for hot paths |
| Security | Permission key assigned; CSRF on POST; audit row written; threat note in PR |
| Reads do not write | HTTP test asserts no journal count change across all GET routes |
| Documentation | `docs/business-rules.md` sentence; customer doc page; API doc regenerated |
| Operability | Feature flag; rollback steps; alert if the feature has a failure mode |
| Ledger reconciliation | Any derived figure has a reconciliation assertion against the ledger |

* * *

## 9\. Architecture decisions

**ADR-1 — Keep PHP 8.5 and PostgreSQL 18; do not rewrite.** The ledger kernel, assertions, proofs, and 29,000 lines of tests are the product's moat. A rewrite would discard them for no customer benefit. Modern PHP with strict types at PHPStan level 10 is a defensible enterprise stack.

**ADR-2 — Own or replace the micro-framework, one package at a time.** Bring `krubio/perfect-*` into `packages/` with tests and semver, or replace each with the Symfony component behind the same interface (`symfony/routing`, `symfony/http-foundation`, `symfony/dependency-injection`, `monolog`). Decide per package in Phase 0; complete by end of Phase 1. No application code changes its calling convention.

**ADR-3 — Schema-per-tenant stays, with transaction pooling.** Schema isolation gives the strongest tenancy boundary available without separate databases and the proofs already cover it. The cost is connection management at scale; PgBouncer in transaction mode with `SET LOCAL search_path` resolves it and is scheduled for Phase 7 with the connection-layer change made early in Phase 1 so nothing later depends on session state.

**ADR-4 — Postgres-backed job queue, not Redis or a hosted queue.** One durable store keeps the proofs complete and the operational surface small. `FOR UPDATE SKIP LOCKED` is proven at far larger scale than this product needs. Revisit only if job volume exceeds what a single Postgres can absorb, which would be a very good problem.

**ADR-5 — The POS is an API client; the back office stays server-rendered.** The register needs latency, offline, and hardware access that page reloads cannot provide. Forms and lists do not. Splitting along that line keeps the rewrite small and the risk low.

**ADR-6 — Stripe first, agnostic always.** Stripe Terminal and Stripe Connect cover card and ACH with one vendor relationship and SAQ-A scope. The manual card tender remains free of surcharge forever, as a stated commitment against the lock-in model Ricochet adopted.

**ADR-7 — Reporting is derived and reconciled, never authoritative.** Fact tables are rebuilt from the ledger and checked nightly. If they ever disagree with the ledger, the ledger is right and the alert fires.

**ADR-8 — Photos and documents live in object storage, referenced by hash.** Postgres holds metadata and the hash; the object store holds bytes. Backups stay small and restores stay fast.

* * *

## 10\. Team, effort, and sequencing assumptions

The plan assumes a core team of two senior PHP/PostgreSQL engineers who are comfortable writing PL/pgSQL and proofs, one senior TypeScript engineer for the POS and vendor PWAs, one DevOps/SRE engineer at half time through Phase 1 and full time from Phase 6, one QA automation engineer, and one product designer at half time. A product owner with domain knowledge of vendor malls reviews every business rule sentence before it is coded. At that staffing the critical path is Phase 0 → Phase 1 → Phase 2 (payments) → Phase 3 (POS) → Phase 7 (SaaS), with Phases 4, 5, and 6 running in parallel from week 20. Total duration is approximately fifty-two weeks to general availability; a smaller team extends the calendar proportionally without changing the order, because every phase's exit gate depends on the one before it.

Effort by phase, in engineer-weeks, is estimated at 16 for Phase 0, 40 for Phase 1, 60 for Phase 2, 56 for Phase 3, 48 for Phase 4, 40 for Phase 5, 48 for Phase 6, and 48 for Phase 7, a total of roughly 356 engineer-weeks. These are planning figures with the usual uncertainty; the exit gates, not the dates, define completion.

* * *

## 11\. Risks and how the plan treats them

The largest technical risk is the shape assertion functions: `stall_assert_sale_shape()` must grow to understand commission plans, tax rules, surcharges, tips, loyalty, and online tenders, and it is already 509 lines. The mitigation is the function canon in Phase 0, decomposition into helper functions (`stall_commission_for`, `stall_tax_for`, `stall_tender_shape`) each with its own proof, and the rule that each addition is a new migration with the full body. The second risk is in-place migration edits recurring under schedule pressure; the CI freeze check removes the temptation. The third is payment edge cases — captured but not posted, posted but not captured — which are handled by the two-phase design with compensation and webhook reconciliation, and tested with Stripe test clocks. The fourth is offline conflicts at the register, which the posting key design turns from a data-integrity problem into a user-experience problem with a guided resolution. The fifth is scope: every competitor feature is tempting, and the plan orders them by what store owners check first; anything not in §6 is deferred until a design-partner mall asks for it.

A final risk is cultural rather than technical. The repository's rules were written for a single engineer working with an AI assistant, and some of them (do not run tests unless asked) are incompatible with a team. Phase 0 replaces them with CI that nobody can skip, which is the only rule that survives growth.

* * *

## 12\. What "no other agent can find fault with" means in practice

An adversarial reviewer with the repository and a database will try, in order: to run `composer check` and find a red step; to edit an applied migration and see whether CI notices; to find a GET that writes; to find a balance stored or computed outside SQL; to post the same sale twice; to sell an item from two registers at once; to disable a trigger as the table owner; to find a permission checked by URL string; to find a financial figure in a report that disagrees with the ledger; to find a card capture with no journal or a journal with no capture; to find a secret in the repository; to find a dependency with no tests or no maintainer; to find a screen with no browser test; to find a route with no audit row; and to find a claim in the documentation that the code does not support. Every one of those probes has a specific countermeasure in this plan, and each countermeasure is a command in CI rather than a sentence in a README. That is the standard: the product does not ask to be trusted, it shows the proof.

* * *

## Appendix A — Immediate first week

Open a branch from `bcc55ba`. Move `LateFeeDue::apply()` to a job and make `GET /rent` read-only. Narrow `ProbeMall`'s catch. Delete `.cursor/rules/`, move `ninja/` to `docs/reviews/`. Add `bin/check-migration-freeze.php` and `bin/check-function-canon.php` to `composer check` with `sql/functions/` seeded from the newest body of each function. Ship `069_settlement_open_payable.sql`. Replace prose test assertions with `data-testid`. Add `platform.jobs` and `bin/work.php` with the late-fee handler as the first job. Add `Dockerfile`, `/healthz`, `/readyz`. Rewrite `README.md`. Run `composer check`; it must exit 0.

## Appendix B — Files most affected by this plan

`src/Bootstrap/RoleGate.php` and `OwnerGate.php` (permissions), `src/Feature/Rent/RentController.php` and `LateFeeDue.php` (job), `src/Schema/*` (freeze and canon), `src/Feature/Pos/*` (API extraction then client), `src/Feature/Payout/PayoutDisburser.php` (ACH adapter), `sql/032|056|067` lineage of `stall_assert_sale_shape()` (decomposition), `sql/020_mall.sql` and `066_shop_capabilities.sql` (locations and tax/commission references), `src/Shared/Mail/*` (provider), `src/Feature/Inventory/ItemPhotoController.php` (object storage), `tests/Support/ProbeMall.php` (catch), `.github/workflows/check.yml` (new gates), `bin/deploy-demo.sh` (container deploy).