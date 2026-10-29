---
title: Return / donate / buy into store stock
category: Inventory
hesk: published 2026-10-29
as_of: 2026-10-25
---

# Return / donate / buy into store stock

## As of

2026-10-25

## In one sentence

An expired (or unwanted) consigned piece is cleared by return, donation, or store purchase — each with different stock and cost effects.

## When it happens

Clearing consigned goods that cannot or should not sell as consignment.

## What the books / screens do

- **Return:** sets quantity to zero (back to vendor path).
- **Donation:** makes it store stock with no cost.
- **Buy:** one store purchase; item becomes store stock; expire clears; cost is the amount paid.
- Booth pieces do not use the expired-consigned sale gate the same way.

## What is refused

- Second buy; buy of a booth piece; buy of a store piece; buy after the item has sold.
- Selling expired consigned still on hand without return/donate/buy first.

## How to check

Expired consigned → buy: now store stock with cost; sale posts store path. Attempt second buy: refused.

## Source

`docs/business-rules.md` — Vendors (expire / buy).

## Related

- Expire + markdown schedule
- Booth piece vs consigned vs store stock
