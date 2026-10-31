---
title: Late fees on booth rent
category: Rent
hesk: published 2026-10-31
as_of: 2026-10-25
---

# Late fees on booth rent

## As of

2026-10-25

## In one sentence

Late fees charge a booth’s rent receivable (and late-fee income); they never reduce what the mall owes a vendor.

## When it happens

Mall settings turn late fees on (grace days, first fee, later fee). A job posts fees that are due. Staff may waive a fee.

## What the books / screens do

- Turning late fees on sets the effective date to that day. Fees start from then, not from older unpaid history before the feature was enabled.
- Grace days are 0–28. First and later fee amounts must be positive while the feature is on.
- Post: debit booth rent receivable, credit late-fee income. Own posting key per booth/period/ordinal.
- Waive is a reversal of that late-fee journal.

## What is refused

- A late fee that hits vendor payable (or otherwise reduces what the store owes the vendor).
- Waiving an already waived fee.

## How to check

After a late fee, booth rent receivable rises and vendor payable is unchanged. Statement/rent bill shows the late fee source.

## Source

`docs/business-rules.md` — Late fees. `sql/prove_late_fee.sql`.

## Related

- What a rent charge posts; what a rent payment posts
- Control accounts and subledgers
- Settlement vs check / drawer / safe disbursement
