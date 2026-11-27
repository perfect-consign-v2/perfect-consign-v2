---
title: The rent page: one row per stay per month
category: Rent
hesk: pending
as_of: 2026-11-27
---

# The rent page: one row per stay per month

## As of

2026-11-27

## In one sentence

The rent page lists every stay that covers the month, with its amount and whether it is charged; Charge on a row posts that stay's rent for that month.

## When it happens

Opening Rent, choosing a month, pressing Charge.

## What the books / screens do

- The database builds the list (`stall_rent_book`): one row per stay per rent period, plus empty booths that have an asking rate (shown as Empty).
- Two vendors in one booth in the same month (one moved out, one moved in) are two rows, each with its own amount.
- Each row shows the vendor, the amount (prorated in a move-in month), Charged or Charge $amount, and what is still open on that stay.
- Charge is dated today when today is inside the month; otherwise on the later of the month start and the stay start.
- Owed is counted once per stay, not once per month row.

## What is refused

- A month that has not started ("That month has not started yet.").
- A second charge for the same stay and month.
- Charging while booth rent is turned off.

## How to check

Move a vendor out on the 5th and another in on the 6th: the month shows both rows; charge each.

## Source

`docs/business-rules.md` — Vendors (rent). `sql/prove_rent_book.sql`.

## Related

- What a rent charge posts; what a rent payment posts
- Rent cycle day and prorated move-in charge
