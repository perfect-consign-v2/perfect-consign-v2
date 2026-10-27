# Perfect Consign

This is the product copy of the Stall baseline at `27e5e88a740e676024268fdf2ebf2e09ba3bad87`. Stall stays the baseline and is not edited from this tree.

No hacks, no shortcuts, no workarounds, and no patches. Fix the design that is wrong. Do not leave a second calculation in application code, or nudge a date or flag so that calculation happens to match the database. Money and balances come from the view or function that owns them.

## Database

PHP `8.5`. Postgres `18.6`. Host port **5436**. Database `consign`. User `stall`. Volume `perfect_consign_pg`. Container `perfect-consign-postgres`. Do not use ports 5432, 5433, 5434, or 5435, and do not use volume `stall_pg`.

`stall_poster` inserts journals and lines. It cannot turn triggers off, drop them, or set replica mode. Append-only triggers fire in replica mode too, so that session still cannot update, delete, or truncate them.

## Threat model

The boundary is `stall_poster` and `stall_app`. A superuser can `DROP EVENT TRIGGER` or set `event_triggers=false`. Postgres does not deliver those statements to an event trigger. That is outside the product role.

## Load

`php bin/provision-platform.php` is the fresh install. It applies `sql/000_platform.sql` once, on an empty database. That file creates `stall_app`, `stall_backup`, the platform tables, schema `extensions`, and `btree_gist`. A second run exits 0 and does not re-apply the file. After `platform_reject_guard_function_replace` exists, replacing `platform.reject_append_only_trigger_change` is refused. Then provision a mall. `sql/001_tenant.sql` is the whole mall schema. `public` in that file is rewritten to the mall schema. An existing database is not upgraded: `php bin/migrate.php` refuses a database whose stamps name an older file, or whose objects exist without `schema_migrations`. Create a new database and provision it. `php bin/migrate.php platform`, `php bin/migrate.php tenant <slug>`, and `php bin/migrate.php tenant-all` apply a file that has not been stamped yet. `sql/002_platform_jobs.sql` is the job queue. `sql/003_audit_events.sql` is the audit log and the report-drift check. `sql/004_job_grants.sql` lets the poster role read the job queue. `php bin/work.php` claims due jobs once. `php bin/work.php --forever` keeps claiming them. A stamped `sql/0*.sql` file is not edited; `php bin/check-migration-freeze.php` refuses that diff. A tenant session path is `tenant_x, pg_catalog, extensions`. `public` is not a mall.

`STALL_DB_HOST`, `STALL_DB_PORT`, `STALL_DB_NAME`, `STALL_DB_USER`, and `STALL_DB_PASSWORD` are required. `STALL_APP_PASSWORD` is required for the web app (role `stall_app`). `STALL_PUBLIC_ORIGIN` is the canonical site origin (`https://example.com` or `http://127.0.0.1:8080`) used for password-reset and consignor magic links. The app refuses to start without it unless `STALL_ALLOW_HOST_ORIGIN=true` and `APP_ENV` is `local` / `development` / `dev` (or `APP_DEBUG=true` outside `production` / `prod`). Host-derived origin is never used in production-like env. `STALL_TRUSTED_PROXIES` is a comma-separated list of proxy IPs or IPv4 CIDRs; only those peers may supply `X-Forwarded-Proto` for Secure cookies and HSTS. If unset, forwarded proto is ignored. HSTS is `max-age=86400` without `includeSubDomains`. `PC_COVERAGE` is honored only when `APP_ENV` is `test` or `coverage`. Log and coverage directories are created as `0700`; rotate `var/log/app.log` with logrotate or an equivalent. `php bin/work.php` logs in as `stall_worker` with `STALL_WORKER_PASSWORD`. The site and the command scripts read `.env` (one parser: quotes, `export`, and inline `#` comments). A reset plants the Gmail mailbox from `STALL_GMAIL_ADDRESS`, `STALL_GMAIL_APP_PASSWORD`, and `STALL_MAIL_KEY`. `bin/deploy-demo.sh` writes `STALL_MAIL_KEY` into that file when the line is missing, then migrate seals any plaintext mailbox password with it.

```text
php bin/seed-mall.php
php bin/seed-dev-tenant.php
```

`php bin/seed-mall.php` removes slug `mall`, provisions it again, and posts six months of booths, sales, rent, and payouts through the same posting paths the register uses. It also removes slug `shop` and provisions that store as consignment only: two consignors, a few consigned items, and no booths or rent. Sign in at `/` with the owner recovery email and `STALL_OWNER_PASSWORD`, or at `/mall/login` / `/shop/login` with username `owner`. Set `STALL_OWNER_EMAIL` before `set-owner` so the email directory has a row. The test suite still builds an empty `webprobe` when that slug is missing.

`php bin/seed-dev-tenant.php` wipes slug `dev` and no other mall, provisions it, and loads `sql/dev_tenant.sql`: one vendor, one booth on Main Floor, one open assignment, and three items. It does not post a journal. The owner is still `php bin/set-owner.php dev owner` (optional `STALL_OWNER_EMAIL`).

## Application

`sql/000_platform.sql` creates the `stall_app` and `stall_backup` login roles, stores one owner per mall, and stores owner download times. The poster grants live in `sql/001_tenant.sql` and are applied inside each mall schema by the provisioner. `php bin/provision-platform.php` applies the platform file once.

The web app connects as `stall_app`. Set `STALL_APP_PASSWORD`, `STALL_BACKUP_PASSWORD`, and `STALL_WORKER_PASSWORD` in the environment or in `.env`. That file stays out of git. `STALL_PG_DUMP` is `pg_dump` on `PATH`, a path to the Postgres 18 client, or `docker:perfect-consign-postgres` to use the client in that container. The host client must be 18. A Postgres 17 client cannot dump this server.

```text
php bin/enable-stall-app.php
php bin/set-owner.php mall owner
```

`set-owner.php` reads `STALL_OWNER_PASSWORD` and optional `STALL_OWNER_EMAIL`. The same `STALL_DB_*` values used for provisioning are required. A duplicate recovery email across malls is refused. The site is `public/index.php`. Root `/` is email sign-in; a mall is `/{slug}` with username deep link `/{slug}/login`. Five failed email sign-ins from one address in fifteen minutes lock that pair; twenty failures for the same email from any addresses lock the email. Stale failure rows are pruned on the next failure. Every response sends an enforcing Content-Security-Policy (`script-src` / `style-src 'self'`, no `unsafe-inline`); page scripts and styles are files under `/assets/`. Edge helpers and TLS/origin/CSP rules are documented in `docs/http-edge.md`. Production deploys should run `composer build:routes` so `var/cache/feature-controllers.php` is present before traffic hits the app. After platform migrate, apply `054_email_sign_in_failure_stale.sql` for the stale-failure index.

Platform admin is `/admin`. After `php bin/migrate.php platform`, run `php bin/set-platform-admin.php` with `STALL_PLATFORM_ADMIN_EMAIL` and `STALL_PLATFORM_ADMIN_PASSWORD` set. The slug `admin` is reserved and cannot be provisioned as a store.

```bash
php -S 127.0.0.1:8096 -t public public/index.php
```

That demo server is HTTP. Secure cookies and HSTS stay off unless the process sees trusted TLS (`HTTPS` or a peer in `STALL_TRUSTED_PROXIES`). Self-serve `/signup`, promo codes, and the military customer tax path are not in this tree yet.

## Tests

`composer test` runs the unit suite. `composer test:integration` runs the tests that open Postgres. `composer test:http` runs the HTTP suite (excludes the mutating surface form crawl). `composer check` runs those suites plus PHPStan, PHPMD, and the proofs. A green `check` on `master` is what deploys the demo. `composer test:mutate` and `composer test:coverage` run on a nightly GitHub Action; coverage does not block deploy. `composer test:smoke` and `composer test:http` boot the site on `127.0.0.1:8097` and drive it over HTTP. Smoke includes the GET surface link crawl for every mall role (and owner preview `?as=`). `composer test:surface-mutate` submits safe forms on that same graph; `composer test:browser` drives Chrome JS clicks (Print, preview Back). Both run on the `surface` GitHub workflow with Chrome and `sockets`. The browser suite needs the PHP `sockets` extension.

Those commands use mall `webprobe` on the local database only — never `mall`, `shop`, or a pulled tenant. The suite creates `webprobe` when it is missing and sets its owner password. It posts journals into that mall. It does not change any other mall. If `STALL_DB_USER` and `STALL_DB_PASSWORD` are unset, the suite uses the local `docker-compose.yml` login.

## Proofs

Each fact `sql/prove_*.sql` rolls back. Run those proofs with `search_path` set to the mall schema from `platform.tenants`, then `pg_catalog`, then `extensions`. `php bin/prove-race.php` provisions slug `race`, then runs the two-session check, including the tenant slug and the on-hand sale. The `sql/prove_race_*.sql` files are not proofs on their own. `php bin/prove-tenant.php` rolls back the cross-tenant proof. `php bin/prove-backup.php` dumps a fixture mall and restores it into a second schema, then drops both.

An owner download is streamed and not kept. `php bin/backup-tenant.php <slug>` writes one zip per UTC day under `STALL_BACKUP_DIR`, which must be outside the `perfect_consign_pg` volume, and removes files in that directory older than 30 days. `php bin/pull-tenant.php <slug>` copies one mall from `STALL_SOURCE_DB_*` onto local port 5436 database `consign` and refuses any other destination.
