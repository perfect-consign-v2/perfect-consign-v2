---
title: Platform admin portal
category: Platform notes
hesk: published 2026-10-27
as_of: 2026-10-25
---

# Platform admin portal

## As of

2026-10-25

## In one sentence

`/admin` is for operators: sign in, reset passwords, enter a store, wipe a tenant, and read an append-only admin audit.

## When it happens

You operate Perfect Consign across malls, not while ringing a sale as a cashier.

## What the books / screens do

- Admin accounts with email + password hash.
- Password-reset tokens expire; reset and audit rows are append-only.
- Enter-as owner or cashier into a tenant store.
- Tenant wipe and other admin actions land in the admin audit.

## What is refused

- Bad admin email format.
- Editing or deleting append-only reset / audit history.

## How to check

Reset flow creates a token row; using it does not leave editable history holes. Audit lists enter-as / wipe events.

## Source

`docs/business-rules.md` — Platform admin. `sql/prove_platform_admin.sql`.

## Related

- Email sign-in finds the mall
- Public origin, proxies, and Secure cookies
- What Stall / perfect-consign is today vs full mall screens
- Vendor portal sees only one vendor
