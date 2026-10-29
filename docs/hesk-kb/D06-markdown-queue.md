---
title: Markdown waits in the queue
category: Inventory
hesk: published 2026-10-29
as_of: 2026-10-25
---

# Markdown waits in the queue

## As of

2026-10-25

## In one sentence

A scheduled markdown can wait in a queue; the asking price changes when the re-tag is confirmed.

## When it happens

Markdown schedule day is reached; staff confirm re-tag; tickets may already be parked.

## What the books / screens do

- Queue holds the pending markdown until re-tag confirm.
- Asking price updates on confirm.
- A parked ticket keeps the price it already captured.
- Changing a floor price needs an audit actor.

## What is refused

Skipping / repeating / early steps on the schedule (same as before). Silent floor changes without an actor.

## How to check

Item due for markdown stays at old asking price until confirm; after confirm, asking price matches the step. Parked ticket still shows its captured price.

## Source

`docs/business-rules.md` — Markdown queue. `sql/prove_markdown_queue.sql`.

## Related

- Expire + markdown schedule
- Tag / barcode / asking price vs posted sale amount
- Park / recall / layaway
