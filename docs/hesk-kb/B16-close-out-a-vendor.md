---
title: Closing out a vendor who is leaving
category: Vendors and booths
hesk: pending
as_of: 2026-11-27
---

# Closing out a vendor who is leaving

## As of

2026-11-27

## In one sentence

The Close out page counts out what the vendor leaves with, shows each part that must clear, and turns on Make inactive when every part has a check mark.

## When it happens

- Two weeks before a vendor's last booth ends, the vendor page and the dashboard list them with how many items are still in the system.
- On the vendor page, press Close out (owner and manager).

## What the books / screens do

- Items still in the store: tag, description, price, count, and any on an open sale. Each row has one button per reason.
- "Mary took everything on the last day. [Mark all as Took it home]" counts every piece out at once.
- The checklist: Items, Open sales, Booth, Rent, Returns, Store owes them, Paid ahead, and Deposit (only when deposits are on). Make inactive turns on when all are clear.
- Rent for the month they leave is the full month.
- A piece found after the vendor left: the register says "This piece belongs to Mary Smith, who has left the store. Please set it aside and give it to the manager." On the item page press "Hold it for Mary Smith". It shows on her Close out page under "Held for Mary" until "Mary picked it up".
- Items left behind, only if the vendor agreement says so: the owner sets the days to wait in Settings → Item counts. After the last booth has been ended that long, "The store keeps it" turns a piece into store stock with no cost. Before then the page says from which day.

## What is refused

- Make inactive while any piece is counted or on an open sale ("They still have 3 items in the store. Close them out first.").
- A new item, or a change of seller, for a vendor who has left ("Mary Smith has left the store. Choose another vendor.").
- Selling a piece of a vendor who has left.
- The store keeping a piece before the waiting days, while the vendor still has a booth, when the days are not set, or when store stock is off. Only the owner keeps a piece.

## How to check

Vendor with two pieces and an ended booth: Close out shows "They still have 2 items in the store." Press Mark all as Took it home, then Make inactive.

## Source

`docs/business-rules.md` — Vendors. `sql/prove_item_quantity.sql`, `sql/prove_race_inactive_sale_*.sql`.

## Related

- Why an item count goes down
- What blocks making a vendor Inactive
- Money still open after a vendor leaves
