---
title: Public origin, proxies, TLS, and page script policy
category: Platform notes
hesk: published 2026-11-12
as_of: 2026-11-12
---

# Public origin, proxies, TLS, and page script policy

## As of

2026-11-12 — DocumentRoot must be `public/`; repo-root `.htaccess` denies `.env`, `.github/`, `composer.phar` / `*.phar`, and app paths; `public/.htaccess` skips HTTPS redirect when `X-Forwarded-Proto` is already `https` only when the proxy (or `mod_remoteip`) strips client-spoofed headers — PHP still ignores forwarded proto unless `REMOTE_ADDR` is in `STALL_TRUSTED_PROXIES`; schema installers are `000_platform.sql`, `001_tenant.sql`, and later files listed in `SchemaCatalog::TENANT` (stamped bytes immutable; disposable demo may `STALL_ALLOW_PLATFORM_REINSTALL` then wipe+reprovision — deploy recovers `MigrationFileChanged`; seed-demo skips migrate until after wipe); nightly backups refuse a web-reachable `STALL_BACKUP_DIR`.

## In one sentence

Password-reset and magic links always use the configured public site origin; Secure cookies and HSTS only turn on when TLS is actually trusted; browsers only run same-origin scripts and styles shipped under `/assets/`.

## When it happens

You deploy PC2 behind HTTPS, a reverse proxy, or `php -S` for a local demo; or you debug a reset link that points at the wrong host.

## What the books / screens do

- Apache DocumentRoot is `public/`. Do not serve the repository root. If the vhost is wrong, repo-root `.htaccess` also denies `.github/` and `composer.phar` / `*.phar`.
- `STALL_PUBLIC_ORIGIN` is the canonical origin (`https://mall.example` or `http://127.0.0.1:8096`). Outbound links use that value, not a forged `Host` header.
- Host-derived origin is allowed only when `STALL_ALLOW_HOST_ORIGIN=true` and the env is not production-like (`local` / `development` / `dev`, or `APP_DEBUG=true` outside `production` / `prod`). Production-like env always needs `STALL_PUBLIC_ORIGIN`.
- `STALL_TRUSTED_PROXIES` lists proxy IPs or IPv4 CIDRs. Only those peers may supply `X-Forwarded-Proto` for Secure cookies and HSTS, and the rightmost untrusted hop of `X-Forwarded-For` for lockouts and audit. If the list is empty, forwarded proto and address are ignored; only the server’s own HTTPS signal and `REMOTE_ADDR` count. `bin/deploy-demo.sh` refuses an `https://` public origin when that list is empty and the live origin does not already set a Secure session cookie.
- Apache’s HTTPS rewrite also trusts `X-Forwarded-Proto: https` to avoid redirect loops behind a TLS terminator. That trust is ops wiring: `mod_remoteip` (or equivalent) must strip client-spoofed `X-Forwarded-Proto` before Apache sees it. PHP never trusts that header from an unlisted peer.
- Schema installers are `sql/000_platform.sql` and `sql/001_tenant.sql` only. Stamped bytes are immutable. Deploy never forgets stamps or refreshes checksums. `MigrationFileChanged` means a new forward file or wipe + reprovision (`STALL_ALLOW_PLATFORM_REINSTALL` on disposable demos; that flag alone is the wipe gate). Deploy recovers when the flag is set; `seed-demo` lands tip with migrate skipped, then wipe + seed.
- When TLS is trusted, HSTS is sent as `max-age=86400` without `includeSubDomains`. Mutating POSTs still need a session CSRF token; Referer is not CSRF.
- Every page sends an enforcing Content-Security-Policy: scripts and styles must come from the same origin under `/assets/` (no inline script or style tags, no `style=` attributes, no `onclick=` handlers). Barcode sticker images may use `data:` URLs.
- Pages load a tiny `boot.css` before the theme so the browser does not flash oversized unstyled text while theme CSS is still arriving.
- Log and coverage directories are mode `0700`. Rotate `var/log/app.log` with logrotate (or equivalent), or clear it from Admin → App log (audited).

## What is refused

- Spoofed `X-Forwarded-Proto: https` from a peer not on the trusted list (no Secure cookie, no HSTS).
- Empty public origin in production-like env.
- Coverage dumps when `APP_ENV` is not `test` or `coverage`. (`composer test:coverage` sets `APP_ENV=coverage`, clears dumps once, then runs unit/integration and http as separate PHPUnit processes so ProbeServer HTTP dumps are collected.)
- Inline page script or author CSS (the browser CSP blocks it; the app test suite also refuses it in templates).
- Editing an already-stamped migration file and papering over it with a checksum refresh.

## How to check

Set `STALL_PUBLIC_ORIGIN`, request a password reset, and confirm the mailed link host matches that origin even if `Host` is forged. Behind a proxy, list the proxy in `STALL_TRUSTED_PROXIES` and confirm Secure cookies and the client address used for lockouts; without the list, a forged forwarded-proto header must not set Secure. Local `php -S` without HTTPS correctly keeps Secure off. View response headers for `Content-Security-Policy` with `script-src 'self'` and without `unsafe-inline`.

## Source

`README.md` — Load / Application. `docs/http-edge.md`. `.cursor/rules/hostile-bootstrap.mdc`. `src/Shared/Http/TlsSignal.php`, `RequestOrigin.php`, `RequestFactory.php`, `public/index.php`.

## Related

- Email sign-in finds the mall
- Platform admin portal
