# Perfect Consign

This is the product copy of the Stall baseline at `27e5e88a740e676024268fdf2ebf2e09ba3bad87`. Stall stays the baseline. Do not change `C:\laragon\www\stall` from here.

No hacks, no shortcuts, no workarounds, and no patches. Fix the design that is wrong. Do not leave a second calculation in application code, or nudge a date or flag so that calculation happens to match the database. Money and balances come from the view or function that owns them.

## Database

Postgres `18.6`. Host port **5436**. Database `consign`. User `stall`. Volume `perfect_consign_pg`. Container `perfect-consign-postgres`. Do not use ports 5432, 5433, 5434, or 5435, and do not use volume `stall_pg`.

`stall_poster` inserts journals and lines. It cannot turn triggers off, drop them, or set replica mode. Append-only triggers fire in replica mode too, so that session still cannot update, delete, or truncate them.

## Threat model

The boundary is `stall_poster` and `stall_app`. A superuser can `DROP EVENT TRIGGER` or set `event_triggers=false`. Postgres does not deliver those statements to an event trigger. That is outside the product role.

## Load

`php bin/provision-platform.php` is the fresh install. It applies `sql/000_platform.sql`, `sql/015_stall_app.sql`, `sql/016_owners.sql`, and `sql/018_owner_backup.sql` only when `platform_reject_guard_function_replace` does not exist yet. A second run exits 0 and does not re-apply those files. After that guard exists, replacing `platform.reject_append_only_trigger_change` is refused. `000` creates schema `extensions` and installs `btree_gist` there. Then provision a mall. `TenantProvisioner::ledgerFiles()` is the list, from `sql/001_kernel.sql` through `sql/043_rent_receipt.sql`. Each table and each function is created once. A schema change is a new file under `sql/`. `SchemaMigrator` applies it. `php bin/migrate.php platform` updates the platform schema. `php bin/migrate.php tenant-all` updates every mall. `php bin/migrate.php tenant <slug>` updates one mall. A tenant session path is `tenant_x, pg_catalog, extensions`. `public` is not a mall.

`STALL_DB_HOST`, `STALL_DB_PORT`, `STALL_DB_NAME`, `STALL_DB_USER`, and `STALL_DB_PASSWORD` are required.

```text
php bin/seed-mall.php
php bin/seed-dev-tenant.php
```

`php bin/seed-mall.php` removes slug `mall`, provisions it again, and posts six months of booths, sales, rent, and payouts through the same posting paths the register uses. Sign in as `owner`. The password is `STALL_OWNER_PASSWORD`, or `mall-demo` when that variable is unset. The test suite still builds an empty `webprobe` when that slug is missing.

`php bin/seed-dev-tenant.php` wipes slug `dev` and no other mall, provisions it, and loads `sql/dev_tenant.sql`: one vendor, one booth on Main Floor, one open assignment, and three items. It does not post a journal. The owner is still `php bin/set-owner.php dev owner`.

## Application

`sql/015_stall_app.sql` creates the `stall_app` login role. `sql/016_owners.sql` stores one owner per mall. `sql/017_poster_grants.sql` is applied inside each mall schema by the provisioner. `sql/018_owner_backup.sql` stores owner download times and creates the `stall_backup` login. `php bin/provision-platform.php` applies it once, after `016`.

The web app connects as `stall_app`. Set `STALL_APP_PASSWORD` and `STALL_BACKUP_PASSWORD` in the environment or in `.env`. That file stays out of git. `STALL_PG_DUMP` is `pg_dump` on `PATH`, a path to the Postgres 18 client, or `docker:perfect-consign-postgres` to use the client in that container. The host client must be 18. A Postgres 17 client cannot dump this server.

```text
php bin/enable-stall-app.php
php bin/set-owner.php mall owner
```

`set-owner.php` reads `STALL_OWNER_PASSWORD`. The same `STALL_DB_*` values used for provisioning are required. The site is `public/index.php`. A mall is `/{slug}`.

```bash
php -S 127.0.0.1:8096 -t public public/index.php
```

## Tests

`composer test` runs the unit suite. `composer test:integration` runs the tests that open Postgres. `composer test:http` runs the HTTP suite. `composer check` runs all three. `composer test:coverage` enforces the 90 percent line floor. `composer test:smoke` and `composer test:http` boot the site on `127.0.0.1:8097` and drive it over HTTP. `composer test:browser` drives the same site in Chrome. The browser suite needs the PHP `sockets` extension.

Those three commands use mall `webprobe` on the local database. The suite creates that mall when it is missing and sets its owner password. It posts journals into that mall. It does not change any other mall. If `STALL_DB_USER` and `STALL_DB_PASSWORD` are unset, the suite uses the local `docker-compose.yml` login.

## Proofs

Each fact `sql/prove_*.sql` rolls back. Run those proofs with `search_path` set to the mall schema from `platform.tenants`, then `pg_catalog`, then `extensions`. `php bin/prove-race.php` provisions slug `race`, then runs the two-session check, including the tenant slug and the on-hand sale. The `sql/prove_race_*.sql` files are not proofs on their own. `php bin/prove-tenant.php` rolls back the cross-tenant proof. `php bin/prove-backup.php` dumps a fixture mall and restores it into a second schema, then drops both.

An owner download is streamed and not kept. `php bin/backup-tenant.php <slug>` writes one zip per UTC day under `STALL_BACKUP_DIR`, which must be outside the `perfect_consign_pg` volume, and removes files in that directory older than 30 days. `php bin/pull-tenant.php <slug>` copies one mall from `STALL_SOURCE_DB_*` onto local port 5436 database `consign` and refuses any other destination.
