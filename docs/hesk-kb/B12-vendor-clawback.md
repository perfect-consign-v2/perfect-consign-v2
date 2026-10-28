---
title: Vendor clawback (they owe us after return/void)
category: Payouts and settlement
hesk: published 2026-10-28
as_of: 2026-10-25
---

# Vendor clawback (they owe us after return/void)

## As of

2026-10-25

## In one sentence

When a return or void happens after that vendor was already paid, the shortfall posts to vendor receivable (they owe us) instead of driving payable below zero or blocking the shopper.

## When it happens

Return or void on a sale whose vendor payable was already settled or disbursed; then the next payout close.

## What the books / screens do

- **Return:** debit open payable only up to what is still open; debit vendor receivable for the rest; credit the tender.
- **Void after payout:** first a clawback reclass (debit receivable, credit payable for the shortfall), then the exact reverse of the sale.
- **Payout close:** apply the smaller of open payable and open receivable for that vendor (no cash), then settle rent as usual.
- Vendor screen shows **They owe us (returns)** when receivable is open.
- Vendor receivable is a control account (subledger = vendor) and cannot go below zero.

## What is refused

- Driving vendor payable below zero with a return/void (except the documented vendor-memo debit case).
- Driving vendor receivable below zero (over-apply).
- Posting to vendor receivable from a source type that is not listed (sale adjustment, free form, etc.).
- A second clawback journal under the same posting key.

## How to check

Sell, pay the vendor out, return the item: payable stays ≥ 0, receivable rises, shopper gets the refund. Later sales create payable; close applies receivable against payable before settlement.

## Source

`docs/business-rules.md` — Payouts; Sales (void / return). `sql/040_vendor_clawback.sql`.

## Related

- Void vs return
- Settlement vs check / drawer / safe disbursement
- Control accounts and subledgers
- Chart roles in plain English
