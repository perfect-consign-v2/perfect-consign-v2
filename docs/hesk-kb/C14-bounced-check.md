---
title: A check bounced
category: Sales and register
hesk: pending
as_of: 2026-11-27
---

# A check bounced

## As of

2026-11-27

## In one sentence

When the bank sends back a check the store took, **Bounced check** finds it by its number and marks it bounced for the check's own amount.

## When it happens

A customer's or vendor's check comes back from the bank unpaid.

## What the books / screens do

- Go to **Bounced check**, type the check number from the bank's notice, and press **Find the check**.
- Each check with that number shows its amount, the day it was taken and what it paid for. Press **It bounced** on the right one. The day is today unless you change it.
- The store's Checks go down by the check's amount and Returned checks go up by the same amount. Nothing else is typed.

## What is refused

- A number the store never took ("No check numbered … was taken."), or a check from a sale that was voided.
- Marking the same check bounced twice ("That check is already marked as bounced.").
- A day before the check was taken.

## How to check

Open the posted entry: it reads "Bounced check" with the number, and moves exactly the check's amount. The check now shows "This check is already marked as bounced."

## Source

`docs/business-rules.md` — Bounced check. `sql/prove_check_return.sql`.

## Related

- Ticket vs sale journal
- Gift certificate / gift card / store credit basics
