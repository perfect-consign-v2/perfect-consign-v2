---
title: Rent history on the vendor page
category: Rent
hesk: pending
as_of: 2026-11-27
---

# Rent history on the vendor page

## As of

2026-11-27

## In one sentence

Each month, newest first: what was charged on each booth, how each charge was paid, and what is still owed.

## When it happens

Opening a vendor's page.

## What the books / screens do

- Each line: Booth · Rent (or Late fee, or the extra) · charged · each payment with its receipt and register, or the payout · still owed.
- Example: "Booth 12 · Rent · $60.00 charged · $50.00 card (receipt #1042, Front) · $10.00 from sales (payout of 11-05-2026) · $0.00 owed".
- A waived late fee stays under its month, marked Waived, and owes nothing.
- The six newest months show first. Show all lists every month, with no limit.
- Which payment paid which charge is the database's answer, the same rule the payment split and the rent payments report use.

## What is refused

Nothing on the card can be deleted. A mistake is voided or reversed from the receipt or the entry.

## How to check

Charge $40.00 and take $25.00 by card: the month reads $40.00 charged · $25.00 card (receipt #…) · $15.00 owed.

## Source

`docs/business-rules.md` — Sales tax and reports. `sql/prove_vendor_reports.sql`.

## Related

- The vendor register
- Rent payments report
- Late fees on booth rent
