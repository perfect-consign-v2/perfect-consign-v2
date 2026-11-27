---
title: Booth extras, booth deposits, and assignment rent
category: Floor and ops
hesk: pending
as_of: 2026-11-27
---

# Booth extras, booth deposits, and assignment rent

## As of

2026-11-27 — Extras and deposits post on a stay. Deposits are a store setting, off by default. 2026-11-18 — A cash booth deposit and the cash returned from it name the open shift.

## In one sentence

Booth extras and booth deposits are posted on a stay from Booth extras; deposits appear only when the store turns them on, and a release cannot exceed what that stay holds.

## When it happens

Charging electric or a sign; taking or giving back a deposit; assigning a booth with a rent.

## What the books / screens do

- Booth extra: debit that stay's rent receivable, credit booth extras. The person picks the booth ("Booth A1, Ada Lane"), types what it is for and the amount.
- Settings → Booth deposits switch. While off, Booth extras shows no deposit forms.
- Take a deposit: debit cash, credit booth deposits on that stay. The cash names the open shift.
- Give it back: only stays holding a deposit are offered, with the amount held. Cannot exceed what is held. The cash names the open shift.
- The assignment is the lease: its rent is locked for that stay. Mid-period move-in prorates.

## What is refused

- The same stay, day, and reference twice ("That charge is already posted today.").
- A deposit while deposits are off ("Booth deposits are turned off for this store.").
- A release larger than the deposit held.
- A cash deposit or return when no register is open ("Open the register first to take or return cash.").
- Turning deposits off while the store still holds one.

## How to check

Turn deposits on; hold $20 on a stay; Give it back shows "(holding $20.00)"; release $20.01 is refused.

## Source

`docs/business-rules.md` — Floor (extras / deposit / assignment rent). `sql/prove_booth_deposit.sql`, `sql/prove_rent_book.sql`.

## Related

- Rent cycle day and prorated move-in charge
- What blocks making a vendor Inactive
