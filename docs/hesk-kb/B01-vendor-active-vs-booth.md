---
title: Vendor Active vs booth assignment vs Unassigned
category: Vendors and booths
hesk: published 2026-10-29
as_of: 2026-10-25
---

# Vendor Active vs booth assignment vs Unassigned

## As of

2026-10-25

## In one sentence

Active/Inactive is the vendor’s own standing; which booth they hold is a separate assignment fact.

## When it happens

Creating or editing a vendor, assigning or ending a booth, or reading the vendor list.

## What the books / screens do

- A vendor with no booth can still be Active. The booth column says Unassigned.
- Name is first + last; business name may be empty. When present, lists and receipts show the business name.
- The payable id (vendor id) does not change when the name or booth changes.
- An inactive vendor can still own inventory items. The booth stays on the assignment, not on the item.

## What is refused

Treating “no booth” as Inactive, or treating Inactive as “clears the payable id.”

## How to check

Open a vendor with no assignment: standing Active, booth Unassigned. End an assignment: vendor can remain Active.

## Source

`docs/business-rules.md` — Vendors; Inventory (inactive can own items).

## Related

- Why open rent blocks Inactive
- Booth / consignment / store-stock switches
