---
title: Mall-wide sale percent and dates
category: Floor and ops
hesk: published 2026-10-27
as_of: 2026-10-25
---

# Mall-wide sale percent and dates

## As of

2026-10-25

## In one sentence

A mall-wide sale has a date span, a percent at most 50, and who absorbs it — mall or vendor — with no overlapping sales.

## When it happens

Owner schedules a store-wide discount event; register applies the event that covers today.

## What the books / screens do

- Mall absorb: store discount. Vendor absorb: reduces the vendor’s price.
- Percent cannot exceed 50.
- Date ranges cannot overlap another mall sale.
- The post records who created/changed the mall-sale activity.

## What is refused

- Percent above 50.
- Overlapping mall-sale date ranges.

## How to check

Create 40% mall-absorbed sale for this week; register shows it today. Second overlapping sale refused. 51% refused.

## Source

`docs/business-rules.md` — Floor, sales, and checks. `sql/011_mall_sale_span.sql`.

## Related

- Store take / discount / tax on a line
- Second-signature ceilings (discount / void / payout)
