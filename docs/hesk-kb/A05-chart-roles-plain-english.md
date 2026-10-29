---
title: Chart roles in plain English
category: Money spine
hesk: published 2026-10-29
as_of: 2026-10-25
---

# Chart roles in plain English

## As of

2026-10-25

## In one sentence

The mall book has named roles for cash, bank, commission, sales tax, vendor payable, vendor receivable (clawback), and booth rent — each used only for the facts allowed on that account.

## When it happens

Chart seed and every later post that picks accounts by role rather than free typing.

## What the books / screens do

Typical seeded roles:

- **Cash / bank** — money in the drawer or at the bank. Cash may go below zero (overdraft allowed).
- **Commission / store take** — mall share of a sale.
- **Sales-tax payable** — tax collected; payments reduce it; cannot go below zero.
- **Vendor payable (control)** — what the mall owes consignors; subledger = vendor.
- **Vendor receivable (control)** — what a consignor owes the mall after return/void when payable was already paid; subledger = vendor.
- **Booth rent receivable (control)** — rent owed by booths; subledger = booth.
- **Rent revenue** — credit side of a rent charge.
- **Retained earnings** — year-end close target.
- **Operating expense** — adjustments on non-control accounts.

`chart_source_rules` lists which source types each chart account accepts.

## What is refused

- Posting a source type the account does not list.
- Changing a posted account’s book, postable flag, control flag, or subledger type.
- Using vendor payable or booth rent as the plug on a register close.

## How to check

Inspect the chart / chart roles for the mall book; confirm a sample sale hits cash (or tender), commission, tax, and vendor payable.

## Source

`docs/PLAN.md` — Chart seed; Choices that stay; Adjustment; Sales-tax payment; Year-end close.

## Related

- Control accounts and subledgers
- What is a journal and a posting key
- Vendor clawback (they owe us after return/void)
