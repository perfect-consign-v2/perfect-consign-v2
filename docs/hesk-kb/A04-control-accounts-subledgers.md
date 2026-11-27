---
title: Control accounts and subledgers
category: Money spine
hesk: pending
as_of: 2026-11-27
---

# Control accounts and subledgers

## As of

2026-11-27 — Rent, late fees, booth extras, and booth deposits name the stay (assignment), not the booth. 2026-10-25.

## In one sentence

A control account tracks many parties; every line on it must name the right subledger (vendor id or stay id).

## When it happens

Any post to vendor payable, vendor receivable (clawback), booth rent receivable, or booth deposits.

## What the books / screens do

- A control account requires a subledger type and reference.
- Vendor id is the payable and clawback receivable subledger. The stay (booth assignment) id is the subledger for rent, late fees, booth extras, and booth deposits.
- A stay is one vendor in one booth for a span of dates, so rent a vendor left owing never moves to the next vendor in that booth.
- A post that names a vendor or stay takes that vendor's lock, the same lock Make inactive takes.
- Vendor payable, vendor receivable, and booth rent cannot go below zero (except the vendor-memo debit case on payable).

## What is refused

- A control line without a subledger, or with a subledger of the wrong kind ("a subledger is a vendor or a booth stay").
- A stay id that does not exist.
- Source types those control accounts do not allow.

## How to check

Statement for one vendor: payable and receivable lines for that vendor id, and rent lines for each of their stays, in order.

## Source

`docs/PLAN.md` — Kernel; Vendors and booths; Ledger gate. `docs/business-rules.md` — Vendors; Payouts. `sql/prove_vendor_balances.sql`.

## Related

- Chart roles in plain English
- Balances are the sum of lines
- What a rent charge posts; what a rent payment posts
