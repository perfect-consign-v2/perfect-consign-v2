---
title: Self-serve store signup
category: Platform notes
hesk: published 2026-11-19
as_of: 2026-11-16
---

# Self-serve store signup

## As of

2026-11-16 — when email confirmation is on, `/signup` collects the store name and email only. The owner chooses the password on the confirmation page. Opening the link does not verify the address. Admin can still hand-create a store with a password and a verified email.

## In one sentence

Operators enable public signup so a new mall can be created from the site. When confirmation is on, the owner chooses the password on the confirmation page before sign-in.

## When it happens

When Admin → Signup has “Allow public store signup” on. New malls can also be created from Admin → Tenants → Create store (always verified) or CLI provision + set-owner.

## What the books / screens do

- Root shows Create your store only when public signup is on.
- `/signup` creates the tenant and owner. When “Require email confirmation” is on, the form asks for the store name and email only. The confirmation email sends in that request through the platform Gmail/SMTP env. The link opens a password form. Submitting that form verifies the address and sets the password. Opening the link does not verify the address and does not sign the owner in. When confirmation is off, the form still asks for a password and signs the owner in.
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
