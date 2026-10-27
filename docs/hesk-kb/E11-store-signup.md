---
title: Self-serve store signup
category: Platform notes
hesk: published 2026-10-27
as_of: 2026-10-26
---

# Self-serve store signup

## As of

2026-10-26

## In one sentence

When operators turn public signup on, anyone can create a mall from `/signup` with a store name, owner email, and password.

## When it happens

Admin → Signup is enabled. The root sign-in page shows Create your store. Signup starts off until an operator turns it on.

## What the books / screens do

- Asks for store name, owner email, and password (eight characters minimum, confirmation must match).
- Builds the web address (slug) from the store name.
- Provisions the mall the same way the CLI provisioner does, then signs the owner in.
- Owner email lands in the platform sign-in directory so `/` email login works afterward.
- Too many tries from one client address are refused for an hour.

## What is refused

- Signup while the feature is off.
- Weak or mismatched passwords.
- Duplicate store slug or recovery email already used on another sign-in.
- Rate-limit window after too many attempts.
- Leaving a half-built mall if provision fails.

## How to check

Enable signup in admin, submit Create your store with a unique email, land on the new mall dashboard, then sign out and sign in again at `/` with that email.

## Source

`docs/business-rules.md` — Sign-in / Platform admin. Signup feature and platform settings.

## Related

- Email sign-in finds the mall
- Platform admin portal
