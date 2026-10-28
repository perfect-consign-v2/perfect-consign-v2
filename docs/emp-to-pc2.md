# EMP → PC2: steal list, then stop citing EMP

**Goal:** Absorb everything worth keeping from EMP into **PC2**, then treat EMP (`C:\laragon\www\emp`) as **archived**. Do not keep opening EMP as a living product reference.

Names: [naming.md](naming.md). Competitor forensics now live here: [competitors/README.md](competitors/README.md).

EMP’s Nest/Next app, ports, and migration numbers are **not** the destination. PC2 keeps its own SQL proofs, chart roles, and PHP stack. Steal **rules, jobs, and UX bars** — not Nest modules.

---

## Already in PC2 (do not re-import)

These EMP ideas are already PC2 law. Cite `docs/business-rules.md` / hesk KB, not EMP.

| EMP idea | PC2 home |
|----------|----------|
| Append-only journals, posting keys, balances = sum of lines | Kernel + proofs |
| Payable vs rent as **two** controls; rent never a silent payable haircut | Settlement vs disbursement (B07); late fees credit late-fee income |
| Frozen payout pack / envelope checksum | Payout run proofs + specs |
| Cash drop drawer → safe; over/short on close | Registers rules |
| Cash rounding on cash-only residual → cash-rounding account | C04 (steps 1/5/10/25) |
| Vendor portal scoped to one vendor | Portal + `stall.vendor_id` |
| Booth codes unique / restore, not mint a second A1 | Vendors & booths proofs |
| Cycle-day rent, late fees as own journals | Rent + late-fee rules |
| Instant add, park/hold, layaway liability, gifts, store credit | Sales / stored-value rules |
| Nightly report ↔ GL tie-out | E02 |
| Competitor “steal feel / refuse books” | [competitors/](competitors/) |

---

## Adopt into PC2 (worth stealing)

Prioritized for cutting EMP as a dependency. Each row is a **PC2** work item; EMP SRS IDs are breadcrumbs only.

### 1. Books that EMP got right and PC2 still lacks

| Steal | Why | EMP breadcrumb | PC2 status |
|-------|-----|----------------|------------|
| **Clawback receivable** after return/void when leftover payable is gone | PC1 blocks (`RETURN_EXCEEDS_PAYABLE`); EMP posts named receivable so the shopper is not blocked and books stay honest | CON-004, `1310`, CLW-* | **Done** — `vendor_receivable` / `sql/001_tenant.sql`, apply at payout close, vendor “They owe us” |
| **Processor fee expense** on settlement; never haircut vendor payable | Card fees must not become a dealer haircut (Quail/SC pattern) | CON-006, `6170` | **Missing** until card settlement posts |
| **Card clearing + “Waiting for the bank” list** that always matches the GL | Demo-killer vs typed-only card; diligence feature | SET-*, `1200` | Partial (card tender exists); no settlement ritual yet |
| **Cash rounding increments** `{1, 5, 10, 25}` (not only nickel on/off) | ConsignCloud/Quail family; EMP already named the dropdown | MON-009 | **Done** — `cash_round_minor`, Settings dropdown, `041_cash_round_steps.sql` |
| **JSON money as digit strings** (no IEEE float in API/audit) | Prevents Resell4.me-class bugs | MON-002 | Prefer in any new HTTP/JSON money; SQL stays integer minor |

### 2. Saturday / office jobs EMP shipped that PC2 should match or beat

| Steal | Why | Notes |
|-------|-----|--------|
| **Vendor workbench: three money numbers** — We owe them / They owe rent / They owe us (clawback) | Grandma clarity; kills one-number payout | **Partial** — clawback figure on vendor page; rent/pay stats already present |
| **Collect rent on the till strip** as a first-class floor job | EMP rent-Saturday; PC1 has booth rent receipts — PC2 should not hide rent off POS | **Done** — `register-nav.php` Collect rent → `/rent` |
| **Apply-then-pay as an owner-visible setting** (default on for mall) | PC2 already settles on close; surface the story in copy so it is not a silent PC1 haircut | Copy + settings, not a second ledger |
| **Booth occupancy pills as text** (available / occupied / ending / incoming), not color alone | Accessibility + Saturday speed | Map/list IA |
| **Map builder: Canvas 2D sufficient; WebGL optional** | EMP CON-010 — list always enough to assign/switch/end | Do not make Three.js required for mall ops |
| **Grandma floor chrome** — percent UI, helper under fields, skip links, 44px targets, trust copy that matches books | EMP G1–G8 | UX bar when screens land; do not copy Nest components |

### 3. Product posture (docs / process), not Nest code

| Steal | Why |
|-------|-----|
| **Competitor notes in-repo** | Done — [competitors/](competitors/) |
| **Named refuse list** (rent-in-payout, dealer card fee on payable, date-range “payout”, JS float money) | Keep in business-rules / hesk; expand as clawback + card settlement land |
| **Practice → go live** and platform pause | EMP SaaS doors — only when PC2 multi-tenant SaaS is in scope |
| **Square Terminal / processor port** as an adapter, same `1200` books | When operator names cards; do not invent a second ledger |

### 4. Explicitly do **not** steal

| EMP thing | Why leave it |
|-----------|----------------|
| Nest / Next / pnpm workspace, ports 3000–3002 | Wrong stack for PC2 |
| PostgreSQL `uuidv7()` / PG18-only as a requirement | PC2 chooses its own ID/runtime rules |
| EMP CoA **codes** as mandatory (`2000`/`1300` literals) | PC2 uses **chart_roles**; keep roles, not EMP’s number paint |
| MappedIn / Three.js-as-requirement | Competitors sell 3D; PC2 booth list + optional map |
| Silent or marketed rent-from-balance haircut | EMP refused it; PC2 already refuses |
| EMP Test Mall data as Becca truth | Never |

---

## How to close EMP as a reference

1. ~~Land **clawback** (+ vendor workbench “They owe us”) in PC2 proofs and business-rules.~~ **Done** (prove_clawback, B12, business-rules).
2. ~~Land **cash round steps** `{1,5,10,25}` + Need stickers inventory filter.~~ **Done** (`041`, C04, `sticker_printed_at`, All / Need stickers / Qty 0).
3. Land **card settlement / processor fees** when card integration is named.
4. **Optional:** vendor intake batches / store review (ConsignCloud feel; empty on their trial) — only when a mall asks.
5. Keep competitor forensics and this file under `docs/`; do not re-open EMP for those topics.
6. For a one-off EMP screen walk, the operator must **name EMP** in that chat. Default: PC2 only.
7. When the adopt table rows above are done or explicitly waived, mark them here and stop citing EMP SRS volumes.

### Status legend for the adopt table

- **Missing** — not in PC2 yet; implement here.
- **Widen** — PC2 has a narrower version; extend in place.
- **Partial** — pieces exist; finish the EMP job shape.
- **Done** — move the row into “Already in PC2.”

---

## One-line pitch (keep)

> Competitors (and PC1’s rent-from-payout switch) sell **one number**. PC2 posts **clawback apply, then settlement, then disbursement** — and posts receivable instead of blocking the shopper on return/void after payout.
