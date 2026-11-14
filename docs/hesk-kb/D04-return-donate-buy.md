---
title: Return / donate / buy into store stock
category: Inventory
hesk: published 2026-11-14
as_of: 2026-11-13
---

# Return / donate / buy into store stock

## As of

2026-11-13

## In one sentence

An expired consigned piece is cleared by return, donation, or an owner store purchase. A piece that has not expired cannot be bought. The cost of a buy is the amount paid.

## When it happens

Clearing consigned goods that cannot or should not sell as consignment.

## What the books / screens do

- **Return:** sets quantity to zero (back to vendor path).
- **Donation:** makes it store stock with no cost.
- **Buy:** an owner posts one store purchase. The piece must already be expired (expire date before today in the mall time zone; a piece that expires today stays on the floor). It becomes store stock, the expire date clears, and the cost is the amount paid, above or below the tag. A manager may return or donate and cannot post the buy.
- Booth pieces do not use the expired-consigned sale gate the same way.

## What is refused

- A buy of a piece that has not expired, including one that expires today.
- Second buy; buy of a booth piece; buy of a store piece; buy after the item has sold.
- A manager posting the buy. Return and donate stay with the manager.
- Selling expired consigned still on hand without return/donate/buy first.

## How to check

Expired consigned → owner buy, including an amount above or below the tag: now store stock with that cost; sale posts the store path. Attempt a buy of a piece that has not expired, a second buy, or a manager buy: refused.

## Source

`docs/business-rules.md` — Vendors (expire / buy).

## Related

- Expire + markdown schedule
- Booth piece vs consigned vs store stock
