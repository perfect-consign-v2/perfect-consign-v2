---
title: Vendor portal sees only one vendor
category: Platform notes
hesk: published 2026-10-31
as_of: 2026-10-28
---

# Vendor portal sees only one vendor

## As of

2026-10-28 — consignor entry-link mail is filed to the outbox and leaves on the mail worker, not in the web request.

## In one sentence

A vendor portal session sees only the vendor named by `stall.vendor_id`.

## When it happens

A consignor signs in (or uses an entry token) to view their items / account.

## What the books / screens do

- Role `stall_vendor` is scoped to that vendor id setting.
- Entry tokens are time-limited and tied to one vendor. The magic-link email is filed first and goes out when `php bin/work.php` runs (mall Settings mailbox).

## What is refused

- Missing `stall.vendor_id` still returning rows.
- Seeing another vendor’s items or balances.

## How to check

Set vendor A; list shows only A. Clear setting or set B; A’s rows disappear.

## Source

`docs/business-rules.md` — Vendor portal and offline sync. `sql/prove_vendor_portal.sql`.

## Related

- Vendor Active vs booth assignment vs Unassigned
- What Stall / perfect-consign is today vs full mall screens
- When mail sends (web vs worker)

