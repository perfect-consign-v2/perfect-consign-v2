---
title: Store take default vs override
category: Vendors and booths
hesk: published 2026-10-25
as_of: 2026-10-25
---

# Store take default vs override

## As of

2026-10-25

## In one sentence

Store take is the mall’s percent of the final sold price; a vendor (or item) may override the mall default, or follow it.

## When it happens

Saving a vendor, saving an item’s store take, or posting a sale.

## What the books / screens do

- Settings holds the mall default.
- A saved vendor percent different from the default is marked overridden.
- A saved amount equal to the default follows later changes to that default.
- On an item, empty or the vendor’s percent stores no item override; a different percent is overridden on the item.
- At sale time, store take is item percent, else vendor, else mall default, rounded half up to the cent.
- The sale still posts the commission amount computed for that sale.

## What is refused

Nothing special on save beyond normal percent rules; the important rule is precedence at post time.

## How to check

Change the mall default; vendors marked default should follow. Overridden vendors keep their percent. Confirm a sale’s commission matches the precedence.

## Source

`docs/business-rules.md` — Vendors; Sales; Inventory (item store take).

## Related

- Ticket vs sale journal
- Store take / discount / tax on a line
