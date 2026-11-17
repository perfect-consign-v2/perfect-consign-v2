---
title: Vendor portal sees only one vendor
category: Platform notes
hesk: published 2026-11-17
as_of: 2026-11-16
---

# Vendor portal sees only one vendor

## As of

2026-11-16 — Building has Vendor portal (on by default) and Vendor inventory (off by default). Inventory applies only while the portal is on. Saving the portal off stores inventory off. A vendor adds one item at a time when inventory is on. The vendor list colors rows by days on the floor. A consignor entry link still shows a confirm page, and the session starts only when that page is submitted. Making a vendor Inactive still ends portal sessions on the next request.

## In one sentence

A vendor portal session sees only the vendor named by `stall.vendor_id`, the owner can close the portal or the inventory tools, and Inactive standing ends that access.

## When it happens

The owner saves Building. A vendor signs in, an owner opens `?as=`, or a consignor requests or redeems an entry link. With inventory on, that vendor adds, edits, deletes, or prints their own items.

## What the books / screens do

- Role `stall_vendor` is scoped to that vendor id setting.
- Entry tokens are time-limited and tied to one vendor. The magic-link email is filed first and goes out when `php bin/work.php` runs (mall Settings mailbox).
- Opening the link shows a confirm page and does not start a session. Submitting that page redeems the link, regenerates the session id, and rotates the CSRF token.
- Vendor portal off refuses vendor password sign-in, owner preview, and consignor entry. The sign-in message is “The vendor portal is off.” That refusal is not a failed-password lockout. An open vendor or consignor session ends on the next request.
- Vendor inventory off keeps the check, sales, deals, and the consignor account. The Inventory link, the item list, sticker print, add, edit, and delete are closed. The consignor account hides Items and About to expire.
- Vendor inventory on: one item at a time, the add form stays open, the store assigns the tag and barcode, and the store take stays the vendor’s percent. A booth piece needs an open assignment today and does not expire. A consigned expire date before intake is refused. Quantity zero pulls the piece with no disposition and no journal. A sale line keeps the price and blocks delete. Quantity zero plus a sale line blocks edit. Return, donate, and buy stay at the desk.
- The vendor item list colors available rows by days on the floor from intake through today: 0–30, 31–60, 61–90, and 91 or more. Today and a future intake are 0. Quantity zero or a blank intake is not colored. Staff inventory has no legend.
- Inactive vendors cannot mint or redeem links; an open portal session clears on the next request; vendor-role password sessions lose credentials_version.

## What is refused

- Missing `stall.vendor_id` still returning rows.
- Seeing another vendor’s items or balances.
- Portal or password access after Inactive.
- Vendor sign-in, owner preview, and consignor entry while the portal is off.
- Inventory, labels, add, edit, and delete while inventory is off.
- A booth piece with no open assignment, a consigned expire date before intake, store placement, another vendor’s item, a price change after a sale, edit of a sold-out item, and delete after a sale line.

## How to check

Set vendor A; list shows only A. Clear setting or set B; A’s rows disappear. Turn the portal off; vendor sign-in, preview, and a consignor link stop, and an open session ends on the next click. Turn inventory off; the check and sales stay, and the item list is gone. Turn inventory on; add one item, print its sticker, set quantity to zero, and see the row color for its days on the floor. Mark A Inactive while a portal session is open; the next portal click returns to consignor sign-in.

## Source

`docs/business-rules.md` — Vendor portal and offline sync. `sql/095_vendor_portal.sql`. `sql/prove_vendor_portal.sql`.

## Related

- Vendor Active vs booth assignment vs Unassigned
- What Stall / perfect-consign is today vs full mall screens
- When mail sends (web vs worker)
