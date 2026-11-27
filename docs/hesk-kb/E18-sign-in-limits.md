---
title: Too many sign-in attempts
category: Platform notes
hesk: published 2026-11-26
as_of: 2026-11-25
---

# Too many sign-in attempts

## As of

2026-11-25 — store and admin sign-in share one gate in `sql/000_platform.sql`. The limits are one row in `platform.sign_in_rules`. Every try is in `platform.sign_in_attempts`; remembered browsers are in `platform.sign_in_devices`.

## In one sentence

After too many wrong passwords, sign-in says "Too many sign-in attempts. Try again in a few minutes." for fifteen minutes, but a browser that signed in to that account before still gets in.

## When it happens

- Five wrong passwords for one account from one address in fifteen minutes stop that address for that account.
- Forty wrong passwords from one address across any accounts stop that address.
- Twenty wrong passwords for one account from any addresses stop new browsers for that account.
- A good sign-in or a password reset starts the count again.

## What the screens do

- A refused try shows the same sentence whether the email exists or not, and the password is not checked.
- The owner and the platform admin get one email the first time an account reaches twenty in a window.
- The Password page lists the browsers that person signed in from, marks This browser, and has Remove.
- Admin → Sign-ins lists the newest 200 tries, filtered by email, address, and what happened. Click an address to see only it.

## What to tell the customer

- Wait fifteen minutes, or use Forgot password: a reset ends the count.
- Sign in from the shop computer you normally use; it is not stopped by someone else guessing.
- If you got the email and did not cause it, change your password and remove browsers you do not know on the Password page.

## What support checks

- Admin → Sign-ins, filtered by the customer's email: look for many wrong passwords from one or many addresses.
- One address with many accounts is password spraying; it is also in the probe bans when it scans.
- Tries are kept 90 days and browsers 180 days (`attempts_kept_days`, `device_days`).
