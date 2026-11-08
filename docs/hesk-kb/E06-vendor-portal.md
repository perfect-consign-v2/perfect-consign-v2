---
title: Vendor portal sees only one vendor
category: Platform notes
hesk: published 2026-11-08
as_of: 2026-11-06
---

# Vendor portal sees only one vendor

## As of

2026-11-06 — making a vendor Inactive ends portal sessions on the next request, burns unused entry links, and bumps that vendor’s password-login credentials. Magic-link redeem rotates the session id. Entry-link requests are capped per email and per client address.

## In one sentence

A vendor portal session sees only the vendor named by `stall.vendor_id`, and Inactive standing ends that access.

## When it happens

A consignor signs in (or uses an entry token) to view their items / account; or the mall marks that vendor Inactive.

## What the books / screens do

- Role `stall_vendor` is scoped to that vendor id setting.
- Entry tokens are time-limited and tied to one vendor. The magic-link email is filed first and goes out when `php bin/work.php` runs (mall Settings mailbox).
- Redeeming a link regenerates the session id and CSRF token.
- Inactive vendors cannot mint or redeem links; an open portal session clears on the next request; vendor-role password sessions lose credentials_version.

## What is refused

- Missing `stall.vendor_id` still returning rows.
- Seeing another vendor’s items or balances.
- Portal or password access after Inactive.

## How to check

Set vendor A; list shows only A. Clear setting or set B; A’s rows disappear. Mark A Inactive while a portal session is open; the next portal click returns to consignor sign-in.

## Source

`docs/business-rules.md` — Vendor portal and offline sync. `sql/prove_vendor_portal.sql`.

## Related

- Vendor Active vs booth assignment vs Unassigned
- What Stall / perfect-consign is today vs full mall screens
- When mail sends (web vs worker)
