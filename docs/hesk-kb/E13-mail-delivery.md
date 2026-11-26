---
title: When mail sends (web vs worker)
category: Platform notes
hesk: published 2026-11-20
as_of: 2026-11-19
---

# When mail sends (web vs worker)

## As of

2026-11-19 — Admin → Mail worker lists the open queue and jobs given up after five attempts, and warns when the worker heartbeat is older than two minutes. A worker pass emails the platform admin once for a job still open more than fifteen minutes past due, and once when a job is given up. 2026-11-18 — a refused send is logged with the SMTP status, the stage, and the server's reply. That reply is also in the operator notice.

## In one sentence

Some messages leave the moment the screen finishes; other store mail is filed first and leaves only when the mail worker runs.

## When it happens

An owner resets a password, a cashier emails a receipt, a consignor entry link is requested, the owner files a vendor letter, or nightly sale/digest/rent jobs enqueue mail.

## What the books / screens do

- **Immediate (web request):** mall password reset and receipt email (mall Settings mailbox); platform admin forgot-password and the signup link (platform Gmail/SMTP env); platform operator notices for mail failure or HTTP 500. Mall `/forgot` keeps the same user success flash when a known account’s reset mail fails; operators still get that platform notice.
- **Filed, then worker:** the "Your store is ready" email after signup (platform mailbox), consignor magic link, owner vendor letter, sale notice, Sunday digest, rent reminder. The letter desk shows Waiting until the worker marks Sent or Bounced. The Sunday digest starts off until Building turns Weekly vendor letter on. When it is on, the digest and the rent reminder are plain text to the vendor’s own email: name, Payable, and Rent you owe. Sunday includes a vendor who owes nothing. The rent reminder waits for the rent-cycle day and skips a vendor who owes nothing. A sale notice that day is subject Sold today, with one line per tag sold. Saving money leaves the Sunday switch as it is.
- The store home page says when that store has work waiting to run. Another store’s queue, and mail that has already gone out, stay off that page.
- Admin → Mail worker shows whether `STALL_WORKER_PASSWORD` is set, whether the last `php bin/work.php` heartbeat is missing or older than two minutes, whether site SMTP and platform Gmail are set, each open job, jobs given up after five attempts, waiting outbox rows, and a pruneable run log. A worker pass emails the platform admin once when a job stays open more than fifteen minutes past due, and once when a job is given up. The host runs `php bin/work.php --forever`.
- Store-facing mail uses each mall’s Settings mailbox. Platform mail uses the platform Gmail/SMTP env.
- A refused send is logged with the SMTP status, the stage, and the server's reply. The operator notice includes that reply.

## What is refused

- Expecting filed store mail to leave without a worker pass.
- Using the mall Gmail row for platform admin reset, signup verify, or operator notices.

## How to check

Request a password reset and confirm the message arrives without starting the worker. File a vendor letter and confirm Waiting until `php bin/work.php` runs. Open Admin → Mail worker and confirm the last run, whether that heartbeat is stale, the open jobs, and the site SMTP line.

## Source

`docs/business-rules.md` — Floor, sales, and checks; Platform admin. `README.md` — Application. `src/Shared/Mail/MailOutbox.php`, `src/Shared/Jobs/OutboundMailJob.php`.

## Related

- Platform admin portal
- Email sign-in finds the mall
- Self-serve store signup
- Vendor portal sees only one vendor
