---
title: Late fees on booth rent
category: Rent
hesk: pending
as_of: 2026-11-27
---

# Late fees on booth rent

## As of

2026-11-27 — The database decides which fees are due and posts them on the stay. A waived fee is not charged again. 2026-10-25.

## In one sentence

Late fees charge a stay's rent receivable (and late-fee income); they never reduce what the mall owes a vendor.

## When it happens

Mall settings turn late fees on (grace days, first fee, later fee). The nightly job asks the database which fees are due and posts them. Staff may waive a fee.

## What the books / screens do

- Turning late fees on sets the effective date to that day. Fees start from then, not from older unpaid history.
- Each unpaid rent period takes a fee each time grace passes, through the end of that period. The first fee in a period uses the first amount; each later one the next amount. Grace 0 is one fee a day.
- Each new period starts over with a first fee.
- Post: debit that stay's rent receivable, credit late-fee income. One posting key per stay, period, and fee number.
- Waive is a reversal of that fee, with a note of at most 500 characters. The waived fee is not charged again, and the next fee still follows it.
- A payment pays a stay's rent before its late fees.

## What is refused

- A late fee that hits vendor payable.
- Waiving an already waived fee.
- Charging a waived fee again.

## How to check

After a late fee, that stay's open rent rises and vendor payable is unchanged. Waive it; the next nightly run does not post it again.

## Source

`docs/business-rules.md` — Late fees. `sql/prove_late_fee.sql`.

## Related

- What a rent charge posts; what a rent payment posts
- Control accounts and subledgers
