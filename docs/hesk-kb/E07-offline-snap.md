---
title: Offline register snap
category: Platform notes
hesk: published 2026-11-02
as_of: 2026-11-01
---

# Offline register snap

## As of

2026-11-01

## In one sentence

An offline register snap posts the server-minted ticket price and tax when the drawer can take it; commission comes from the rule engine.

## When it happens

A register that was offline syncs captured sales to the server.

## What the books / screens do

- Mints waiting snaps when the pay form is built for a payable open ticket (price, quantity, tax including waiver/exempt); the browser only keeps posting keys.
- Posts that frozen price and tax with an open shift / open quantity. Cash is unit price × quantity + tax.
- Register cards show Unsynced snaps and Refused snaps as that same cash total (not unit price alone).
- Commission uses the rule engine.
- `journal_no` stays empty until the post commits.

## What is refused

- Client-chosen price or tax on `/pos/snap` (snaps are minted on the server).
- Tax that does not match the mall rate or an authorized waiver/exempt zero.
- Duplicate snap / posting key.
- Closed period, missing item, closed drawer.
- Second register taking the last unit.
- Poster insert of free-form snap money; rewriting or deleting a posted snap.

## How to check

Open pay on a ticket: waiting snaps exist with ticket price/tax. Sync one key: sale journal appears with rule-engine commission. Replay same key: one journal. Forged `/pos/snap` with an evil price: refused. Waiver ticket mint: tax 0 on the journal. A waiting snap for a $10 item × 3 with $0.83 tax shows $30.83 under Unsynced snaps.

## Source

`docs/business-rules.md` — Vendor portal and offline sync. `sql/prove_offline_sync.sql`, `sql/prove_offline_sync_rules.sql`. `RegisterStore::snapTotal`.

## Related

- Commission rules win by specificity
- Open shift required; Z-tape and over/short
- Ticket vs sale journal
