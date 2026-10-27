---
title: What Stall / perfect-consign is today vs full mall screens
category: Platform notes
hesk: published 2026-10-27
as_of: 2026-10-27
---

# What Stall / perfect-consign is today vs full mall screens

## As of

2026-10-27

## In one sentence

Stall is the internal name for the Perfect Consign (PC2) database/ledger kernel; customers never see that name, and PLAN’s old “database only” line is historical — the repo now has mall screens and an HTTP edge.

## When it happens

Reading `docs/PLAN.md`, working in `C:\laragon\www\perfect-consign`, or comparing to PC1 (`curser-pos-*`) or EMP.

## What the books / screens do

- **PC2 / perfect-consign:** Proven ledger facts plus register, inventory, rent, payouts, vendor portal, email sign-in at `/`, and platform admin. Prefer the name **PC2** in plans; **Stall** is the DB nickname.
- **HTTP edge:** Public origin, trusted proxies, Secure cookies — see Public origin, proxies, and Secure cookies / `docs/http-edge.md`.
- **Business rules doc:** mall product behavior the system enforces.
- Customers see Perfect Consign (or the mall brand), never “Stall.”
- Isolation: do not share ports/volumes/DBs with emp, double-entry, or other malls accidentally.
- **Not shipping yet** (do not operate from those Hesk seeds as live): military tax customer path, promo codes, self-serve store signup.

## What is refused

Copying emp-pos payout patterns that recompute and rewrite history. Mixing Perfect Consign DB with EMP or Stall sibling volumes.

## How to check

Read PLAN for kernel invariants; read business-rules for mall behavior; confirm which surface you are operating (CLI proof vs UI).

## Source

`docs/PLAN.md` — Stall scope and decisions. `docs/business-rules.md` — mall rules.

## Related

- Public origin, proxies, and Secure cookies
- Email sign-in finds the mall
- What is a journal and a posting key
- Export journals (QBO / Xero) at a high level
