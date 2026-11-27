---
title: Vendor Active vs booth assignment vs Unassigned
category: Vendors and booths
hesk: pending
as_of: 2026-11-27
---

# Vendor Active vs booth assignment vs Unassigned

## As of

2026-11-27 — Money is kept per stay. 2026-10-25

## In one sentence

Active/Inactive is the vendor’s own standing; which booth they hold is a separate assignment fact.

## When it happens

Creating or editing a vendor, assigning or ending a booth, or reading the vendor list.

## What the books / screens do

- A vendor with no booth can still be Active. The booth column says Unassigned.
- Name is first + last; business name may be empty. When present, lists and receipts show the business name.
- The payable id (vendor id) does not change when the name or booth changes.
- An inactive vendor can still own inventory items. The booth stays on the assignment, not on the item.
- Each stay (one vendor in one booth for a span of dates) carries its own rent, late fees, extras, and deposit. A vendor with two booths, or one who moved booths, has a balance per stay.

## What is refused

Treating “no booth” as Inactive, or treating Inactive as “clears the payable id.”

## How to check

Open a vendor with no assignment: standing Active, booth Unassigned. End an assignment: vendor can remain Active.

## Source

`docs/business-rules.md` — Vendors; Inventory (inactive can own items).

## Related

- What blocks making a vendor Inactive
- Booth / consignment / store-stock switches
