---
title: Void vs return
category: Sales and register
hesk: pending
as_of: 2026-11-02
---

# Void vs return

## As of

2026-11-02 — void second-signature stays in the same transaction as the reversing journal; returns credit the original tender unless a second signer allows a different tender.

## In one sentence

Void reverses the whole sale; a return is its own journal for one item and leaves the rest of the ticket sold.

## When it happens

From a finished receipt: void the sale, or return one line.

## What the books / screens do

- **Void:** reverses the whole sale and frees every item. Units that are still sold go back on hand. If that vendor’s payable was already paid out, void first reclasses the shortfall to vendor receivable, then posts the exact reverse. A cashier or manager void needs a second signature inside the same transaction as that reverse — a blank override leaves no void journal.
- **Return:** reverses that item’s discount share, store take, and tax; credits the original sale tender by default; reduces vendor payable only up to what is still open; any shortfall posts to vendor receivable; that item can be sold again. Choosing a different tender needs a second signature before the return posts.
- Asking price stays on the item after sale/void/return paths as documented.
- The shopper is not blocked because payable was already cleared.

## What is refused

- A second void of the same sale.
- Selling zero on hand.
- Driving vendor payable below zero on return/void (shortfall must go to receivable).
- Cashier/manager void or a tender-changed return without a covering second signature.

## How to check

Void: original + reversal; all items free. Cashier void with blank override: 422 and no new reversal journal. Return one of two lines: one return journal; other line still sold. After payout then return: payable stays ≥ 0 and receivable rises (see Vendor clawback). Card sale returned as cash without second sign: refused.

## Source

`docs/business-rules.md` — Sales; Payouts (clawback).

## Related

- Why we reverse instead of edit
- Ticket vs sale journal
- Second-signature ceilings
- Vendor clawback (they owe us after return/void)
