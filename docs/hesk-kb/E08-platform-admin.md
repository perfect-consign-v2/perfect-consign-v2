---
title: Platform admin portal
category: Platform notes
hesk: published 2026-11-26
as_of: 2026-11-20
---

# Platform admin portal

## As of

2026-11-20 — The signed-in platform header shows the server date and time on the left, and the seconds keep moving while the page stays open. Platform admin authenticator is on until a signed-in admin turns it off under Authenticator. Off skips the code and leaves the phone setup (`admin.mfa_disabled`, current password plus a current code). On is the default (`admin.mfa_enabled`). While it is on, sign-in is email, password, and a 6-digit authenticator code (or one recovery code). The first sign-in shows a QR code the phone scans and the key in groups of four, then ten recovery codes, and stays closed until those codes are confirmed. A password reset leaves the authenticator on. `php bin/clear-admin-mfa.php email` removes the phone setup and is audited as `admin.mfa_cleared`. `/admin` is an empty 404. A scanner user-agent is banned on the first request. Twenty probe hits from one client address within ten minutes ban it for fifteen minutes, every time. A scanner ban lasts fifteen minutes, and a repeat within seven days lasts an hour, then a day. Missing records, pages a role cannot open, and files browsers and phones ask for on their own never count, and someone signed in is never counted or refused. App log, or `php bin/lift-probe-ban.php address admin-email`, ends a ban now (`probe_ban_lift`). Mail worker lists each open job and each job given up after five attempts, warns when the heartbeat is older than two minutes, and shows whether site SMTP and platform Gmail are set. A worker pass emails the platform admin once for a job still open more than fifteen minutes past due, and once when a job is given up. Outside production, deleting a store asks for the slug only. Production still requires a second platform admin password and a recent owner backup.

## In one sentence

`STALL_ADMIN_PATH` is for operators: sign in with email and password, and an authenticator code while Authenticator is on, turn that requirement on or off, reset passwords, enter a store, wipe or hand-create a tenant, toggle public signup, watch the mail worker, view or clear the application log, lift a probe ban, and read Operator audit. That page is operator actions only. A store's activity is `/{slug}/activity`, not this log. `/admin` is an empty 404. Mail failures and server 500s are emailed to the platform admin address.

## When it happens

You operate Perfect Consign across malls, not while ringing a sale as a cashier.

## What the books / screens do

- The signed-in platform header shows the server date and time on the left. The seconds keep moving while the page stays open.
- Admin accounts with email + password hash, plus a sealed authenticator secret (`STALL_ADMIN_MFA_KEY`, not the mailbox key). The enroll screen shows a QR code the phone scans, and the same key in groups of four when the camera cannot. The issuer is PC2 and the account name is the admin email. Ten recovery codes are shown once and stored only as hashes.
- Authenticator starts on. A signed-in admin turns it off or on at Authenticator. Off requires the current password and, when a phone is set up, a current code, and does not remove that phone. On again asks for a code from the same phone, or shows the QR code when no phone is set up. An admin with no phone set up cannot turn it off. The switch is audited as `admin.mfa_disabled` or `admin.mfa_enabled`.
- While authenticator is on, a correct password opens a short pending sign-in, not Tenants. Tenants, wipe, enter-as, and the log stay closed until the authenticator code succeeds and, on first enrollment, the operator confirms the recovery codes were saved. Logout and a failed session check clear that pending sign-in. While it is off, a correct password opens Tenants.
- Password-reset tokens expire; reset and audit rows are append-only. A password reset does not clear the authenticator, the last-used step, recovery codes, or authenticator-failure rows. Admin forgot-password sends the reset email in that request through the platform Gmail/SMTP env (`STALL_GMAIL_*` or `STALL_SMTP_*`), not the mall Settings mailbox. Demo deploy plants the admin from `STALL_PLATFORM_ADMIN_EMAIL` / `STALL_PLATFORM_ADMIN_PASSWORD` when those are set, and runs `bin/ensure-admin-mfa-key.php` beside the mail key. A matching password plant leaves the authenticator secret in place.
- Enter-as opens the tenant store in a new browser window (one button per owner username when the mall has co-owners; cashier and manager when those staff rows exist; manager lands on the register); a suspended owner is refused; the admin Tenants list stays in the original tab.
- Tenants → Create store queues a store with the owner email already confirmed; the worker builds it and emails the owner (works even when public signup is off).
- Admin → Signups turns public `/signup` on or off, lists the signups with Resend link and Discard, and lists older stores whose owner never confirmed the email.
- When `APP_ENV` is `production` or `prod`, tenant wipe requires the store slug typed exactly, a second different platform admin’s email and password, and an owner backup recorded for that store within seven days. Otherwise, including local development when `APP_ENV` is unset, typing the slug is enough. Password-reset and email-verification rows stay, and they do not block the drop. Audit `tenant.delete` / `ok` lands only after the drop succeeds.
- When a second signature is required, wrong second-admin passwords on the delete form lock that form for fifteen minutes: five failures for that email and client address, or forty failures from that address across emails. Those failures are not admin sign-in failures. There is no email-wide cap, so a signed-in admin cannot lock the other admin out of the platform door. Unknown email and a wrong password count the same. A successful second signature clears that pair.
- Admin sign-in lockout: five failures for the same email+address, forty spray failures from one address, twenty failures for one email across addresses (fifteen-minute window); stale failure rows prune on insert. Authenticator failures use a separate table with the same three limits. A new browser does not reset that budget. An accepted code clears that email's authenticator failures. A password success does not.
- `php bin/clear-admin-mfa.php email` is the owner break-glass for a lost phone. It clears the secret for that email, burns unused recovery codes, and writes `admin.mfa_cleared`. The web role cannot clear the secret. The Authenticator screen turns the requirement on or off and leaves the secret in place.
- Gmail/SMTP send failures are logged with the server's reply text and emailed to `STALL_PLATFORM_ADMIN_EMAIL`. The notice includes that reply.
- Uncaught exceptions and fatal errors (HTTP 500) are logged and emailed to that same address.
- Mail worker shows whether `STALL_WORKER_PASSWORD` is set, whether the last `php bin/work.php` heartbeat is missing or older than two minutes, whether site SMTP and platform Gmail are set, each open job (store, kind, due time, attempts, subject, recipients, transport, last error), jobs given up after five attempts, waiting outbox mail, and a prune/clear run log. A worker pass emails `STALL_PLATFORM_ADMIN_EMAIL` once when a job stays open more than fifteen minutes past due, and once when a job is given up. A stopped worker cannot send that mail. The host runs `php bin/work.php --forever`.
- App log shows the newest slice of `var/log/app.log` (signup failures and other PSR-3 errors) and can empty that file; clear is audited. The same page lists probe bans and lifts one address (`probe_ban_lift`).
- A scanner user-agent (ZAP, sqlmap, nikto, nuclei, nessus, nmap, masscan, dirbuster, gobuster, ffuf, feroxbuster, wpscan, acunetix, openvas, zgrab, as a whole token) is banned on the first hit. Twenty probe hits (`/admin`, a probe-shaped path, or a path no route knows) from one client address within ten minutes ban it. Every ban ends. A ban for probe hits always lasts fifteen minutes. A scanner ban lasts fifteen minutes, then an hour, then a day for repeats within seven days. Each morning at seven (Los Angeles time) the platform admin gets one email listing the bans from the day before: address, why, ban number, path, start, and end. No bans, no email. Missing records, pages a role cannot open, missing `/assets/` files, and `/.well-known/`, icon, and manifest requests do not count. Someone signed in is never counted or refused. `/admin` never shows the sign-in form.

## What is refused

- Bad admin email format.
- Unknown admin emails still hit the sign-in lockout table (rows older than fifteen minutes are pruned) but do not write `admin.sign_in_refused` to the append-only audit; wrong password for a known admin still does.
- A wrong authenticator code, a code from outside the one-step window, and a bad recovery code. A code already used in that 30-second step. A pending sign-in opening Tenants before the code succeeds while authenticator is on. Turning authenticator off without the current password, or without a current code when a phone is set up. A wrong password on that switch counts toward the admin sign-in lock. An admin with no phone set up turning it off. Emailed codes are not offered. Mall owner and staff sign-in stay password-only.
- In production: wipe without a second admin, without a recent owner backup, or after the delete form is locked by too many second-admin guesses. Outside production: wipe when the typed slug does not match.
- Editing or deleting append-only reset / audit history.

## How to check

Reset flow creates a token row; using it does not leave editable history holes. Audit lists enter-as / wipe / `app.log_clear` / `probe_ban_lift` events. One admin alone on wipe: refused. App log shows a planted line after a failed signup, then Clear empties the file.

## Source

`docs/business-rules.md` — Platform admin. `sql/prove_platform_admin.sql`. `sql/prove_admin_mfa.sql`.

## Related

- Email sign-in finds the mall
- Self-serve store signup
- When mail sends (web vs worker)
- Public origin, proxies, TLS, and page script policy
- What Stall / perfect-consign is today vs full mall screens
- Vendor portal sees only one vendor
