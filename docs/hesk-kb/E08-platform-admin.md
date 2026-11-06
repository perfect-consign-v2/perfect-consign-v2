---
title: Platform admin portal
category: Platform notes
hesk: published 2026-11-06
as_of: 2026-11-02
---

# Platform admin portal

## As of

2026-11-02 — tenant wipe needs a second platform admin and a seven-day owner backup; admin sign-in spray/email lockouts mirror email sign-in; wipe audit `ok` only after drop succeeds.

## In one sentence

`/admin` is for operators: sign in, reset passwords, enter a store, wipe or hand-create a tenant, toggle public signup, watch the mail worker, view or clear the application log, and read an append-only admin audit. Mail failures and server 500s are emailed to the platform admin address.

## When it happens

You operate Perfect Consign across malls, not while ringing a sale as a cashier.

## What the books / screens do

- Admin accounts with email + password hash.
- Password-reset tokens expire; reset and audit rows are append-only. Admin forgot-password sends the reset email in that request through the platform Gmail/SMTP env (`STALL_GMAIL_*` or `STALL_SMTP_*`), not the mall Settings mailbox. Demo deploy plants the admin from `STALL_PLATFORM_ADMIN_EMAIL` / `STALL_PLATFORM_ADMIN_PASSWORD` when those are set.
- Enter-as opens the tenant store in a new browser window (one button per owner username when the mall has co-owners; cashier and manager when those staff rows exist; manager lands on the register); a suspended owner is refused; the admin Tenants list stays in the original tab.
- Tenants → Create store provisions a mall with the owner email already verified (works even when public signup is off).
- Admin → Signup turns public `/signup` on or off and whether email confirmation is required before owner sign-in.
- Tenant wipe requires the store slug typed exactly, a second different platform admin’s email and password, and an owner backup recorded for that store within seven days; then removes that mall’s password-reset and email-verification rows and drops the store. Audit `tenant.delete` / `ok` lands only after the drop succeeds.
- Admin sign-in lockout: five failures for the same email+address, forty spray failures from one address, twenty failures for one email across addresses (fifteen-minute window); stale failure rows prune on insert.
- Gmail/SMTP send failures are logged and emailed to `STALL_PLATFORM_ADMIN_EMAIL`.
- Uncaught exceptions and fatal errors (HTTP 500) are logged and emailed to that same address.
- Mail worker shows whether `STALL_WORKER_PASSWORD` is set, last `php bin/work.php` run, waiting outbox mail, and a prune/clear run log.
- App log shows the newest slice of `var/log/app.log` (signup failures and other PSR-3 errors) and can empty that file; clear is audited.

## What is refused

- Bad admin email format.
- Unknown admin emails still hit the sign-in lockout table (rows older than fifteen minutes are pruned) but do not write `admin.sign_in_refused` to the append-only audit; wrong password for a known admin still does.
- Wipe without a second admin, without a recent owner backup, or while password-reset rows still block the drop.
- Editing or deleting append-only reset / audit history.

## How to check

Reset flow creates a token row; using it does not leave editable history holes. Audit lists enter-as / wipe / `app.log_clear` events. One admin alone on wipe: refused. App log shows a planted line after a failed signup, then Clear empties the file.

## Source

`docs/business-rules.md` — Platform admin. `sql/prove_platform_admin.sql`.

## Related

- Email sign-in finds the mall
- Self-serve store signup
- When mail sends (web vs worker)
- Public origin, proxies, TLS, and page script policy
- What Stall / perfect-consign is today vs full mall screens
- Vendor portal sees only one vendor
