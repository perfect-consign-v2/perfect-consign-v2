---
title: Nightly tie-out idea (reports vs account balances)
category: Tax reports and export
hesk: published 2026-10-30
as_of: 2026-10-25
---

# Nightly tie-out idea (reports vs account balances)

## As of

2026-10-25

## In one sentence

Named money reports and a nightly job must match account balances within one cent — or the job fails.

## When it happens

Overnight check after the day’s posts; anytime you export those named queries.

## What the books / screens do

- Named queries: sales by day/tender/vendor/cashier, rent roll, payout register, stored-value liability. Each CSV is that same query.
- Nightly job compares those money totals with account balances; fails on a one-cent difference.
- Saved reports are catalog-driven (columns/filters/range/group). They cannot replace the sales-tax worksheet or the nightly tie-out. Name “sales tax” is reserved.

## What is refused

- Silent drift: job fails rather than “best effort.”
- Saved report that pretends to be the worksheet or tie-out.

## How to check

After a clean day, nightly job exits success. Force a one-cent disagreement in a test mall: job fails.

## Source

`docs/business-rules.md` — Sales tax and reports.

## Related

- Monthly sales-tax worksheet strips
- Balances are the sum of lines
