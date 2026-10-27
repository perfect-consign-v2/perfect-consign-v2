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
| `AssetServer` | `/assets/*` with path guard, `Content-Length`, cache |
| `RequestCoverage` | `PC_COVERAGE` only when `APP_ENV` is `test` or `coverage`; atomic write; `0700` dirs |
| `DatabaseConfig` | App DSN; rejects `;` in host/name; int port |
| `ErrorHandling` | `display_errors` on and off; fatal shutdown log; index discards output buffer on error |

Agent gate: `.cursor/rules/hostile-bootstrap.mdc` (always apply). Permanent vectors: `tests/Shared/Http/*`.

## Product defaults

- **TLS:** Honor forwarded proto only when `REMOTE_ADDR` is in `STALL_TRUSTED_PROXIES`. Unset/empty → ignore forwarded headers.
- **Origin:** Never pass `''`. Production-like `APP_ENV` refuses Host fallback.
- **Debug vs origin:** `APP_DEBUG` / local env control disclosure and `display_errors`. Host origin needs `STALL_ALLOW_HOST_ORIGIN=true` separately.
- **CSRF:** Session token + `SameSite=Lax`. Referer is back-link hygiene only.
- **HSTS:** Only when TLS is trusted; `max-age=86400`; no `includeSubDomains` / preload by default.

## Demo / ProbeServer

`php -S` and HTTP probe servers stay cleartext without trusted proxies — Secure cookies stay off. HTTPS demos must set server HTTPS or list the proxy in `STALL_TRUSTED_PROXIES`.
