---
title: Rent cycle day and prorated move-in charge
category: Rent
hesk: pending
as_of: 2026-11-27
---

# Rent cycle day and prorated move-in charge

## As of

2026-11-27 — The quote is per stay and comes from the database (`stall_rent_quote`). 2026-10-25.

## In one sentence

Rent periods follow a cycle day (1–28); the period a stay starts in is prorated by days, rounded down in the vendor's favor.

## When it happens

Setting the mall cycle day, assigning a vendor to a booth mid-period, charging rent, or collecting rent.

## What the books / screens do

- Cycle day 1 = calendar month. Any other day runs from that day through the day before it next month.
- Move-in period: days from the start date through the end of that period, times the rate, divided by the days in the period, rounded down. Collect rent names those dates on that charge.
- The period a stay ends in is charged in full.
- The rate is the stay's own rate in force on the later of the period start and the stay start.
- Sales can pay that period's rent only up to what the vendor earned; the rest stays receivable and is collected at the register.

## What is refused

- Changing the cycle day after a rent charge has been posted.
- A quote for a period the stay does not cover, or a date that is not a period start.

## How to check

Assign on the 15th of a 30-day month at $90: the charge is $48.00. End a stay on the 3rd: that month is the full rate.

## Source

`docs/business-rules.md` — Vendors (rent period / move-in / leave). `sql/prove_rent_move_in_out.sql`, `sql/prove_rent_book.sql`.

## Related

- The rent page: one row per stay per month
- What a rent charge posts; what a rent payment posts
