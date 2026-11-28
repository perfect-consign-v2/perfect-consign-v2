---
title: Money still open after a vendor leaves
category: Vendors and booths
hesk: pending
as_of: 2026-11-27
---

# Money still open after a vendor leaves

## As of

2026-11-27 — Money the store owes a vendor it cannot reach can be moved to unclaimed money after the dormancy days; money they owe can be written off after the waiting days. 2026-11-27

## In one sentence

A vendor who has left can still owe the store or be owed by it, so the store keeps them in front of staff until every balance is settled.

## When it happens

A vendor's last stay has ended, or the vendor is Inactive, and rent, returns, payable, or a deposit is still open.

## What the books / screens do

- Dashboard: a card, Left the store, money still open, lists each such vendor with what is open.
- Pay vendors: they are paid or collected from like anyone else, listed on their own under Vendors who have left.
- The rent page still shows their last stay's months, so a last month nobody charged can be charged.
- Vendor memo: they are still offered, marked (has left the store).
- Their rent stays on their own stay. The next vendor in that booth never inherits it.

## What is refused

Making the vendor Inactive while money is still open (see What blocks making a vendor Inactive).

## How to check

End a vendor's booth with rent unpaid: the dashboard card lists them; collect the rent; the card clears.

## Source

`docs/business-rules.md` — Vendors. `sql/prove_payout_run.sql`, `sql/prove_vendor_balances.sql`.

## Related

- Money the store cannot pay a vendor
- Writing off what a vendor owes
- What blocks making a vendor Inactive
- Settlement vs check / drawer / safe disbursement
- Vendor memo (and payable below zero)
