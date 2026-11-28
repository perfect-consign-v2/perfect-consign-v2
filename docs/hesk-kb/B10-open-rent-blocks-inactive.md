---
title: What blocks making a vendor Inactive
category: Vendors and booths
hesk: pending
as_of: 2026-11-27
---

# What blocks making a vendor Inactive

## As of

2026-11-27 — Pieces still counted or on an open sale block Inactive too; the Close out page clears them. The database decides, and it names every reason. Money the store owes the vendor now blocks Inactive too. 2026-10-25.

## In one sentence

A vendor stays Active until nothing is open between them and the store; the refusal lists every reason in plain words.

## When it happens

Pressing Make inactive on a vendor.

## What the books / screens do

- The database checks, under the same per-vendor lock a money post takes, so a sale or payment cannot slip in at the same moment.
- Reasons, each shown as one sentence:
  - They owe rent on any of their stays.
  - They owe money from returns (clawback).
  - The store owes them money.
  - Their payout balance is below zero.
  - They have paid ahead for a booth that has not started.
  - The store still holds a booth deposit for them.
  - They still have a booth today or later ("End it first.").
  - They still have pieces counted in the store ("Close them out first.").
  - One of their pieces is on an open, parked or layaway sale.
- Example: "Ada Lane can't be made inactive yet. They owe $100.00 in rent. They still have a booth. End it first."

## What is refused

Saving Inactive while any of those reasons is open. Changing or dropping the check is refused too (sealed trigger).

## How to check

Vendor with a booth and unpaid rent: Make inactive shows both sentences. End the booth, collect the rent, pay out what is owed: Make inactive works.

## Source

`docs/business-rules.md` — Vendors. `sql/prove_vendor_balances.sql`, `sql/prove_vendors_booths.sql`, `sql/prove_race.sql` (vendor inactive races), `sql/prove_item_quantity.sql`.

## Related

- Closing out a vendor who is leaving
- Vendor Active vs booth assignment vs Unassigned
- Money still open after a vendor leaves
- What a rent charge posts; what a rent payment posts
