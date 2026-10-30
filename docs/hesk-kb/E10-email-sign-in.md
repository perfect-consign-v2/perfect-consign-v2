---
title: Email sign-in finds the mall
category: Platform notes
hesk: published 2026-10-30
as_of: 2026-10-29
---

# Email sign-in finds the mall

## As of

2026-10-29 — staff roles in the directory include cashier, manager, books, and vendor; sign-in is only at `/`; forgot-password reset mail sends in the web request through the mall mailbox.

## In one sentence

Sign in only at `/` with email and password; the platform directory resolves which mall and username to open.

## When it happens

An owner, manager, cashier, books login, or vendor opens the site root, or uses Forgot password at `/forgot` with their sign-in email. Old `/{slug}/login` and `/{slug}/forgot` bookmarks redirect to `/` and `/forgot`. Bare `/{slug}` redirects to `/` (or the role home when already signed in for that mall).

## What the books / screens do

- `platform.sign_in_directory` holds one row per owner or staff account that has a sign-in email.
- Email is unique across the whole platform (lowercased). Owner and staff share that uniqueness.
- Saving an owner or staff email updates the directory through triggers (`052` / `053`).
- The account menu Change password screen updates the password first, then the sign-in email. The mall company email is not a password-reset fallback.
- Wrong password and unknown email show the same message. Five failures in fifteen minutes for the same email and client address lock that pair. Forty failures from one client address across any emails lock that address. Twenty failures for the same email from any addresses lock the email. Older failure rows are pruned when a new failure is recorded. Mall username and platform admin failure rows prune the same way.
- `/forgot` looks up the email and, when found, sends the reset email in that request through the mall mailbox in Settings; the success message is always the same whether the email is known or not.

## What is refused

- A second account claiming an email already in the directory.
- Sign-in while the failure lock is active.
- Empty or malformed emails in the directory.
- Tenant slugs `admin`, `forgot`, `signup`, and `assets`.

## How to check

Plant an owner with `STALL_OWNER_EMAIL`, open `/`, sign in with that email. Open `/{slug}/login` and confirm it redirects to `/`. Try the same email on another mall’s staff — refused. Run `sql/prove_sign_in_directory.sql`.

## Source

`docs/business-rules.md` — Sign-in. `sql/000_platform.sql`, `sql/001_tenant.sql`, `sql/prove_sign_in_directory.sql`. `src/Bootstrap/EmailSignIn.php`.

## Related

- Platform admin portal
- Self-serve store signup
- When mail sends (web vs worker)
- Public origin, proxies, TLS, and page script policy
