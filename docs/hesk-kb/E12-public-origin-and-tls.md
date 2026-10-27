---
title: Public origin, proxies, and Secure cookies
category: Platform notes
hesk: published 2026-10-27
as_of: 2026-10-27
---

# Public origin, proxies, and Secure cookies

## As of

2026-10-27

## In one sentence

Password-reset and magic links always use the configured public site origin; Secure cookies and HSTS only turn on when TLS is actually trusted.

## When it happens

You deploy PC2 behind HTTPS, a reverse proxy, or `php -S` for a local demo; or you debug a reset link that points at the wrong host.

## What the books / screens do

- `STALL_PUBLIC_ORIGIN` is the canonical origin (`https://mall.example` or `http://127.0.0.1:8096`). Outbound links use that value, not a forged `Host` header.
- Host-derived origin is allowed only when `STALL_ALLOW_HOST_ORIGIN=true` and the env is not production-like (`local` / `development` / `dev`, or `APP_DEBUG=true` outside `production` / `prod`). Production-like env always needs `STALL_PUBLIC_ORIGIN`.
- `STALL_TRUSTED_PROXIES` lists proxy IPs or IPv4 CIDRs. Only those peers may supply `X-Forwarded-Proto` for Secure cookies and HSTS. If the list is empty, forwarded proto is ignored; only the server’s own HTTPS signal counts.
- When TLS is trusted, HSTS is sent as `max-age=86400` without `includeSubDomains`. Mutating POSTs still need a session CSRF token; Referer is not CSRF.
- Log and coverage directories are mode `0700`. Rotate `var/log/app.log` outside the app.

## What is refused

- Spoofed `X-Forwarded-Proto: https` from a peer not on the trusted list (no Secure cookie, no HSTS).
- Empty public origin in production-like env.
- Coverage dumps when `APP_ENV` is not `test` or `coverage`.

## How to check

Set `STALL_PUBLIC_ORIGIN`, request a password reset, and confirm the mailed link host matches that origin even if `Host` is forged. Behind a proxy, list the proxy in `STALL_TRUSTED_PROXIES` and confirm Secure cookies; without the list, a forged forwarded-proto header must not set Secure. Local `php -S` without HTTPS correctly keeps Secure off.

## Source

`README.md` — Load / Application. `.cursor/rules/hostile-bootstrap.mdc`. `src/Shared/Http/TlsSignal.php`, `RequestOrigin.php`, `public/index.php`.

## Related

- Email sign-in finds the mall
- Platform admin portal
