---
title: Offline register snap
category: Platform notes
hesk: published 2026-11-06
as_of: 2026-11-02
---

# Offline register snap

## As of

2026-11-02 — minted snaps carry line and store discounts; sync posts net + tax; snap tax must match the minted row.

## In one sentence

An offline register snap posts the server-minted ticket price, discounts, and tax when the drawer can take it; commission comes from the rule engine.

## When it happens

A register that was offline syncs captured sales to the server.

## What the books / screens do

- Mints waiting snaps when the pay form is built for a payable open ticket (price, quantity, discounts, tax including waiver/exempt); the browser only keeps posting keys.
- Posts those frozen figures with an open shift / open quantity. Cash is net of discounts plus tax.
- Register cards show Unsynced snaps and Refused snaps as that same cash total (not unit price alone).
- Commission uses the rule engine.
- `journal_no` stays empty until the post commits.

## What is refused

- Client-chosen price or tax on `/pos/snap` (snaps are minted on the server).
- Tax that does not match the minted snap (or the mall rate / authorized waiver/exempt zero).
- Duplicate snap / posting key.
- Closed period, missing item, closed drawer.
- Second register taking the last unit.
- Poster insert of free-form snap money; rewriting or deleting a posted snap.

## How to check

Open pay on a discounted ticket: waiting snaps carry discount minors. Sync: sale journal matches online net. Zero-tax insert on a priced snap posting key: refused. Waiver ticket mint: tax 0 on the journal.

## Source

`docs/business-rules.md` — Vendor portal and offline sync. `sql/prove_offline_sync.sql`, `sql/prove_snap_discounts.sql`. `RegisterStore::snapTotal`.

## Related

- Commission rules win by specificity
- Open shift required; Z-tape and over/short
