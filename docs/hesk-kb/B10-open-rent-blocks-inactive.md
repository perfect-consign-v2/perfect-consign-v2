---
title: Why open rent blocks Inactive
category: Vendors and booths
hesk: published 2026-10-25
as_of: 2026-10-25
---

# Why open rent blocks Inactive

## As of

2026-10-25

## In one sentence

A vendor with open rent cannot be made Inactive; open payable alone does not block Inactive.

## When it happens

Trying to set a vendor to Inactive while booth rent receivable is still positive on a booth they held.

## What the books / screens do

- Open rent = positive rent receivable on a booth that vendor held.
- Positive vendor payable (store owes the vendor) does **not** by itself block Inactive.
- Clearing or collecting the rent removes the block.

## What is refused

Saving Inactive while open rent remains.

## How to check

Vendor with unpaid rent: Inactive refused. After rent is paid/settled to zero: Inactive allowed even if payable remains.

## Source

`docs/business-rules.md` — Vendors.

## Related

- Vendor Active vs booth assignment vs Unassigned
- What a rent charge posts; what a rent payment posts
- Settlement vs check / drawer / safe disbursement
