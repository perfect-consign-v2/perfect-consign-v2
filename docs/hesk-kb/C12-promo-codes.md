---
title: Promo codes
category: Sales and register
hesk: published 2026-10-27
as_of: 2026-10-26
---

# Promo codes

## As of

2026-10-26

## In one sentence

Owners create redeemable promo codes; cashiers enter a code at the register and it becomes the ticket promotion discount.

## When it happens

An owner manages codes under Promo codes. A cashier opens Promo on the register and types a code before tendering.

## What the books / screens do

- Each code is unique per mall (case-insensitive), percent or amount, with optional start/end dates and a max use count.
- A valid code becomes the ticket discount with reason promotion. Clear any other ticket discount first.
- The cashier discount ceiling still applies.
- Uses increment only when the sale posts. Voiding a sale does not give the use back.
- Delete a code only if it has never been used; otherwise deactivate it.

## What is refused

- Invalid, expired, inactive, or used-up codes (never silently ignored).
- Applying a promo while another ticket discount is still set.
- Deleting a code that already has uses.

## How to check

Create a code, apply it on a ticket, pay: use count rises by one. Void the sale: use count stays. Try an expired or spent code: refused.

## Source

`docs/business-rules.md` — Sales. Promo code migrations and proofs.

## Related

- Store take / discount / tax on a line
- Second-signature ceilings
- Disabled-vet tax vs military merchandise discount
