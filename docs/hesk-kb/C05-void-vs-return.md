---
title: Void vs return
category: Sales and register
hesk: published 2026-10-31
as_of: 2026-10-25
---

# Void vs return

## As of

2026-10-25

## In one sentence

Void reverses the whole sale; a return is its own journal for one item and leaves the rest of the ticket sold.

## When it happens

From a finished receipt: void the sale, or return one line.

## What the books / screens do

- **Void:** reverses the whole sale and frees every item. Units that are still sold go back on hand. If that vendor’s payable was already paid out, void first reclasses the shortfall to vendor receivable, then posts the exact reverse.
- **Return:** reverses that item’s discount share, store take, and tax; credits one tender; reduces vendor payable only up to what is still open; any shortfall posts to vendor receivable; that item can be sold again.
- Asking price stays on the item after sale/void/return paths as documented.
- The shopper is not blocked because payable was already cleared.

## What is refused

- A second void of the same sale.
- Selling zero on hand.
- Driving vendor payable below zero on return/void (shortfall must go to receivable).

## How to check

Void: original + reversal; all items free. Return one of two lines: one return journal; other line still sold. After payout then return: payable stays ≥ 0 and receivable rises (see Vendor clawback).

## Source

`docs/business-rules.md` — Sales; Payouts (clawback).

## Related

- Why we reverse instead of edit
- Ticket vs sale journal
- Second-signature ceilings
- Vendor clawback (they owe us after return/void)
