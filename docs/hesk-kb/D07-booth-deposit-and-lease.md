---
title: Booth deposit release and assignment rent
category: Floor and ops
hesk: published 2026-11-20
as_of: 2026-11-18
---

# Booth deposit release and assignment rent

## As of

2026-11-18 — A cash booth deposit and the cash returned from it name the open shift. No open register refuses the post. 2026-10-25.

## In one sentence

Collecting a booth deposit credits booth deposits and names the open shift; releasing it cannot exceed what that booth still holds and returns that cash on the same shift. The assignment is the lease and supplies the contracted rent for charges.

## When it happens

Desk collects or returns a deposit; assignment sets rent; rent charge posts for a booth period.

## What the books / screens do

- Collect: debit cash, credit booth deposits (subledger booth). The cash names the open shift.
- Release: cannot exceed the deposit still held for that booth. The cash returned names that open shift.
- No open register refuses the collect and the return.
- Assignment contracted rent is locked for that stay. Rent-charge posting key remains one booth and one period. Mid-period move-in prorates.
- Booth extras beyond rent debit rent receivable and credit booth extras; actor is recorded.

## What is refused

- A release larger than the deposit held.
- A cash deposit or return when no register is open.
- A rent charge with no assignment covering the charge date.

## How to check

Hold $50; release $50.01 refused. Mid-month move-in: prorated rent charge; deposit account unchanged by the rent charge itself.

## Source

`docs/business-rules.md` — Floor (deposit / assignment rent). `sql/prove_booth_deposit.sql`.

## Related

- Rent cycle day and prorated move-in charge
- What a rent charge posts; what a rent payment posts
