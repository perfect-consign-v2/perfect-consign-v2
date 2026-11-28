---
title: Why an item count goes down
category: Inventory
hesk: pending
as_of: 2026-11-27
---

# Why an item count goes down

## As of

2026-11-27

## In one sentence

Every change of an item's count is recorded with why, who and when, and a lower count by hand needs one of the store's reasons.

## When it happens

- Saving an item with a lower Qty, on the item page or in the vendor portal.
- Counting a piece out on the Close out vendor page.
- A sale, a void, a customer return, a missed sale, a store use, or giving an expired piece back to its consignor (these record themselves).

## What the books / screens do

- A lower Qty asks "Why are there fewer?" with one button per reason. Nothing is saved until one is pressed.
- A higher Qty asks nothing. It records "Added more", or "Found" when the piece had been marked missing or counted out by hand.
- The item page and the vendor page show the count history, for example "Mary Smith: 3 → 2, Took it home, Nov 27, 2026 2:14 pm".
- Settings → Item counts lists the reasons. Rename one, add one, switch one off, or delete one that no history uses yet. Each says whether the piece left the store or it was a correction, and who may use it (vendors and staff, or staff only).
- The defaults: Took it home, Lost or stolen, Damaged, It was entered wrong.

## What is refused

- A lower count with no reason, or with a reason that is switched off or not for this person.
- A vendor changing another vendor's item.
- A count below what sits on an open, parked or layaway sale ("1 of these is on an open, parked or layaway sale. Finish or remove that sale first.").
- Switching off the last reason ("Keep at least one reason switched on.") and deleting a reason the history uses ("That reason is in the count history. Switch it off instead.").
- Deleting an item whose count was changed by hand ("This item has a count history, so it stays. Set its count to 0 instead.").
- Any count change without its history line, even straight in the database.

## How to check

Open an item with Qty 3, enter 2, press Save changes: the page asks why. Press Damaged: the item saves and Count history shows the line with your name.

## Source

`docs/business-rules.md` — Inventory. `sql/prove_item_quantity.sql`, `sql/prove_race_count_sale_*.sql`, `sql/prove_race_sale_count_*.sql`.

## Related

- Closing out a vendor who is leaving
- What blocks making a vendor Inactive
