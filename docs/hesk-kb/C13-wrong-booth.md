---
title: A sale paid the wrong booth
category: Sales and register
hesk: pending
as_of: 2026-11-27
---

# A sale paid the wrong booth

## As of

2026-11-27

## In one sentence

When a sale paid the wrong vendor for a piece, **Wrong booth** on the receipt moves that piece's share to the vendor who should have been paid, once.

## When it happens

A piece was tagged or rung under the wrong booth, and the sale credited the wrong vendor.

## What the books / screens do

- Open the receipt, find the piece under **Wrong booth**, choose the vendor who should have been paid, and press **Move the payable**.
- The store moves the piece's share: its price, less any discount, less the store's take. Example: a $100.00 piece with a 40% store take moves $60.00. The store's $40.00 stays with the store.
- The item price, the sale and the vendor named on the item stay as they are.
- When the store asks for a second signature, a cashier or manager needs one (see Second-signature ceilings).

## What is refused

- Moving a piece a second time ("That item was already moved to another booth.").
- Choosing the same vendor, or no vendor ("Choose the booth that should have been paid.").
- A piece whose sale was voided, or a piece that paid no vendor.
- Moving more than the wrong vendor is still owed: if they were already paid out, settle that first.

## How to check

The vendor register shows a "Booth correction" line: the wrong vendor goes down by the piece's share and the right vendor goes up by the same amount.

## Source

`docs/business-rules.md` — Wrong booth. `sql/prove_booth_correction.sql`.

## Related

- Ticket vs sale journal
- Second-signature ceilings (discount / void / payout)
- The vendor register
