# PC2 HTTP edge (bootstrap)

Operators and deployers: start with [hesk-kb/E12-public-origin-and-tls.md](hesk-kb/E12-public-origin-and-tls.md) and the Load section of [README.md](../README.md). This note is the technical map.

## Entry

`public/index.php` wires env, TLS, cookies, headers, coverage, and dispatch. Edge helpers live under `src/Shared/Http/`:

| Unit | Role |
|------|------|
| `EnvFile` | One dotenv parser (`bin/load-env.php` → `EnvFile::apply`) |
| `TlsSignal` | Native HTTPS + trusted-proxy `X-Forwarded-Proto` (leftmost token) |
| `RequestOrigin` | `STALL_PUBLIC_ORIGIN` or gated Host fallback |
| `RequestFactory` / `RequestPath` / `RequestUploads` / `SameHostReferer` | Request shape, path normalize, upload refuse-multi, same-host referer hygiene |
| `AssetServer` | `/assets/*` from repo `public/assets` (three levels above `src/Shared/Http`); path guard; `Content-Length`; cache |
| `RequestCoverage` | `PC_COVERAGE` only when `APP_ENV` is `test` or `coverage`; filter is repo `src/`; atomic write; `0700` dirs. `composer test:coverage` / `bin/coverage-run.php` sets `APP_ENV=coverage`, clears `var/coverage` once, then runs PHPUnit in two processes (`unit,integration` then `http`) so a long Windows Xdebug run does not leave the HTTP `php -S` probe stuck. PHPUnit writes unique `process-*.php` dumps; ProbeServer HTTP requests write `*.cov`. ProbeServer also forces `APP_ENV=coverage` whenever `PC_COVERAGE` is set, so a unit test that `putenv`’d `APP_ENV` cannot disable HTTP dumps. Each request may flush once (`enable` clears the write latch and restarts Xdebug coverage) so long-lived `php -S` workers keep accumulating dumps. An explicit `--testsuite` on `coverage-run.php` stays a single process. |
| `DatabaseConfig` | App DSN; rejects `;` in host/name; int port |
| `ErrorHandling` | `display_errors` on and off; fatal shutdown log; index discards output buffer on error; fatals and uncaught exceptions also email `STALL_PLATFORM_ADMIN_EMAIL` via `PlatformOperatorNotice` (platform Gmail/SMTP env, not mall Gmail) |

`RegisterFeatures` registers `*Controller.php` under `src/Feature`. Admin, then Root and Signup, then mall controllers — so exact paths like `/forgot` and `/signup` win over Home’s `/{slug}` catch-all. Each process memoizes the class list. When `APP_ENV=production`, it reads `var/cache/feature-controllers.php` (build with `composer build:routes` / `php bin/build-feature-routes.php`) and **re-sorts** that list on every load so a stale unordered cache cannot steal `/forgot` or `/signup`. Non-production always rescans once per process. `bin/deploy-demo.sh` runs Composer with the Plesk PHP binary, puts that binary’s directory on `PATH`, then runs `composer build:routes` after `composer install` so a reset checkout cannot keep a stale or missing manifest. The `build:routes` script uses Composer’s `@php` so it reuses the same interpreter when bare `php` is not on PATH. After migrate, deploy plants the mall owner (`STALL_OWNER_PASSWORD`) and platform admin (`STALL_PLATFORM_ADMIN_PASSWORD`) when those env values are set; both plant scripts skip a `credentials_version` bump when the password already matches. Admin forgot-password needs `STALL_GMAIL_*` or `STALL_SMTP_*` on that host (mall Settings Gmail does not send platform mail). The deploy health check curls `https://v2.perfectconsign.com/` and expects `200` (email sign-in). Old `/{slug}/login` bookmarks return `303` to `/` and must not be the probe. After `git reset --hard`, the script `exec`s itself (`DEPLOY_REEXEC=1`) so composer, migrate, and that probe run from the tip’s script — bash otherwise keeps the pre-reset inode open and can still fail with the previous probe. Manual production hosts still need the same rebuild (or delete the cache file) before traffic. `RouteInventory` plus `tests/Http/RouteSurfaceTest` keep every literal GET route reachable (no silent 404). `RouteInventory::sampleGets` expands `|` alternations (Settings hubs, catalog kinds) so each branch is requested. Owner Settings is path-based: `GET /{slug}/settings` redirects to `/{slug}/settings/money`; Money, Building, Receipt, and Backup live under `/{slug}/settings/{part}` (segmented subnav). `POST /{slug}/settings` (no hub) refuses and redirects to Money — saves go only to `/{slug}/settings/{money|building|receipt}`. The GET surface link crawl (`SurfaceLinkCrawlTest`, smoke) walks rendered same-mall hrefs for owner, cashier, books, vendor, and owner preview on slug `webprobe` only; safe form posts and Chrome JS clicks run via `composer test:surface-mutate` / `composer test:browser` on the `surface` workflow. `ChromeProbe` starts headless Chrome with `noSandbox`, `--disable-dev-shm-usage`, and a writable temp `HOME`/`XDG_*` so GitHub Actions runners do not die with “Chrome process stopped before startup completed”; set `CHROME_PATH` when the binary is not on the default search list.

Agent gate: `.cursor/rules/hostile-bootstrap.mdc` (always apply). Deploy/auth abuse gate: `.cursor/rules/deploy-and-auth-abuse.mdc` (always apply) — `DeployDemoScriptTest`, `EmailSignInLockoutTest` spray vectors, `SetOwnerIdempotentTest`. Permanent bootstrap vectors: `tests/Shared/Http/*`. Docs gate: `.cursor/rules/keep-docs-current.mdc`.

## Product defaults

- **TLS:** Honor forwarded proto only when `REMOTE_ADDR` is in `STALL_TRUSTED_PROXIES`. Unset/empty → ignore forwarded headers.
- **Origin:** Never pass `''`. Production-like `APP_ENV` refuses Host fallback.
- **Debug vs origin:** `APP_DEBUG` / local env control disclosure and `display_errors`. Host origin needs `STALL_ALLOW_HOST_ORIGIN=true` separately.
- **CSRF:** Session token + `SameSite=Lax`. Referer is back-link hygiene only.
- **HSTS:** Only when TLS is trusted; `max-age=86400`; no `includeSubDomains` / preload by default.
- **CSP:** Every response from `public/index.php` sends an enforcing `Content-Security-Policy` with `script-src 'self'` and `style-src 'self'` — no `'unsafe-inline'`, no `'unsafe-eval'`, no Report-Only. Executable JS and author CSS live under `/assets/`. Templates under `src/` must not use inline `<script>` (without `src`), `<style>`, `style=`, or `on*=` handlers. Dynamic layout uses `data-style-prop` + `data-style.js` (CSSOM). Print / auto-submit use `data-print` / `data-submit-on-change`. Permanent gates: `tests/Shared/Http/CspSurfaceTest`, `tests/Http/CspHeaderTest`.
- **First paint:** Layouts load `/assets/boot.css` before theme/app CSS. `boot.css` keeps `body` hidden until `theme.css` sets `visibility: visible`, so a slow stylesheet cannot flash the browser’s large unstyled defaults. `theme-boot.js` sets `data-css-fallback` after 2.5s if theme CSS never arrives.

| Directive | Value |
|-----------|--------|
| `default-src` | `'self'` |
| `base-uri` | `'self'` |
| `form-action` | `'self'` |
| `frame-ancestors` | `'none'` |
| `object-src` | `'none'` |
| `script-src` | `'self'` |
| `style-src` | `'self'` |
| `img-src` | `'self' data:` (sticker barcodes) |
| `font-src` | `'self'` |
| `connect-src` | `'self'` |
| `worker-src` | `'self'` |

## Demo / ProbeServer

`php -S` and HTTP probe servers stay cleartext without trusted proxies — Secure cookies stay off. HTTPS demos must set server HTTPS or list the proxy in `STALL_TRUSTED_PROXIES`.
