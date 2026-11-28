---
title: Writing off what a vendor owes
category: Vendors and booths
hesk: pending
as_of: 2026-11-27
---

# Writing off what a vendor owes

## As of

2026-11-27

## In one sentence

When a vendor leaves and never pays rent or returns they owe, the owner writes it off as a loss with a reason; if they pay later, the owner takes it back and the payment posts as usual.

## When it happens

- On the vendor page, "They owe us" lists each debt with its amount and how long it has been past due.
- Once every debt is past due for the waiting days (Settings → Bad debts, 90 by default), the owner sees **Write off**.

## What the books / screens do

- The write-off page: "Mary owes $80.00. Writing it off records it as a loss. If Mary pays later, you can still take the money." Type why, press Write off $80.00.
- Each stay's open rent (with its late fees and extras) and the open returns are written off in full, today: debit Bad debt, credit that receivable. Nothing else changes.
- After: "$80.00 written off. Mary can now be made inactive." The vendor page keeps the line: "Written off $80.00 on Nov 26, 2026: Moved out of state, no forwarding address."
- Making a vendor with a write-off active again shows that line as a warning; it is allowed.
- They pay later: on that line, "They paid after this", enter what they paid (up to what is left), press Take it back. Then take the payment on the rent page or at the next payout. The history reads charge, write-off, taken back, payment.
- Reports → Bad debts: date, vendor, booth, rent or returns, amount, taken back so far, reason, who. Rent payments shows write-offs as "Written off".
- Settings → Bad debts: the waiting days (0 to 3650), and, when booth deposits are on, whether a held deposit must be applied to unpaid rent first.

## What is refused

- Anyone but the owner ("Only the owner can do that.").
- No reason, or more than 500 letters.
- While the store still owes the vendor ("The store owes Mary $12.50. Apply that first.").
- When a held deposit must be applied first and is still held.
- Before every debt is old enough ("Mary's debts can be written off from 02-25-2027.").
- Voiding a write-off or a take-back; taking back more than the write-off took.

## How to check

A vendor whose booth ended in July with July rent unpaid: their page shows the debt and Write off. Write it off with a reason; the debt is gone, the line stays, and Reports → Bad debts lists it.

## Source

`docs/business-rules.md` — Vendors. `sql/prove_write_off.sql`, `sql/prove_race_write_off_*.sql`, `sql/prove_race_pay_write_off_*.sql`.

## Related

- Closing out a vendor who is leaving
- What blocks making a vendor Inactive
- Vendor clawback
- Settlement vs disbursement
