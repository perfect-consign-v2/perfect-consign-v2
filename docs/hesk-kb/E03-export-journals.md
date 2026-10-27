---
title: Export journals (QBO / Xero) at a high level
category: Tax reports and export
hesk: published 2026-10-27
as_of: 2026-10-25
---

# Export journals (QBO / Xero) at a high level

## As of

2026-10-25

## In one sentence

Export writes one row per posted line into QuickBooks Online and Xero CSV shapes using mapped account names/codes — it is not a screen.

## When it happens

Owner runs `bin/export-journals.php` (or the mall’s export path) to import into their accounting package.

## What the books / screens do

- One row per posted line plus `export_accounts` mapping for QBO and Xero.
- QBO description starts with the posting key; account column is the company account name.
- Xero date is `YYYY-MM-DD`; tax rate must match that organisation; account column is the account code.
- Seeded values are Stall’s codes until a real company is mapped.
- Export does not rewrite fields that start with `=` (formula-safe for the owner’s file).
- Oversized amounts print as `#` (not a normal consignment size).

## What is refused

Treating export as a place to “fix” books — books stay in journals; export is a projection.

## How to check

Post a known sale; export; confirm posting key in QBO description and mapped accounts; import dry-run in the accounting app if needed.

## Source

`docs/PLAN.md` — Export; Choices that stay.

## Related

- What is a journal and a posting key
- Chart roles in plain English
- What Stall / perfect-consign is today vs full mall screens
