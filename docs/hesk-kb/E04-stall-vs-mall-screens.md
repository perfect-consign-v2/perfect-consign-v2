---
title: What Stall / perfect-consign is today vs full mall screens
category: Platform notes
hesk: published 2026-10-25
as_of: 2026-10-25
---

# What Stall / perfect-consign is today vs full mall screens

## As of

2026-10-25

## In one sentence

Stall is the internal name for the Perfect Consign database/ledger kernel; customers never see that name, and the kernel scope is not the same as every mall screen.

## When it happens

Reading `docs/PLAN.md`, working in `C:\laragon\www\perfect-consign`, or comparing to the full consignment POS product surface.

## What the books / screens do

- **Stall / perfect-consign (kernel focus):** Postgres ledger, proven posting facts, CLI export. PLAN’s “scope right now” called out database + export without register/tags/vendor login — the repo has since grown many proven facts (sales, rent, payouts, inventory, registers, etc.).
- **Business rules doc:** describes the mall product behavior (register, inventory, wait list, letters, ceilings) the system enforces.
- Customers see Perfect Consign (or the mall brand), never “Stall.”
- Isolation: do not share ports/volumes/DBs with emp, double-entry, or other malls accidentally.

## What is refused

Copying emp-pos payout patterns that recompute and rewrite history. Mixing Perfect Consign DB with EMP or Stall sibling volumes.

## How to check

Read PLAN for kernel invariants; read business-rules for mall behavior; confirm which surface you are operating (CLI proof vs UI).

## Source

`docs/PLAN.md` — Stall scope and decisions. `docs/business-rules.md` — mall rules.

## Related

- What is a journal and a posting key
- Settlement vs check / drawer / safe disbursement
- Export journals (QBO / Xero) at a high level
