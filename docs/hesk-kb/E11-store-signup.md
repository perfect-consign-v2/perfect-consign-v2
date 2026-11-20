---
title: Self-serve store signup
category: Platform notes
hesk: published 2026-11-20
as_of: 2026-11-19
---

# Self-serve store signup

## As of

2026-11-19 — Create your store always collects the password. A rejected signup keeps the store name, email, and both password fields, and shows the error in red. “That store could not be created.” stays generic on the form. Admin → App log records the store address, the email, and the database reason. When email confirmation is on, the link confirms the email and opens the store. Opening the link does not verify the address. An expired form links to Try again and Sign in, and to the store when the session already belongs to one. Admin can still hand-create a store with a password and a verified email.

## In one sentence

Operators enable public signup so a new mall can be created from the site. The owner chooses the password on Create your store. When confirmation is on, the email link opens the store.

## When it happens

When Admin → Signup has “Allow public store signup” on. New malls can also be created from Admin → Tenants → Create store (always verified) or CLI provision + set-owner.

## What the books / screens do

- Root shows Create your store only when public signup is on.
- `/signup` creates the tenant and owner. The form always asks for the store name, email, and password. A rejected signup keeps those fields filled and shows the error in red. When “Require email confirmation” is on, the confirmation email sends in that request through the platform Gmail/SMTP env. The link opens a confirm page. Submitting that page verifies the address and signs the owner in. Opening the link does not verify the address and does not sign the owner in. When confirmation is off, the same form signs the owner in. A form whose session check fails shows Try again and Sign in, and Open your store when the session already belongs to a store.
- `platform.owners.email_verified_at` records when the email was confirmed (or set at admin/CLI create).
- Admin audit records `store.signup`, `store.signup_toggle`, `store.admin_create`, and `store.email_verified`.

## What is refused

Public signup when the flag is off. Owner sign-in while `email_verified_at` is null. Duplicate store slug (“That store address is already taken.”). Duplicate owner email or other create failure (“That store could not be created.” — same text either way). That failure is written to the app log with the store address, the email, and the database reason. More than five public signup attempts per client address per hour (defaults).

## How to check

Admin → Signup toggles; create via `/signup` with confirmation on and confirm refuse-until-verify; Admin → Tenants → Create store signs in without mail; `sql/prove_store_signup.sql` in the prove suite.

## Source

`docs/business-rules.md` (Self-serve store signup).

## Related

- Email sign-in finds the mall
- Platform admin portal
- When mail sends (web vs worker)
