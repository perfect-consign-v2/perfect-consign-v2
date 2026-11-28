---
title: Vendor portal sees only one vendor
category: Platform notes
hesk: pending
as_of: 2026-11-27
---

# Vendor portal sees only one vendor

## As of

2026-11-27 — A lower Qty in the portal asks "Why are there fewer?" with the reasons vendors may use, and the item shows its count history. 2026-11-18 — A deal asks for a bunch at one price, or a lower price each once they buy enough. The sticker price does not change. A vendor deal on one item is chosen from that vendor’s own items. The list matches the tag, the barcode, or the description, and shows the tag price. Another vendor’s tag is refused. The lookup stays available while inventory is off. An owner who opens sticker labels with `?as=` stays on that vendor’s item list. Since your last check is the day after the last posted check through today. A check written today leaves that list empty. The last check includes one linked on the payout when the line has no journal id. With no check yet, the list starts at the current payout window. This check is that window. A typed name that was not added stays on the form, and the Add button stays available for that name and for a different new name. 2026-11-17 — Column filters and the search box search that vendor’s whole item list. The table shows one page of matches. Building has Vendor portal (on by default), Vendor inventory (off by default), and Weekly vendor letter (off by default). Inventory applies only while the portal is on. Saving the portal off stores inventory off. The Sunday letter is filed only when Weekly vendor letter is on. A vendor adds one item at a time when inventory is on, and can search or add a brand, color, category, size, condition, or department before saving. The vendor list colors rows by days on the floor. A consignor entry link still shows a confirm page, and the session starts only when that page is submitted. Making a vendor Inactive still ends portal sessions on the next request.

## In one sentence

A vendor portal session sees only the vendor named by `stall.vendor_id`, the owner can close the portal or the inventory tools, and Inactive standing ends that access.

## When it happens

The owner saves Building. A vendor signs in, an owner opens `?as=`, or a consignor requests or redeems an entry link. With inventory on, that vendor adds, edits, deletes, or prints their own items. Sticker labels for an owner preview return to that same vendor’s items.

## What the books / screens do

- Role `stall_vendor` is scoped to that vendor id setting.
- Entry tokens are time-limited and tied to one vendor. The magic-link email is filed first and goes out when `php bin/work.php` runs (mall Settings mailbox).
- Opening the link shows a confirm page and does not start a session. Submitting that page redeems the link, regenerates the session id, and rotates the CSRF token.
- Vendor portal off refuses vendor password sign-in, owner preview, and consignor entry. The sign-in message is “The vendor portal is off.” That refusal is not a failed-password lockout. An open vendor or consignor session ends on the next request.
- Vendor inventory off keeps the check, sales, deals, and the consignor account. The Inventory link, the item list, sticker print, add, edit, and delete are closed. The consignor account hides Items and About to expire. A deal on one item is still chosen from that vendor’s items by tag, barcode, or description. The row shows the tag price. Pieces still on hand come first. Another vendor’s tag is refused, including when the address asks for a different vendor. The vendor pays for that deal. The form asks for a bunch at one price, or a lower price each once they buy enough. The sticker price does not change.
- Vendor inventory on: one item at a time, the add form stays open, the store assigns the tag and barcode, and the store take stays the vendor’s percent. Brand, color, category, size, condition, and department are chosen by name. A name that is not already on the list can be added before the item is saved, including when a similar name is already there. A typed name that was not added is refused. That refusal stays on the form. The Add button stays available for that name and for a different new name. Saving again repeats the refusal until the name is added. A booth piece needs an open assignment today and does not expire. A consigned expire date before intake is refused. Quantity zero pulls the piece with no disposition and no journal. A sale line keeps the price and blocks delete. Quantity zero plus a sale line blocks edit. Return, donate, and buy stay at the desk.
- Weekly vendor letter starts off. Turning it on files the Sunday letter (Payable and Rent you owe) for each active vendor whose email has not bounced. Saving money leaves that switch as it is. Sale-day notices and the rent reminder are separate.
- Your sales opens on Since your last check: the day after the last posted check through today. A check written today leaves that list empty. That check includes one linked on the payout when the payout line has no journal id. With no check yet, the list starts at the current payout window. This check is that window. Today, Yesterday, This week, This month, and a chosen pair of dates are the other ranges.
- The vendor item list colors available rows by days on the floor from intake through today: 0–30, 31–60, 61–90, and 91 or more. Today and a future intake are 0. Quantity zero or a blank intake is not colored. Staff inventory has no legend. Column filters and the search box search that vendor’s whole list. The table shows one page of matches.
- Inactive vendors cannot mint or redeem links; an open portal session clears on the next request; vendor-role password sessions lose credentials_version.

## What is refused

- Missing `stall.vendor_id` still returning rows.
- Seeing another vendor’s items or balances.
- Portal or password access after Inactive.
- Vendor sign-in, owner preview, and consignor entry while the portal is off.
- Inventory, labels, add, edit, and delete while inventory is off.
- A booth piece with no open assignment, a consigned expire date before intake, store placement, another vendor’s item, a price change after a sale, edit of a sold-out item, and delete after a sale line.
- A brand, color, category, size, condition, or department that was typed on the item and not added to the list. The Add button stays available until that name is added.

## How to check

Set vendor A; list shows only A. Clear setting or set B; A’s rows disappear. Turn the portal off; vendor sign-in, preview, and a consignor link stop, and an open session ends on the next click. Turn inventory off; the check and sales stay, and the item list is gone. On Deals, a deal for one item still finds A’s tag and shows the description and tag price; B’s tag is not in that list. Turn inventory on; add one item, print its sticker, set quantity to zero (the portal asks "Why are there fewer?"; press Took it home), and see the row color for its days on the floor. Mark A Inactive while a portal session is open; the next portal click returns to consignor sign-in.

## Source

`docs/business-rules.md` — Vendor portal and offline sync. `sql/001_tenant.sql`. `sql/prove_vendor_portal.sql`.

## Related

- Vendor Active vs booth assignment vs Unassigned
- What Stall / perfect-consign is today vs full mall screens
- When mail sends (web vs worker)
