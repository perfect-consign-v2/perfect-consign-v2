---
title: Control accounts and subledgers
category: Money spine
hesk: published 2026-10-28
as_of: 2026-10-25
---

# Control accounts and subledgers

## As of

2026-10-25

## In one sentence

A control account tracks many parties; every line on it must name the right subledger (vendor id or booth id).

## When it happens

Any post to vendor payable, vendor receivable (clawback), or booth rent receivable — sale, return, void reclass, clawback apply, rent charge, rent payment, settlement, memo, draw, and related reversals.

## What the books / screens do

- A control account requires a subledger type.
- A line on that account requires that subledger reference.
- Vendor id is the payable and clawback receivable subledger ref. Booth id is the rent subledger ref.
- Vendor payable, vendor receivable, and booth rent cannot go below zero (except the special vendor-memo debit case on payable documented under payouts).

## What is refused

- A control line without a subledger.
- A subledger id that is not a UUID (cast error; row does not post).
- Source types those control accounts do not allow (for example adjustment onto vendor payable or vendor receivable).

## How to check

Statement for one vendor: payable and receivable lines for that vendor id plus rent lines for that booth, in order.

## Source

`docs/PLAN.md` — Kernel; Chart seed; Vendors and booths; Ledger gate. `docs/business-rules.md` — Payouts (clawback).

## Related

- Chart roles in plain English
- Balances are the sum of lines
- What a rent charge posts; what a rent payment posts
- Vendor clawback (they owe us after return/void)
