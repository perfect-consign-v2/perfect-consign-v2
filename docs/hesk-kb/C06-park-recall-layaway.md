---
title: Park / recall / layaway
category: Sales and register
hesk: published 2026-10-30
as_of: 2026-10-25
---

# Park / recall / layaway

## As of

2026-10-25

## In one sentence

Park moves a ticket off the shift so close can finish; layaway takes partial tenders while items stay unsold until paid in full.

## When it happens

Need to close the register with a cart open, or customer pays a deposit toward a ticket.

## What the books / screens do

- **Park:** ticket off the shift; not drawer cash. Recall puts it on the active shift. Lines cannot change until recalled. Parked ticket can be discarded.
- Two shifts cannot hold the same item.
- **Layaway:** cash/card/check under amount due with a customer on the ticket. Debits tender, credits layaway deposits. Items stay unsold. Sale journal posts only when deposits and later tenders cover the due. Deposit above due is a sale. Cancel refunds deposits and frees the ticket.
- Gift certificates, store credit, and draw-on-sales cannot hold a layaway ticket.

## What is refused

- Close while the shift still has an open (not parked) ticket.
- Layaway held only with disallowed tenders.

## How to check

Park → close succeeds → recall → pay. Layaway deposit: no sale journal yet; final pay posts sale.

## Source

`docs/business-rules.md` — Sales.

## Related

- Ticket vs sale journal
- Open shift required; Z-tape and over/short
