# Codeception (PC2)

Functional (PhpBrowser) and acceptance (Chrome) suites run beside PHPUnit. Money proofs, PHPStan, and `composer check` stay on PHPUnit — but Codeception now owns the full mall user-surface bar: every static route, every discoverable link, every safe form, explicit money paths, and Chrome clicks on safe controls.

## Commands

```text
composer codecept:build
composer test:functional
composer test:acceptance
composer test:codeception
```

`test:codeception` builds actors, then runs functional and acceptance as **two processes** (so a wedged `php -S` in one suite cannot hang the other). Expect a long run (route catalog + hub crawls + Chrome).

## How it boots

`tests/Codeception/params.php` ensures mall `webprobe` and starts `ProbeServer` on port **8099** (PHPUnit HTTP uses 8097). Set `WEB_BASE_URL` to skip the local server and hit an existing base URL.

## Suites

| Suite | Driver | What it covers |
|---|---|---|
| `functional` | PhpBrowser | Guest doors; owner sign-in; **every static mall GET** (`RouteCatalogCest`); seeded entity GETs; **all-href link crawl** + **safe form crawl** from role hubs (owner/cashier/books/vendor/preview); cash sale + rent charge + refused payout |
| `acceptance` | `ChromeBrowser` (chrome-php) | Owner Chrome sign-in; Sale nav; Print/Back; **click every safe nav/seg/print control** on dashboard/POS/settings, every static mall GET, vendor portal, and owner preview |

### Safe vs money

The form/click crawls **skip** logout, delete, pay, payout, void, password, backup, rent charge, register close, export, letters, etc. (`SurfaceFormDenyList` + click deny list). Those are covered by **MoneyPathCest** (sale/pay, rent charge, payout refuse) and dedicated journeys — not by blind submits.

### Link + form crawl

Link crawl follows **surface** same-mall links (nav, segments, buttons — not every parties/inventory row) from role hubs plus seeded vendor/item/preview URLs, soft-capped at 120 pages/role. Form crawl submits **safe POSTs on those hub pages only**. **Every static route pattern** is covered by `RouteCatalogCest` and Chrome `everyStaticMallGetLoadsInChrome`; seeded entity GETs cover live vendor/item URLs.

Acceptance uses the same Chrome binary discovery as `ChromeProbe` (`CHROME_PATH` override). Chromedriver is not required.

## Layout

```text
codeception.yml
tests/Codeception/
  Functional/   Guest, owner, routes, surface crawls, money paths
  Acceptance/   Sign-in, print, click sweeps
  Module/ChromeBrowser.php
  Support/FullSurfaceCrawler.php
  Support/MallRouteCatalog.php
  ProbeHarness.php
```
