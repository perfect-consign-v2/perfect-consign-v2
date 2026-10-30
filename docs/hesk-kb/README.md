# Perfect Consign KB (local Hesk seed pack)

Personal learning articles for local Hesk (`https://hesk.test`). Only you can reach that host. Not customer support. Not YouTrack.

## Authority

- [docs/business-rules.md](../business-rules.md) and [docs/PLAN.md](../PLAN.md) win on conflicts.
- Hesk is the readable map. Update the matching article when those files change.
- Agent gate: `.cursor/rules/keep-docs-current.mdc` — owner docs, technical docs, and these seeds stay in the same turn as the code.

## Hesk setup (this machine)

- KB enabled (`kb_enable = 1`). Articles are **public in Hesk** so search works; the site itself is still only on your Laragon host.
- Help desk title: `PC learning`
- Root category: `Perfect Consign`, with children:
  - Money spine
  - Vendors and booths
  - Sales and register
  - Rent
  - Payouts and settlement
  - Inventory
  - Tax reports and export
  - Floor and ops
  - Platform notes
- Local search uses MySQL `BOOLEAN MODE` so common words like `rent` and `vendor` still return hits (natural-language mode treats them as too common).

Admin KB: `https://hesk.test/admin/manage_knowledgebase.php` (or `/hesk/admin/...` if your vhost uses that path).

## Article front matter

```yaml
---
title: Short subject line for Hesk
category: Money spine
hesk: pending
as_of: 2026-10-25
---
```

After publish into Hesk, set `hesk: published YYYY-MM-DD`.

## Template body

Every article uses these headings:

1. As of
2. In one sentence
3. When it happens
4. What the books / screens do
5. What is refused
6. How to check
7. Source
8. Related

## Publish

From the perfect-consign repo root:

```text
php docs/hesk-kb/publish-to-hesk.php
```

Upserts public HTML articles, recounts categories, clears the KB cache, and marks each seed `hesk: published`.

## Filename map

| Prefix | Category |
|--------|----------|
| A## | Money spine |
| B## | Vendors and booths, Rent, or Payouts and settlement |
| C## | Sales and register |
| D## | Inventory or Floor and ops |
| E## | Tax reports and export, Floor and ops, or Platform notes |

Deploy / TLS for operators: `E12-public-origin-and-tls.md`. Mail timing (web vs worker): `E13-mail-delivery.md`. People and mall roles: `E14-people-and-roles.md`. Technical map: [docs/http-edge.md](../http-edge.md). Seeds that say **not in PC2 product code yet** must not be treated as live ops until rewritten and proven.
