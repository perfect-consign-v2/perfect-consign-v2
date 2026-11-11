---
title: Tag / barcode / asking price vs posted sale amount
category: Inventory
hesk: published 2026-11-11
as_of: 2026-11-11
---

# Tag / barcode / asking price vs posted sale amount

## As of

2026-11-11 — Tag may be left blank; TG + mall date + sequence fills it (same idea as barcode).

## In one sentence

Tag and barcode identify the item; asking price is the label price, not a journal — the sale posts the amount entered at pay.

## When it happens

Creating/editing items, printing stickers, ringing a sale.

## What the books / screens do

- Tag unique in the mall (case-insensitive), max 40 chars; blank fills with TG + mall date + sequence.
- Barcode unique (case-insensitive), max 100; blank fills with BC + mall date + sequence.
- Required fields show a red asterisk after the label.
- Asking price is greater than zero. It is not a journal.
- After a sale, asking price and consignor stay on the item. Return pays the consignor on the item.
- Price and consignor editable until a sale is posted. Quantity 0 cannot be sold. On an open ticket, a sold-price change is audited; when the item has a floor, a price below that floor is refused.
- Stickers print the barcode. Sheet choices name Avery 8160 / Avery 94073 (and hang/jewelry tags). Tags include brand, size, and intake date when they fit. The first sticker preview marks the item printed; Items → Need stickers lists the rest with a count. Available is quantity on hand; Sold is quantity zero with a posted sale; Qty 0 is any quantity zero (not only sold). Status on each row is On floor, Sold, or Out. Clear drops search and stock filters.
- On Items, check rows to print; sheet choice, copies, and start cell sit on the selection bar with Print stickers. After save or on edit, Print stickers stays on the item form.

## What is refused

- Duplicate tag or barcode.
- Sold price below the item floor when a floor exists.
- Deleting an item while something still points at it (no Delete on the list; a direct delete is refused).
- Deleting a vendor/brand an item still names.

## How to check

Change asking price after intake; sell at a different sold price; journal uses sold price; item still shows asking price.

## Source

`docs/business-rules.md` — Inventory.

## Related

- Ticket vs sale journal
- Store take / discount / tax on a line
