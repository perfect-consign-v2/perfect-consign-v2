---
title: Why we reverse instead of edit
category: Money spine
hesk: published 2026-10-29
as_of: 2026-10-25
---

# Why we reverse instead of edit

## As of

2026-10-25

## In one sentence

A mistake is a later reversal journal; posted rows are never edited or deleted.

## When it happens

Void a sale, undo a wrong rent charge, reverse a bad disbursement, or reverse an opening — always with a new journal.

## What the books / screens do

- One reversal per original journal, same book and currency.
- Sides are swapped (debits become credits and the reverse).
- Reversal time is later; posting date is on or after the original.
- History stays intact: original plus reversal.

## What is refused

- UPDATE or DELETE on posted journals and lines.
- A second reversal of the same original.
- Truncate of ledger tables (refused by guards).

## How to check

Find the original by posting key; confirm exactly one linked reversal with swapped sides and later time.

## Source

`docs/PLAN.md` — Decisions; Kernel (reversal rules); Ledger gate.

## Related

- What is a journal and a posting key
- Balances are the sum of lines
