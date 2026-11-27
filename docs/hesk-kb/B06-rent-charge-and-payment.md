---
title: What a rent charge posts; what a rent payment posts
category: Rent
hesk: pending
as_of: 2026-11-27
---

# What a rent charge posts; what a rent payment posts

## As of

2026-11-27 — Rent posts on the stay through the database (`stall_post_rent_charge`, `stall_post_rent_payment`). A payment is split across stays, oldest first. 2026-11-18 — When more than one register is open and this session has not chosen one, a cash rent payment stays on the rent form and says Choose the active register.

## In one sentence

A rent charge creates rent receivable on one stay; a rent payment pays the vendor's stays down, oldest unpaid stay first.

## When it happens

Charge on the rent page for a stay and month, or rent paid at the desk / register.

## What the books / screens do

- **Rent charge:** one posting key per stay per period. Debit that stay's rent receivable, credit rent revenue. The amount is the database's quote for that stay and period; payout does not recalculate it.
- The charge is dated today when today is inside the period, otherwise on the later of the period start and the stay start.
- **Rent payment:** debit cash, card, or checks; credit rent receivable on the vendor's stays. The database splits it: the stay with the oldest unpaid charge first, and inside one stay, rent before late fees.
- Cash rent payment on the register must name the open shift. When more than one register is open and this session has not chosen one, the rent form says Choose the active register.

## What is refused

- A month that has not started ("That month has not started yet.").
- A period the stay does not cover.
- A second charge for the same stay and period (a voided charge can be charged again).
- A payment above the rent they owe.
- An assignment end/delete that would uncover rent already posted.

## How to check

After a charge, that stay's open rent rises. Pay less than two stays owe: the older stay is paid first. Statement rent lines show both.

## Source

`docs/business-rules.md` — Vendors (rent). `docs/PLAN.md` — Rent charge; Rent payment. `sql/prove_rent_charge.sql`, `sql/prove_rent_payment.sql`, `sql/prove_rent_book.sql`.

## Related

- The rent page: one row per stay per month
- Rent cycle day and prorated move-in charge
- Control accounts and subledgers
- Settlement vs check / drawer / safe disbursement
