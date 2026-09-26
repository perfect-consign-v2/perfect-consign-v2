# Perfect Consign

This is the product copy of the Stall baseline at `27e5e88a740e676024268fdf2ebf2e09ba3bad87`. Stall stays the baseline. Do not change `C:\laragon\www\stall` from here.

## Database

Postgres `18.6`. Host port **5436**. Database `consign`. User `stall`. Volume `perfect_consign_pg`. Container `perfect-consign-postgres`. Do not use ports 5432, 5433, 5434, or 5435, and do not use volume `stall_pg`.

`stall_poster` inserts journals and lines. The table owner can still turn triggers off.

## Load

Apply the numbered files in this order:

`sql/001_kernel.sql` through `sql/014_export_files.sql`.

## Proofs

Each fact `sql/prove_*.sql` rolls back. `sql/prove_race.ps1` is the two-session check. The `sql/prove_race_*.sql` files are not proofs on their own.
