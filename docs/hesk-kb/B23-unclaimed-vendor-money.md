---
title: Money the store cannot pay a vendor
category: Vendors and booths
hesk: pending
as_of: 2026-11-27
---

# Money the store cannot pay a vendor

## As of

2026-11-27

## In one sentence

Money the store owes a vendor it cannot reach is not store income: after the dormancy days the owner moves it to unclaimed money, then pays the vendor from it if they come back, or sends it to the state.

## When it happens

- A vendor has left, the store still owes them, checks were never cashed and they do not reply.
- Once nothing has moved on what the store owes them for the dormancy days (Settings → Bad debts and unclaimed, 1095 days by default), the owner sees "Money the store cannot pay" on the vendor page.

## What the books / screens do

- Move: type why, press Move $X to unclaimed money. Vendor payable goes to zero; Unclaimed vendor money (2400) holds it and still names the vendor. The vendor can then be made inactive.
- They came back: pay them from what is held, in cash on the open register or by check (with its number).
- Sent to the state: record the amount and the state's report number.
- Reports → Unclaimed money: each vendor, when it was moved, how many days it has been held, what was paid to them, what was sent to the state, what is still held, and the state report numbers. Print or download for the yearly filing.

## What is refused

- Anyone but the owner ("Only the owner can do that.").
- No reason; nothing owed; before the dormancy days ("What the store owes Mary Smith can be moved to unclaimed money from 06-01-2029.").
- Paying or sending more than is held ("The store holds $20.00 for them."); a check with no number; cash without an open register; sending without the state's report number.
- Voiding any of these; posting them around the owner's steps.

## How to check

Settings → Bad debts and unclaimed: set the days to 0. A vendor the store owes $50.00: their page offers the move. Move it, make them inactive, pay $20.00 by check, send the rest with a report number; Reports → Unclaimed money shows it all.

## Source

`docs/business-rules.md` — Vendors. `sql/prove_unclaimed.sql`.

## Related

- Money still open after a vendor leaves
- Writing off what a vendor owes
- What blocks making a vendor Inactive
