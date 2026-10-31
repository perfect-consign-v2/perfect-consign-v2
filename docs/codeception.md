# Codeception (PC2)

Functional (PhpBrowser) and acceptance (Chrome) suites run beside PHPUnit. Money proofs, PHPStan, and `composer check` stay on PHPUnit.

## Commands

```text
composer codecept:build
composer test:functional
composer test:acceptance
composer test:codeception
```

`test:codeception` builds actors, then runs functional and acceptance.

## How it boots

`tests/Codeception/params.php` ensures mall `webprobe` and starts `ProbeServer` on port **8099** (PHPUnit HTTP uses 8097). Set `WEB_BASE_URL` to skip the local server and hit an existing base URL.

## Suites

| Suite | Driver | What it covers |
|---|---|---|
| `functional` | PhpBrowser | Guest doors, owner sign-in, key GETs, role link crawl, safe form crawl (20 visits/role) |
| `acceptance` | `ChromeBrowser` module (chrome-php) | Owner Chrome sign-in, POS nav click, vendor Print / preview Back |

Form crawl across five roles can take several minutes locally; PHPUnit `test:surface-mutate` remains the fuller 35-visit crawl.

Acceptance uses the same Chrome binary discovery as `ChromeProbe` (`CHROME_PATH` override). Chromedriver is not required.

## Layout

```text
codeception.yml
tests/Codeception/
  Functional/
  Acceptance/
  Module/ChromeBrowser.php
  Support/Helper/
  ProbeHarness.php
```
