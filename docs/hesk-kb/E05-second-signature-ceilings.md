---
title: Second-signature ceilings (discount / void / payout)
category: Floor and ops
hesk: pending
as_of: 2026-11-02
---

# Second-signature ceilings (discount / void / payout)

## As of

2026-11-02 — void and payout second signatures commit with the money movement; a refused override rolls the journal back. Return tender changes also need a second sign.

## In one sentence

A cashier or manager needs a second staff signature when a discount, tax-free sale, void, waive, payout, or return-tender change exceeds their stored ceiling; an owner does not.

## When it happens

Aggressive discount, tax-free ticket, void, waive, payout, or returning to a tender other than the original sale tender.

## What the books / screens do

- Cashier or manager discount cannot pass the stored percent ceiling unless a second staff member signs as themselves and their ceiling covers it.
- Tax-free sale cannot pass the stored tax-free ceiling without the same second signature.
- Cashier or manager void, waive, or payout needs that second signature; void/payout checks run before the money commit so a blank override leaves no posted journal.
- A return that credits a different tender than the original sale needs that second signature.
- The second signer must be a **manager or cashier** (not books, not vendor).
- Audit row names the signing person.
- Owner does not need the second signature.

## What is refused

Completing the restricted action without a covering second signature when the actor is a cashier or manager over ceiling. A books or vendor password as the second sign.

## How to check

Cashier over ceiling without second sign: refused. With manager sign under their ceiling: allowed; audit shows manager. Books password as second sign: refused. Cashier void with blank override: no reversal journal.

## Source

`docs/business-rules.md` — Floor, sales, and checks.

## Related

- People and mall roles
- Store take / discount / tax on a line
- Void vs return
- Settlement vs check / drawer / safe disbursement
