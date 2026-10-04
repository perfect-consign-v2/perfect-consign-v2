# Hostile review — `8d622ee` (cycle 13)

**Commit:** `8d622ee9a698fa3c7c9ec16197f8bf77df31fa18` **Date:** 2026-10-01 17:36:50 −0700 **Subject:** Give a sold gift certificate a printable slip, show its balance in the payment window, and list open certificates beside the Book. **Parent:** `f41553c` (one commit behind; `git rev-list --count f41553c..8d622ee` = 1) **Diff:** 19 files, +799 / −67 **Environment:** PostgreSQL 18.6 (127.0.0.1:5436), PHP 8.4.26, tree at `8d622ee`, all gates re-run from scratch.

* * *

## Verdict

This is a **feature** commit, not a kernel commit: it builds the PHP/UI layer on top of the stored-value machinery that already existed in `sql/028_tenders.sql` (the `stored_values` table, the `2200 Gift certificates` liability control account, the `gift_certificate_issue` source rule, and the two deferred constraint triggers that enforce the issue shape and the non-negative subledger balance). The **double-entry core is sound and, importantly, already proven** — I verified it rather than assuming it. The new PHP is clean, well-typed, and passes every gate.

But it ships **one real, reproducible defect in the certificate-numbering logic** (F-13.1), and it **widens a structural gap in the gate itself** (F-13.2): the entire HTTP test surface that exercises this feature is not run in CI, and one of those tests is date-brittle and is red today. Those two are the findings that matter. The rest are low-severity polish.

I did **not** find a money-correctness hole. The ledger cannot be made to lie about a gift certificate. That is the headline good news, and I confirmed it empirically, not by reading.

* * *

## What the commit actually does

1.  **Issue path (`src/Feature/Pos/GiftIssue.php`).** `issue()` now accepts an **empty** number and auto-allocates `GC-<n>` (starting at 1001), retrying up to 5 times when the DB raises `stored_values_number_key`. It builds a two-line journal — debit `cash|card|checks`, credit the `gift_certificate` control account with `subledger_type='gift_certificate'` and `subledger_ref = stored_values.id` — inside `Statements::isolated()`, with a compensating `DELETE` of the orphan `stored_values` row if the post fails.
2.  **Slip (`src/Feature/Pos/gift-slip.php`, `GiftIssue::slip()`).** A printable certificate at `GET /<slug>/pos/gift/<journal_id>`, guarded to `source_type='gift_certificate_issue'` and a reference that does not end in `:void`, showing the issued amount and a "Voided" banner when the journal is reversed.
3.  **Balance lookup (`PosLookupController::giftCertificate`).** `GET /<slug>/pos/gift-certificate.json?number=…` returns `{ok, balance}`; the pay modal calls it on `change`/Enter/button and shows "Balance left …".
4.  **Open list (`src/Feature/Book/OpenGiftCertificates.php`, `gift-certificates.php`).** A Book page listing every certificate with a positive `sum(credit − debit)`, with a "Print certificate" link.
5.  **Error re-entry.** The gift modal and the payment modal now reopen with the server's error and the cashier's inputs preserved (`PosPage::giftForm`/`payForm`), instead of dumping a flash alert.

* * *

## Double-entry / money correctness — verified, not assumed

I checked the ledger semantics directly rather than trusting the prose.

-   **Issue shape is DB-enforced.** `stall_assert_stored_value_issue()` (a deferred constraint trigger on `journals`) requires a `gift_certificate_issue` journal to have exactly two lines: a full-amount debit on `cash|card|checks` with `subledger_type IS NULL`, and a full-amount credit on the `gift_certificate` role with `subledger_type = kind`. `GiftIssue::store()` produces exactly that shape, so the PHP cannot drift from the kernel without the insert failing. Good.
-   **Over-redemption is DB-enforced and already proven.** `stall_assert_subledger_non_negative()` rejects any journal that drives a touched `gift_certificate` subledger below zero (`a gift certificate cannot go below zero`). `sql/prove_session_posts.sql` already contains the proof (`d73` redeems 5000 against a 3000 certificate and asserts the refusal). I ran the suite: `ok prove_session_posts.sql`.
-   **The liability model is correct.** Cash is taken at issuance (debit cash / credit `2200`); the liability is drawn down at redemption (debit `2200` / credit `vendor_payable`), so the mall owes the consignor only when the certificate is actually spent. Voiding reverses both legs and the `HAVING … > 0` filter drops the certificate from the open list. Consistent.
-   **`balance()` and `outstanding()` agree.** Both compute `sum(credit_minor − debit_minor)` over `subledger_type='gift_certificate'` lines keyed by `stored_values.id::text`. Same formula, same result. The `outstanding()` `issued` sub-query is redundant (it re-derives the issue journal's credit total) but not wrong.

**Double-spend / concurrency.** Two concurrent sales redeeming the same certificate are serialized by the `SELECT … FOR UPDATE` on the `stored_values` row inside `stall_reject_unknown_subledger()`; the second transaction's deferred non-negative check then sees the first's committed debit and is rejected. This is safe **by construction**, and it is the same pattern as the vendor-payable and booth-rent guards. It is **not** covered by a race proof (see F-13.3) — but neither is vendor-payable, so this is a pre-existing, systemic gap rather than a regression.

* * *

## Findings

### F-13.1 — (Medium) Auto-allocation is case-blind to the unique index; a lowercase custom number wedges certificate numbering

`GiftIssue::allocate()` picks the next number with a **case-sensitive** regex:

```sql
SELECT number FROM stored_values
WHERE kind = 'gift_certificate' AND number ~ '^GC-[0-9]+$'   -- case-sensitive
ORDER BY char_length(number) DESC, number DESC LIMIT 1
```

…but the unique index is **case-insensitive** (`sql/028_tenders.sql:80`):

```sql
CREATE UNIQUE INDEX stored_values_number_key ON stored_values (kind, lower(number));
```

…and every lookup (`GiftIssue::balance()`, `RegisterStore::storedValueId()`) is also case-insensitive (`lower(number) = lower(?)`). So a certificate created with a lowercase or mixed-case number — the UI's "This certificate already has a number" path does **no** case normalisation — is invisible to `allocate()` yet collides with it.

When the auto-sequence reaches that value, `allocate()` returns `GC-1001`, the `INSERT` collides case-insensitively, the 5-attempt retry re-reads the same maximum (the lowercase row still does not match `^GC-[0-9]+$`) and returns `GC-1001` again, and `issue()` finally throws `That certificate number is already used.` — **permanently wedging auto-numbered certificate sales** for that tenant, because `stored_values` is append-only and the poison row cannot be removed.

Reproduced against the live cluster (scratch schema mirroring `028_tenders.sql`):

```
INSERT INTO stored_values (kind, number) VALUES ('gift_certificate', 'gc-1001');
--- allocate() sees:            <NULL>          (regex is case-sensitive)
--- auto-allocate INSERT of GC-1001:
ERROR: duplicate key value violates unique constraint "stored_values_number_key"
DETAIL: Key (kind, lower(number))=(gift_certificate, gc-1001) already exists.
--- but storedValueId()/balance() DO match it case-insensitively:  (1 row)
```

Any lowercase `gc-<n>` with `n ≥ 1001` is a time-bomb: it fires the moment the sequence reaches it. **Fix:** make the pattern case-insensitive (`number ~* '^GC-[0-9]+$'`) so `allocate()` sees the lowercase row and advances past it. (The bounded retry loop then does the right thing.) One character.

### F-13.2 — (Medium) The HTTP suite that exercises this whole feature is not run in CI, and one of its tests is date-brittle and red today

`composer check` is:

```
check = max-lines, phpmd, phpstan, test (unit), test:integration,
        prove:obligations, prove:race, prove
```

**`@test:http` is not in it.** And the only workflow (`.github/workflows/check.yml`) installs `extensions: pdo_pgsql, zip` — **not `curl`**, which `tests/Support/ProbeServer.php` hard-requires ("the curl extension is required"). So the entire HTTP test surface — including `FloorCoverageTest`, whose 162 assertions are the _only_ end-to-end exercise of the gift-certificate feature (issue custom + auto, slip, balance lookup, open list, redemption, and every new error path) — **never runs in CI**. A regression in this commit's UI would ship green.

Worse, that suite is currently **red**, and CI cannot see it. Running it here:

```
Tests: 34, Assertions: 458, Failures: 1.
CoverageRemainderTest::testRegistersRentPayoutAndTheOpenCart
  → a rent charge requires the rate stored for that month
```

The cause is a hard-coded month: the test creates a booth with `month_start => '2026-09-01'` but charges for the page's default posting date (the current month). Today is **2026-10-02**, so the October charge has no stored rate and the charge is refused. The test only passes during September 2026. (This file is unchanged by `8d622ee`, so it is not a regression from _this_ commit — but it is a latent red that the missing CI wiring hides, and it will bite the moment the suite is enabled.)

**Fix:** add `@test:http` to `check`; add `curl` to the workflow's `extensions`; and derive the rent test's month from the charge date instead of hard-coding September.

### F-13.3 — (Low) The obligation gate is blind to private methods, so the most bug-prone new logic needs no coverage

`bin/prove-obligations.php::unprovenSymbols()` scans only `/public\s+(?:static\s+)?function\s+…/`. `GiftIssue::allocate()`, `store()` and `issuedAmount()` are **private**, so they are exempt from the "every new symbol must be named by a proof or test" rule — which is exactly where F-13.1 lives. I confirmed the gate is otherwise live by injecting an uncovered public method (`ninjaGiftProbe` → `8d622ee … adds ninjaGiftProbe and no proof or test names it`, exit 1), so the gap is specifically the private-method blind spot. Related: several new **public** methods (`gift`, `payment`, `certificate`, `certificates`, `giftCertificate`, `slip`, `outstanding`) are "covered" only because a test helper shares their name — the same name-collision heuristic flagged as N-12.2 last cycle. Here the helpers do genuinely call the endpoints, but the gate cannot tell.

### F-13.4 — (Low) Certificate balances are enumerable

`GET /<slug>/pos/gift-certificate.json?number=…` returns the balance for any number, gated only by `MallApp::open()`'s foreign-tenant check — there is **no register-session requirement**. Auto-issued numbers are sequential (`GC-1001, GC-1002, …`), so any signed-in user can walk the range and read every open certificate's balance. This matches the pre-existing posture of `suggest.json`, but the balance endpoint extends the disclosure to money. Consider requiring an open register session.

### F-13.5 — (Low) A whitespace-only custom number silently auto-allocates

`PosTenderController::gift()` tests the **raw** field (`if ($request->field('gift_custom') === '1' && $number === '')`), but `GiftIssue::number()` trims. So `gift_custom=1` with `gift_number=' '` passes validation, then `number()` trims to `''` and the certificate is auto-allocated — silently ignoring the cashier's request for a specific number. Trim before the emptiness check.

### F-13.6 — (Very low) `slip()` treats any number ending in `:void` as unprintable

`GiftIssue::slip()` uses `str_ends_with($reference, ':void')` to detect a voided certificate. A custom number that legitimately ends in `:void` would be rejected as "not a gift certificate". Edge case; note only.

### F-13.7 — (Very low) `outstanding()` join has no supporting index

The open-list query joins `journals ON source_type = 'gift_certificate_issue' AND source_reference = stored.number`, but the only indexes on `journals` are `one_reversal`, `(book_id, period_id)`, `(book_id, posting_date)` and `(book_id, posting_key)`. No `(source_type, source_reference)` index exists, so the join is a scan. Fine at mall scale; flag for growth.

* * *

## Gate results on `8d622ee` (all re-run from scratch)

| Gate | Result |
| --- | --- |
| `bin/check-max-lines.php 500` | ✅ All files within 500 lines |
| `phpstan analyse` (184 files) | ✅ `[OK] No errors` |
| `phpmd src text phpmd.xml` | ✅ clean |
| `phpunit --testsuite unit` | ✅ 62 tests / 189 assertions |
| `phpunit --testsuite integration` | ✅ 3 tests / 8 assertions |
| `bin/prove-obligations.php` (tip) | ✅ `ok obligations` |
| `bin/prove-obligations.php` (`OBLIGATION_BASE=f41553c`) | ✅ `ok obligations` (live — injection test fails as expected) |
| `bin/prove-suite.php` | ✅ 30 proofs, all `ok` |
| `bin/prove-tenant.php` | ✅ exit 0 |
| `phpunit --testsuite http` | ⚠️ 34 tests / 458 assertions, **1 failure** (F-13.2, date-brittle) |
| `bin/prove-race.php` | ⛔ not runnable here (hard-codes `docker exec`; Docker absent) — N-12.1, works in CI |

Note on the obligation gate: at the tip (`merge-base HEAD origin/master` = `HEAD`) the diff is empty, so I also ran it against the parent `f41553c` to actually exercise the new symbols. It passed, and I independently proved the gate is live by injection.

* * *

## Carried forward (unchanged, still open)

-   **N-12.1** — `bin/prove-race.php` is Docker-bound (`docker exec perfect-consign-postgres psql …`); it cannot run in a non-Docker environment. Works in CI.
-   **N-12.2** — the obligation gate's coverage test is name-based, so a test helper sharing a production method's name satisfies it. Now more pervasive (see F-13.3).
-   **N-12.3** — `tests/Shared/ObligationSymbolTest.php` can `exit(1)` the whole PHPUnit process via the required `bin/prove-obligations.php` bootstrap.
-   **N-12.4** — the 90% coverage floor (`bin/coverage-report.php`) is measured by `coverage-run.php`, which is **not** wired into `composer check` or the workflow, so it is not enforced in CI. Same class of gap as F-13.2.

* * *

## What is genuinely good here

-   The money path is DB-authoritative: the issue shape and the non-negative balance are enforced by deferred constraint triggers, so the PHP layer cannot corrupt the ledger even if it tries.
-   The compensating `DELETE` in `store()` is correct for the autocommit case (`Statements::isolated()` runs the closure directly when not already in a transaction), and harmless-but-redundant inside a savepoint. Not dead code, as I first suspected of the analogous `wipe-tenant` helper last cycle.
-   The retry loop is bounded (5), only retries the auto-allocated path, and rethrows custom-number collisions immediately — the right shape, undermined only by F-13.1's regex.
-   The error re-entry is thoughtful: the gift modal reopens with the field that failed focused, and the payment modal reopens with the balance error and the cashier's tenders preserved. The `FloorCoverageTest` additions assert exactly that (`assertStringNotContainsString('alert alert-danger', …)`).
-   Every dynamic value in the new views goes through `View::text`; the gift POST checks CSRF.

* * *

## Priority for the next push

1.  **F-13.1** — one-character fix (`~` → `~*`) plus a regression proof for the lowercase-poison case.
2.  **F-13.2** — wire `@test:http` into `check`, add `curl` to CI, and de-brittle the rent test.
3.  **F-13.3** — extend `unprovenSymbols()` to private methods (or at least to methods with a body over N lines), and tighten the name-collision heuristic.
4.  **F-13.4/F-13.5** — gate the balance endpoint behind an open register session; trim the custom number before validating.

The bar is enterprise world-class. F-13.1 is a real defect that a cashier can trip with one lowercase keystroke and that then cannot be undone; F-13.2 means the tests that would have caught it are not even running. Both should be closed before this feature is called done.