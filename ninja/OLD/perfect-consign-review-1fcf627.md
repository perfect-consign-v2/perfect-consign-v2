# Hostile review — cycle 14

**Commit under review:** `1fcf627` — _"Keep gift-certificate numbering past a lowercase number, and fail the check when the HTTP suite is red."_ **Base:** `8d622ee` (cycle 13) — 16 files, +309 / −34. **Reviewer stance:** adversarial. Findings are withdrawn only when the code refutes them.

This commit is a direct, honest response to the cycle-13 findings. It touches every one of them, and most of the fixes are correct. But it introduces one **high-severity regression in the CI itself**, and the headline regression test **does not actually test the bug it is named for**. Both are proven below with runnable repros, not assertions.

* * *

## 1\. Gate results on `1fcf627` (local, env set)

| Gate | Command | Result |
| --- | --- | --- |
| max-lines | `php bin/check-max-lines.php 500` | **PASS** |
| PHPStan | `php vendor/bin/phpstan analyse --memory-limit=512M` | **PASS** (0 errors) |
| PHPMD | `php vendor/bin/phpmd src text phpmd.xml` | **PASS** |
| unit | `phpunit --testsuite unit` | **PASS** (62 tests, 191 assertions) |
| integration | `phpunit --testsuite integration` | **PASS** (4 tests, incl. the new one) |
| http | `phpunit --testsuite http` | **PASS** (34 tests, 479 assertions) |
| obligations (tip) | `php bin/prove-obligations.php` | **PASS** |
| obligations (vs base) | `OBLIGATION_BASE=8d622ee php bin/prove-obligations.php` | **PASS** |
| prove-suite | `php bin/prove-suite.php` | **PASS** (all `prove_*.sql`) |
| prove-tenant | `php bin/prove-tenant.php` | **PASS** |
| prove-race | `php bin/prove-race.php` | **PASS** — `ok race`, **no Docker** |

`composer` is not installed in this sandbox, so each constituent of `composer check` was run directly. Every one is green **when the `STALL_*` environment is present** — which is exactly the assumption that finding **F-14.1** shows the CI no longer satisfies.

* * *

## 2\. Findings

### F-14.1 — HIGH — The CI check is now red: the database env was moved off `composer check`

The commit's stated goal is _"fail the check when the HTTP suite is red."_ It adds `@test:http` to the `composer check` script and adds a `composer test:coverage` step to the workflow. To do the latter it inserted the new step **between** `composer check` and the step-level `env:` block:

```yaml
# .github/workflows/check.yml  (new, lines 43–53)
      - run: composer check            # <-- no env
      - run: composer test:coverage    # <-- env now binds here
        env:
          STALL_DB_HOST: 127.0.0.1
          STALL_DB_PORT: '5436'
          STALL_DB_NAME: consign
          STALL_DB_USER: stall
          STALL_DB_PASSWORD: stall
          STALL_APP_PASSWORD: ci-app-password
          STALL_OWNER_PASSWORD: ci-owner-password
          STALL_BACKUP_PASSWORD: ci-backup-password
```

In YAML a step-level `env:` applies to **that step only**. Before this commit the block sat under `composer check` (verified: `git show 8d622ee:.github/workflows/check.yml` → line 43 `composer check`, line 44 `env:`). After it, `composer check` runs with **no** `STALL_*` variables at all, and there is no job-level `env:`, no committed `.env` (`.env` is gitignored), and no `$GITHUB_ENV` export of these keys.

`composer check` now runs `@test:integration`, `@test:http`, `@prove:race` and `@prove`, and every one of those opens Postgres through `ProbeMall::ensure()`, which **requires** `STALL_DB_HOST`, `STALL_DB_PORT`, `STALL_DB_NAME`, `STALL_APP_PASSWORD` (see `tests/Support/ProbeMall.php:54`), and the integration tests read `STALL_DB_HOST` directly (`GiftCertificateNumberTest::env()`). With the env absent they do not run.

**Repro (mimics the CI `composer check` step exactly):**

```
$ env -u STALL_DB_HOST -u STALL_DB_PORT -u STALL_DB_NAME -u STALL_DB_USER \
      -u STALL_DB_PASSWORD -u STALL_APP_PASSWORD -u STALL_OWNER_PASSWORD \
      -u STALL_BACKUP_PASSWORD php vendor/bin/phpunit --testsuite integration
...
1) GiftCertificateNumberTest::testALowercaseNumberDoesNotWedgeTheNextCertificate
RuntimeException: STALL_DB_HOST is required to run the web probe
2) LayawayHoldTest::testADepositStaysOnTheLayawayBalance
RuntimeException: STALL_DB_HOST is required to run the web probe
3) LayawayHoldTest::testAFailedHoldRollsTheDepositBack
RuntimeException: STALL_DB_HOST is required to run the web probe
4) LineDiscountShareTest::testThePreviewUsesTheLedgerShare
RuntimeException: STALL_DB_HOST is required to run the web probe

ERRORS! Tests: 4, Assertions: 0, Errors: 4.
```

`composer check` aborts at the first failing script, so the job dies at `@test:integration` and never reaches `composer test:coverage`. The commit that set out to make the check fail on a red HTTP suite instead makes the check fail on _missing env_, before the HTTP suite even starts. This is a regression against the previous green CI (the old `composer check` inherited the env block).

**Fix:** give the `composer check` step the same `env:` block (or hoist the seven variables to the job level so both steps inherit them). One-line-per-key, no logic change.

### F-14.2 — MEDIUM — The new regression test does not reproduce the wedge it is named for

`tests/Integration/Pos/GiftCertificateNumberTest.php::testALowercaseNumberDoesNotWedgeTheNextCertificate` seeds **two** certificates and expects the next auto-number:

```php
$lower    = 'gc-' . $stem . '1';   // lowercase
$upper    = 'GC-' . $stem . '5';   // uppercase, higher
$expected = 'GC-' . $stem . '6';
// INSERT both, then issue() with an empty number and assert source_reference === $expected
```

The old (pre-fix) `allocate()` scans `number ~ '^GC-[0-9]+$'` — case-sensitive — so it **ignores** the lowercase `gc-<stem>1` but still finds the uppercase `GC-<stem>5`, increments it, and allocates `GC-<stem>6`. No collision, no wedge. The test therefore passes on the buggy code.

**Proof — revert `allocate()` to the pre-fix version and run the test:**

```
$ # restore the 8d622ee body of allocate():  number ~ '^GC-[0-9]+$'  /  preg_match('/^GC-(\d+)$/', …)
$ php vendor/bin/phpunit --testsuite integration --filter GiftCertificateNumberTest --testdox
Gift Certificate Number
 ✔ A lowercase number does not wedge the next certificate
OK (1 test, 2 assertions)          # <-- passes on the BUGGY code
```

The genuine wedge needs the lowercase certificate to sit **at the next sequence point with nothing above it**. I reproduced exactly that (`wedge_probe.php`, seeds a lone `gc-<next>` where `<next>` = highest uppercase + 1, then calls `issue()` with an empty number):

```
=== OLD (case-sensitive) code ===
top uppercase = GC-1002, seeded lowercase = gc-1003
RESULT: issue() THREW DatabaseRefusal: That certificate number is already used.   # <-- the wedge

=== FIXED (case-insensitive) code ===
top uppercase = GC-1002, seeded lowercase = gc-1003
RESULT: issued source_reference = GC-1004                                          # <-- fixed
```

So the **fix is correct** (F-13.1 is genuinely resolved), but the shipped test gives false confidence: it would stay green if `allocate()` were reverted. A faithful guard would seed **only** the lowercase number at the top of the sequence (drop `$upper`), which is the shape that actually wedges.

### F-14.3 — VERY LOW — the obligation gate still misses methods with no visibility keyword

The widened scan is `(?:public|protected|private)\s+(?:static\s+)?function\s+(NAME)\s*\(`. A method written as plain `function foo()` (implicitly public) is not scanned. In this codebase that is currently harmless — the only no-modifier `function` declarations under `src/` are **JavaScript** inside `register-ui.php` / `pay-ui.php` templates, which is precisely why the modifier is required (making it optional would flag that JS). So this is a deliberate, reasonable trade-off, not an active hole; note it only so it is not mistaken for coverage.

### F-14.4 — VERY LOW — `symbolIsCovered` strips `function NAME(` but not `function &NAME(`

The new de-duplication strips `\bfunction\s+NAME\s*\(` so a _declaration_ in a test cannot masquerade as a _call_. A by-reference declaration `function &foo(` is not matched by that strip, so `\bfoo\s*\(` would still match it and count a declaration as coverage. There are **no** by-reference methods under `src/` today (`grep -rnE 'function\s+&[A-Za-z_]' src/` → none), so this is latent only. Tighten to `/\bfunction\s+&\s*NAME\s*\(/` if you want it airtight.

* * *

## 3\. Cycle-13 findings — fix verification

| Cycle-13 finding | Status in `1fcf627` | Evidence |
| --- | --- | --- |
| **F-13.1** lowercase number wedges auto-numbering | **FIXED (verified)** | `allocate()` now `number ~* ?` + `upper(number)`; wedge repro throws on old, returns `GC-1004` on new. Test is weak (F-14.2). |
| **F-13.2** HTTP suite not in `check`; date-brittle red test | **FIXED** (de-brittle + wired), **but CI wiring regressed** | `CoverageRemainderTest` derives `$month` from `charge_date`; `@test:http` added; CI adds `curl`, `xdebug`, `test:coverage`. HTTP suite now green locally (34/479). CI regression = F-14.1. |
| **F-13.3** gate blind to private/protected methods | **FIXED (verified)** | Regex now \`public |
| **F-13.4** enumerable gift balances on JSON endpoint | **FIXED** | `PosLookupController` takes `RegisterGate` and calls `$this->gate->session($slug)` before returning a balance. |
| **F-13.5** whitespace-only custom number accepted | **FIXED** | `$number = trim($request->field('gift_number'))` before the emptiness check; HTTP test posts `' '` and asserts `Enter the certificate number.` |
| **F-13.6** void detected by `:void` string suffix | **FIXED** | `journal.php` + `GiftIssue::slip()` now test `is_reversal` (a real, selected column); `JournalController::void()` preserves `source_type` and sets `reverses_journal_id`. |
| **F-13.7** missing index for the open-certificate list | **FIXED (verified)** | `journals_source_idx (source_type, source_reference)` exactly matches the `outstanding()` join (and `LayawayBook`). |
| **N-12.1** race runner bound to Docker | **FIXED (verified)** | `bin/prove-race-session.php` (PDO, `STALL_DB_*`); `prove-race.php` uses `PHP_BINARY`; `prove-race` prints `ok race` with no Docker. |

### Notes on the fixes that are correct

-   **F-13.6** is a real improvement, not a lateral move: the old check keyed off the `:void` _string convention_, the new one keys off the authoritative `is_reversal` flag. The `in_array(…, [true,1,'1','t','true'], true)` guard tolerates every representation PDO/pgsql can hand back.
-   **F-13.7** is not a speculative index: `OpenGiftCertificates::outstanding()` joins `journals ON source_type = 'gift_certificate_issue' AND source_reference = stored.number`, which is exactly the index prefix.
-   The **race-session SQL splitter** is adequate for the race corpus — no `$$` dollar-quoting, no block comments, and no inline `--` in any `prove_race_*.sql`; the hold files use `SELECT pg_sleep(6)` to pin the lock, which the splitter passes through intact.

* * *

## 4\. Verdict

The engineering response is strong: seven of eight prior findings are genuinely fixed and verified, and the two new heuristics (obligation-gate widening, race-runner replacement) are sound. Two things must be corrected before this can be called done:

1.  **F-14.1 (High)** — restore the `STALL_*` env on the `composer check` step. Until then the CI is red for a reason unrelated to the code, and the commit's own promise ("fail the check when the HTTP suite is red") is unmet because the check fails earlier.
2.  **F-14.2 (Medium)** — rewrite the regression test so it seeds only the lowercase number at the next sequence point; as shipped it passes on the buggy code and protects nothing.

Neither is a design flaw; both are wiring/coverage defects. Fix them and the cycle closes clean.

* * *

### Reproduction artifacts

-   `wedge_probe.php` — faithful wedge repro (old throws, fixed returns `GC-1004`).
-   Reverting `allocate()` to the `8d622ee` body and running `--filter GiftCertificateNumberTest` — passes, proving the shipped test is not a regression test.
-   `env -u STALL_DB_* … phpunit --testsuite integration` — 4/4 errors, proving F-14.1.