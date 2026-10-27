---
title: Booth piece vs consigned vs store stock
category: Inventory
hesk: published 2026-10-27
as_of: 2026-10-25
---

# Booth piece vs consigned vs store stock

## As of

2026-10-25

## In one sentence

Every item sits in a booth, on consignment, or in store stock — those modes decide vendor link, expiry, and how a sale posts.

## When it happens

Intake and sale of inventory.

## What the books / screens do

- Booth and consigned pieces name a vendor; store pieces do not.
- Booth: needs rent on + open assignment; does not expire; sale owes the vendor.
- Consigned: needs consignment on; may expire; sale owes the vendor.
- Store: needs store stock on; no store-take override; sale credits store sales for full net, no vendor payable; cost (if present) posts COGS and relieves inventory.

## What is refused

Intake that violates the matching switch / assignment rules.

## How to check

Sell one of each type: booth/consigned → vendor payable; store → store sales only.

## Source

`docs/business-rules.md` — Vendors (item placement); Inventory.

## Related

- Booth / consignment / store-stock switches
- Expire + markdown schedule
- Return / donate / buy into store stock
