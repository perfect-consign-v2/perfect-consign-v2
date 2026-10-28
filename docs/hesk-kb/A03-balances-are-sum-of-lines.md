---
title: Balances are the sum of lines
category: Money spine
hesk: published 2026-10-28
as_of: 2026-10-25
---

# Balances are the sum of lines

## As of

2026-10-25

## In one sentence

There is no stored running balance column; an account balance is the sum of its journal lines.

## When it happens

Any screen, statement, payout math, or export that needs “what’s open” reads from posted lines (or a view/query over them).

## What the books / screens do

- No hash column and no stored balance column on the money facts.
- `account_balances` (where used) is defined as the sum of the lines.
- Open vendor payable and open booth rent are likewise derived from lines on those control accounts.

## What is refused

Treating a cached or rewritten balance as authoritative over the lines. Emp-pos-style “recompute and rewrite history” is explicitly not this design.

## How to check

Sum debits and credits for an account (and subledger id when it is a control account). That total is the balance.

## Source

`docs/PLAN.md` — Decisions; Database finished (account_balances).

## Related

- What is a journal and a posting key
- Control accounts and subledgers
- Chart roles in plain English
