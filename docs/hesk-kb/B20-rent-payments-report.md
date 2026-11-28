---
title: Rent payments report
category: Rent
hesk: pending
as_of: 2026-11-27
---

# Rent payments report

## As of

2026-11-27

## In one sentence

Every way rent was paid in a date range, store wide, with the register and cashier of each desk payment and the months each payment paid.

## When it happens

Reports → Rent payments. Staff who read reports only.

## What the books / screens do

- One row per stay per payment: Date · Receipt · Vendor · Booth · Months · How paid · Register · Cashier · Amount.
- How paid: Cash, Card, Check, more than one way, From sales (at payout), or Waived (a late fee waived).
- Rent from sales shows "Payout of" its date in place of the register.
- Months come from the database's rule: on each stay, payments pay the oldest charge first.
- Filters: dates (This month, Last month), vendor, booth, how paid, and a search for name, vendor code, receipt number, or booth.
- A voided receipt stays listed, marked Voided, and is left out of the totals.
- Under the list: count and total, totals by how paid, and totals by register.
- Print and Download CSV. A receipt number opens the receipt or the payout.

## What is refused

- A filter that is not on the page, a way paid that does not exist, or To before From.
- Cashiers and vendors opening it.

## How to check

Take a $25.00 card payment at the Front register: it shows with Card, Front, the cashier, and the month; the Card total is $25.00.

## Source

`docs/business-rules.md` — Sales tax and reports. `sql/prove_vendor_reports.sql`.

## Related

- The vendor register
- What a rent charge posts; what a rent payment posts
