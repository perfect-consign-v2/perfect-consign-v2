---
title: Self-serve store signup
category: Platform notes
hesk: published 2026-10-30
as_of: 2026-10-28
---

# Self-serve store signup

## As of

2026-10-28 — shipped in PC2. Public `/signup` is gated by Admin → Signup. Email confirmation before owner entry is a separate admin switch (default on). Admin can hand-create a store without confirmation. Duplicate owner email uses the same generic create-failure copy (no email enumeration; no “sign-in name”).

## In one sentence

Operators enable public signup so a new mall can be created from the site with store name, owner email, and password; optional email confirmation blocks sign-in until the owner clicks the link.

## When it happens

When Admin → Signup has “Allow public store signup” on. New malls can also be created from Admin → Tenants → Create store (always verified) or CLI provision + set-owner.

## What the books / screens do

- Root shows Create your store only when public signup is on.
- `/signup` creates the tenant and owner; when “Require email confirmation” is on, the confirmation email sends in that request through the platform Gmail/SMTP env, and the owner must open the link before sign-in.
- `platform.owners.email_verified_at` records when the email was confirmed (or set at admin/CLI create).
- Admin audit records `store.signup`, `store.signup_toggle`, `store.admin_create`, and `store.email_verified`.

## What is refused

Public signup when the flag is off. Owner sign-in while `email_verified_at` is null. Duplicate store slug (“That store address is already taken.”). Duplicate owner email or other create failure (“That store could not be created.” — same text either way). More than five public signup attempts per client address per hour (defaults).

## How to check

Admin → Signup toggles; create via `/signup` with confirmation on and confirm refuse-until-verify; Admin → Tenants → Create store signs in without mail; `sql/prove_store_signup.sql` in the prove suite.

## Source

`docs/business-rules.md` (Self-serve store signup).

## Related

- Email sign-in finds the mall
- Platform admin portal
- When mail sends (web vs worker)
