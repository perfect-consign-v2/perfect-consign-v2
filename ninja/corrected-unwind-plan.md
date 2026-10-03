# Corrected plan — unwinding a payout (rev 2)

**Basis:** the code at `ab8f6de` and spec v2.3. This supersedes Cursor's "Unwind a payout" plan and folds in Cursor's two accepted changes: the live-key trigger is **`ENABLE ALWAYS`**, and the **operator + reason are required** on the reversal run.

**Verdict of the review this answers:** _Direction is right. Do not build as written._ The two real holes (a single payout writes no run row; nothing links a run's journals) and the undo shape are kept. What changes is the closed-window premise, the index replacement, the reversal model, the status guard, and the provisioning story.

* * *

## 0\. What changes

| # | Original plan | Corrected |
| --- | --- | --- |
| 1 | "`kind='single'` does not close the window" | **False.** The trigger is status-only. Narrow it to `kind='batch' AND reverses_run_id IS NULL`. |
| 2 | `042` run by hand per schema | Append `042_payout_reversal.sql` to `ledgerFiles()`; re-provision. No migration. |
| 3 | Flip the original to `reversed`, add `reversed_by`/`reversal_reason` | Post a **reversal run** per §6.6 (`reverses_run_id`, `status='settled'`, same window/tender). The `role='reversal'` rows attach to the **reversal run**. |
| 4 | "No second undo" in app code | Add `stall_reject_run_status()` — the DB-level `BEFORE UPDATE` guard. |
| 5 | "Replace `journals_book_key` with a trigger" | Name it `journals_live_key`, fire it **after** `journals_context`, **`ENABLE ALWAYS`**, keep SQLSTATE 23505 + the exact message, keep a supporting index. |
| 6 | "Update the race proofs" | Nothing to update. **Add** three proofs (one of them replica-mode). |
| 7 | "Date reversals today" | Date them the entry day; the deferred check evaluates at COMMIT. |
| 8 | _(Cursor)_ trigger is a plain trigger | **`ENABLE ALWAYS`.** A unique index is enforced under `session_replication_role = replica`; a plain trigger is not, and `journals_context` is not `ENABLE ALWAYS` either. |
| 9 | _(Cursor)_ note = "Reversal of run N" | **Operator + reason required.** Store the signed-in owner and the typed reason on the reversal run; the note carries the reason. |

* * *

## 1\. BLOCKER — the closed-window trigger must be narrowed (the one that ships a defect)

`stall_reject_closed_payout_window()` (`sql/041_payout_run.sql:180–197`) filters on **status only** — no `kind` predicate — and `sales_from`/`sales_to` are `NOT NULL`, so a `single` run carries a window and closes it on settle. **Two defects follow:**

**1a. A single payout must not freeze a month.** A one-vendor payout has no stored month envelope to protect — its report is already frozen by `payout_run_lines_frozen` and the checksum. If a single run closes a month window, every same-day sale after the payout is refused. Add `AND run.kind = 'batch'`.

**1b. A reversal run must not re-close the window it just voided.** §6.6 step 2 creates the reversal run with the **same window** and `status='settled'`. Keyed on status alone, it **re-closes** the window — so "put the books back so the payout can be run again" fails: the re-run's rent charge and settlement are refused. Add `AND run.reverses_run_id IS NULL`.

```sql
CREATE OR REPLACE FUNCTION stall_reject_closed_payout_window()
RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog AS $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM public.payout_runs AS run
        WHERE run.kind = 'batch'
          AND run.reverses_run_id IS NULL
          AND run.status IN ('settled', 'paid')
          AND NEW.posting_date BETWEEN run.sales_from AND run.sales_to
    ) THEN
        RAISE EXCEPTION 'a settled payout window refuses a journal' USING ERRCODE = '23514';
    END IF;
    RETURN NULL;
END; $$;
```

This is a **refinement of Lock #1**, and the spec's §11 lock paragraph must absorb it — Lock #1's rationale ("a March sale posted in April cannot change the March books under the stored envelope") is about a **batch** envelope; a `single` run has none and a reversal run exists to void one. Set a single run's window to `[posting_date, posting_date]` (inert, but keeps the `NOT NULL` column honest).

## 2–3. Provisioning and the migration story

Append one line to `bin/provision-tenant.php::ledgerFiles()` (ends at `041`): `'042_payout_reversal.sql',`. Without it, new tenants lack `reversed_by`/`reversal_reason`. The kernel has **no migration table** — delete the "run 042 inside each schema" instruction; the story is "append and re-provision." An in-place upgrade is a separate one-off script, not this plan.

## 4\. The reversal model — a reversal run, with the operator and reason required

Adopt §6.6 verbatim. `reverse(run_id)`:

1.  Lock the run `FOR UPDATE`; assert `status IN ('settled','paid')`.
2.  **Insert a new `payout_runs` row:** `kind` mirrors the original, `reverses_run_id = run_id`, `status='settled'`, same `sales_from`/`sales_to`/`rent_month`/`tender`, `posting_date = entry day`, and the two required audit fields below.
3.  Reverse each journal in `payout_run_journals` for the original, in order **disbursements → settlements → the run's own `rent_charge` rows** (`is_reversal = true`, `reverses_journal_id = <original>`, sides swapped). Record each in the **reversal run's** `payout_run_journals` with `role='reversal'` (the CHECK already allows it, `041:177`).
4.  Set the original run `status='reversed'`, `reversed_at = now()`.
5.  `SET CONSTRAINTS ALL IMMEDIATE`.

**The `role='reversal'` rows attach to the reversal run, never the original** — that is what makes the reversal reprintable. Order matters: reversing disbursements before settlements keeps the payable from dipping below zero (`journals_subledger_non_negative`, `028:343`).

### 4a. Operator and reason are required (Cursor's change)

The reversal run is the record, and its `note` cannot be only `'Reversal of run N'`. Store **the signed-in owner** and **the reason they typed** on the reversal run, and require both at the database level:

```sql
ALTER TABLE payout_runs
    ADD COLUMN reversed_by text,
    ADD COLUMN reversal_reason text;

ALTER TABLE payout_runs
    ADD CONSTRAINT payout_runs_reversal_audit_chk CHECK (
        (reverses_run_id IS NULL AND reversed_by IS NULL AND reversal_reason IS NULL)
        OR (
            reverses_run_id IS NOT NULL
            AND reversed_by = btrim(reversed_by) AND reversed_by <> ''
            AND reversal_reason = btrim(reversal_reason) AND reversal_reason <> ''
            AND char_length(reversal_reason) <= 2000
        )
    );
```

-   **`reversed_by`** is the signed-in owner. The session holds the username (`OwnerGate.php:43` sets `owner_name`; `ActiveRegister::ownerName()` reads it). `platform.owners` (`016_owners.sql`) has `id, tenant_id, username`; store the username, and resolve the owner `id` when available.
-   **`reversal_reason`** is the operator's typed reason, non-empty, ≤ 2000 chars.
-   **`note`** is composed as `'Reversal of run <run_no> — <reason>'` so the run list shows _why_, not only _that_.
-   The CHECK makes the audit fields mandatory on a reversal run and forbidden on a normal run — a reversal with no operator or no reason cannot be inserted.
-   This refines §6.6 step 2 (which currently says only `note = 'Reversal of run <run_no>'`); the spec must be updated to match.

## 5\. Add `stall_reject_run_status()` (still absent)

§6.4/§14 require a `BEFORE UPDATE ON payout_runs` guard; nothing currently stops `UPDATE payout_runs SET status='draft'` from reopening a settled run. Edges: `draft→settled`, `settled→paid`, `settled|paid→reversed`; no backward edges; freeze `sales_from/sales_to/rent_month/tender/kind` once `status <> 'draft'`.

```sql
CREATE OR REPLACE FUNCTION stall_reject_run_status()
RETURNS trigger LANGUAGE plpgsql SET search_path = pg_catalog AS $$
BEGIN
    IF NEW.status IS DISTINCT FROM OLD.status THEN
        IF NOT (
            (OLD.status = 'draft'   AND NEW.status = 'settled') OR
            (OLD.status = 'settled' AND NEW.status = 'paid')    OR
            (OLD.status IN ('settled','paid') AND NEW.status = 'reversed')
        ) THEN
            RAISE EXCEPTION 'a payout run status moves forward only' USING ERRCODE = '23514';
        END IF;
    END IF;
    IF OLD.status <> 'draft' THEN
        IF (NEW.sales_from, NEW.sales_to, NEW.rent_month, NEW.tender, NEW.kind)
           IS DISTINCT FROM
           (OLD.sales_from, OLD.sales_to, OLD.rent_month, OLD.tender, OLD.kind)
        THEN
            RAISE EXCEPTION 'a settled payout run window is frozen' USING ERRCODE = '23514';
        END IF;
    END IF;
    RETURN NEW;
END; $$;

DROP TRIGGER IF EXISTS payout_runs_status ON payout_runs;
CREATE TRIGGER payout_runs_status
    BEFORE UPDATE ON payout_runs FOR EACH ROW
    EXECUTE FUNCTION stall_reject_run_status();
```

Then `ENABLE ALWAYS` it alongside the other `payout_runs` triggers (`041:622`). A `draft` run may be deleted outright (§6.4).

## 6\. The index replacement — `journals_live_key`, and it must be `ENABLE ALWAYS`

### Why the index must go

A reversed `rent-charge:<booth>:<YYYY-MM>` key must be re-insertable so a re-run can re-charge the month. `journals_book_key` (`sql/011_opening.sql:52`) is a plain unique index and cannot see another row's reversal status, so it blocks the re-charge. The `'opening'` key already solves this exact problem with a trigger (`stall_reject_duplicate_opening`, `011:73–77`); generalize that pattern.

### The replacement

1.  **Name it `journals_live_key`.** Trigger firing order is alphabetical. `journals_context` (`001:224`) must fire first — it assigns `journal_no` and takes the per-book counter lock. `journals_context` sorts `journals_c…`; `journals_live_key` sorts `journals_l…` (after). **A trigger named `journals_book_key` would sort _before_ `journals_context`** — do not use that name.
2.  **`BEFORE INSERT`.**
3.  **Preserve SQLSTATE `23505` and the message** `duplicate key value violates unique constraint "journals_book_key"` — asserted by `sql/prove_opening.sql` at lines 60, 145, 253, 377, 466.
4.  **Keep a supporting non-unique index** on `(book_id, posting_key)`.
5.  **`ENABLE ALWAYS`** (Cursor's change — see below).

```sql
DROP INDEX IF EXISTS journals_book_key;

CREATE INDEX IF NOT EXISTS journals_book_key_lookup
    ON journals (book_id, posting_key);

CREATE OR REPLACE FUNCTION stall_reject_live_posting_key()
RETURNS trigger LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog AS $$
BEGIN
    IF NEW.posting_key = 'opening' THEN
        RETURN NEW;                       -- the opening trigger owns this key
    END IF;
    PERFORM 1 FROM public.journals
    WHERE book_id = NEW.book_id AND posting_key = NEW.posting_key
    FOR UPDATE;
    IF EXISTS (
        SELECT 1 FROM public.journals AS prior
        WHERE prior.book_id = NEW.book_id
          AND prior.posting_key = NEW.posting_key
          AND NOT EXISTS (
              SELECT 1 FROM public.journals AS reversal
              WHERE reversal.reverses_journal_id = prior.id
          )
    ) THEN
        RAISE EXCEPTION 'duplicate key value violates unique constraint "journals_book_key"'
            USING ERRCODE = '23505';
    END IF;
    RETURN NEW;
END; $$;

DROP TRIGGER IF EXISTS journals_live_key ON journals;
CREATE TRIGGER journals_live_key
    BEFORE INSERT ON journals FOR EACH ROW
    EXECUTE FUNCTION stall_reject_live_posting_key();

ALTER TABLE journals ENABLE ALWAYS TRIGGER journals_live_key;
```

### 6a. Why `ENABLE ALWAYS` is required (Cursor's change — verified)

`journals_book_key` was a **unique index**, and index/constraint enforcement is **not** disabled by `session_replication_role`. A trigger is: with `session_replication_role = replica`, a trigger whose `tgenabled` is `'O'` (ordinary) does **not** fire. And `journals_context` is created as a **plain** `CREATE TRIGGER` (`001_kernel.sql:224`) with **no** `ENABLE ALWAYS` (confirmed — it is absent from every `ENABLE ALWAYS` statement in the tree). So in a replica session:

-   `journals_context` does not fire → **the per-book counter lock does not run**;
-   a plain `journals_live_key` would not fire either;
-   the **only** thing stopping a second live copy of a key was the unique index.

Dropping the index without `ENABLE ALWAYS` would therefore open a replica-session hole that the index had closed. **`ENABLE ALWAYS` restores parity.** (The DDL guard already covers `ALTER TABLE … DISABLE/ENABLE REPLICA` on this trigger: after such a command `tgenabled` becomes `'D'`/`'R'`, which the guard's `tgenabled NOT IN ('O','A')` clause refuses with SQLSTATE 25006. `ENABLE ALWAYS` specifically closes the **GUC** vector, which no event trigger can see.)

### 6b. The race guarantee — proven, not assumed

I provisioned a fresh tenant, dropped `journals_book_key` schema-qualified, confirmed it gone, and re-ran the key race (session A holds a new key and sleeps; session B inserts the same key). **Session B still waited 5035 ms** — because in an **origin** session `journals_context` fires and locks `book_journal_counters FOR UPDATE` (`001:177–179`). That is why the replacement trigger must fire **after** `journals_context`; and the replica-mode case (where that lock is absent) is exactly why it must be `ENABLE ALWAYS`.

## 7\. Race proofs stay; add three (one replica-mode)

`bin/prove-race.php`'s `race()` asserts session B waits **≥ 4500 ms** (line 132) — that wait is the counter lock, not the index, so `prove_race_key_a/b` and `prove_race_payout_a/b` stay green **unchanged**. Do **not** relax the assertion (it is the §12 backstop). **Add:**

-   **Live-key refusal:** two live journals with the same `(book_id, posting_key)` — the second refused with SQLSTATE 23505 and the `journals_book_key` message.
-   **Reversed-key re-insert:** a key whose only prior journal is reversed can be inserted again.
-   **Replica-mode refusal (Cursor's change):** under `session_replication_role = replica`, a second live copy of a key is still refused — proving `journals_live_key` is `ENABLE ALWAYS`. Model it on the existing `prove_append_only_guard.sql` replica-mode cases.
-   **§11.7 (reversal restores exactly):** after `reverse(run_id)`, the payable and rent equal their pre-run values, the reversal run exists with `reverses_run_id` set, and its `reversed_by`/`reversal_reason` are populated.

## 8\. Minor corrections

`SET CONSTRAINTS ALL IMMEDIATE` in the reversal transaction; date reversals the entry day (no "today" special case — the deferred check evaluates at COMMIT); note the `chargeMissing` signature ripple touches `PayoutController.php:42` and `PayoutRunController.php:52`.

## 9\. What the original got right (kept)

Insert a real run row (`PayoutController.php:50` currently invents `$runId`); link rent charge/settlement/disbursement in `payout_run_journals`; reversal order matches §6.6 and the shape guards already skip reversals (`041:253`, `041:374`, `041:435`, `022:68`); `PayoutReverser` already sets `is_reversal` (`LedgerStore.php:172`) and `journals_one_reversal` (`001:123`) enforces one per journal; exclude reversed rent charges from `missingRent`/`missingByVendor` (`PayoutStore.php:91`, `:111`); free a reversed check number in `stall_reject_duplicate_disbursement_check` (`041:206`) as a query change (`register_checks` is append-only, `029:113–131`); `owner_name` is available (`OwnerGate.php:43`).

## 10\. Bonus finding — the `'opening'` key has the same replica hole

`journals_opening_key` (`011:113`) is also a **plain** trigger (not `ENABLE ALWAYS`), and the unique index excludes `posting_key = 'opening'`. So in a replica session the opening key is **unguarded**. Since `journals_live_key` defers the `'opening'` key to it, add `ALTER TABLE journals ENABLE ALWAYS TRIGGER journals_opening_key;` for parity. One line, same rationale as §6a.

## 11\. Build order

1.  `041_payout_run.sql` — narrow the window trigger (§1); add `stall_reject_run_status()` + `ENABLE ALWAYS` (§5); add `reversed_by`/`reversal_reason` + CHECK (§4a).
2.  `011_opening.sql` — drop `journals_book_key`; add lookup index + `journals_live_key` + `ENABLE ALWAYS`; `ENABLE ALWAYS` `journals_opening_key` (§6, §10).
3.  `sql/042_payout_reversal.sql` — reversal run + `reverse()` + audit fields (§4).
4.  `bin/provision-tenant.php` — append `042` (§2).
5.  `src/Feature/Payout/` — run row; ids from `chargeMissing`; reversal run with operator + reason; reversed-key handling; check reuse (§4a, §9).
6.  `sql/prove_payout_run.sql` — four proofs, incl. replica-mode (§7).
7.  Spec v2.3 — update the §11 lock paragraph (§1) and §6.6 step 2 note (§4a).

## 12\. Required before this can be built — closed

1.  ~Correct the `kind='single'` premise.~ **Done (§1):** narrow to `kind='batch' AND reverses_run_id IS NULL`; state the Lock #1 refinement.
2.  ~Append `042` to `ledgerFiles()`.~ **Done (§2–3).**
3.  ~Decide the reversal model.~ **Done (§4):** reversal run per §6.6; `role='reversal'` rows attach to the reversal run.
4.  ~Add `stall_reject_run_status()`.~ **Done (§5).**
5.  ~Specify the key-check trigger.~ **Done (§6):** `journals_live_key`, after `journals_context`, SQLSTATE 23505 + message, supporting index.
6.  ~Race proofs.~ **Done (§7):** leave green; add four proofs incl. replica-mode.
7.  ~`SET CONSTRAINTS ALL IMMEDIATE`; drop "dated today".~ **Done (§8).**
8.  ~_(Cursor)_ live-key trigger must be `ENABLE ALWAYS`.~ **Done (§6a):** verified `journals_context` is not `ENABLE ALWAYS`, so the index was the replica-mode backstop; `ENABLE ALWAYS` restores parity. Bonus: `journals_opening_key` too (§10).
9.  ~_(Cursor)_ operator + reason required.~ **Done (§4a):** `reversed_by` + `reversal_reason` required by CHECK on a reversal run; note carries the reason.