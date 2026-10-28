---
title: Self-serve store signup
category: Platform notes
hesk: published 2026-10-28
as_of: 2026-10-27
---

# Self-serve store signup

## As of

2026-10-27 — **not in PC2 product code yet.** The root page may reserve a Create your store link behind a hard-off flag; there is no Signup admin feature or `/signup` flow to turn on.

## In one sentence

Planned: operators enable public signup so a new mall can be created from the site with store name, owner email, and password.

## When it happens

Not yet. New malls are still provisioned with the CLI (`php bin/provision-tenant.php` / seeds) and `set-owner`.

## What the books / screens do

Email sign-in at `/` is live. Public self-serve provision is not.

## What is refused

Treating this article as live ops guidance, or expecting Admin → Signup settings.

## How to check

Confirm `src/Feature/Signup` and `sql/054_store_signup.sql` are absent until this article is rewritten for a shipped fact.

## Source

Intent only. Not in `docs/business-rules.md` until shipped.

## Related

- Email sign-in finds the mall
- Platform admin portal
