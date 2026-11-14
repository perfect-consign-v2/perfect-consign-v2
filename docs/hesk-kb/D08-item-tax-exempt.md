---
title: Tax-exempt item
category: Inventory
hesk: published 2026-11-14
as_of: 2026-11-12
---

# Tax-exempt item

## As of

2026-11-12

## In one sentence

A piece marked tax exempt on add or edit item posts with no sales tax at the register, on the receipt, and on the sales-tax worksheet.

## When it happens

Saving an item with Tax exempt checked, then selling that piece.

## What the books / screens do

- Add/edit item has a Tax exempt checkbox. Off is the default.
- That piece is not taxed at POS. The register line and the receipt show Tax exempt.
- The monthly sales-tax worksheet lists those sales as exempt with reason item exempt.
- A tax-exempt vendor still zeros tax on every piece, even when this box is off.
- Ticket tax-free (with a reason) still zeros every line on that ticket.

## What is refused

Posting mall-rate tax on a tax-exempt item.

## How to check

Mark one item tax exempt and leave another taxable. Ring both: tax is only on the taxable piece. Receipt marks the exempt line. Worksheet exempt strip includes item exempt.

## Source

`docs/business-rules.md` — Sales; Sales tax and reports.

## Related

- Tax-exempt vendor rules
- Store take / discount / tax on a line
- Monthly sales-tax worksheet strips
