# Perfect Consign

This is the product copy of the Stall baseline at `27e5e88a740e676024268fdf2ebf2e09ba3bad87`. Stall stays the baseline. Do not change `C:\laragon\www\stall` from here.

## Database

Postgres `18.6`. Host port **5436**. Database `consign`. User `stall`. Volume `perfect_consign_pg`. Container `perfect-consign-postgres`. Do not use ports 5432, 5433, 5434, or 5435, and do not use volume `stall_pg`.

`stall_poster` inserts journals and lines. The table owner can still turn triggers off.

## Load

Apply `sql/000_platform.sql` to database `consign`. Then provision a mall. That applies `sql/001_kernel.sql` through `sql/014_export_files.sql` into that mall's schema. `public` is not a mall.

`STALL_DB_HOST`, `STALL_DB_PORT`, `STALL_DB_NAME`, `STALL_DB_USER`, and `STALL_DB_PASSWORD` are required.

```text
php bin/provision-tenant.php mall Mall
```

## Application

`sql/015_stall_app.sql` creates the `stall_app` login role. `sql/016_owners.sql` stores one owner per mall. `sql/017_poster_grants.sql` is applied inside each mall schema by the provisioner. `sql/018_owner_backup.sql` stores owner download times and creates the `stall_backup` login. Apply `018` once to database `consign`, after `016`. An existing mall needs `017` applied once, with `search_path` set to that schema. Do not apply `001` through `014` again.

The database does not match the code until `018` is applied. Until then the Book page and sign-out backup fail instead of downloading a file.

The web app connects as `stall_app`. Set `STALL_APP_PASSWORD` and `STALL_BACKUP_PASSWORD` in the environment or in `.env`. That file stays out of git. `STALL_PG_DUMP` is `pg_dump` on `PATH`, a path to the Postgres 18 client, or `docker:perfect-consign-postgres` to use the client in that container. The host client must be 18. A Postgres 17 client cannot dump this server.

```text
php bin/enable-stall-app.php
php bin/set-owner.php mall owner
```

`set-owner.php` reads `STALL_OWNER_PASSWORD`. The same `STALL_DB_*` values used for provisioning are required. The site is `public/index.php`. A mall is `/{slug}`.

```bash
php -S 127.0.0.1:8096 -t public public/index.php
```

## Proofs

Each fact `sql/prove_*.sql` rolls back. Run those proofs with `search_path` set to the mall schema from `platform.tenants`. `sql/prove_race.ps1` is the two-session check, including the tenant slug. The `sql/prove_race_*.sql` files are not proofs on their own. `php bin/prove-tenant.php` rolls back the cross-tenant proof. `php bin/prove-backup.php` dumps a fixture mall and restores it into a second schema, then drops both.

An owner download is streamed and not kept. `php bin/backup-tenant.php <slug>` writes one zip per UTC day under `STALL_BACKUP_DIR`, which must be outside the `perfect_consign_pg` volume, and removes files in that directory older than 30 days. `php bin/pull-tenant.php <slug>` copies one mall from `STALL_SOURCE_DB_*` onto local port 5436 database `consign` and refuses any other destination.
