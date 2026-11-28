---
title: Vendor clawback (they owe us after return/void)
category: Payouts and settlement
hesk: pending
as_of: 2026-11-27
---

# Vendor clawback (they owe us after return/void)

## As of

2026-11-27 — A clawback a vendor never pays can be written off by the owner after the waiting days (Bad debt), and taken back if they pay later. 2026-10-25

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

- **How a clawback ends:** applied at a payout, paid at the desk, or written off by the owner as bad debt once it is past due for the waiting days (Settings → Bad debts). A write-off takes exactly what is open; if the vendor pays later, the owner takes back up to that amount and the payment posts as usual.

## What is refused

- Driving vendor payable below zero with a return/void (except the documented vendor-memo debit case).
- Driving vendor receivable below zero (over-apply).
- Posting to vendor receivable from a source type that is not listed (sale adjustment, free form, etc.).
- A second clawback journal under the same posting key.

## How to check

Sell, pay the vendor out, return the item: payable stays ≥ 0, receivable rises, shopper gets the refund. Later sales create payable; close applies receivable against payable before settlement.

## Source

`docs/business-rules.md` — Payouts; Sales (void / return). `sql/001_tenant.sql`, `sql/prove_clawback.sql`.

## Related

- Void vs return
- Settlement vs check / drawer / safe disbursement
- Control accounts and subledgers
- Chart roles in plain English
