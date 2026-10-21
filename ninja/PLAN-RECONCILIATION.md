# Plan Reconciliation — Cursor's `MASTER-PLAN.md` vs. the Completion Plan

**Date:** October 2026 **Inputs:** `deliverables/PERFECT-CONSIGN-COMPLETION-PLAN.md` (mine) and `MASTER-PLAN.md` (Cursor's) **Purpose:** Decide which plan to run, and merge the best of both into one spine.

* * *

## 0\. Verdict up front

Cursor's plan is the **better plan to run**. Mine is the **better map of the destination**. The correct move is to adopt Cursor's sequencing and discipline as the execution spine, keep my inventory as the backlog of record so nothing is forgotten, and patch the three places where Cursor is too thin for the stated bar ("world's best," "owners leave competitors for it," "no other agent can find fault").

This is not a compromise for politeness. Cursor's ordering is commercially correct and mine was backwards: I front-loaded platform plumbing (API, webhooks, job runner, object storage, audit, 2FA, locations) _before_ the three things an owner actually compares when switching — an integrated card terminal, ACH to vendors, and a register that does not reload. Owners do not switch for an API. Cursor is right about that.

Where Cursor is thin is not in engineering taste; it is in the operational and compliance floor (backups/PITR, 2FA, observability) and in the treatment of competitor _table stakes_ as if they were optional. Those three gaps are cheap to close and expensive to skip.

* * *

## 1\. Where Cursor is right — and better than my plan

Each item below was checked against the repo at `bcc55ba`.

1.  **Value ordering.** Fix the books → fast register → card + ACH → vendor/reports → staff/photos/location. This is the order in which value reaches a store owner. My Phase 1 (platform-first) delayed the switching features by ~12 weeks for no commercial reason.
2.  **Late-fee job design.** Move `GET /rent` to a read; run late fees from a `platform.jobs` table claimed with `FOR UPDATE SKIP LOCKED`; keep one POST ("Assess late fees now"); share the posting key `late-fee:{booth}:{period}` so the job and the button cannot double-post. _Verified:_ `mall.time_zone` exists (`sql/020_mall.sql`), there is no jobs table and no `SKIP LOCKED` anywhere today, so this is a real build — and the shared posting key is the correct idempotency mechanism, not a workaround.
3.  **Migration freeze in CI.** Refuse a diff to any `sql/0*.sql` whose number is ≤ the highest file on the merge base. This is enforceable and precise; my plan only said "freeze applied migrations" without a mechanism.
4.  **Split the sale assertion by extraction, not by a second canonical copy.** Helpers (`stall_commission_for`, later `stall_tax_for`, `stall_tender_shape`) live only inside the new migration that installs them; the old files stay byte-for-byte. _Verified:_ `stall_assert_sale_shape()` is redefined wholesale across `032`→`056`→`067`, so a second canonical copy would be a genuine correctness hazard under the checksum model. Cursor's approach is the right one.
5.  **The offline insight.** "The posting key makes replay idempotent. It does not make an offline receipt true. No receipt prints until the server posts." This is the correct stance for a money system, and it is sharper than my plan, which proposed an offline outbox with idempotent replay without confronting that a printed offline receipt can be wrong (price changed, item sold elsewhere) and cannot be unprinted.
6.  **The PgBouncer landmine.** _Verified:_ `src/Bootstrap/MallApp.php:219` runs `SET search_path TO <tenant>, pg_catalog, extensions` on the connection. Transaction-mode pooling will not preserve that. Cursor caught a real bug-in-waiting that my plan missed when it mentioned PgBouncer.
7.  **Reports as SQL with a nightly reconciliation proof.** Report totals must equal `account_balances` for the accounts they claim to represent, with a visible failure when they do not. This is leaner and safer than my reconciled fact tables, because it reconciles directly against the ledger rather than against a derived copy.
8.  **Tax and channels.** Avalara (if ever) is a quote stored on the ticket _before_ post; the trigger checks the quote and never calls the network. Shopify is its own sale source with its own assertion, not an in-store tender with a new name. Store credit is its own control balance per customer; loyalty points are a separate unit. All correct.
9.  **"No feature flags on posting rules. One schema, one meaning."** This is the single most important line in either plan. It prevents the classic enterprise failure where two malls post the same event differently and the ledger loses a single meaning.
10.  **YAGNI on `location_id`.** Do not add multi-location until the first mall with two sites. Correct — my plan added it speculatively.
11.  **"Calendar slips before scope grows."** The right project-management reflex.

* * *

## 2\. Where Cursor is too thin for the stated bar

These are not taste disagreements; they are gaps against "world's best," "enterprise," and "no other agent can find fault."

1.  **Backups and point-in-time recovery are deferred too far.** Cursor says WAL archiving begins "when `pg_dump` from the owner screen is no longer enough." For a system whose entire value proposition is that the books cannot be corrupted, PITR plus a _rehearsed restore_ must exist the moment a real store's books live in it. One bad restore ends the relationship, and no feature compensates for lost data. → **Move to Phase 0/1.**
2.  **2FA and session hardening are deferred entirely.** Owner and manager accounts move money. A security reviewer evaluating an "enterprise" POS will name 2FA in the first meeting. It is cheap and expected. → **Add to Phase 1.**
3.  **Observability is absent.** No error tracking, alerting, or uptime signal is mentioned. A POS that fails silently at 9 a.m. on a Saturday is a churn event, not a bug. This is not a feature owners compare, but it is the difference between finding out from your alerting and finding out from an angry phone call. → **Add to Phase 1.**
4.  **Competitor table stakes are treated as optional.** You named SimpleConsign, Liberty, ConsignCloud, Ricochet, and Quail. Several things Cursor defers are features those products have _today_ — e-commerce/Shopify, tiered or sliding commissions, multi-rule tax, multi-location, and a migration importer. "Wait until a mall asks twice" is wise for _differentiators_; it is risky for _table stakes_, because a store owner will not switch to a product that is missing something they already use. → **Separate "prerequisites to be considered" from "differentiators that win the deal," and put the prerequisites on the near roadmap with triggers.**
5.  **"Explicitly later" is a list, not a plan.** Deferral is defensible and, done right, is the strongest possible defense against a fault-finding reviewer — a documented, justified, _triggered_ deferral cannot be called an omission. But it must carry a trigger and a rough size. → **Convert to a triggered roadmap (Section 4).**
6.  **Loosening local test discipline risks the moat.** Cursor keeps the "do not run tests unless asked" rule and relies on CI as the gate. CI-as-gate is fine only if CI is fast and comprehensive; the existing quality bar (PHPStan L10, mutation testing, `prove_*.sql`) is the product's actual moat, and the fastest way to lose it is to stop running it locally. → **Keep CI as the hard gate, but require the _relevant_ suite on change via a pre-commit hook. "When a human asks" is a smell.**
7.  **The timeline is optimistic.** "About twenty to thirty engineer-weeks to a mall that takes cards, pays by ACH, and rings sales without a reload" understates Stripe Terminal integration, Stripe Connect onboarding, a PWA register, and — above all — the capture/post crash-window reconciliation, which is genuinely hard to get right and to prove. Cursor's number is more honest than my 52 weeks, but it should be stress-tested before it is promised.

* * *

## 3\. The merged plan (spine = Cursor, three patches)

Phase order, owners, and exit criteria are Cursor's, unchanged except for the three additions marked **➕**.

| Phase | Weeks | Scope | Source |
| --- | --- | --- | --- |
| **0 — Make the books honest** | 1–3 | Late fees off `GET /rent` onto the worker + one POST; narrow the probe catch; migration freeze in CI; forward settlement migration; first helper extracted only if a proof names it. **➕ PITR + a rehearsed restore drill; error tracking switched on.** | Cursor + patch |
| **1 — The register talks to the server** | 4–10 | Existing sale/tender/park/recall/void/return commands become same-origin JSON actions behind the same session and CSRF; one client register page; server-rendered register retired only after a full mall day. **➕ 2FA + session hardening; alerting on post failures and job backlog.** | Cursor + patch |
| **2 — Card and ACH** | 8–18 | Stripe Terminal (WisePOS E / S700, SAQ-A); Stripe Connect Express for vendor ACH; keyed "Card" tender retained; capture→post crash-window recovery proven by an integration test that kills the worker; ACH failure reverses. | Cursor (unchanged) |
| **3 — What the vendor and the owner look at** | 16–24 | Server-rendered vendor portal; weekly email from the same figures as the vendor check; rent reminders on `mall.time_zone`; online rent payment via the card adapter; owner reports as SQL with a nightly reconciliation proof against `account_balances`. | Cursor (unchanged) |
| **4 — Staff, photos, and a second location only if needed** | after 3 | Verb-list permissions with numeric discount/tax-free ceilings on the role; overrides by a second signer as the audit actor; append-only `audit_events`; photos to object storage when `bytea` backups hurt; `location_id` only when the first two-site mall appears. | Cursor (unchanged) |

Everything else — offline sales, public API + webhooks, tiered commissions and multi-rule tax, customer store credit, Shopify, importers, self-serve signup + Stripe Billing, read replicas, SOC 2, pen test, GDPR erasure, QBO/Xero live sync, report builder, vision-model item entry, SMS, e-sign, a second processor — moves to the triggered roadmap below.

* * *

## 4\. Triggered roadmap (turns "later" into a plan)

Each row is deferred _on purpose_, with the event that starts it. This is the document a hostile reviewer reads to see that nothing was forgotten — only sequenced.

| Item | Trigger to start | Rough size |
| --- | --- | --- |
| Migration importer (one competitor at a time) | A named mall commits to switch | 2–3 eng-wks each |
| E-commerce / Shopify channel | A paying mall sells online or asks | 4–6 eng-wks |
| Tiered / sliding commission plans | A mall's contract needs more than one rate | 2 eng-wks + proofs |
| Multi-rule tax (and Avalara quote, if ever) | A mall with mixed tax jurisdictions signs | 3 eng-wks + proofs |
| Multi-location | A mall opens its second site | 3–4 eng-wks |
| Public API v1 + webhooks | A mall or partner needs integration, or a channel needs it | 5–8 eng-wks |
| Offline sales | A mall's connectivity proves unreliable _and_ accepts server-post-only receipts | 4–6 eng-wks |
| Customer store credit (control balance per customer) | A mall issues credit today | 3 eng-wks + proofs |
| Loyalty (separate unit) | A mall asks | 3 eng-wks |
| QBO/Xero live sync | A mall's accountant rejects the CSV | 4–6 eng-wks |
| Report builder | Three malls ask for the same custom report | 4–6 eng-wks |
| Self-serve signup + Stripe Billing + platform console | More than ~10 malls, or sales stops scaling | 6–10 eng-wks |
| PgBouncer transaction mode | Concurrency needs it — and only after every request and job uses `SET LOCAL search_path` inside the transaction | 2 eng-wks + load test |
| Read replicas / WAL archiving beyond PITR | Backup window or read load demands it | 3–5 eng-wks |
| SOC 2, penetration test, GDPR erasure | First enterprise or regulated prospect | 8–16 eng-wks (mostly process) |
| Second card processor / surcharging | A mall's contract requires it | 4 eng-wks |

* * *

## 5\. Recommendation

1.  **Run the merged plan in Section 3.** Cursor's spine, with PITR/restore, 2FA, and observability pulled forward into Phases 0–1.
2.  **Keep `analysis/findings.md` and `analysis/competitors.md` as the backlog of record.** They are the inventory that keeps the triggered roadmap honest.
3.  **Adopt Cursor's "Law" verbatim** — money is a journal; PostgreSQL posts or refuses; balances stay views; a mistake is a dated reversal; a stamped file is never edited; no feature flags on posting rules.
4.  **Do not loosen the quality bar.** CI stays the hard gate; run the relevant suite on change.
5.  **Publish the triggered roadmap** so "later" is a commitment with a trigger, not a shrug.
6.  **Stress-test the timeline** before promising 20–30 engineer-weeks for card + ACH + a no-reload register.

The two plans are not rivals. Mine is the whole map; Cursor's is the first leg, drawn by someone who read the terrain more carefully. Run Cursor's leg, carry my map, and close the three gaps above.