---
title: Ticket vs sale journal
category: Sales and register
hesk: published 2026-10-27
as_of: 2026-10-25
---

# Ticket vs sale journal

## As of

2026-10-25

## In one sentence

A ticket is the open cart on a register shift; it becomes a sale journal only when Pay/Complete posts.

## When it happens

Scanning items, changing prices, parking, layaway deposits, and finally paying.

## What the books / screens do

- One open ticket per shift. Scanning an item already on the ticket adds one.
- Sold price can change until pay. Pay posts the sale and freezes the ticket.
- Discard removes a ticket only before pay. Close is refused while an open ticket remains.
- Receipt is that same ticket for later lookup, return, or void.

## What is refused

- Posting without completing pay when tenders do not cover the amount due (except layaway deposit path).
- Holding more units across open/parked/layaway tickets than on hand.

## How to check

Build a ticket: no journal yet. Pay: one sale journal with posting key; ticket frozen on the receipt.

## Source

`docs/business-rules.md` — Sales.

## Related

- Tenders and never use payable or tax as the plug
- Void vs return
- Park / recall / layaway
