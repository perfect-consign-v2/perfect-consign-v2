---
title: Commission rules win by specificity
category: Sales and register
hesk: published 2026-10-25
as_of: 2026-10-25
---

# Commission rules win by specificity

## As of

2026-10-25

## In one sentence

Commission rules can override the simple item / vendor / mall store-take path; a posted line keeps the rule that was in force.

## When it happens

Pricing a sale (including an offline snap) when commission rules exist for the mall.

## What the books / screens do

- Rules win by specificity, then priority, then price bounds.
- The posted sale line keeps that commission.
- An offline snap may send a client commission figure; the post still uses the rule engine’s take.

## What is refused

An equal tie between two rules. Rewriting commission on a posted line / snap after the fact.

## How to check

Two competing rules with a clear winner: sale commission matches the winner. Tied rules: post refused. Snap with wrong client commission: books show rule-engine take.

## Source

`docs/business-rules.md` — Commission rules. `sql/prove_commission_precedence.sql`, `sql/prove_offline_sync_rules.sql`.

## Related

- Store take default vs override
- Store take / discount / tax on a line
- Offline register snap
