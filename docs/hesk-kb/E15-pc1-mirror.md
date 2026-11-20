---
title: PC1 → PC2 dev mirror (Beccas sandbox)
category: Platform notes
hesk: published 2026-11-20
as_of: 2026-11-20
---

# PC1 → PC2 dev mirror (Beccas sandbox)

## As of

2026-11-20 — `refresh` and `load` require `--admin=email`. An unknown admin is refused before the wipe. A successful load writes one `store.load` row on Operator audit and does not stamp replayed history with a person.

## In one sentence

A read-only extract from live Becca loads disposable mall `beccas-mirror` on local PC2 or v2 so books can be compared without writing prod or replaying history.

## When it happens

Operator runs `php bin/pc1-mirror.php refresh --target=local --admin=operator@example.com` (or load/prove stepwise; load also needs `--admin`) when they want a fresh sandbox from current prod totals.

## What the books / screens do

- Extract writes a dated artifact under `var/pc1-extracts/`.
- Load wipes only `beccas-mirror`, provisions, imports vendors/booths/items, posts one opening journal.
- The mall is booth mall: rent on, consignment off, floor days empty. Floor items are booth pieces when that vendor has a stay covering today. A stay with no monthly rent is stored without a rent rate. An item with no such stay is left out (`item-skips.json`), not marked consignment.
- Prove requires every control total to match the extract to the cent.
- Load names a real platform admin. That admin gets one `store.load` row. Replayed sales and edits are not given a PC2 username.
- Prod is read-only. Deploy to v2 does not wipe books; mirror load is a separate command.

## What is refused

Writing prod; loading onto `api.perfectconsign.com`; wiping any slug other than `beccas-mirror`; quoting sandbox balances after a failed prove; load or refresh without a known platform admin email.

## How to check

Refresh local; open `/beccas-mirror`; payable / rent / tax match the prove report; as-of in the extract manifest matches the run.

## Source

`docs/pc1-mirror.md`, `.cursor/rules/pc1-mirror.mdc`.

## Related

- What Stall / perfect-consign is today vs full mall screens
- Export journals (QBO / Xero) at a high level
