---
title: Tag / barcode / asking price vs posted sale amount
category: Inventory
hesk: published 2026-10-30
as_of: 2026-10-30
---

# Tag / barcode / asking price vs posted sale amount

## As of

2026-10-30

## In one sentence

Tag and barcode identify the item; asking price is the label price, not a journal — the sale posts the amount entered at pay.

## When it happens

Creating/editing items, printing stickers, ringing a sale.

## What the books / screens do

- Tag unique in the mall (case-insensitive), max 40 chars.
- Barcode unique (case-insensitive), max 100; blank fills with BC + mall date + sequence.
- Asking price is greater than zero. It is not a journal.
- After a sale, asking price and consignor stay on the item. Return pays the consignor on the item.
- Price and consignor editable until a sale is posted. Quantity 0 cannot be sold.
- Stickers print the barcode (sheet layouts remembered by the mall). The first sticker preview marks the item printed; Items → Need stickers lists the rest with a count. Available is quantity on hand; Qty 0 is quantity zero, not a “do not sell” policy.
- On Items, check rows to print; sheet choice, copies, and start cell sit on the selection bar with Print stickers. After save or on edit, Print stickers stays on the item form.

## What is refused

- Duplicate tag or barcode.
- Deleting an item while something still points at it (no Delete on the list; a direct delete is refused).
- Deleting a vendor/brand an item still names.

## How to check

Change asking price after intake; sell at a different sold price; journal uses sold price; item still shows asking price.

## Source

`docs/business-rules.md` — Inventory.

## Related

- Ticket vs sale journal
- Store take / discount / tax on a line
