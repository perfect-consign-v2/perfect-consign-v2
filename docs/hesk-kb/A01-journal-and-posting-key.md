---
title: What is a journal and a posting key
category: Money spine
hesk: published 2026-10-29
as_of: 2026-10-25
---

# What is a journal and a posting key

## As of

2026-10-25

## In one sentence

A journal is one balanced posting to the books; the posting key is the business id that may only post once.

## When it happens

Any money fact that hits the ledger — sale, rent charge, rent payment, settlement, disbursement, tax payment, opening, adjustment, close — posts as a journal.

## What the books / screens do

- A journal has at least two lines. Debits equal credits and equal the header totals.
- Amounts are non-negative integers in the book’s currency scale. Each line is only a debit or only a credit.
- An account appears on only one side of a journal.
- The database assigns `journal_no`. A caller-supplied number is refused.
- The posting key stays the business identity (for example one key per sale).

## What is refused

- The same posting key inserted twice.
- A line added after the posting transaction commits.
- Updating or deleting a posted journal or its lines.

## How to check

Look up the journal by posting key or `journal_no`. Line debits must equal line credits.

## Source

`docs/PLAN.md` — Decisions; Kernel already in place; Journal number.

## Related

- Why we reverse instead of edit
- Balances are the sum of lines
- Control accounts and subledgers
