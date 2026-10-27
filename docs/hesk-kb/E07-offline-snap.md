---
title: Offline register snap
category: Platform notes
hesk: published 2026-10-27
as_of: 2026-10-25
---

# Offline register snap

## As of

2026-10-25

## In one sentence

An offline register snap posts the captured price and tax when the drawer can take it; commission comes from the rule engine, not the client’s number.

## When it happens

A register that was offline syncs captured sales to the server.

## What the books / screens do

- Posts captured sold price and collected tax with an open shift / open quantity.
- Commission uses the rule engine even if the client sent a wrong take.
- `journal_no` stays empty until the post commits.

## What is refused

- Duplicate snap / posting key.
- Closed period, missing item, closed drawer.
- Second register taking the last unit.
- Rewriting or deleting a posted snap.

## How to check

Sync one snap: sale journal appears with rule-engine commission. Replay same key: refused. Wrong client commission: books still show server take.

## Source

`docs/business-rules.md` — Vendor portal and offline sync. `sql/prove_offline_sync.sql`, `sql/prove_offline_sync_rules.sql`.

## Related

- Commission rules win by specificity
- Open shift required; Z-tape and over/short
- Ticket vs sale journal
