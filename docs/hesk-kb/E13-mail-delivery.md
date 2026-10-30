---
title: When mail sends (web vs worker)
category: Platform notes
hesk: published 2026-10-30
as_of: 2026-10-30
---

# When mail sends (web vs worker)

## As of

2026-10-30 — password-reset and receipt mail send in the web request; other store mail waits in the outbox for `php bin/work.php`. Platform admin reset and signup verify also send in the request through `STALL_GMAIL_*` / `STALL_SMTP_*` (not the mall Settings mailbox). Operator notices for failed Gmail or HTTP 500s stay immediate.

## In one sentence

Some messages leave the moment the screen finishes; other store mail is filed first and leaves only when the mail worker runs.

## When it happens

An owner resets a password, a cashier emails a receipt, a consignor entry link is requested, the owner files a vendor letter, or nightly sale/digest/rent jobs enqueue mail.

## What the books / screens do

- **Immediate (web request):** mall password reset and receipt email (mall Settings mailbox); platform admin forgot-password and signup confirmation (platform Gmail/SMTP env); platform operator notices for mail failure or HTTP 500.
- **Filed, then worker:** consignor magic link, owner vendor letter, sale notice, Sunday digest, rent reminder. The letter desk shows Waiting until the worker marks Sent or Bounced.
- Admin → Mail worker shows whether `STALL_WORKER_PASSWORD` is set, the last `php bin/work.php` run, waiting outbox rows, and a pruneable run log.
- Store-facing mail uses each mall’s Settings mailbox. Platform mail uses the platform Gmail/SMTP env.

## What is refused

- Expecting filed store mail to leave without a worker pass.
- Using the mall Gmail row for platform admin reset, signup verify, or operator notices.

## How to check

Request a password reset and confirm the message arrives without starting the worker. File a vendor letter and confirm Waiting until `php bin/work.php` runs. Open Admin → Mail worker and confirm last run and waiting count.

## Source

`docs/business-rules.md` — Floor, sales, and checks; Platform admin. `README.md` — Application. `src/Shared/Mail/MailOutbox.php`, `src/Shared/Jobs/OutboundMailJob.php`.

## Related

- Platform admin portal
- Email sign-in finds the mall
- Self-serve store signup
- Vendor portal sees only one vendor
