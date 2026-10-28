---
title: Store take / discount / tax on a line
category: Sales and register
hesk: published 2026-10-28
as_of: 2026-10-25
---

# Store take / discount / tax on a line

## As of

2026-10-25

## In one sentence

Customer pays final sold prices plus tax; store take is a percent of the final price; tax does not reduce what the vendor is owed.

## When it happens

Pricing a ticket line, applying line or ticket discounts, or posting pay.

## What the books / screens do

- Sold price starts at asking price; discount lowers it.
- Store take: item percent, else vendor, else mall default; half up to the cent.
- Vendor is owed the rest of that final price (not reduced by tax).
- Sales tax: mall rate on final price (unless tax-exempt vendor or ticket tax-free).
- Line discount stored on the line; ticket discount applies after line discounts. Flat ticket discount splits across lines; last line takes remainder.
- Tax-free ticket stores a reason and zeros every line’s tax; restore tax clears it.

## What is refused

- Discount greater than the price.
- Tax-free / discount above cashier ceilings without a second signature (see second-signature article).

## How to check

One discounted line: confirm final price, store take, vendor share, and tax on the receipt and journal.

## Source

`docs/business-rules.md` — Sales.

## Related

- Store take default vs override
- Tax-exempt vendor rules
- Second-signature ceilings
