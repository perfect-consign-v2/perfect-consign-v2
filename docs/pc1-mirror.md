# PC1 → PC2 dev mirror

Dev-only pathway to load **current** Becca (`beccas-bargains`) shape and as-of control balances into disposable PC2 mall `beccas-mirror`. Not a cutover. Not full history replay.

## Safety

- **Prod** (`api.perfectconsign.com`): extract is **read-only** (`default_transaction_read_only=on`). Never modify prod.
- **Wipe/load destinations:** local `127.0.0.1|localhost:5436` / `consign`, or **v2** via `STALL_MIRROR_V2_DB_*` (or `STALL_DB_*` when the CLI runs on the v2 host). Host must be `v2.perfectconsign.com`, localhost, or `STALL_MIRROR_V2_ALLOW_HOST`.
- Do **not** point `pull-tenant` / `STALL_SOURCE_DB_*` at PC1.

## No drift

Every load stamps an as-of in the extract `manifest.json`. Quote sandbox balances only after `prove` exits 0 against that extract. A failed prove is a stop — refresh again.

## Commands

```text
# Read-only extract from prod (needs PROD_DB_PASS or PC_DB_PASSWORD)
php bin/pc1-mirror.php extract --slug=beccas-bargains

# Wipe + provision beccas-mirror + master + opening + prove
php bin/pc1-mirror.php refresh --target=local

# Or stepwise
php bin/pc1-mirror.php load --extract=var/pc1-extracts/beccas-bargains/<UTC> --target=local --wipe
php bin/pc1-mirror.php prove --extract=var/pc1-extracts/beccas-bargains/<UTC> --target=local
```

Artifacts land under `var/pc1-extracts/` (gitignored). Set `STALL_OWNER_PASSWORD` (and optional `STALL_OWNER_EMAIL`) so load can plant the mirror owner.

## v2

`bin/deploy-demo.sh` stays migrate-only (no book wipe). To refresh the mirror mall on v2:

1. Extract on a machine with read-only prod credentials.
2. Copy the artifact directory to the v2 host (or re-extract there with the same read-only creds).
3. On the v2 host (with `STALL_DB_*` pointing at the demo DB):

```text
php bin/pc1-mirror.php load --extract=/path/to/artifact --target=v2 --wipe
php bin/pc1-mirror.php prove --extract=/path/to/artifact --target=v2
```

Or from a laptop with `STALL_MIRROR_V2_DB_HOST` / `PORT` / `NAME` / `USER` / `PASSWORD` set to the v2 Postgres (never `api.perfectconsign.com`).

## Account map

| PC1 sense | PC2 role |
|-----------|----------|
| Consignor payable | `vendor_payable` |
| Open booth rent | `booth_rent_receivable` |
| Sales tax | `sales_tax_payable` |
| Consignor receivable (negative payable) | `vendor_receivable` |
| Gift / store credit | `gift_*` / `store_credit` |
| Balancing equity | `retained_earnings` |

Opening mirrors **balances as extracted**. Forward posting on PC2 follows PC2 books law (two controls; no rent-from-payout haircut).

## Related

- `.cursor/rules/pc1-mirror.mdc`
- Parent no-drift rule: `consigngold-local-db-not-source-of-truth.mdc`
