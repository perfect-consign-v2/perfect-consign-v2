---
title: Disabled-vet tax vs military merchandise discount
category: Sales and register
hesk: published 2026-10-28
as_of: 2026-10-27
---

# Disabled-vet tax vs military merchandise discount

## As of

2026-10-27 — **not in PC2 product code yet.** This seed stays so the distinction is not lost; do not operate as if the screens or proofs exist.

## In one sentence

Planned: saved disabled-veteran tax exemption on a customer vs a walk-up military merchandise discount; they are independent.

## When it happens

Not yet. Until migrations and register paths ship, cashiers use existing tax-free / discount tools only.

## What the books / screens do

Nothing extra in the live tree. Do not expect a military customer flag, a worksheet military bucket, or a dedicated military discount reason.

## What is refused

Treating this article as live ops guidance.

## How to check

Confirm `sql/051_military_customer_tax.sql` / `sql/prove_military_exempt.sql` and matching Feature code are absent until this article’s `as_of` and Source are rewritten for a shipped fact.

## Source

Intent only. Not in `docs/business-rules.md` until shipped.

## Related

- Sales tax worksheet
- Store take, discount, and tax on a ticket
