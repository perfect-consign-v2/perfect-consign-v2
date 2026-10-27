---
title: Disabled-vet tax vs military merchandise discount
category: Sales and register
hesk: pending
as_of: 2026-10-26
---

# Disabled-vet tax vs military merchandise discount

## As of

2026-10-26

## In one sentence

Disabled-veteran tax exemption is a saved customer flag that waives sales tax; a military merchandise discount is an on-the-fly percent or amount off with no customer required.

## When it happens

A disabled vet presents credentials for tax exemption on file, or a walk-up asks for a military merchandise discount at the register.

## What the books / screens do

- Customer edit: tax exempt + number; military / disabled-veteran implies tax exempt.
- Putting that customer on the ticket sets waiver reason military and zeros tax. No cashier tax-free ceiling for that path.
- Walk-up: ticket or line discount with reason military. Discount ceiling and second signature still apply. No customer required.
- Sales-tax worksheet lists military waivers separately from other exempt sales.
- Both may apply on one ticket; they are independent.

## What is refused

- Military customer flag without tax exempt (and number).
- Tax charged on a military-waived ticket.
- Treating a merchandise military discount as the tax worksheet military bucket by itself.

## How to check

Save a military customer, attach to ticket, pay: tax 0 and worksheet military bucket. Apply discount reason military with no customer: merchandise reduced, tax still collected unless a separate waiver applies.

## Source

`docs/business-rules.md` — Sales. `sql/prove_military_exempt.sql`.

## Related

- Store take / discount / tax on a line
- Second-signature ceilings
