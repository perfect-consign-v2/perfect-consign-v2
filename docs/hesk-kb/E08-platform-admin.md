---
title: Platform admin portal
category: Platform notes
hesk: published 2026-10-29
as_of: 2026-10-28
---

# Platform admin portal

## As of

2026-10-28 — Admin → App log, Mail worker, Signup toggles, and Tenants → Create store are live. Tenant wipe clears that mall’s password-reset rows. Admin forgot-password sends in the web request.

## In one sentence

`/admin` is for operators: sign in, reset passwords, enter a store, wipe or hand-create a tenant, toggle public signup, watch the mail worker, view or clear the application log, and read an append-only admin audit. Mail failures and server 500s are emailed to the platform admin address.

## When it happens

You operate Perfect Consign across malls, not while ringing a sale as a cashier.

## What the books / screens do

- Admin accounts with email + password hash.
- Password-reset tokens expire; reset and audit rows are append-only. Admin forgot-password sends the reset email in that request through the platform Gmail/SMTP env.
- Enter-as owner or cashier into a tenant store.
- Tenants → Create store provisions a mall with the owner email already verified (works even when public signup is off).
- Admin → Signup turns public `/signup` on or off and whether email confirmation is required before owner sign-in.
- Tenant wipe removes that mall’s password-reset and email-verification rows (append-only elsewhere), then drops the store; wipe and other admin actions land in the admin audit.
- Gmail/SMTP send failures are logged and emailed to `STALL_PLATFORM_ADMIN_EMAIL`.
- Uncaught exceptions and fatal errors (HTTP 500) are logged and emailed to that same address.
- Mail worker shows whether `STALL_WORKER_PASSWORD` is set, last `php bin/work.php` run, waiting outbox mail, and a prune/clear run log.
- App log shows the newest slice of `var/log/app.log` (signup failures and other PSR-3 errors) and can empty that file; clear is audited.

## What is refused

- Bad admin email format.
- Editing or deleting append-only reset / audit history.

## How to check

Reset flow creates a token row; using it does not leave editable history holes. Audit lists enter-as / wipe / `app.log_clear` events. App log shows a planted line after a failed signup, then Clear empties the file.

## Source

`docs/business-rules.md` — Platform admin. `sql/prove_platform_admin.sql`.

## Related

- Email sign-in finds the mall
- Self-serve store signup
- When mail sends (web vs worker)
- Public origin, proxies, TLS, and page script policy
- What Stall / perfect-consign is today vs full mall screens
- Vendor portal sees only one vendor

