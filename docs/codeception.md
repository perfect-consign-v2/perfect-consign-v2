# Codeception (PC2)

Functional (PhpBrowser) and acceptance (Chrome) suites run beside PHPUnit. Money proofs, PHPStan, and `composer check` stay on PHPUnit — but Codeception owns the mall user-surface bar. `OwnerActionCest` is the owner obligation: it signs in as the tenant owner and requests every mall route, then fails if a route was never requested or a screen returned the wrong sentence.

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
| `functional` | PhpBrowser | Guest doors; owner sign-in; **every owner mall route** (`OwnerActionCest`, including posts); **every static mall GET** (`RouteCatalogCest`); seeded entity GETs; link crawl + safe form crawl; cash sale + rent charge + refused payout |
| `acceptance` | `ChromeBrowser` (chrome-php) | Owner Chrome sign-in; Sale nav; Print/Back; **click every safe nav/seg/print control** on dashboard/POS/settings, every static mall GET, vendor portal, and owner preview |

### Safe vs money

The form/click crawls **skip** logout, delete, standing, pay, payout, void, password, backup, rent charge, register close, export, letters, etc. (`SurfaceFormDenyList` + click deny list). `OwnerActionCest` submits those owner actions and checks the sentence on the screen. It does not reset the probe mall, does not close the 2020–2035 period, and puts the probe owner password back before it signs out. Before the vendor portal pages it saves building with the portal and vendor inventory on, and it removes only the floor it just added.

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
