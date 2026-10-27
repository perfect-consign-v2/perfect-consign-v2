---
title: Booth deposit release and assignment rent
category: Floor and ops
hesk: published 2026-10-27
as_of: 2026-10-25
---

# Booth deposit release and assignment rent

## As of

2026-10-25

## In one sentence

Collecting a booth deposit credits booth deposits; releasing it cannot exceed what that booth still holds. The assignment is the lease and supplies the contracted rent for charges.

## When it happens

Desk collects or returns a deposit; assignment sets rent; rent charge posts for a booth period.

## What the books / screens do

- Collect: debit cash, credit booth deposits (subledger booth).
- Release: cannot exceed the deposit still held for that booth.
- Assignment contracted rent is locked for that stay. Rent-charge posting key remains one booth and one period. Mid-period move-in prorates.
- Booth extras beyond rent debit rent receivable and credit booth extras; actor is recorded.

## What is refused

- A release larger than the deposit held.
- A rent charge with no assignment covering the charge date.

## How to check

Hold $50; release $50.01 refused. Mid-month move-in: prorated rent charge; deposit account unchanged by the rent charge itself.

## Source

`docs/business-rules.md` — Floor (deposit / assignment rent). `sql/prove_booth_deposit.sql`.

## Related

- Rent cycle day and prorated move-in charge
- What a rent charge posts; what a rent payment posts
