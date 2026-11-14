---
title: Second-signature ceilings (discount / void / payout)
category: Floor and ops
hesk: published 2026-11-14
as_of: 2026-11-06
---

# Second-signature ceilings (discount / void / payout)

## As of

2026-11-06 — Owner Settings Money has **Require second signature** (off by default). When on, void/payout/discount/booth-correction second signatures commit with the money movement; a refused override rolls the journal back. Return tender changes also need a second sign when the toggle is on.

## In one sentence

When the mall turns on second signatures, a cashier or manager needs another staff signature for over-ceiling discounts, tax-free, void, waive, payout, booth correction, or a return-tender change; family stores can leave the toggle off; an owner never needs a second person.

## When it happens

Aggressive discount (ticket or line), tax-free ticket, void, waive, payout, booth payable correction, or returning to a tender other than the original sale tender — only when Require second signature is on.

## What the books / screens do

- Owner Settings → Money → **Require second signature** (default off).
- When off: cashier or manager may discount, waive tax, void, change return tender, waive rent, pay vendors, and correct booth payable alone; audit names that person.
- When on: cashier or manager discount cannot pass the stored percent ceiling unless a second staff member signs as themselves and their ceiling covers it.
- Tax-free sale cannot pass the stored tax-free ceiling without the same second signature.
- Cashier or manager void, waive, payout, or booth correction needs that second signature; checks run before the money commit so a blank override leaves no posted journal.
- A return that credits a different tender than the original sale needs that second signature.
- The second signer must be a **manager or cashier** (not books, not vendor).
- Audit row names the signing person.
- Owner does not need the second signature either way.

## What is refused

With the toggle on: completing the restricted action without a covering second signature when the actor is a cashier or manager over ceiling. A books or vendor password as the second sign.

## How to check

With toggle on: cashier over ceiling without second sign is refused; with manager sign under their ceiling allowed; books password as second sign refused; cashier void with blank override leaves no reversal journal. With toggle off: the same cashier action posts and audits the cashier alone.

## Source

`docs/business-rules.md` — Floor, sales, and checks.

## Related

- People and mall roles
- Store take / discount / tax on a line
- Void vs return
- Settlement vs check / drawer / safe disbursement
