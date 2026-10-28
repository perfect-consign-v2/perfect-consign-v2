---
title: Email sign-in finds the mall
category: Platform notes
hesk: published 2026-10-28
as_of: 2026-10-28
---

# Email sign-in finds the mall

## As of

2026-10-28

## In one sentence

Sign in at `/` with email and password; the platform directory resolves which mall and username to open.

## When it happens

An owner or cashier lands on the site root, or uses Forgot password with their recovery email. Username login at `/{slug}/login` remains for bookmarks and deep links.

## What the books / screens do

- `platform.sign_in_directory` holds one row per owner or staff account that has a recovery email.
- Email is unique across the whole platform (lowercased). Owner and staff share that uniqueness.
- Saving an owner or staff recovery email updates the directory through triggers (`052` / `053`).
- Wrong password and unknown email show the same message. Five failures in fifteen minutes for the same email and client address lock that pair. Forty failures from one client address across any emails lock that address. Twenty failures for the same email from any addresses lock the email. Older failure rows are pruned when a new failure is recorded.
- `/forgot` is a platform path (not a mall slug). It looks up the email and starts a reset only when a directory row exists; the sent message is always the same.

## What is refused

- A second account claiming an email already in the directory.
- Sign-in while the failure lock is active.
- Empty or malformed recovery emails in the directory.
- Tenant slugs `admin`, `forgot`, `signup`, and `assets`.

## How to check

Plant an owner with `STALL_OWNER_EMAIL`, open `/`, sign in with that email. Open `/{slug}/login` with username and the same password. Try the same email on another mall’s staff — refused. Run `sql/prove_sign_in_directory.sql`.

## Source

`docs/business-rules.md` — Sign-in. `sql/000_platform.sql`, `sql/001_tenant.sql`, `sql/prove_sign_in_directory.sql`. `src/Bootstrap/EmailSignIn.php`.

## Related

- Platform admin portal
- Public origin, proxies, and Secure cookies
