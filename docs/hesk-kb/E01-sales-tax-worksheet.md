---
title: Monthly sales-tax worksheet strips
category: Tax reports and export
hesk: published 2026-11-26
as_of: 2026-11-20
---

# Monthly sales-tax worksheet strips

## As of

2026-11-20

## In one sentence

The monthly worksheet is one calendar month on the mall clock, and the filing and liability strips must agree with sales-tax payable.

## When it happens

Month-end tax filing; the screen, the print view, or the CSV.

## What the books / screens do

- Uses the mall clock and posting date. A sale counts in the month it posts. A void counts in the month the reversal posts and comes out of that month, including a void posted after the sale month.
- An exempt void reduces exempt sales. It does not reduce taxable sales. A return counts on the return's posting date.
- Exempt sales follow the ticket waiver, a tax-exempt vendor, and a tax-exempt piece.
- Booth rent collected, and gift certificates, gift cards, and store credit issued, are not taxable. Redemptions stay inside the sale they paid.
- **Filing:** Gross, Exempt, Returns, Taxable sales, Tax collected, then each rate as `8.00% on $100.00`. Eight thousand basis points is 8.00%.
- **Liability:** Started the month, Collected, Paid to the state, Still owe. Still owe is the closing balance through the end of the month. The pay link posts that amount. Zero or less has no link, and the page says nothing is owed.
- The screen, the print view, and the CSV are that same query. Print hides the month form and the download.

## What is refused

- Collected tax on the taxable, exempt, return, and void rows disagrees with sales-tax payable: the page says "Sales tax does not match the books." and shows no dollar table.
- A month that is not a real `YYYY-MM`, including `June`. It is not rewritten to this month.

## How to check

Run a closed month. A same-month void nets taxable sales and tax collected to zero. A later-month void leaves the sale month and reduces the later month. The CSV dollars match the screen.

## Source

`docs/business-rules.md` — Sales tax and reports. `sql/001_tenant.sql`, `sql/prove_report_sheets.sql`.

## Related

- Tax-exempt vendor rules
- Tax-exempt item
- Nightly tie-out
