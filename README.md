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

`sql/015_stall_app.sql` creates the `stall_app` login role. `sql/016_owners.sql` stores one owner per mall. `sql/017_poster_grants.sql` is applied inside each mall schema by the provisioner. An existing mall needs `017` applied once, with `search_path` set to that schema. Do not apply `001` through `014` again.

The web app connects as `stall_app`. Set `STALL_APP_PASSWORD` in the environment or in `.env`. That file stays out of git.

```text
php bin/enable-stall-app.php
php bin/set-owner.php mall owner
```

`set-owner.php` reads `STALL_OWNER_PASSWORD`. The same `STALL_DB_*` values used for provisioning are required. The site is `public/index.php`. A mall is `/{slug}`.

```text
php -S 127.0.0.1:8096 -t public public/index.php
```

## Proofs

Each fact `sql/prove_*.sql` rolls back. Run those proofs with `search_path` set to the mall schema from `platform.tenants`. `sql/prove_race.ps1` is the two-session check, including the tenant slug. The `sql/prove_race_*.sql` files are not proofs on their own. `php bin/prove-tenant.php` rolls back the cross-tenant proof.
