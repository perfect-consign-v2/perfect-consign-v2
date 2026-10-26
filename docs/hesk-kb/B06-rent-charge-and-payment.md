---
title: What a rent charge posts; what a rent payment posts
category: Rent
hesk: published 2026-10-25
as_of: 2026-10-25
---

# What a rent charge posts; what a rent payment posts

## As of

2026-10-25

## In one sentence

A rent charge creates booth rent receivable; a rent payment (outside settlement) pays that receivable down with cash.

## When it happens

Periodic rent charge for a booth/month, or cash rent paid at the desk / register.

## What the books / screens do

- **Rent charge:** one posting key per booth per period. Debit that booth’s rent receivable, credit rent revenue. Amount comes from the rate stored for that period — payout does not recalculate it.
- **Rent payment:** debit cash, credit that booth’s receivable, for rent paid outside a payout/settlement.
- A charge requires an assignment covering the charge date.
- Cash rent payment on the register must name the open shift.

## What is refused

- Charge without a covering assignment.
- Assignment end/delete that would uncover rent already posted.
- Second identical posting key for the same charge.
- Driving booth rent below zero.

## How to check

After a charge, booth rent receivable for that booth rises. After a payment, it falls. Statement rent lines show both.

## Source

`docs/PLAN.md` — Rent charge; Rent payment. `docs/business-rules.md` — Registers (cash rent).

## Related

- Rent cycle day and prorated move-in charge
- Control accounts and subledgers
- Settlement vs check / drawer / safe disbursement
