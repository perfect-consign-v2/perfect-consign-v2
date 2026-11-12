---
title: Wait list (not a vendor, not a journal)
category: Floor and ops
hesk: published 2026-11-12
as_of: 2026-11-11
---

# Wait list (not a vendor, not a journal)

## As of

2026-11-11 — The row is keyed by the person's party. Name lives on the person; primary email and phone live on the party. Open-list phone/email rules also refuse bad contact edits, not only wait-list row saves.

## In one sentence

A wait-list row is a person who wants a booth; staff still create the vendor and assignment by hand when a booth opens.

## When it happens

No open booths; capturing interest; later placing or declining someone.

## What the books / screens do

- The row is keyed by the party. It holds preferred size, status, and notes. It does not store the person's name, email, or phone.
- Staff open the list at `/{slug}/wait-list` and one person at `/{slug}/wait-list/{party id}`.
- Required: first name, last name, primary email, primary phone on the party. Notes optional (max 2000).
- Preferred size: width and depth in whole feet, or both empty.
- Status: Waiting, Contacted, Placed, Declined. New rows start Waiting. Open list = Waiting + Contacted, oldest first.
- Same primary email cannot be Waiting or Contacted twice. After Placed or Declined, that email can be added again.
- Changing the party's primary phone or email while the row is Waiting or Contacted is held to the same required-contact and duplicate-email rules.
- Edit/delete allowed; delete does not change a vendor or booth.

## What is refused

- One side of preferred size without the other; size zero.
- Duplicate open primary email (Waiting/Contacted), including when only party contacts change.
- Clearing primary phone or email on an open wait-list party.

## How to check

Add a wait-list person; place them manually as vendor + assignment; wait-list status Placed does not auto-create books.

## Source

`docs/business-rules.md` — Wait list. Party identity: `E16-party-identity.md`.

## Related

- Vendor Active vs booth assignment vs Unassigned
- Booth / consignment / store-stock switches
