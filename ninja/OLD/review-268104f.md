# Adversarial review — `perfect-consign-v2` @ `268104f`

**Commit:** `268104fb632c8dffee3aca3cf07709db975df1be` **Subject:** "Do not start the proof suite unless this message asks for it." **Author:** Kevin Rubio (co-authored by Cursor) **Range reviewed:** `d7f1d21..268104f` (4 commits) **Reviewed:** 2026-10-04, on `origin/master` (detached HEAD, clean tree) **Reviewer:** SuperNinja (hostile pass; Xdebug 3.5.3 installed locally so the mutation harness runs end to end)

* * *

## 1\. What landed

| Commit | Subject | Shape |
| --- | --- | --- |
| `190bea8` | Keep a card or check count on the shift, and keep the close notice when an older drawer still needs closing. | product + proof |
| `27c9512` | Give the long lists a sortable table and order the payout pack by vendor code. | product + UI |
| `3b1db35` | Archive the older payout and register notes. | docs |
| `268104f` | Do not start the proof suite unless this message asks for it. | agent policy |

`190bea8` is the substantive one: it retires the long-standing finding **R** (a card/checks over-short the ledger never booked), adds `sql/prove_register_close.sql`, and fixes a real flash-loss bug in `MallApp`. `27c9512` introduces a shared sortable/exportable list component and converts 17 pages to it. `3b1db35` is a pure rename into `ninja/OLD/`. `268104f` changes only `.cursor/rules/*` — no product code.

* * *

## 2\. Gate results (all green)

Run on the local PostgreSQL 18.6 (port 5436, `consign`) with Xdebug 3.5.3 present.

| Gate | Result |
| --- | --- |
| `check:max-lines` (500) | **OK** — "All PHP files under src are within 500 lines." |
| `check:phpmd` | **OK** — no findings |
| `phpstan` | **OK** — 200/200 files, "No errors" |
| `test` (unit) | **OK (83 tests, 321 assertions)** — up from 80/302 |
| `test:integration` | **OK (7 tests, 45 assertions)** |
| `test:http` | **OK (37 tests, 544 assertions)** — run twice on the same warm DB, both green |
| `prove:obligations` | **OK** — `ok obligations` |
| `prove:race` | **OK** — `ok race` |
| `prove` (suite) | **OK** — every `prove_*.sql` passes, including the new `prove_register_close.sql` |
| `test:mutate` | **OK** — `Mutants 313, killed 300, equivalent 13, survived 0, errors 0`, `EXIT=0` |

The HTTP suite is **idempotent across consecutive runs** (37/544, 37/544) on a warm database — the `d7f1d21` fix holds. The mutation run was executed alone (it temporarily rewrites `src/`), and the tree was clean afterwards.

* * *

## 3\. Finding R is resolved — and resolved the right way

This is the headline. Finding **R** has been open since `0475f0d`: the shifts page rendered a `card_difference` / `checks_difference` with over/short shading for a figure the ledger never booked — `cash_over_short` is the only variance account, and `RegisterSession::close()` posts variance for the **cash** difference only.

`190bea8` closes it by choosing the "if it is not real accounting, stop rendering a difference the ledger does not carry" branch I recommended in `review-0475f0d`, `review-78d088c`, and `review-8e2adfd`:

-   `RegisterActivity::shifts()` no longer computes `card_difference`/`checks_difference`/`card_mark`/`checks_mark`. It exposes `posted_card_minor` / `posted_checks_minor` (the real posted tender totals) alongside the stored `counted_card_minor` / `counted_checks_minor`.
-   `shifts.php` renders `Counted $X, posted $Y` for card and checks — no over/short shading.
-   `tape.php` renders `Card posted $0.00, counted $1.00` (and checks likewise).
-   `registers.php:212` states the rule in the UI: _"Post the cash difference to over/short. Card and check differences stay on the tape."_

Crucially, it is **locked by a new proof**, not just changed. `sql/prove_register_close.sql` (128 lines) asserts, inside one rolled-back transaction:

1.  `stall_register_expected_cash()` ignores the drop (expected 6000 after a 2000 float + 5000 sale − 1000 drop).
2.  A short count cannot close without confirmation (`23514`, "counted cash does not match the expected drawer").
3.  A variance that is not cash↔over/short is refused (`23514`, "register variance moves the difference between cash and over/short").
4.  The card and check counts **are stored** but post **no** `register_variance` journal.
5.  The cash variance **excludes** the card count (exactly one variance journal, total 100).
6.  A drop posted to a closed session is refused ("a drawer journal needs an open register").
7.  The append-only triggers cannot be disabled / replica-enabled / origin-enabled / dropped (`25006`), the guard functions cannot be replaced (`25006`), and `session_replication_role = replica` cannot reopen a closed session (`23514`).

`bin/prove-suite.php` picks it up automatically via its `prove_*.sql` glob, so it runs in `composer check` and in CI with no wiring. Verified: `ok prove_register_close.sql`.

**Assessment: closed.** The card/check "over-short" is now honestly presented as counted-vs-posted, the ledger still books only the cash variance, and a proof prevents the old rendering from creeping back.

* * *

## 4\. The flash-loss fix in `MallApp` is real

`190bea8` also fixes a genuine bug. `show()` used to consume the flash **before** deciding whether to bounce to a prior open drawer:

```php
$data['notice']  = $this->takeFlash('notice');   // consumes and deletes
$data['warning'] = $this->takeFlash('warning');
...
if ($away !== null) {
    $this->redirect($away);                       // flash already gone
}
```

`takeFlash()` reads _and deletes_ the session key. So any page that set a notice and then redirected to close a prior drawer **silently dropped the message**. The fix takes the flash into locals, carries them into `redirect($away, $notice, $warning)`, and only assigns them to `$data` when the page actually renders. `redirect()` now re-`keepFlash()`es non-empty values. This is correct and the ordering is right (take once, keep on redirect, assign on render).

* * *

## 5\. The shared list component (`27c9512`)

New: `src/Shared/Web/ListTable.php`, `public/assets/js/list-datatables.js`, `public/assets/list-datatables.css`, plus vendored jQuery 3.7.1 and DataTables (core, buttons, html5, print, jszip, pdfmake, vfs\_fonts). 17 feature pages converted.

What I checked:

-   **Every `ListTable::open()` is paired with `assets()`.** Verified by scanning all 17 files — none opens a table without emitting assets. `assets()` is idempotent (`$printed` guard) and only fires when a table opened (`$needed`).
-   **The `data-dt-order` JSON survives escaping.** `View::text` turns `"` into `"`; the browser decodes the attribute before `JSON.parse`. The unit test asserts the escaped form, which is correct.
-   **No double jQuery.** `app.js` does not use jQuery; jQuery is loaded only by `ListTable::assets()`, and only on list pages. `layout.php` does not load it. Clean.
-   **`pack()` ordering is now consistent.** `PayoutRunStore::pack()` moved from `ORDER BY booth, code` to `ORDER BY code`, and `PayoutRunController` re-sorts with `strnatcasecmp($left['code'], $right['code'])`. `stored()` and `envelopes()` already ordered by `vendor.code`. `PayoutSettler::close()` iterates `pack()` but its per-vendor work is order-independent, so this is a consistency fix, not a behaviour change. The payout pack now reads in vendor-code order in the UI and the SQL agrees.
-   **`FormClient` timeouts 20→60s** — test-harness only, to tolerate the heavier rendered pages. Fine.
-   **`ListTableTest`** (3 tests) covers open/head/assets, the sort/token attributes, the order-JSON filter, and the print-once guard.

One design note, not a defect: `ListTable::$needed` / `$printed` are process-global static state. That is safe here because the app is request-per-process (the HTTP suite drives it over curl, one PHP process per request), and the unit test resets the statics in `tearDown`. It would leak in a long-lived worker, but this codebase is not one.

* * *

## 6\. New finding — the mutant floor did not move with the count

**Severity: LOW. This is Cursor's own rule, not mine.**

Cursor's stated policy (verbatim): _"The message is blunt when a real edit removes a covered operator, and the number is also written in the test. Update both in the same commit that actually changes the mutant count."_

This commit **changed the mutant count** — 300 → 313 — but left `mutantMinimum()` at **300** and `MutationAllowListTest::assertSame(300, mutantMinimum())` unchanged:

-   `bin/mutate.php`: `function mutantMinimum(): int { return 300; }`
-   `tests/Shared/MutationAllowListTest.php:34`: `self::assertSame(300, mutantMinimum());`
-   Observed run: `Mutants 313, killed 300, equivalent 13, survived 0`.

So there is now a **13-mutant margin** between the real count (313) and the floor (300). That is precisely the slack Cursor argued against when declining to lower the floor to 250: _"A margin such as 250 would let a partial coverage failure through again."_ A regression that silently drops 13 covered operators would now pass the floor check.

The floor still catches a large partial-coverage failure (<300), so this is not a hole in the harness — it is an inconsistency between the stated rule and the practice. The one-line fix, in a commit that touches the count, is to raise `mutantMinimum()` to 313 and the test to `assertSame(313, …)` together.

I am not asking for a standalone commit to change the number (Cursor explicitly ruled that out). I am flagging that the commit which _did_ change the count was the moment to move it, and it did not.

* * *

## 7\. Residuals carried forward (unchanged, still open)

-   **§11 of the payout spec is still only partially covered.** `sql/prove_payout_run.sql` is unchanged in this range (`git diff --stat d7f1d21..268104f -- sql/prove_payout_run.sql` is empty). It remains 289 lines with 10 `RAISE EXCEPTION`s covering only the "locks" (settled-window refusal, unique disbursement check number, frozen `settlement_journal_id`, settled-run delete refusal, reversed-run change refusal, checksum, zero-activity vendor, skipped-status refusal). The spec's §11 lists **28** invariants. `tests/Integration/Payout/PayoutRunTest.php` is still a single broad test. This is the "next product work" Cursor named, and it did not start here.
-   **The live payout spec is now archived.** `3b1db35` moved `perfect-consign-payout-spec-v2.3.md` (and v2, v2.1, v2.2) into `ninja/OLD/`. The §11 reference for the next work now points at an archived document. Harmless, but the "next product work" citation should be re-anchored to a live path when that work starts.

* * *

## 8\. The policy commit (`268104f`) — neutral, with one caveat

`268104f` adds `.cursor/rules/do-not-run-tests.mdc` and rewrites `maintain-proven-level.mdc` / `prove-before-advance.mdc` so the agent does not start `composer check`, PHPUnit, or `prove:*.sql` unless the user asks in that message. It does **not** touch `composer check`, the gate list, or CI — `.github/workflows/check.yml` still runs `composer check` and `composer test:coverage` on every push. So the proven level is unchanged for CI; only the agent's default behaviour changed. That matches the user's instruction to stop the unprompted runs. No product impact.

* * *

## 9\. Verdict

The `190bea8` slice is the strongest work in this range: it closes finding **R** by removing a figure the ledger never carried, presents counted-vs-posted honestly on both the shifts page and the tape, and locks the decision with a real proof that the suite picks up automatically. The `MallApp` flash fix is a genuine correctness win. The list component is well-tested and consistently applied.

Two things to hold Cursor to:

1.  **The mutant floor is now 13 below the real count** — by Cursor's own rule, the commit that changed the count should have moved the constant and the test with it.
2.  **The next product work is unchanged and unstarted** — §11 is still not fully in `prove_payout_run.sql`, and the card/check over-short _posting_ (as opposed to its honest non-posting) is a deliberate design decision that should be confirmed against the spec, since the spec is now archived.

No regression found. No gate weakened. The proven level holds.