---
title: Assigning a booth takes its first rent
category: Rent
hesk: pending
as_of: 2026-11-27
---

# Assigning a booth takes its first rent

## As of

2026-11-27

## In one sentence

A booth is assigned only when its first rent is taken at the desk: a full period from the cycle day, prorated otherwise, held for the vendor when the booth starts in a later period.

## When it happens

On the vendor page: Assign a booth, See what is due, then Take $X and assign. Ending a booth and cancelling a reservation are on the booth's row.

## What the books / screens do

- The database works out what is due (`stall_booth_due`) and assigns (`stall_assign_booth`) in one step with the payment.
- Starts in the current period: the first rent is charged and paid at once.
- Starts in a later period: the money is held as rent paid in advance; the row says "Reserved: paid $X for <date>". The nightly run charges that first rent and pays it from the money held when the period arrives.
- End booth: sets the last day only; that month stays full rent.
- Cancel reservation (owner): Refund on the open register, or Keep (forfeited reservation income), with a reason. The booth is free again for those dates.
- Rent payments and the vendor register show "Paid ahead" money.

## What is refused

- A start before the first day of the current rent period.
- An end before the start; ending twice.
- A payment that is not exactly what is due; cash, card or a check with no open register; a check with no number.
- A booth already taken on those dates (two desks at once wait for each other).
- Cancelling a booth that has started, or without a reason.

## How to check

Assign a $30.00 booth from next month's first day: Due now $30.00, Reserved on the row. Cancel and keep it: the row clears and the booth is free.

## Source

`docs/business-rules.md` — Vendors (rent). `sql/prove_assign_booth.sql`.

## Related

- Rent cycle day and prorated move-in charge
- What a rent charge posts; what a rent payment posts
- What blocks making a vendor Inactive
