# Perfect Consign — Master Plan

**Status:** proposal, October 2026
**Codebase:** `perfect-consign`, PHP 8.5, PostgreSQL 18, schema-per-tenant
**Aim:** a mall an owner will run every day, on the ledger that already exists

The product is already a double-entry consignment system: append-only journals, posting keys, database refusals, race proofs, and a register that can sell, rent, pay out, and close. The work left is to remove the few ways the application can still surprise the books, then make the register fast and give the vendor and the owner a clear view of the same figures. Card processing and ACH are the last phase. Everything else waits for a design-partner mall to ask twice.

## Law

Money is a journal. PHP, a worker, and a browser submit drafts. PostgreSQL posts or refuses. Balances stay views. A mistake is a dated reversal. Schema changes are new files under `sql/`. A stamped file is never edited.

A feature is done when its refusal is in `sql/prove_*.sql` and that proof has been run, and the PHP path is covered by the existing unit and HTTP suites. CI on the push is the hard gate: `composer check` must pass there. A pre-commit hook is not added; the full check is too long, and a short hook will skip the proof that matters. A new screen gets one browser test of the path a cashier or owner actually uses. Customer docs are written when a stranger will operate the feature without us in the room.

No feature flags on posting rules. One schema, one meaning.

## Phase 0 — Make the current books honest

Weeks 1–3, one engineer.

`GET /rent` becomes a read. Late fees move to a Postgres queue: a `platform.jobs` table claimed with `FOR UPDATE SKIP LOCKED`, and `bin/work.php` running as its own process. The job runs in the mall’s `time_zone`, which already lives on `mall`. The owner keeps one POST, “Assess late fees now,” with the same posting key the job uses (`late-fee:{booth}:{period}`), so the two callers cannot double-post.

`ProbeMall` stops catching `MigrationFileChanged`. A checksum mismatch fails the suite.

CI refuses a diff to any `sql/0*.sql` file whose number is less than or equal to the highest file on the merge base. The settlement predicate that was edited inside an already-shipped file is re-created in the next forward migration so a tenant stamped on the old bytes still converges.

`stall_assert_sale_shape()` is split by extraction, not by a second canonical copy. Helpers (`stall_commission_for`, and later `stall_tax_for` and `stall_tender_shape`) live only inside the new migration that installs them. Each helper gets its own proof. The old migration files stay byte-for-byte.

README describes clone, migrate, and run on any OS. Internal review notes stay in `ninja/`. Cursor rules stay with the people who use Cursor. CI on the push remains the gate.

**Exit:** no GET inserts a journal; freeze check is on `composer check`; a tenant stamped before the settlement fix migrates forward; late fees post from the worker and from the button under one key.

## Phase 1 — The register talks to the server

Weeks 4–10, one PHP engineer and one front-end engineer.

The sale, tender, park, recall, void, and return commands the register already uses become JSON actions on the same origin, behind the same session and CSRF. They return the server’s ticket. The browser formats money and sends intents. Tax, commission, and stock decisions stay in SQL.

The register UI becomes one client page: scan, lines, discount, tender, receipt. Keyboard first, large targets second. The server-rendered register stays until this client has run a full mall day, including a void and a return, and is then removed in a later change of its own.

Receipts, the drawer, and labels stay on the paths that work today. ESC/POS and ZPL wait until a named printer is on the counter.

Alert when a job sits past its `run_at`, and when a sale post fails. That is a log line and a check on `platform.jobs`. A hosted error tracker is a later choice.

**Exit:** add-line and pay do not reload the document; a refused post is shown as the database refusal; the HTTP suite still covers the server commands; p95 add-line on the demo is under 150 ms; a stuck job and a failed post are visible without a phone call.

## Phase 2 — What the vendor and the owner look at

After Phase 1.

Vendor portal, still server-rendered: sales since last payout, current payable, rent due, and a weekly email built from the same figures as the vendor check. Mail goes through the job runner. One provider (SES or Postmark) behind the mail interface that already exists. Bounces mark the address.

Rent reminders use the cycle day and `mall.time_zone`. Rent is still collected at the register. Paying rent online waits for the card phase.

Owner reports are SQL, not fact tables: sales by day, tender, vendor, department, and cashier; rent roll and arrears; payout register; sales-tax collected; gift-certificate and store-credit liability. Each report has a date range and a CSV of the same query. A nightly job asserts that the report totals equal `account_balances` for the accounts those reports claim to represent, and writes a visible failure when they do not.

Statements are HTML rendered from those journals. 1099-NEC waits until an accountant has signed the field list.

**Exit:** Sunday mail matches the vendor check to the cent; one report query has a proof that a planted one-cent drift fails the nightly check; CSV and screen share a query.

## Phase 3 — Staff, photos, and a second location only if needed

After Phase 2, still one mall in production.

Permissions replace path checks with a short verb list: `sale.post`, `sale.void`, `sale.discount`, `sale.tax_free`, `payout.disburse`, `rent.waive`, `register.close`, `settings.edit`. Discount and tax-free ceilings are numeric columns on the role, not encoded into the permission name. The four roles that exist today are the seeded templates. A missing permission is an override by a second person signing in as themselves. Their user id is the audit actor.

`audit_events` is append-only for actor, action, entity type, entity id, and request id. Personal fields stay on the vendor and customer rows so a later erasure can clear them.

Item photos move to object storage when `bytea` backups hurt. Metadata and sha256 stay in PostgreSQL. The column drops only after every tenant has zero rows left.

`location_id` is not added in advance. The first mall with two sites gets one migration: a location row, a location on the register and the journal, reports filtered by it, and one book for the legal entity.

**Exit:** a cashier template still passes today’s HTTP suite; an override names the manager; a photo round-trips from storage on the demo.

## Phase 4 — Card and ACH

Last. Starts only after Phases 0–3 are in use.

One processor. Stripe Terminal for cards (WisePOS E or S700, SAQ-A, no card data on our host). Stripe Connect Express for vendor ACH. The keyed “Card” tender stays, with no surcharge product and no second processor.

Owner and manager accounts use TOTP before the first live card charge. Session cookies are marked correctly and the session id is regenerated on sign-in. WebAuthn waits.

A card payment is a row (`provider`, intent id, amount, status) plus a journal. Order of operations:

1. The server prices the ticket and reserves the posting key.
2. The terminal captures that exact amount, using that key as the idempotency key.
3. The server posts the sale journal.
4. If the process dies between capture and post, the webhook worker posts the journal or refunds the intent. Both outcomes are ledger or audit rows. A cashier never closes that gap by memory.

A refused assertion after a successful capture refunds the intent and records both ids. The proof is an integration test that kills the worker in that window and shows one recovery.

ACH adds a payout method on the vendor and a transfer row on the disbursement. The payable still settles in `stall_assert_settlement()` exactly as a check does. The journal posts when the transfer is created. A failed transfer is a reversal that puts the amount back on the payable. The mall never stores account numbers; Connect’s hosted onboarding does.

Online rent payment uses this same card adapter and posts `rent_payment`.

**Exit:** a test-terminal sale posts a tender equal to the capture; a crash in the capture window reconciles to one journal or one refund; a test-mode ACH failure reverses; a check disbursement still proves. That date is not promised until a test terminal has survived a killed worker.

## Before the first paying mall

These are cutover conditions. They are not Phase 0, and they do not sit on the register page.

Someone restores an owner `pg_dump` onto a clean server and reads the books back. The first paying mall does not cut over until that drill has succeeded. Continuous WAL archiving starts when a dump is too slow or too coarse to recover a day. A mall can run on cash, check, and the keyed card tender before Phase 4.

## Triggered later

Each item starts on its trigger, as its own plan. Week counts are omitted where a short estimate would be a false promise.

| Item | Trigger |
| --- | --- |
| Importer, one competitor export at a time, ending in an opening-balance journal so the first payout matches | A named mall commits to switch. A few weeks for one known export. |
| Shopify, as its own sale source and its own assertion, including fees, shipping, and tax | A paying mall sells online or asks. Size is not estimated here. |
| Tiered commission plans, as a new SQL helper with a proof | A mall’s contract needs more than one rate. |
| Multi-rule tax. Avalara, if ever, is a quote stored on the ticket before post; the trigger checks the quote and does not call the network | A mall with mixed tax jurisdictions signs. |
| Multi-location: one location row, location on the register and the journal, one book for the legal entity | That mall opens a second site. |
| Public API and webhooks | A mall or partner needs an integration, and the sale shape has stopped moving. |
| Offline sales. The posting key makes replay idempotent. It does not make an offline receipt true. Nothing prints until the server posts | A mall’s connectivity is actually unreliable, and the mall accepts server-post-only receipts. Size is not estimated here. |
| Customer store credit as its own control balance per customer | A mall issues credit today. |
| Loyalty, as its own unit, separate from store credit | A mall asks. |
| QuickBooks and Xero live sync. CSV remains the handoff until then | A mall’s accountant rejects the CSV. |
| Report builder | Three malls ask for the same custom report. |
| Self-serve signup, Stripe Billing, platform console, read replicas, SOC 2, penetration test, GDPR erasure | More than about ten malls, or the first regulated prospect. SOC 2 is an operating period, not a short build. |
| PgBouncer in transaction mode | Concurrency needs it, and only after every request and job uses `SET LOCAL search_path` inside the transaction. `MallApp` sets `search_path` on the connection today, and a pooler will not keep that. |
| WAL archiving beyond the `pg_dump` drill | A dump is too slow or too coarse to recover a day. |
| Second card processor, surcharging, SMS, e-sign, vision-model item entry, marketplace drafts | A mall’s contract or a repeated ask requires that one item. |

## Scale, when the second mall hurts

Load is fifty concurrent registers on one tenant, measured with a script, and fixed where it breaks. Indexes come from those queries, not from sequential scans on the demo seed.

## People

Two engineers through the register and the vendor and owner views. A designer is a review of the register and the vendor page, not a seat for the year. Phase 4 is staffed when the earlier phases are in use, not before. Calendar slips before scope grows.

## First week

1. Move late fees off `GET /rent` and onto the worker and one POST.
2. Narrow the probe catch.
3. Add the migration freeze to CI.
4. Add the forward settlement migration.
5. Extract the first helper out of the sale assertion only if a proof already names the behavior being moved.

Stop there. Phase 1 starts when those five are on `master`.
