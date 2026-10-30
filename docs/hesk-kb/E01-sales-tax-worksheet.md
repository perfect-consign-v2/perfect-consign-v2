---
title: Monthly sales-tax worksheet strips
category: Tax reports and export
hesk: published 2026-10-30
as_of: 2026-10-25
---

# Monthly sales-tax worksheet strips

## As of

2026-10-25

## In one sentence

The monthly worksheet reads journals and sale lines for a calendar month and shows filing and liability strips that must agree with sales-tax payable.

## When it happens

Month-end tax filing prep; screen, print, or CSV of the worksheet.

## What the books / screens do

- Uses mall time zone and posting date.
- Exempt sales follow ticket waiver and tax-exempt vendors. Returns/voids in the month subtract.
- Booth rent collected and stored-value issues are non-taxable. Redemptions stay inside the sale they paid.
- **Filing strip:** gross, exempt, returns, taxable base, tax collected. Each posted rate shown with its base.
- **Liability strip:** opening balance, tax collected, tax paid, closing balance of sales-tax payable.
- Screen, print, and CSV use the same query.

## What is refused

Worksheet when collected tax disagrees with the sales-tax payable account by one cent.

## How to check

Run worksheet for a closed month; filing tax collected matches liability movement; CSV matches screen.

## Source

`docs/business-rules.md` — Sales tax and reports.

## Related

- Tax-exempt vendor rules
- Nightly tie-out idea
