---
title: Tenders and never use payable or tax as the plug
category: Sales and register
hesk: published 2026-10-28
as_of: 2026-10-25
---

# Tenders and never use payable or tax as the plug

## As of

2026-10-25

## In one sentence

Sale tenders (cash, card, check, gift certificate, gift card, store credit) must add up to the amount due; vendor payable and sales tax are never used to force the journal to balance.

## When it happens

Completing a sale, closing a register, or redeeming stored value.

## What the books / screens do

- Each tender used at most once; at least one required; they add up to the sale (plus cash-round difference when applicable).
- Journal debits each tender once; credits store take, sales tax, and vendor payable per rules.
- “From their sales” debits that vendor’s payable once, capped at payable before this sale.
- Gift certificates / cards / store credit redeem by number; open credit cannot go below zero.
- On register close, over/short plugs cash vs cash over/short — never payable or tax.

## What is refused

- Completing when tenders do not cover the due amount (except layaway deposits).
- Redeeming more stored value than remains.
- Using payable or tax as a balancing plug.

## How to check

Balanced sale journal: tender debits = sold net + tax (+ round). Close with a cash difference: one over/short journal only.

## Source

`docs/business-rules.md` — Registers; Sales.

## Related

- Cash rounding (cash-only)
- Open shift required; Z-tape and over/short
- Gift certificate / gift card / store credit basics
