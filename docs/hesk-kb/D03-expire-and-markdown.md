---
title: Expire + markdown schedule
category: Inventory
hesk: published 2026-10-27
as_of: 2026-10-25
---

# Expire + markdown schedule

## As of

2026-10-25

## In one sentence

Consigned items may expire from days-on-floor; markdown steps raise percent off the original asking price by day thresholds.

## When it happens

Intake of consigned goods, settings for days on floor, applying a markdown step.

## What the books / screens do

- Expire date empty, or on/after intake. Settings days on floor 1–365 while consignment on; empty setting means no auto-expire.
- Booth and store pieces do not expire.
- Expired consigned still on hand cannot be sold until returned, donated, or bought.
- Markdown schedule: days + percent off original asking; percents increase as days increase. Applying a step writes prior/new price and updates asking price.
- Add form keeps vendor and intake date; next item gets a fresh expire from days on floor.

## What is refused

- Skipping, repeating, or applying a markdown step before its day.
- Selling an expired consigned item still on hand.
- Saving days on floor while consignment is off.

## How to check

Consigned item past expire: sale refused until return/donate/buy. Apply markdown on its day: asking price updates; history of prior price kept.

## Source

`docs/business-rules.md` — Vendors (markdown/expire); Inventory.

## Related

- Booth piece vs consigned vs store stock
- Return / donate / buy into store stock
