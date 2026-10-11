# Perfect Consign — Payout Platform: Build-Ready Spec (v2.2)

**Status:** build-ready, for implementation on `perfect-consign-v2/perfect-consign-v2` (`master`). **Supersedes:** v1 (`perfect-consign-payout-spec.md`), v2, and v2.1. Changes from v1 are marked **\[v2\]**; from v2, **\[v2.1\]**; from v2.1, **\[v2.2\]**.

* * *

## Changelog — v2.2 (five contradictions + two leftovers from Cursor's review of v2.1)

Every item below is code-grounded against the kernel at `1d28611` and was verified before acceptance. Each is tagged **\[v2.2\]** inline at the point of change.

1.  **The payable identity is now the ledger identity, not a column sum (§4.7, §5, §11).** v2.1's `brought_forward + sales − commission − draw + adjustment` omitted real payable movements. A discount lowers the sold price — `032_tickets.sql` computes the store take as `half_up(price − discount)` and the tender as `prices − discounts + taxes`, and `034_item_return.sql` proves the payable debit on a return is `price − discount − commission`. A void (a reversal of the sale) debits the payable. And a check written on the 1st is dated inside the _next_ window — neither brought forward nor a draw. Fix: `carried_payable = brought_forward + Σ(vendor_payable credits − debits in the window)`, with the columns as a breakdown that now includes `discount_minor`, `return_minor`, `void_minor`, `check_paid_minor`.
2.  **The envelope shows the rent breakdown (§4.7, §5, §9.1).** Netting all open rent is right, but the page printed only this month's charge as "Rent," so the check never equalled their share minus the printed rent when an older shortfall was open. Fix: add `rent_brought_forward_minor` and `rent_paid_since_minor`, so `rent_brought_forward + rent_charged − rent_paid_since = carried_rent` (the rent netted).
3.  **The checksum is verified against the frozen rows, not the live ledger (§4.11, §6.1, §6.2, §11).** Review hashes the live projection; settlement then reduces the payable, so re-hashing the live ledger at `disburse()` could never match. And `payout_run_sales` does not exist at review. Fix: one checksum definition with two entry points — live projection at review, stored rows after post — that must produce the same string; `disburse()` verifies the frozen hash and separately asserts the live open payable still equals the frozen `check_minor`.
4.  **The freeze is column-aware (§4.12, §6.2, §11).** v2.1's guard refused _any_ change once settled, which blocked `disburse()` from writing `check_number` and `disbursement_journal_id`. Fix: money columns freeze at settle; `check_number`/`disbursement_journal_id` are write-once while `settled`, then freeze.
5.  **The posting key is the run, not the month (§6.1, §6.2, §11).** `settlement:<vendor>:<YYYY-MM>` could not serve weekly, biweekly, or a second ad-hoc pay in one month. Fix: `settlement:<run_id>:<vendor_id>` and `disbursement:<run_id>:<vendor_id>`. Also: `cash_in_safe` is missing from the source rules, so a safe payout cannot post — add `('cash_in_safe', 'cash_disbursement')` (§4.5); and `payout_run_sales` stored no item description, so a later item edit changed the reprinted envelope — store `item_code`/`description` (§4.8).
6.  **Two leftovers removed (§5, §6.1, §9.1).** §5 said the two journals post in one transaction (they are two steps); the screen disabled Close while rent was missing (settle() posts the rent itself); and the rent charge must be dated inside `rent_month` (the kernel derives both the key and the rate from `posting_date` — `022_assignment_rent.sql`), while the close may be dated the click day.

* * *

## Changelog — v2.1 (three corrections accepted from Cursor's review of v2)

All three are code-grounded fixes to genuine self-contradictions in v2, not preferences. Each is tagged **\[v2.1\]** inline at the point of change.

1.  **Brought-forward payable added to the report identity (§4.7, §5, §6.1, §11).** v2's identity summed only the window's movements, so it failed for any vendor with a prior-month open payable and the run refused. Fix: new frozen column `brought_forward_minor`, and the identity becomes `brought_forward + sales − commission − draw + adjustment == carried_payable`. Enforced as a deferred assertion at post time.
2.  **The run nets _all_ open rent, not just `rent_month` (§3, §5, §6.1, §7, §11).** v2 defined `R` as `rent_month`'s rent only, so an older shortfall was never swept and sat open forever. Fix: `rent_month` now governs only which charge the run _ensures_; `R` = the vendor's entire open rent across every month. This matches the kernel's existing `stall_assert_payout()`.
3.  **The checksum covers the sale lines (§4.11, §11).** v2's `stall_pack_checksum` hashed only vendor/net/rent, so a void plus a new sale with the same net would pass review with different underlying lines. Fix: the checksum hashes the full frozen envelope including every `payout_run_sales` row, and is re-verified at both `settle()` and `disburse()`.

**Author's note to Cursor:** written against the kernel at `1d28611`. Read `sql/001_kernel.sql`, `002_chart.sql`, `003_vendors_booths.sql`, `004_sale.sql`, `005_rent_charge.sql`, `006_rent_payment.sql`, `007_payout.sql`, `008_statement.sql`, `022_assignment_rent.sql`, `028_tenders.sql`, `029_register_posts.sql`, `031_sale_lines.sql`, `038_vendor_draw.sql`, and `src/Feature/Payout/*` before you start. The conventions there are load-bearing; this spec extends them, it does not replace them.

* * *

## 0\. Why the live payout failed, and the one rule that fixes it

The live app's payout report was a **second implementation of the money math**. The ledger computed one number and the report computed another, and the two drifted every month. Six months, six discrepancies.

The fix is a single, non-negotiable rule:

> **The ledger is the only place money is computed. A payout report is a _stored snapshot of what the ledger already says_, taken once, frozen, and reprinted forever. A payout _settles the subledger_; it never recomputes a sale.**

Everything below is a consequence of that rule. If an implementation detail ever forces a choice between "make the report agree with the ledger" and "make the report agree with a recomputation," the ledger wins and the report is regenerated.

* * *

## 1\. Scope

This spec delivers:

1.  A **`payout_run`** object: one posted, frozen, idempotent, reversible record of a batch _or_ single payout.
2.  A **stored report** (`payout_run_lines` + `payout_run_sales`), materialized at post, so the original report is retrievable forever with no hand-managed PDFs. **\[v2\] the _preview_ is a live view; the _record_ is frozen at post.**
3.  A **per-vendor `payout_mode`** (`pay` / `credit_only` / `hold`) so a vendor who spends all sales as store credit is excluded from runs.
4.  A **rent-in-advance** model: a run settles the _prior_ period's sales and applies them to the _next_ month's rent, atomically, with a shortfall ("owes store") case.
5.  A **single `settle()` engine** shared by the batch and single-vendor paths, **tender-agnostic and rent-optional** so it serves consignor mode, booth-mall mode, and both. **\[v2\]**
6.  A **settlement/disbursement split**: close nets rent against the payable (no cash); a separate disbursement writes the check or pays the drawer. **\[v2\]**
7.  A **reversal-based undo** (`draft → settled → paid → reversed`).
8.  A **`store_purchase`** journal for when the store buys from a vendor.
9.  **One operator screen** ("Pay vendors") plus a **print-ready envelope** per vendor, with history on a separate route. **\[v2\]**
10.  A **one-time live-tenant reconciliation** to open the new system clean.
11.  **`prove_payout_run.sql`** with the invariants that make the above true.
12.  **A designed-for, not-built-now** external payout rail (ACH/Zelle/Venmo) — see §13. **\[v2\]**

* * *

## 2\. Design principles (the bar)

-   **Ledger is truth.** The report is derived from `journal_lines`, never from `sale_lines` alone. Where the report shows sale detail, that detail must _sum to_ the ledger movement; a mismatch is a hard refusal, not a warning.
-   **One engine.** Batch and single-vendor payouts call the same `settle()`/`disburse()`. There is no second code path that can drift.
-   **Preview is live; the record is frozen.** The operator reviews a live projection of the ledger. Posting materializes that projection into immutable `payout_run_lines`/`payout_run_sales` and re-verifies it against the live balances in the same transaction. **\[v2\]**
-   **Tender-agnostic, rent-optional.** The engine never assumes a tender and never assumes rent exists. Rent is read per vendor from that vendor's booth assignments; a vendor with no booth has `R = 0`. **\[v2\]**
-   **Idempotent.** Every settlement and disbursement posts under a `posting_key` unique per book, so a double-click or a retry cannot double-pay.
-   **Reversible, never edited.** A posted run is immutable. Undo is a reversal run. The audit trail is append-only.
-   **Serialized.** Settlement locks the vendor (and booth) subledgers, so two runs cannot settle the same vendor at once.
-   **Nothing is deleted.** Posted lines are frozen by trigger; journals are append-only with `ENABLE ALWAYS` guards, exactly as the kernel already does.

* * *

## 3\. Operating modes **\[v2\]**

The app runs in one of three modes. **The mode is a tenant setting; it never forks the engine.** Everything mode-specific is either configuration (does the tenant assign booths?) or per-vendor (does _this_ vendor have rent?).

|   
 | Consignor mode | Vendor mall | Both (default) |
| --- | --- | --- | --- |
| Vendor is | consignor (items on consignment) | booth renter | either |
| Rent | none (`R = 0`) | booth rent receivable | per vendor — `0` if no booth |
| Statement shows | sales, commission, fees, payout | \+ rent, draws, rent payments | adapts per vendor |
| Natural payout | on-demand | monthly batch | both |
| Clearing account | needed when a rail exists | only for bank/cash | both |

The load-bearing rule: **rent is per-vendor and optional, never assumed.** The engine computes `R` as **all** open rent on the vendor's assigned booths — every month, not just `rent_month` (`rent_month` governs only which charge the run _ensures_). A consignor vendor has no booth assignments, so `R = 0`, and the math collapses to `net = P` — a pure payable payout. There is no branch and no second code path.

**"Both" is the real test.** One tenant, one run, a mixed population: a booth vendor nets rent, a walk-in consignor does not, in the same batch — because rent is read per vendor, not per run. If rent were built into the payout path, "both" would be impossible without a fork.

**Default is `both`.** A consignor-only tenant simply never assigns booths; nothing in the schema changes if they later add them.

```sql
ALTER TABLE mall ADD COLUMN operating_mode text NOT NULL DEFAULT 'both';
ALTER TABLE mall ADD CONSTRAINT mall_operating_mode_chk
    CHECK (operating_mode IN ('consignor', 'booth_mall', 'both'));
```

The mode drives UI (hide booths/rent when unused) and the default cadence. It is **not** a constraint the engine reads.

* * *

## 4\. Data model

### 4.1 New SQL file

Add **`sql/041_payout_run.sql`** and append it to `TenantProvisioner::ledgerFiles()` **after `040_layaway.sql`**. It is a tenant file (provisioned per schema), so it must:

-   reference tables as `public.<table>` (the provisioner rewrites `public.` → `<schema>.`),
-   grant to `stall_poster` (rewritten to the tenant role),
-   use `ENABLE ALWAYS` on every guard trigger,
-   add `..._no_truncate` triggers using `ledger_reject_truncate()`.

### 4.2 Vendor payout mode

```sql
ALTER TABLE vendors ADD COLUMN payout_mode text NOT NULL DEFAULT 'pay';
ALTER TABLE vendors ADD CONSTRAINT vendors_payout_mode_chk
    CHECK (payout_mode IN ('pay', 'credit_only', 'hold'));
```

-   `pay` — settled by a run (default).
-   `credit_only` — never settled; the payable accrues and the vendor draws against it at the POS (`vendor_draw`). Excluded from every run.
-   `hold` — temporarily excluded (dispute, missing agreement). Payable and rent both accrue.

`payout_mode` is identity, not money; it is editable any time and needs no guard beyond the CHECK.

### 4.3 Mall payout settings

```sql
ALTER TABLE mall ADD COLUMN payout_cadence text NOT NULL DEFAULT 'monthly';
ALTER TABLE mall ADD CONSTRAINT mall_payout_cadence_chk
    CHECK (payout_cadence IN ('weekly', 'biweekly', 'monthly', 'ad_hoc'));

ALTER TABLE mall ADD COLUMN rent_prepaid_from_prior_sales boolean NOT NULL DEFAULT false;
```

-   `payout_cadence` drives the default sales window and rent month on the screen. It is a _default_, not a constraint; an operator can always choose `ad_hoc`.
-   `rent_prepaid_from_prior_sales` is the tenant option the live mall uses: prior-period sales pay next-month rent. When `false`, rent is settled in the same month it is charged (the current kernel behaviour).

### 4.4 Store-purchase account

The store buying from a vendor is revenue-neutral to the vendor's payable and posts to an expense account. Add account `6100 Store purchases` and the `store_purchases` role:

```sql
INSERT INTO accounts (id, book_id, code, name, account_type, normal_balance, is_postable, is_control, subledger_type)
SELECT '01900000-0000-7000-8000-000000000020', '01900000-0000-7000-8000-000000000001',
       '6100', 'Store purchases', 'expense', 'debit', true, false, NULL
WHERE NOT EXISTS (SELECT 1 FROM accounts WHERE id = '01900000-0000-7000-8000-000000000020');

INSERT INTO chart_roles (role, account_id)
SELECT 'store_purchases', '01900000-0000-7000-8000-000000000020'
WHERE NOT EXISTS (SELECT 1 FROM chart_roles WHERE role = 'store_purchases');
```

> Rationale: "business use" is consumption, so expense is the honest default. If the mall buys to _resell_, the accountant reclassifies `6100` to an inventory asset later; the journal shape does not change. (Note this in the UI help text.)

### 4.5 `chart_source_rules` additions (mandatory) **\[v2 — new source split\]**

The kernel's `stall_reject_control_source()` refuses any chart line whose role is not listed for the journal's `source_type`. Every new source type therefore needs its rules, or the first post fails with _"a chart account line must come from a listed source type."_ Add:

```sql
INSERT INTO chart_source_rules (role, source_type)
SELECT seed.role, seed.source_type
FROM (
    VALUES
        ('vendor_payable',         'settlement'),
        ('booth_rent_receivable',  'settlement'),
        ('vendor_payable',         'check_disbursement'),
        ('bank',                   'check_disbursement'),
        ('vendor_payable',         'cash_disbursement'),
        ('cash',                   'cash_disbursement'),
        ('cash_in_safe',           'cash_disbursement'),   -- [v2.2] a safe payout cannot post without this
        ('store_purchases',        'store_purchase'),
        ('vendor_payable',         'store_purchase')
) AS seed(role, source_type)
WHERE NOT EXISTS (
    SELECT 1 FROM chart_source_rules AS existing
    WHERE existing.role = seed.role AND existing.source_type = seed.source_type
);
```

The old `payout` source is **retired** (§6.5). `vendor_payable`, `booth_rent_receivable`, `cash`, and `bank` keep their existing `sale`/`rent_charge`/`rent_payment`/`adjustment` rules.

### 4.6 `payout_runs`

```sql
CREATE TABLE payout_runs (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    book_id uuid NOT NULL REFERENCES ledger_books (id),
    run_no bigint NOT NULL,
    kind text NOT NULL,
    cadence text NOT NULL,
    sales_from date NOT NULL,
    sales_to date NOT NULL,
    rent_month date,
    tender text NOT NULL,
    status text NOT NULL DEFAULT 'draft',
    posting_date date NOT NULL,
    note text,
    reviewed_checksum text,          -- [v2] what the operator last reviewed
    reviewed_at timestamptz,         -- [v2]
    created_at timestamptz NOT NULL DEFAULT clock_timestamp(),
    settled_at timestamptz,
    paid_at timestamptz,
    reversed_at timestamptz,
    reverses_run_id uuid REFERENCES payout_runs (id),
    CONSTRAINT payout_runs_kind_chk    CHECK (kind IN ('batch', 'single')),
    CONSTRAINT payout_runs_cadence_chk CHECK (cadence IN ('weekly', 'biweekly', 'monthly', 'ad_hoc')),
    CONSTRAINT payout_runs_tender_chk  CHECK (tender IN ('drawer_cash', 'safe_cash', 'check')),
    CONSTRAINT payout_runs_status_chk  CHECK (status IN ('draft', 'settled', 'paid', 'reversed')),
    CONSTRAINT payout_runs_window_chk  CHECK (sales_from <= sales_to),
    CONSTRAINT payout_runs_rent_chk    CHECK (rent_month IS NULL OR rent_month = date_trunc('month', rent_month)::date),
    CONSTRAINT payout_runs_note_chk    CHECK (note IS NULL OR (note = btrim(note) AND note <> '' AND char_length(note) <= 2000)),
    CONSTRAINT payout_runs_reversal_chk CHECK (
        (reverses_run_id IS NULL) OR (status = 'settled' AND reverses_run_id <> id)
    ),
    CONSTRAINT payout_runs_book_no_key UNIQUE (book_id, run_no),
    CONSTRAINT payout_runs_book_id_key UNIQUE (book_id, id)
);
```

-   `run_no` is assigned by a per-book counter, mirroring `book_journal_counters` (§4.10).
-   `tender` chooses the disbursement credit account and whether a register session is required (§6.5): `drawer_cash` → credit `cash` + session; `safe_cash` → credit `cash_in_safe`, no session; `check` → credit `bank`, no session, check number recorded.
-   `status` lifecycle is guarded (§6.4). **\[v2\]** `settled` (netting done, no cash) is distinct from `paid` (checks/cash out).
-   `reviewed_checksum`/`reviewed_at` record exactly what the operator saw, so post-time re-verify can detect drift (§6.3).

### 4.7 `payout_run_lines` — the frozen per-vendor report **\[v2\]**

```sql
CREATE TABLE payout_run_lines (
    id uuid PRIMARY KEY DEFAULT uuidv7(),
    run_id uuid NOT NULL REFERENCES payout_runs (id),
    vendor_id uuid NOT NULL REFERENCES vendors (id),
    mode text NOT NULL,
    sales_minor bigint NOT NULL DEFAULT 0,
    commission_minor bigint NOT NULL DEFAULT 0,
    tax_minor bigint NOT NULL DEFAULT 0,
    discount_minor bigint NOT NULL DEFAULT 0,          -- [v2.2] discounts in the window (lower the payable)
    return_minor bigint NOT NULL DEFAULT 0,            -- [v2.2] item returns in the window (debit the payable)
    void_minor bigint NOT NULL DEFAULT 0,              -- [v2.2] voids/reversals in the window (debit the payable)
    draw_minor bigint NOT NULL DEFAULT 0,
    check_paid_minor bigint NOT NULL DEFAULT 0,        -- [v2.2] checks already written in the window (debit the payable)
    rent_minor bigint NOT NULL DEFAULT 0,              -- rent CHARGED for rent_month this run
    rent_brought_forward_minor bigint NOT NULL DEFAULT 0,  -- [v2.2] prior open rent at window opening
    rent_paid_since_minor bigint NOT NULL DEFAULT 0,   -- [v2.2] rent payments in the window
    adjustment_minor bigint NOT NULL DEFAULT 0,
    brought_forward_minor bigint NOT NULL DEFAULT 0,   -- [v2.1] open payable carried in from earlier months
    carried_payable_minor bigint NOT NULL DEFAULT 0,
    carried_rent_minor bigint NOT NULL DEFAULT 0,      -- [v2.1] ALL open rent netted (every month)
    settled_minor bigint NOT NULL DEFAULT 0,       -- min(P,R): payable applied to rent
    check_minor bigint NOT NULL DEFAULT 0,         -- max(0,P-R): disbursement amount
    owes_minor bigint NOT NULL DEFAULT 0,          -- max(0,R-P): shortfall
    net_minor bigint NOT NULL DEFAULT 0,           -- P - R (signed)
    settlement_journal_id uuid REFERENCES journals (id),
    disbursement_journal_id uuid REFERENCES journals (id),
    check_number text,
    CONSTRAINT payout_run_lines_mode_chk CHECK (mode IN ('pay', 'credit_only', 'hold')),
    CONSTRAINT payout_run_lines_run_vendor_key UNIQUE (run_id, vendor_id),
    CONSTRAINT payout_run_lines_check_number_chk CHECK (
        check_number IS NULL OR (check_number = btrim(check_number) AND check_number <> '')
    )
);
```

Column meanings (all minor units, signed where noted):

| Column | Meaning |
| --- | --- |
| `sales_minor` | Gross sold price of this vendor's items in `[sales_from, sales_to]` (before discount). |
| `discount_minor` | **\[v2.2\]** Discounts given on those sales in the window. A discount lowers the sold price, so it lowers the payable. |
| `commission_minor` | Store take on the discounted price. |
| `tax_minor` | Sales tax collected (pass-through; shown, never deducted from the vendor). |
| `return_minor` | **\[v2.2\]** Item returns in the window (debit the payable). |
| `void_minor` | **\[v2.2\]** Voids (reversals of a sale) in the window (debit the payable). |
| `draw_minor` | `vendor_draw` taken at the POS in the window (debits the payable). |
| `check_paid_minor` | **\[v2.2\]** Checks already written in the window (debit the payable). |
| `rent_minor` | Rent **charged** for `rent_month` on the vendor's assigned booths (the new charge this run ensures). |
| `rent_brought_forward_minor` | **\[v2.2\]** Prior open rent at the opening of the window. |
| `rent_paid_since_minor` | **\[v2.2\]** Rent payments made in the window. |
| `adjustment_minor` | Net non-sale payable movements in the window (e.g. `store_purchase` credits, manual adjustments). |
| `brought_forward_minor` | **\[v2.1\]** Open vendor payable carried in from **earlier months** at the opening of the run's window (before this window's movements). |
| `carried_payable_minor` | Open vendor payable at post time (= `P`), **including** `brought_forward_minor`. |
| `carried_rent_minor` | **\[v2.1\]** **All** open booth rent receivable for the vendor at post time (= `R`), across **every** month — not just `rent_month`. Equals `rent_brought_forward_minor + rent_minor − rent_paid_since_minor`. |
| `settled_minor` | `min(P, R)` — the payable applied to rent. |
| `check_minor` | `max(0, P − R)` — the amount disbursed. |
| `owes_minor` | `max(0, R − P)` — the shortfall that stays open. |
| `net_minor` | Signed `P − R`; `≥ 0` pay vendor, `< 0` vendor owes store. |
| `settlement_journal_id` | The posted settlement journal (null for `credit_only`/`hold` or when `settled = 0`). |
| `disbursement_journal_id` | The posted disbursement journal (null when `check = 0`). |

### 4.8 `payout_run_sales` — the frozen sale detail

```sql
CREATE TABLE payout_run_sales (
    run_id uuid NOT NULL REFERENCES payout_runs (id),
    vendor_id uuid NOT NULL REFERENCES vendors (id),
    journal_id uuid NOT NULL REFERENCES journals (id),
    line_no integer NOT NULL,
    item_id uuid NOT NULL REFERENCES items (id),
    sold_on date NOT NULL,
    item_code text NOT NULL,                          -- [v2.2] frozen so a later item edit cannot change the reprint
    description text NOT NULL,                        -- [v2.2] frozen item description for the envelope
    price_minor bigint NOT NULL,
    commission_minor bigint NOT NULL,
    tax_minor bigint NOT NULL,
    discount_minor bigint NOT NULL DEFAULT 0,
    quantity integer NOT NULL DEFAULT 1,
    PRIMARY KEY (run_id, journal_id, line_no)
);
```

This is the line-item detail the envelope prints. It is copied from `sale_lines`/`items` at post time — **including the item code and description** — so the printed report never changes even if an item's description is later edited. **\[v2.2\] Storing the description is the whole point of copying the lines: without it, editing the item changes the reprinted envelope.**

### 4.9 `payout_run_journals` — exact reversal bookkeeping **\[v2 roles\]**

```sql
CREATE TABLE payout_run_journals (
    run_id uuid NOT NULL REFERENCES payout_runs (id),
    journal_id uuid NOT NULL REFERENCES journals (id),
    role text NOT NULL,
    PRIMARY KEY (run_id, journal_id),
    CONSTRAINT payout_run_journals_role_chk
        CHECK (role IN ('settlement', 'disbursement', 'rent_charge', 'reversal'))
);
```

Every journal a run creates (the rent charges it ensures, the settlement journals, the disbursement journals, and later the reversals) is recorded here. Reversal reads this table and reverses exactly those journals — nothing more, nothing less.

### 4.10 Run-number counter (mirror of `book_journal_counters`)

```sql
CREATE TABLE book_payout_run_counters (
    book_id uuid PRIMARY KEY REFERENCES ledger_books (id),
    last_no bigint NOT NULL,
    CONSTRAINT book_payout_run_counters_last_no_chk CHECK (last_no >= 0)
);

INSERT INTO book_payout_run_counters (book_id, last_no)
SELECT id, 0 FROM ledger_books
ON CONFLICT (book_id) DO NOTHING;
```

A `BEFORE INSERT ON payout_runs` trigger assigns `run_no` by `UPDATE ... RETURNING last_no + 1` with `FOR UPDATE`, exactly like `ledger_reject_journal_context()`. (The book is singular — `002_chart.sql` refuses a second book — so the seed above is complete.)

### 4.11 The preview view and checksum **\[v2\]**

The preview is a **live view** over the ledger for a run's parameters — it is not stored. It is the same projection `settle()` materializes at post, so there is exactly one definition of the money.

```sql
CREATE VIEW payout_pack AS
-- one row per vendor eligible for the run's window/rent_month, computed live:
--   booth, vendor, sales, commission, tax, draw, rent, carried_payable, carried_rent,
--   settled, check, owes, net, mode
-- reads journal_lines / sale_lines through sales_to and the vendor's booth assignments.
```

A companion function returns a **checksum** over the **full envelope** — the per-vendor figures **and every sale line** — so that a void plus a new sale landing on the same net cannot pass review with different underlying lines.

**\[v2.2\] The checksum has two entry points that must produce the identical string:**

-   **`stall_pack_checksum(run_id)`** — hashes the **live projection** (`payout_pack`), reading `journal_lines`/`sale_lines`. This is what the operator reviews.
-   **`stall_pack_checksum_frozen(run_id)`** — hashes the **stored rows** (`payout_run_lines` + `payout_run_sales`). This is what post verifies.

At review, `payout_run_sales` does not exist yet, so the checksum must read the **live** projection — not the stored rows. After post, the stored rows must hash to the **same** value the operator reviewed.

```sql
CREATE FUNCTION stall_pack_checksum(run_id uuid) RETURNS text
-- [v2.2] md5 over the live projection, deterministically ordered:
--   per vendor (ORDER BY vendor_id):
--     vendor_id || ':' || brought_forward_minor || ':' || discount_minor || ':' ||
--     return_minor || ':' || void_minor || ':' || draw_minor || ':' || check_paid_minor || ':' ||
--     rent_brought_forward_minor || ':' || rent_minor || ':' || rent_paid_since_minor || ':' ||
--     adjustment_minor || ':' || carried_payable_minor || ':' || carried_rent_minor || ':' || net_minor
--   then, per vendor, every live sale line (ORDER BY vendor_id, line_no):
--     vendor_id || '#' || line_no || ':' || item_id || ':' || quantity || ':' ||
--     price_minor || ':' || commission_minor || ':' || tax_minor || ':' || discount_minor
-- computed from the same projection as payout_pack.

CREATE FUNCTION stall_pack_checksum_frozen(run_id uuid) RETURNS text
-- [v2.2] the SAME string, computed from the stored payout_run_lines + payout_run_sales rows.
```

The checksum must cover **the sale detail**, not just the vendor totals: two runs with identical net and rent but different underlying sales (e.g. a void offset by a new sale) must hash differently.

The screen renders `payout_pack` and stores `stall_pack_checksum(run)` as `reviewed_checksum` when the operator confirms. `settle()` recomputes the **live** checksum and refuses if it changed. `disburse()` recomputes the **frozen** checksum, refuses if it differs from `reviewed_checksum`, and **separately** asserts the live open payable still equals the frozen `check_minor` (§6.2). One definition of the money, two hashing modes — **never a live-vs-live comparison after settlement**, which could never match.

### 4.12 Append-only guards

```sql
-- [v2.2] Column-aware freeze: money columns freeze at settle; the check number and the
-- disbursement journal are written exactly once, afterward, then they freeze too.
CREATE OR REPLACE FUNCTION stall_reject_posted_run_line()
RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog AS $$
DECLARE run_status text;
BEGIN
    SELECT status INTO run_status FROM public.payout_runs
    WHERE id = COALESCE(NEW.run_id, OLD.run_id);

    IF TG_OP = 'DELETE' THEN
        IF run_status <> 'draft' THEN
            RAISE EXCEPTION 'a settled payout run report cannot change' USING ERRCODE = '23514';
        END IF;
        RETURN OLD;
    END IF;

    IF run_status = 'draft' THEN
        RETURN NEW;                                    -- free to edit before close
    END IF;

    -- Frozen once settled: every money column must be byte-identical.
    IF (NEW.sales_minor, NEW.discount_minor, NEW.commission_minor, NEW.tax_minor,
        NEW.return_minor, NEW.void_minor, NEW.draw_minor, NEW.check_paid_minor,
        NEW.rent_minor, NEW.rent_brought_forward_minor, NEW.rent_paid_since_minor,
        NEW.adjustment_minor, NEW.brought_forward_minor, NEW.carried_payable_minor,
        NEW.carried_rent_minor, NEW.settled_minor, NEW.check_minor, NEW.owes_minor,
        NEW.net_minor, NEW.mode, NEW.vendor_id, NEW.run_id)
       IS DISTINCT FROM
       (OLD.sales_minor, OLD.discount_minor, OLD.commission_minor, OLD.tax_minor,
        OLD.return_minor, OLD.void_minor, OLD.draw_minor, OLD.check_paid_minor,
        OLD.rent_minor, OLD.rent_brought_forward_minor, OLD.rent_paid_since_minor,
        OLD.adjustment_minor, OLD.brought_forward_minor, OLD.carried_payable_minor,
        OLD.carried_rent_minor, OLD.settled_minor, OLD.check_minor, OLD.owes_minor,
        OLD.net_minor, OLD.mode, OLD.vendor_id, OLD.run_id)
    THEN
        RAISE EXCEPTION 'a settled payout run money line cannot change' USING ERRCODE = '23514';
    END IF;

    -- Write-once: the check number and the disbursement journal fill exactly once, while settled.
    IF OLD.check_number IS NOT NULL AND NEW.check_number IS DISTINCT FROM OLD.check_number THEN
        RAISE EXCEPTION 'a check number is written once' USING ERRCODE = '23514';
    END IF;
    IF OLD.disbursement_journal_id IS NOT NULL
       AND NEW.disbursement_journal_id IS DISTINCT FROM OLD.disbursement_journal_id THEN
        RAISE EXCEPTION 'a disbursement journal is written once' USING ERRCODE = '23514';
    END IF;
    IF run_status <> 'settled'
       AND (NEW.check_number IS DISTINCT FROM OLD.check_number
            OR NEW.disbursement_journal_id IS DISTINCT FROM OLD.disbursement_journal_id) THEN
        RAISE EXCEPTION 'a check is written only while the run is settled' USING ERRCODE = '23514';
    END IF;

    RETURN NEW;
END $$;

CREATE TRIGGER payout_run_lines_frozen
    BEFORE UPDATE OR DELETE ON payout_run_lines
    FOR EACH ROW EXECUTE FUNCTION stall_reject_posted_run_line();
CREATE TRIGGER payout_run_sales_frozen
    BEFORE UPDATE OR DELETE ON payout_run_sales
    FOR EACH ROW EXECUTE FUNCTION stall_reject_posted_run_line();
```

`payout_run_journals` is append-only outright (no UPDATE/DELETE). `payout_runs` is mutable only through the guarded status transitions in §6.4.

All guards get `ENABLE ALWAYS` and matching `..._no_truncate` triggers, and the new tables get `GRANT SELECT, INSERT, UPDATE, DELETE ... TO stall_poster; REVOKE TRUNCATE, TRIGGER ...` plus `GRANT SELECT ... TO stall_backup`, matching `028`/`031`.

* * *

## 5\. The money math — settlement then disbursement **\[v2\]**

For a vendor in a run, with `P` = open vendor payable at post time and `R` = **all** open booth rent receivable at post time — **every month, not just `rent_month`** (R is `0` for a vendor with no booth — consignor mode).

> **\[v2.1\] Two distinct jobs, never conflated.** `rent_month` decides only **which rent charges the run ensures exist** (idempotently, the new month's). `R` — the rent the run **nets** — is the vendor's **entire** open rent, so an older shortfall (e.g. September) is always swept into a later run and can never sit open forever. This is exactly what the existing kernel `stall_assert_payout()` already does: it clears the whole open payable and the whole open rent.

```
applied  = min(P, R)          -- the payable applied to rent (settlement)
check    = max(0, P − R)      -- the amount disbursed (disbursement)
owes     = max(0, R − P)      -- the shortfall that stays open
net      = P − R              -- signed
```

Two journals, posted as **two steps** (§6) — settlement first, then disbursement. They are **not** one transaction: the operator reviews, closes (settlement), then writes checks (disbursement). **\[v2.2\]**

```
Settlement   (source 'settlement', no cash):
    Dr  vendor_payable            applied
    Cr  booth_rent_receivable     applied        (split across the vendor's open booths, oldest first)

Disbursement (source 'check_disbursement' | 'cash_disbursement', only when check > 0):
    Dr  vendor_payable            check
    Cr  <tender account>          check          -- bank (check), cash (drawer), cash_in_safe (safe)
```

Identities that always hold:

-   `applied + check = P` — the payable is always fully cleared (when `mode='pay'`).
-   `applied + owes = R` — rent is fully cleared iff `P ≥ R`.
-   `check − owes = net = P − R`.

Cases:

-   **`P ≥ R`**: settlement clears all rent (`applied = R`); disbursement pays `P − R`. Both subledgers clear.
-   **`0 < P < R`**: settlement applies all of `P` to rent (`applied = P`); no disbursement; `R − P` stays open as "owes store."
-   **`P = 0`, `R > 0`**: nothing to apply; **no journal**; the report shows the full rent as owed.
-   **`P = 0`, `R = 0`**: the vendor is skipped.

**Booth clearing order (deterministic):** clear booths by the rent charge's `posting_date` ascending, then `booths.code` ascending, each up to its own open amount, until `applied` is exhausted. This makes partial clearing reproducible and auditable.

**Report reconciliation invariant (hard):** for every `mode='pay'` line, the payable identity is the **ledger identity** — opening plus every payable movement in the window equals the payable at post:

```
carried_payable_minor  ==  brought_forward_minor
                          + Σ( vendor_payable credits − vendor_payable debits in [sales_from, sales_to] )
```

and the frozen columns are a **breakdown** of those movements, which must reconcile to the same number:

```
carried_payable_minor  ==  brought_forward_minor
                          + sales_minor − discount_minor − commission_minor
                          − return_minor − void_minor − draw_minor − check_paid_minor
                          + adjustment_minor
```

The rent breakdown must reconcile to the rent netted:

```
carried_rent_minor  ==  rent_brought_forward_minor + rent_minor − rent_paid_since_minor
net_minor  == carried_payable_minor − carried_rent_minor
settled_minor == min(carried_payable_minor, carried_rent_minor)
check_minor   == max(0, carried_payable_minor − carried_rent_minor)
owes_minor    == max(0, carried_rent_minor − carried_payable_minor)
```

> **\[v2.2\] Why the ledger identity, not a column sum.** v2.1's column sum omitted real movements. A discount lowers the sold price — `032_tickets.sql` computes the store take as `half_up(price − discount)` and the tender as `prices − discounts + taxes`; `034_item_return.sql` proves the payable debit on a return is `price − discount − commission`. A void (a reversal of the sale) debits the payable. And when the tenant pays on the 1st, the check is dated inside the _next_ window — it is neither brought forward nor a draw, so it must appear as `check_paid_minor`. The enforced assertion computes the movement **directly from `journal_lines`** (excluding the run's own journals); the columns are the human-readable breakdown and are asserted to sum to it.

> **\[v2.1\] `brought_forward_minor` is mandatory in the identity.** Without it the identity only holds when nothing carried in from an earlier month; a vendor unpaid in February would fail the identity in March and the run would refuse. `brought_forward_minor` is the payable at the opening of the window, `carried_payable_minor` is the payable at post time, and the period movements bridge the two.

> **\[v2.1\] `rent_minor` (charged) and `carried_rent_minor` (netted) are deliberately different numbers.** `rent_minor` is the new month's charge; `carried_rent_minor` is **all** open rent. The old `rent_minor == carried_rent_minor` equality is dropped — it was only true when no older rent was outstanding, which is the exact case that stranded a shortfall.

These are **stored, enforced invariants**: `settle()` asserts them as deferred constraints (the kernel's existing assertion pattern), so a run can never post a broken envelope. If the run cannot satisfy them, it refuses to post. This is the anti-divergence guarantee: the printed report _is_ the ledger.

> `adjustment_minor` captures `store_purchase` credits and any other non-sale payable movement in the window, so the identity holds even when the store buys from the vendor.

* * *

## 6\. Lifecycle and the engine

### 6.1 `settle(run_id)` — netting, no cash **\[v2\]**

```
settle(run_id):
  BEGIN TRANSACTION
    SELECT ... FROM payout_runs WHERE id = run_id FOR UPDATE          -- lock the run
    assert status = 'draft'
    SELECT ... FROM ledger_books WHERE id = run.book_id FOR UPDATE     -- serialise run_no + period
    assert stall_pack_checksum(run_id) = run.reviewed_checksum         -- post-time re-verify (§6.3)
    FOR each eligible vendor (mode='pay'):
      SELECT ... FROM vendors WHERE id = vendor FOR UPDATE             -- serialise this vendor
      -- [v2.2] ensure the rent charge for rent_month exists (idempotent by posting_key).
      -- The charge's posting_date MUST fall inside rent_month: the kernel derives both the
      -- key and the stored rate from posting_date (022_assignment_rent.sql). Date it the
      -- first day of rent_month. A March charge cannot carry an April 1 date.
      FOR each assigned booth of the vendor covering rent_month:
          post rent_charge if absent  (posting_key 'rent-charge:<booth>:<YYYY-MM>',
                                       posting_date = first day of rent_month)
          record in payout_run_journals(run_id, journal_id, 'rent_charge')
      P := live open payable (subledger_type='vendor', ref=vendor)          -- includes brought-forward
      bf := open payable BEFORE this window's movements (brought_forward_minor)
      R := live open rent for the vendor across EVERY month (all open booths) -- [v2.1] not rent_month only
      applied := min(P, R)
      materialize payout_run_lines (bf, carried P/R, rent breakdown, settled/check/owes/net) and payout_run_sales
      IF applied > 0:
          -- [v2.2] key on the RUN, not the month, so weekly/biweekly/ad-hoc runs can coexist
          post settlement journal (posting_key 'settlement:<run_id>:<vendor>',
                                   posting_date = max(click day, first day of rent_month))
          record in payout_run_journals(run_id, journal_id, 'settlement')
          UPDATE payout_run_lines SET settlement_journal_id = ...
    UPDATE payout_runs SET status='settled', settled_at=now() WHERE id = run_id
    SET CONSTRAINTS ALL IMMEDIATE        -- fire every deferred kernel invariant now
  COMMIT
```

`settle()` writes the settlement journals and freezes the report. It moves **no cash** and needs **no register session**. A vendor who owes, or is `credit_only`/`hold`, is recorded but not settled.

### 6.2 `disburse(run_id, start_check_number)` — the checks / drawer **\[v2\]**

```
disburse(run_id, start_check_number):
  BEGIN TRANSACTION
    SELECT ... FROM payout_runs WHERE id = run_id FOR UPDATE
    assert status = 'settled'
    -- [v2.2] verify the FROZEN envelope, not the live ledger (settlement already moved the payable)
    assert stall_pack_checksum_frozen(run_id) = run.reviewed_checksum
    FOR each line WHERE mode='pay' AND check_minor > 0:
      SELECT ... FROM vendors WHERE id = line.vendor_id FOR UPDATE
      P := live open payable (must still equal line.check_minor)     -- separate live re-verify
      assert P = line.check_minor
      -- [v2.2] key on the RUN, not the month
      post disbursement journal (posting_key 'disbursement:<run_id>:<vendor>')
        Dr vendor_payable check_minor / Cr <tender account> check_minor
      record in payout_run_journals(run_id, journal_id, 'disbursement')
      UPDATE payout_run_lines SET disbursement_journal_id = ..., check_number = ...   -- write-once (§4.12)
    UPDATE payout_runs SET status='paid', paid_at=now() WHERE id = run_id
    SET CONSTRAINTS ALL IMMEDIATE
  COMMIT
```

-   **`check`**: credit `bank`; assign sequential check numbers from `start_check_number`; no session.
-   **`drawer_cash`**: credit `cash`; requires an open register session; lowers expected drawer cash.
-   **`safe_cash`**: credit `cash_in_safe`; no session.
-   A disbursement above the remaining payable is refused by the re-verify. It never touches rent.

### 6.3 Preview and post-time re-verify **\[v2\]**

-   The screen renders `payout_pack` (live) and stores `stall_pack_checksum(run)` as `reviewed_checksum` when the operator confirms.
-   `settle()` recomputes the **live** checksum and **refuses** if it differs — "the ledger moved since you reviewed; re-review." This is what makes "preview vs run" drift impossible: the run can only post exactly what was reviewed.
-   **\[v2.2\]** `disburse()` recomputes the **frozen** checksum (`stall_pack_checksum_frozen`) and refuses if it differs from `reviewed_checksum`; it then separately asserts the live open payable still equals the frozen `check_minor`. It must **not** re-hash the live ledger against the review — settlement already reduced the payable, so that comparison could never match.
-   Because the checksum is computed by the same SQL projection the view uses, there is no second formula — the live hash at review and the frozen hash after post are the same string.

### 6.4 Status transitions (guarded)

```
draft ──settle──▶ settled ──disburse──▶ paid
  │                  │
  └──discard──▶ (deleted)   └──reverse──▶ reversed (via a new reversal run)
```

-   `draft → settled` only through `settle()`.
-   `settled → paid` only through `disburse()`.
-   `settled|paid → reversed` only by posting a **reversal run** (§6.6). A `draft` run may be deleted outright.
-   No backward transitions. A `BEFORE UPDATE ON payout_runs` trigger enforces the allowed edges and freezes `sales_from/sales_to/rent_month/tender/kind` once `status <> 'draft'`.

### 6.5 Tender and the register session **\[v2 — simpler than v1\]**

Because settlement and disbursement are separate sources, the rule is simple and needs no tender-aware hack:

-   `settlement` — **not** a drawer source; no session ever.
-   `check_disbursement` — **not** a drawer source; no session; records a check number.
-   `cash_disbursement` — **is** a drawer source; requires an open register session.

Amend `sql/029_register_posts.sql`: in `stall_reject_session_post()` **remove `payout`** and **add `cash_disbursement`**; in `stall_assert_register_session()` require a session for `cash_disbursement` (and keep the existing list minus `payout`). Retire the `payout` source entirely — `007_payout.sql`'s `stall_assert_payout()` is replaced to refuse any `payout` journal.

> This supersedes v1's tender-aware change to `stall_assert_register_session()`. It is cleaner: the netting event is not a cash event, so it simply is not a drawer source.

### 6.6 Reversal (undo)

`reverse(run_id)`:

1.  Lock the run; assert `status IN ('settled','paid')`.
2.  Create a new `payout_runs` row: `kind` mirrors the original, `reverses_run_id = run_id`, `status = 'settled'`, same window/tender, `note = 'Reversal of run <run_no>'`.
3.  For each journal in `payout_run_journals` for the original (disbursements first, then settlements, then any `rent_charge` the run created), post a reversal journal (`is_reversal = true`, `reverses_journal_id = <original>`), swapping debit/credit sides exactly — `ledger_assert_reversal()` already enforces the swap. Record each in the reversal run's `payout_run_journals` with `role='reversal'`.
4.  Set the original run `status='reversed'`, `reversed_at=now()`.
5.  `SET CONSTRAINTS ALL IMMEDIATE`.

Reversing a run restores the payable and rent to their exact pre-run values. The reversal run is itself visible in the run list and reprintable. `credit_only`/`hold` vendors have no journals, so nothing is reversed for them.

> **Order matters:** disbursements are reversed before settlements, so the payable never dips below zero mid-unwind (the kernel's `stall_assert_subledger_non_negative` would otherwise refuse). This is the exact undo the live app lacked — a guard is not an undo.

* * *

## 7\. Rent-in-advance (the tenant option)

When `mall.rent_prepaid_from_prior_sales = true`:

-   A **monthly** run on the 1st of month _M_ has `sales_from = first day of M−1`, `sales_to = last day of M−1`, `rent_month = M`.
-   The run **ensures the `M` rent charges exist** (idempotent `rent-charge:<booth>:<YYYY-MM>`). This is the _only_ job `rent_month` does.
-   The run then applies the payable to the vendor's **entire** open rent — the new `M` charge **plus any older shortfall** (`R` = all open rent, §5). A prior month's shortfall is swept in here, never stranded.
-   Example: the Oct 1 run uses September sales (`sales_from = 2026-09-01`, `sales_to = 2026-09-30`) to pay October rent (`rent_month = 2026-10-01`). If September sales cover October rent **and any earlier shortfall**, the leftover is a check and all rent is marked paid; if not, the difference stays open as "owes store" and is picked up by the next run.

The rent charge and the settlement are written **in the same transaction**, so a run is never half-applied.

When `rent_prepaid_from_prior_sales = false`, the window and `rent_month` are the same month (current kernel behaviour).

The run's `rent_month` and `sales_from/sales_to` are stored on the run, so the report always says exactly which month's rent and which sales window it covered.

* * *

## 8\. `store_purchase` — the store buys from a vendor

New source type `store_purchase`, its own journal shape:

```
Dr  store_purchases (6100)     amount
Cr  vendor_payable             amount   (subledger_type='vendor', ref=<vendor>)
```

Rules:

-   **No tax** (a purchase for resale/business use does not carry sales tax here).
-   **No commission** by default (the store is the buyer, not the broker).
-   `posting_key = 'store-purchase:' || <reference>`; `source_reference` = a human reference (invoice/note).
-   Works for owner-vendor accounts too (the two owners' personal-selling accounts are ordinary vendor accounts).
-   The vendor's payable rises, so the **next payout run pays the store's purchase** along with everything else — consistent with "the store is never a payee."
-   It appears in `vendor_statement` and in the run's `adjustment_minor`.

A guard `stall_assert_store_purchase()` (deferred constraint trigger on `journals`) enforces exactly two lines: one `store_purchases` debit, one `vendor_payable` credit with `subledger_type='vendor'`, equal amounts, `amount > 0`.

* * *

## 9\. Operator screen **\[v2 — one screen\]**

One primary screen plus a history route, both under `/<slug>/payouts`.

### 9.1 "Pay vendors" (the one screen)

-   Titled with the period (`March payout`). No From, To, scope, or minimum fields.
-   **The table is the pack**, in booth order: vendor, booth, sales, store fee, their share, rent, already taken, check, owes the store, mode. **\[v2.2\]** The rent column expands to the three figures that add up to the rent being netted — **owed before + this month's charge − paid since** — so the check always equals their share minus the printed rent. The footer shows the count and total of checks, the count and total owed to the store, and the envelope count.
-   **Buttons, in order:**
    1.  **Print the pack** — store summary first, then one page per vendor, page break between them. Same `window.print()` path as `src/Feature/Pos/receipt.php`. No PDF library and no second formula in the template.
    2.  **Close March** — one transaction; `settle()` for the whole run, or none of it. **\[v2.2\] It posts any missing rent charge itself (§6.1), so it is never disabled for missing rent.**
    3.  **Write checks** — starting number, then `disburse()` (one bank journal per check row), then the check pages.
-   **Pay this vendor** uses the same row and the same functions: a one-vendor `single` run through `settle()`/`disburse()`. If this month's rent is not charged yet, the only action offered is a draw, and the sentence says rent is not taken out.
-   Any refusal from `settle()` (checksum drift) is shown inline with a **Re-review** action.

### 9.2 "Past payouts" (history route)

-   A table of past runs: `run_no`, period, `rent_month`, tender, status, total paid, total owed, and a link to the **stored** report. Filtered by the day the period was closed. The export lives here.
-   A **New run** button. For `monthly` cadence it pre-fills the window and rent month from today; the operator can override.
-   A one-line "Vendors excluded" note: `credit_only` and `hold` vendors are listed so the operator knows who is not being paid.

### 9.3 Envelope print

-   **One page per vendor**, `@media print` with `page-break-after: always`, **including vendors who are owed nothing** (a zero-activity booth is on the pack and has no journal) — the reports go into envelopes whether they get paid or not.
-   Each envelope shows: mall name, vendor name and code, booth, period, the **sale detail** (item, date, price, commission, tax), a deductions block (commission, draws, rent), the **net** as "Check: $X" or "Amount owed to store: $X", the check number line, and a footer "Report / — reprinted from the stored record."
-   A **summary sheet** first (all vendors, totals) for the operator's file.
-   The print stylesheet uses web-safe fonts, A4/Letter `@page` margins, and no background fills, so it prints cleanly on any printer.

### 9.4 UI tone (grandma-friendly)

-   One primary action per screen, large buttons, plain words ("Pay everyone", "Print the pack"), no accounting jargon on the operator screens (jargon lives only on the journal detail page).
-   Money always shown as `$1,234.56`; "owes store" shown in plain red text, never as a negative number without a label.
-   The screen mirrors the live workflow exactly: **review the pack, then close, then write checks, then print envelopes** — just with the report now stored and the math now single-sourced.

* * *

## 10\. Live-tenant opening reconciliation (one-time)

The live tenant has drifted balances. Before the new system runs, open it clean:

1.  Run a **reconciliation report** (read-only) that prints, per vendor, the current `P` and `R` from the _reconciled_ books and the difference from the live app's numbers.
2.  Post **one opening journal per book** (source type `opening`, posting key `opening` — the kernel already allows exactly one live opening) that sets every vendor's payable and every booth's rent receivable to the reconciled figure, with the offset to a **`2900 Opening reconciliation`** equity account so the books balance.
3.  Freeze the reconciled figures in the run report so the first run's `carried_payable_minor`/`carried_rent_minor` match the opening.

This is a one-time, auditable, reversible-by-reversal step. It must be reviewed and signed off before the first live run.

* * *

## 11\. `prove_payout_run.sql` — the invariants

One transaction, then `ROLLBACK`, following `prove_payout.sql`. It must prove:

1.  **Zero-exactly-selected.** A run whose eligible set is empty posts no journals and ends `status='settled'` with zero lines settled.
2.  **Idempotent re-run.** Posting the same run/vendor twice is refused by `journals_book_key`; a second `settle()` on a settled run is a no-op refusal.
3.  **`credit_only` gets no journal.** A `credit_only` vendor's payable is untouched and no settlement or disbursement journal exists.
4.  **Rent charged once.** Re-running the rent-charge step does not duplicate `rent-charge:<booth>:<YYYY-MM>`.
5.  **Shortfall → no cash movement.** When `P < R`, the settlement credits only `booth_rent_receivable`, there is no disbursement journal, and the booth's open rent equals `R − P` afterwards.
6.  **Settlement then disbursement.** After `settle()`, the payable equals `check_minor` and no cash has moved; after `disburse()`, the payable is zero and the tender account moved by `check_minor`.
7.  **Reversal restores exactly.** After `reverse(run)`, every vendor's payable and rent equal their pre-run values, and every original journal has exactly one reversal. Reversing with a disbursement posted does not drive the payable negative.
8.  **Report re-render equals original.** Re-reading the stored `payout_run_lines`/`payout_run_sales` after posting yields byte-identical figures; editing a settled line is refused.
9.  **The ledger identity holds.** For every `mode='pay'` line, `carried_payable == brought_forward + Σ(vendor_payable credits − debits in the window)`, and the frozen columns sum to the same number.
10.  **Brought-forward is exercised.** A vendor left open in month _M_ and paid in _M+1_ posts a run whose `brought_forward_minor > 0`, whose identity holds, and whose payable clears to zero — the exact case that failed the v2 identity.
11.  **All open rent is swept.** A vendor with an older open rent shortfall (month _M_) and a new charge (_M+1_) has both netted by the _M+1_ run: `carried_rent_minor` equals the sum of every open month, and no shortfall is left stranded.
12.  **\[v2.2\] A discounted sale does not break the identity.** A window containing a discounted sale posts a run whose identity holds — the discount is carried as `discount_minor`, not dropped.
13.  **\[v2.2\] A return, a void, and a prior check are all in the identity.** A window containing an item return, a void (reversal), and a check written on the 1st each posts a run whose identity holds — carried as `return_minor`, `void_minor`, `check_paid_minor`.
14.  **\[v2.2\] The rent breakdown reconciles.** `rent_brought_forward + rent_charged − rent_paid_since == carried_rent`, and the check equals their share minus the printed rent.
15.  **\[v2.2\] The live hash equals the frozen hash.** `stall_pack_checksum(run)` taken at review equals `stall_pack_checksum_frozen(run)` taken after post — the same string.
16.  **Checksum drift refused.** Change a sale between review and `settle()`; the checksum differs and the run refuses. A void plus a new sale with the **same net** also changes the checksum (the sale detail is hashed), so the run refuses.
17.  **\[v2.2\] The check number is written once.** `disburse()` writes `check_number`/`disbursement_journal_id` once; a second write, or any change to a money column after settle, is refused.
18.  **\[v2.2\] The posting key is the run.** Two runs for the same vendor in one month (weekly, biweekly, or a second ad-hoc pay) both post — the keys differ by `run_id`.
19.  **\[v2.2\] A safe payout posts.** A `safe_cash` run credits `cash_in_safe` and posts — the source rule is present.
20.  **Concurrent double-post refused.** Two sessions calling `settle()` on the same run: one wins, the other is refused (§12).
21.  **`store_purchase` shape.** A store purchase posts exactly two lines and raises the vendor's payable; a run then clears it.

* * *

## 12\. Concurrency

-   `settle()`/`disburse()` lock the run (`FOR UPDATE`), the book, and each vendor (`FOR UPDATE`) before reading balances — mirroring `stall_reject_unknown_subledger()`'s vendor lock.
-   The `posting_key` uniqueness (`journals_book_key`) is the backstop against double-posting.
-   Add **`bin/prove-race-payout.sql`** (two sessions, `SELECT ... FOR UPDATE` ordering) and a `bin/prove-race.php` case, following the existing race-proof pattern (`prove_race_*_a.sql` / `_b.sql`).

* * *

## 13\. Deferred — external payouts (ACH / Zelle / Venmo) **\[v2\]**

**Not built now.** This section exists so the _architecture_ does not foreclose it. The feature is a one-vendor, `ad_hoc` run with an external tender — a bolt-on, not a subsystem.

### 13.1 The model (when built)

-   **Consignor requests, tenant approves.** The consignor sees "you're owed $X" in their portal and clicks **Request payout**; it lands in the owner's queue as a pending request; the owner approves (auto-approve optional, off by default). A request is a one-vendor `draft` run; approval is the `settle()`/`disburse()` call. No new concept.
-   **Provider of record.** The platform does **not** send money itself — that makes it a money transmitter (licensing, bonding, AML/OFAC, KYC). It routes through a licensed provider (Stripe Connect, Dwolla, Trolley, Modern Treasury, Moov) that carries the license and does vendor KYC. The platform sends an instruction and receives signed webhooks; it never touches bank credentials.
-   **Three posts, not one:** initiate (Dr `vendor_payable` / Cr `payout_clearing`), settle (Dr `payout_clearing` / Cr `bank`, fee → `payment_fees`), return (Dr `payout_clearing` / Cr `vendor_payable` + return fee). `payout_clearing` is the mirror of the existing `1020 Card` clearing account.
-   **Cleared funds, not "money owed."** Card sales settle T+1/T+2 and can be returned. The portal shows _owed_ and _available to pay now_ (cleared, after reserve). ACH is reversible (model the return path); Zelle/Venmo are effectively irreversible (treat like cash, only after cleared funds). This asymmetry is a per-rail policy.
-   **Reserve/holdback, limits/velocity, KYC/W-9/1099, fees, and a daily reconciliation job** with an exception queue.

### 13.2 The three seams to decide **now** (cheap now, painful later)

1.  **One generic engine, tender-agnostic and rent-optional.** On-demand is a one-vendor, `ad_hoc`, `R = 0` run through the same `settle()`/`disburse()`. If the booth-mall batch were built as a one-off, adding ACH would mean a second payout path — the exact drift bug this spec exists to kill.
2.  **An extensible tender enum.** `drawer_cash / safe_cash / check` today; `ach / zelle / venmo` later. Just don't hardcode "check only." (The `payout_runs_tender_chk` constraint is the one line to widen.)
3.  **An idempotency key on every payout.** One line now; it is what makes any rail exactly-once for free via `journals_book_key`. (The `posting_key` already is this.)

### 13.3 Everything else is genuinely deferrable

The `payout_clearing` account + role + source rules, the provider integration, the instruction outbox, webhooks, the reconciliation job, KYC/1099, and the portal request→approve UI are all additive on top of the engine. Adding a chart role is a one-line insert; the kernel is built for additive roles.

* * *

## 14\. Obligation gate & file checklist

The `bin/prove-obligations.php` gate refuses any new symbol (SQL function or PHP method) not named by a proof/test. Deliver accordingly.

**SQL (new):**

-   `sql/041_payout_run.sql` — all tables, guards, counter, source rules (including `('cash_in_safe','cash_disbursement')`), the `payout_pack` view, `stall_pack_checksum()`, `stall_pack_checksum_frozen()` **\[v2.2\]**, `stall_assert_store_purchase()`, `stall_reject_posted_run_line()` (column-aware **\[v2.2\]**), `stall_reject_run_status()`.
-   `sql/prove_payout_run.sql` — §11 (all 21 invariants).
-   `sql/prove_store_purchase.sql` — §11.21.
-   `sql/prove_race_payout_a.sql`, `sql/prove_race_payout_b.sql` — §12.

**SQL (edited):**

-   `sql/007_payout.sql` — replace `stall_assert_payout()` to refuse any `payout` journal (the source is retired); the run-aware shape lives in `041`.
-   `sql/029_register_posts.sql` — remove `payout`, add `cash_disbursement` to the drawer list (§6.5).
-   `sql/022_assignment_rent.sql` / `sql/005_rent_charge.sql` — no shape change; the run calls the existing rent-charge path.
-   `bin/provision-tenant.php` — append `041_payout_run.sql` to `ledgerFiles()`.
-   `sql/prove_payout.sql` — update to the new shape (it currently links a payout to a session and expects full rent clearing).

**PHP (new, small classes — respect `bin/check-max-lines.php`):**

-   `src/Feature/Payout/PayoutRunStore.php` — draft parameters, reads, stored-report reads, pack view reads.
-   `src/Feature/Payout/PayoutSettler.php` — `settle()` (§6.1).
-   `src/Feature/Payout/PayoutDisburser.php` — `disburse()` (§6.2).
-   `src/Feature/Payout/PayoutReverser.php` — `reverse()` (§6.6).
-   `src/Feature/Payout/PayoutRunController.php` — the one screen + history.
-   `src/Feature/Payout/StorePurchase.php` — the `store_purchase` journal (§8).
-   Views: `src/Feature/Payout/pay-vendors.php`, `history.php`, `envelope.php`, `envelope.css`.

**PHP (edited):**

-   `src/Feature/Payout/PayoutController.php` — route the old single-vendor path through `settle()`/`disburse()` (a one-line run).
-   `src/Feature/Mall/MallSettings.php` + `settings.php` — expose `operating_mode`, `payout_cadence`, `rent_prepaid_from_prior_sales`.
-   `src/Feature/Party/*` — expose `payout_mode` on the vendor form.
-   `src/Feature/Pos/TicketTenders.php` — unchanged (`vendor_draw` still caps at the open payable).

**Tests (name every new symbol):**

-   `tests/Integration/Payout/PayoutRunTest.php` — draft → settle → disburse → reverse; shortfall; `credit_only`; checksum drift.
-   `tests/Integration/Payout/StorePurchaseTest.php`.
-   `tests/Http/PayoutRunScreenTest.php` — the screen renders; the envelope prints one page per vendor; a zero-activity booth is on the pack.

**Definition of done:** `composer check` green (max-lines, PHPStan, PHPMD, unit, integration, http, `prove-obligations`, `prove-suite`, `prove-tenant`, `prove-race`), with the DB env set exactly as the kernel's other gates require.

* * *

## 15\. What this fixes, in the user's words

-   _"The reports have always had some money issue."_ → The report is a stored snapshot of the ledger; it cannot disagree, because a disagreement refuses the run.
-   _"We save a PDF of the original report."_ → The original report is stored in `payout_run_lines`/`payout_run_sales` and reprinted forever.
-   _"Some should never get a check."_ → `payout_mode = 'credit_only'`.
-   _"Last month's sales pay next month's rent."_ → `rent_prepaid_from_prior_sales` + the rent-in-advance window.
-   _"The store account is never paid."_ → the store is a revenue account, never a payee; owners have ordinary vendor accounts.
-   _"We had to undo a batch payout several times."_ → `reverse()` restores the exact pre-run state, disbursements before settlements.
-   _"Report first, then payout."_ → live preview → `settle()` (netting) → `disburse()` (checks), with a post-time re-verify.
-   _"The store buys from vendors."_ → `store_purchase`.
-   _"Consignor mode, vendor mall, or both."_ → one tender-agnostic, rent-optional engine; rent is per-vendor.
-   _"They could get paid by ACH/Zelle/Venmo."_ → designed for in §13; deferred, but the three seams are fixed now.