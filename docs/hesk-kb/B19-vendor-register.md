---
title: The vendor register
category: Vendors and booths
hesk: pending
as_of: 2026-11-27
---

# The vendor register

## As of

2026-11-27

## In one sentence

A bank book for one vendor: an opening balance, every money event in the range with a running balance, and a closing balance that matches the vendor page to the penny.

## When it happens

Reports → Vendor register, or Register on the vendor page. Staff who read reports only.

## What the books / screens do

- Choose the vendor (vendors who have left are listed, marked so), an optional booth, and the dates. This month and Last month are one tap.
- Columns: Date · What happened · Booth · Debit · Credit · Balance. Plain words such as "Rent for October 2026", "Sale #1042, store kept $3.00", "Rent payment, receipt #12".
- One net balance: positive, the store owes them; negative, they owe the store.
- Under the table: totals, the closing balance, and the split (the store owes them, they owe in rent, from returns, the deposit held).
- With a booth chosen, only that booth's rent and deposit lines are shown.
- Print and Download CSV.

## What is refused

- No vendor, an unknown vendor, or a booth that does not exist ("Choose a vendor." / "Choose a booth.").
- A start date after the end date.
- Cashiers and vendors opening it.

## How to check

Open a vendor who owes $15.00 rent and is owed nothing: the register for all time ends at -$15.00, and the split reads They owe in rent $15.00.

## Source

`docs/business-rules.md` — Sales tax and reports. `sql/prove_vendor_reports.sql`.

## Related

- Rent payments report
- Rent history on the vendor page
- Control accounts and subledgers
