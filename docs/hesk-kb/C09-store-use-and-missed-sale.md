---
title: Store use and missed sale
category: Sales and register
hesk: published 2026-10-27
as_of: 2026-10-25
---

# Store use and missed sale

## As of

2026-10-25

## In one sentence

Store use buys a consigned piece for the business; a missed sale pays the vendor their share when goods left without a proper sale ticket — both are ledger journals, not normal register sales.

## When it happens

Mall takes goods for its own use, or records that an item left the floor without a completed sale.

## What the books / screens do

- **Store use:** own journal. Expense the agreed price; credit that vendor’s payable. On-hand quantity falls. No drawer cash. Not a customer sale.
- **Missed sale:** posts as `missed_sale`. Pays the vendor their share and expenses the mall’s cost of that miss.

## What is refused

Treating either path as a normal tendered sale on the open ticket, or posting store use as cash out of the drawer.

## How to check

Store use: vendor payable up, cash unchanged, quantity down. Missed sale: vendor payable reflects their share; source type is `missed_sale`.

## Source

`docs/business-rules.md` — Store use and missed sale. `sql/prove_store_use.sql`, `sql/prove_missed_sale.sql`.

## Related

- Ticket vs sale journal
- Booth piece vs consigned vs store stock
- Return / donate / buy into store stock
