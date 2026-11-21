---
title: Nightly tie-out
category: Tax reports and export
hesk: published 2026-11-21
as_of: 2026-11-20
---

# Nightly tie-out

## As of

2026-11-20

## In one sentence

The nightly job refuses when sales tax, the rent roll, or stored value disagrees with the books.

## When it happens

Overnight, after the day's posts. The same checks run when those pages open.

## What the books / screens do

- Sales tax collected on the taxable, exempt, return, and void rows must match sales-tax payable.
- The rent roll must equal booth rent receivable. A receivable line that is not a booth refuses.
- Gift card, gift certificate, and store credit must each match that account.
- Role balances must match their journal lines. The books view must match the lines.
- Sales merchandise is not one account, so the sales report is not part of the tie-out. Payments to vendors are not part of it either.
- Each report page and its CSV are the same query. A bookmark is a name, a sheet, a grouping, and a range preset. The name sales tax is reserved.

## What is refused

- "Sales tax does not match the books."
- "The rent roll does not match the books."
- "Stored value does not match the books."
- A one-cent gap. The job fails. It does not paper over the difference.

## How to check

After a clean day the nightly job exits success. A one-cent stored-value gap, or a receivable line that is not a booth, makes the job fail.

## Source

`docs/business-rules.md` — Sales tax and reports. `src/Shared/Jobs/ReconcileJob.php`. `sql/109_report_sheets.sql`.

## Related

- Monthly sales-tax worksheet strips
- Balances are the sum of lines
