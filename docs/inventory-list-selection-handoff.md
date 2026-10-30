# Handoff: Items list scroll and sticker selection

Implement this in `C:\laragon\www\perfect-consign` only. Do not edit `curser-pos-specs`, `curser-pos-web`, or `curser-pos-admin`. Do not commit unless the operator asks in that turn.

The storefront already shipped the same behavior in `curser-pos-web` commit `77ee953`. Copy the behavior. Do not copy that app’s server-side DataTables code. This app renders every item into the page, then `public/assets/js/list-datatables.js` pages the table in the browser.

## What is wrong

Staff Items (`/{slug}/inventory`, table `#list-inventory`) is a client-side DataTable. `initTable` always sets `scrollY`, `scrollX`, and `scrollCollapse`. `scrollY` is the viewport minus the wrap top minus 130px, and at least 220px. Sheet fields and Print stickers live on the sticky selection bar (not a permanent card above the table). An older full sticker card above the list collapsed the row area to a few lines. The page does not scroll through the rows.

DataTables removes off-page rows from the document. The print checkboxes use `form="stickers"` and `name="item[]"`. A submit only posts the boxes that are still in the document, so a check on page 1 disappears when the user checks a row on page 2, and it is not printed.

Vendor home (`src/Feature/Vendor/home.php`) is a plain table of that vendor’s items. It does not use DataTables. Leaving that page for the sticker preview, then using Back without printing, drops the checks because nothing stores them.

`src/Feature/Inventory/stickers.php` prints with `window.print()` and never clears a saved selection. One edit-form print (`inventory-form.php`, hidden `item` for a single item) must not clear the list selection.

## What to build

### Page scroll, staff Items only

Mark only `#list-inventory` with `data-dt-page-scroll="1"`. In `initTable`, use `scrollX: true` and do **not** set `scrollY` / `scrollCollapse` (same for every `table.list-dt` in this app today). The resize handler must not write `settings.oScroll.sY` for page-scroll tables.

`scrollX` builds `.dt-scroll-head` / `.dt-scroll-body`. Keep the body height auto (`max-height: none`) so it is not a short vertical scroll box. Horizontal overflow lives only on `.dt-scroll-body` (`overflow-x: auto`). Stick `.dt-scroll-head` to `top: var(--topbar-h)` while the page scrolls vertically. Do not put `overflow-x: auto` on the wrap around that sticky head — that traps vertical stickiness. `.list-dt-wrap.table-wrap` stays `overflow: visible`.

`ListTable::assets()` cache-busts `list-datatables.css` with the mtime of `list-datatables.js`. Version the stylesheet from the CSS file’s own mtime so a CSS-only change is not stuck in the browser.

Do not change the shared `pageLength` of 10. Do not add DataTables to the vendor home table.

### Selection that survives paging

New browser store, same shape as the storefront store, without per-item copy counts. This app has one “How many” field (`copies`, 1–999) for the whole batch. Leave that field on the sticker form. Do not add a copies box on each row.

Store an ordered list of item ids. `setChecked(id, checked)` adds or removes. `applyPage(ids, checked)` changes only those ids. `snapshot()` is the id list. `load` / `save` use `sessionStorage`. Bad JSON or a non-array loads as empty. `clear()` saves `[]`.

Keys, posted as a hidden `selection_storage_key`:

- Staff: `pc.inventorySelection.v1.items.{slug}`
- Vendor: `pc.inventorySelection.v1.vendor.{slug}`

`{slug}` matches the route: `[a-z][a-z0-9-]{0,62}`. A small PHP allowlist returns the key only when it matches `^pc\.inventorySelection\.v1\.(items|vendor)\.[a-z][a-z0-9-]{0,62}$`. Anything else is omitted. Do not put this `if` into a method that is already at cyclomatic complexity 10.

On each draw of `#list-inventory`, restore checks from the store. Filtering, sorting, and paging do not clear the store. Unchecking a row removes that id.

“Select all on this page” lives in the Print column header. Checked only when every selectable row on the current page is checked. Indeterminate when some are. It never clears ids from other pages.

Vendor home has no pages. On load, check the rows whose ids are in the store. On change, update the store. There is no select-all control unless the table later becomes paged.

Sticky print dock, hidden at zero: sheet fields (`stock`, `copies`, `start_row`, `start_column` via `form="stickers"`), “N selected”, a submit for `#stickers`, and Clear. `#stickers` is a shell form (csrf + selection key only). Clear empties the store and unchecks visible rows. Narrow screens stack the dock; from 641px fields and actions sit in one wrap row. Submit buttons stay at least 2.75rem tall. `summary { display: flex }` hides the disclosure marker in some browsers; this bar is not a `<details>`.

On submit of `#stickers`, disable the live `item[]` checkboxes and inject hidden `item[]` fields from the full snapshot, then save. Off-page staff rows must be in that post. Do not disable `stock`, `copies`, `start_row`, `start_column`, or `csrf` (including when those controls are outside the form element and associated with `form=`). `LabelRequest::ids()` already accepts `item` as one string or a list of UUID strings, so the post shape stays the same.

`pageshow` reloads the store and re-enables those fields so a back-forward cache does not submit a disabled empty set.

### Clear after the print dialog

`LabelsController::preview` and `VendorStickers::printOwn` pass `selectionStorageKey` into `Feature/Inventory/stickers` only when the posted key passes the allowlist. The edit-form print does not post a key, so a single-item print does not clear the list.

On `stickers.php`, when the key is present, the Print button carries it and `afterprint` runs `sessionStorage.removeItem(key)`. Browsers also fire `afterprint` when the dialog is cancelled, so closing the dialog ends the batch. Back without opening Print leaves the checks in place.

## Files

- `public/assets/js/list-datatables.js` — page-scroll branch and selection wiring for `#list-inventory`
- `public/assets/js/inventory-selection-store.js` — the store, loaded only where a list uses it
- `public/assets/list-datatables.css` — sticky header and the bar
- `src/Shared/Web/ListTable.php` — optional `pageScroll` flag on `open()`, and CSS mtime
- `src/Feature/Inventory/inventory.php` — page-scroll flag, select-all, bar, hidden key
- `src/Feature/Vendor/home.php` — same store and bar, no DataTables
- `src/Feature/Inventory/sticker-job.php` — hidden key on `#stickers`
- `src/Feature/Inventory/stickers.php` — `afterprint` clear
- `src/Feature/Inventory/LabelsController.php` and `src/Feature/Vendor/VendorStickers.php` — pass a valid key through
- New allowlist class next to the label code, kept out of methods that are already at the complexity cap

## Tests

- `node tests/js/inventory-selection-store.test.cjs` with no new dependency. Cover: page 2 does not erase page 1, uncheck and `applyPage(false)`, no duplicate ids, load/save, bad JSON, `clear()` persists `[]`.
- PHPUnit for the allowlist: staff key, vendor key, extra suffix rejected, empty rejected.
- Existing sticker preview tests still render when the key is absent. Add one case that a valid posted key is rendered onto the print button, and a rejected key is not.
- `ListTable` markup test if `open()` grows a `data-dt-page-scroll` attribute.

`composer.json` test script already runs PHPMD and PHPStan. Cyclomatic complexity report level is 10. NPath minimum is 150. No `@SuppressWarnings`, no baseline, no `@` error suppressor. New PHP methods need return types. Do not grow `LabelsController` or `VendorStickers` past those limits; extract instead.

## Check in the browser

Staff `/{slug}/inventory`: no inner vertical scrollbar on the item rows, the page scrolls, the header stays visible while scrolling, a check on page 1 is still checked after a check on page 2, select-all does not drop the other page, Print stickers posts both ids, Clear hides the bar, and closing the print dialog clears the saved checks. Back from the preview without Print keeps them.

Vendor `/{slug}/vendor`: checks survive Back from the sticker preview, and closing the print dialog clears them. An item printed from the edit form does not clear the Items list selection.
