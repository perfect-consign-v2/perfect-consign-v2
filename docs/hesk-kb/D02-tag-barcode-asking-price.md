---
title: Tag / barcode / asking price vs posted sale amount
category: Inventory
hesk: published 2026-11-19
as_of: 2026-11-18
---

# Tag / barcode / asking price vs posted sale amount

## As of

2026-11-18 — Instant Add keeps a typed brand, color, category, size, condition, or department that was not added on the form. The Add button stays available for that name and for a different new name. Saving again repeats the refusal until the name is added. 2026-11-17 — Column filters and the search box search the whole Items list. The table shows one page of matches. A brand, color, category, size, condition, or department that anything still points at cannot be deleted. It can be marked inactive or merged into another name. The list opens on active names. Inactive names are on the Inactive list, where Make active puts one back. Inactive leaves the name on items that already use it and hides it from the next item. Merge moves those items onto the name you keep and removes the other one. A name that nothing points at can be deleted. A vendor an item still names cannot be deleted.

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
- Stickers print the barcode. Sheet choices name Avery 8160 / Avery 94073 (and hang/jewelry tags). Tags include brand, size, and intake date when they fit. The first sticker preview marks the item printed; Items → Need stickers lists the rest with a count. Available is quantity on hand; Sold is quantity zero with a posted sale; Qty 0 is any quantity zero (not only sold). Status on each row is On floor, Sold, or Out. Clear drops search and stock filters. Column filters and the search box search the whole list. The table shows one page of matches.
- On Items, check rows to print; sheet choice, copies, and start cell sit on the selection bar with Print stickers. After save or on edit, Print stickers stays on the item form.
- A brand, color, category, size, condition, or department that anything still points at cannot be deleted. Mark it inactive to hide it from the next item, or merge it into another name. The list opens on active names. Inactive names are on the Inactive list, where Make active puts one back. Inactive leaves the name on items that already use it. Merge moves those items onto the name you keep, makes that name active, and removes the other one. A name that nothing points at can be deleted. Creating the same spelling does not revive an inactive name.
- On the staff item form, Instant Add, and the vendor item form, those names are chosen from the list. A new spelling can be added before the item is saved, including when a similar name is already there. A typed name that was not added is refused. That refusal stays on the form. The Add button stays available for that name and for a different new name. Saving again repeats the refusal until the name is added.

## What is refused

- Duplicate tag or barcode.
- Sold price below the item floor when a floor exists.
- Deleting an item while something still points at it (no Delete on the list; a direct delete is refused).
- Deleting a brand, color, category, size, condition, or department while anything still points at it.
- Saving an item with a brand, color, category, size, condition, or department that was typed and not added.
- Deleting a vendor an item still names.

## How to check

Change asking price after intake; sell at a different sold price; journal uses sold price; item still shows asking price.

## Source

`docs/business-rules.md` — Inventory.

## Related

- Ticket vs sale journal
- Store take / discount / tax on a line
