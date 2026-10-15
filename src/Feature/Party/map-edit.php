<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var list<array{id: string, name: string, sort: int}> $floors */
/** @var ?string $floorId */
/** @var int $floorWidth */
/** @var int $floorDepth */
/** @var int $defaultWidth */
/** @var int $defaultDepth */
/** @var list<array{id: string, code: string, name: string, width: ?int, depth: ?int, x: ?int, z: ?int, floorId: ?string, status: string}> $booths */
/** @var ?string $error */

$floorName = 'Floor';
foreach ($floors as $floor) {
    if ($floor['id'] === $floorId) {
        $floorName = $floor['name'];
    }
}
$payload = json_encode($booths, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_THROW_ON_ERROR);
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Edit floor map</h1>
        <p class="muted">Drag booths from the unplaced strip onto the floor. Unlock layout to move them. Each square is one foot. Save when the floor looks right.</p>
    </div>
    <a class="btn" href="/<?= View::text($slug) ?>/map<?= $floorId === null ? '' : '?floor=' . View::text($floorId) ?>"><svg aria-hidden="true"><use href="#i-map"></use></svg> View map</a>
</div>

<div class="map-floor-tabs" role="tablist" aria-label="Building floors">
    <?php foreach ($floors as $floor): ?>
        <?php $current = $floor['id'] === $floorId; ?>
        <button type="button" class="map-floor-tab<?= $current ? ' current' : '' ?>" role="tab" aria-selected="<?= $current ? 'true' : 'false' ?>" data-floor="<?= View::text($floor['id']) ?>" data-floor-name="<?= View::text($floor['name']) ?>"><?= View::text($floor['name']) ?></button>
    <?php endforeach; ?>
</div>

<div class="map-floor-actions row gap-2">
    <button class="btn" type="button" data-open-modal="add-floor">Add floor</button>
    <button class="btn" type="button" data-open-modal="rename-floor">Rename floor</button>
    <form method="post" action="/<?= View::text($slug) ?>/map/floors/<?= View::text((string) $floorId) ?>/delete" id="remove-floor-form">
        <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
        <button class="btn" type="submit" id="remove-floor"<?= count($floors) < 2 ? ' disabled' : '' ?>>Remove floor</button>
    </form>
</div>

<div id="add-floor" class="modal" hidden>
    <form class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/map/floors">
        <div class="card-head"><div class="h3">Add floor</div></div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <label for="add-floor-name">Floor name</label>
            <input class="input" id="add-floor-name" name="name" required maxlength="40" value="">
            <div class="row gap-2">
                <button class="btn btn-primary" type="submit">Add floor</button>
                <button class="btn" type="button" data-close-modal="add-floor">Cancel</button>
            </div>
        </div>
    </form>
</div>

<div id="rename-floor" class="modal" hidden>
    <form class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/map/floors/<?= View::text((string) $floorId) ?>" id="rename-floor-form">
        <div class="card-head"><div class="h3">Rename floor</div></div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <label for="rename-floor-name">Floor name</label>
            <input class="input" id="rename-floor-name" name="name" required maxlength="40" value="<?= View::text($floorName) ?>">
            <div class="row gap-2">
                <button class="btn btn-primary" type="submit">Rename floor</button>
                <button class="btn" type="button" data-close-modal="rename-floor">Cancel</button>
            </div>
        </div>
    </form>
</div>

<form method="post" action="/<?= View::text($slug) ?>/map/edit" id="map-save">
    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
    <input type="hidden" name="floor" id="map-floor-field" value="<?= View::text((string) $floorId) ?>">
    <input type="hidden" name="layout" id="map-layout-field" value="[]">
</form>

<div id="map-editor"
     data-floor="<?= View::text((string) $floorId) ?>"
     data-width="<?= View::text($floorWidth) ?>"
     data-depth="<?= View::text($floorDepth) ?>"
     data-default-width="<?= View::text($defaultWidth) ?>"
     data-default-depth="<?= View::text($defaultDepth) ?>"
     data-foot="12">
    <p class="map-legend">Each grid square is <strong>1 foot</strong> when the grid is on. Drag unplaced booths onto the floor. Unlock layout to move them.</p>
    <div class="map-toolbar" role="toolbar" aria-label="Map editor tools">
        <label class="row gap-2"><input type="checkbox" role="switch" id="lock-layout" checked> Lock layout</label>
        <label class="row gap-2"><input type="checkbox" role="switch" id="show-grid" checked> Show grid</label>
        <button class="btn btn-primary" type="submit" form="map-save">Save map</button>
        <button class="btn" type="button" id="take-off" hidden>Take off map</button>
    </div>
    <div class="map-unplaced" id="map-unplaced" aria-label="Unplaced booths">
        <p class="muted text-sm">Unplaced</p>
        <div class="map-unplaced-row" id="map-unplaced-row"></div>
    </div>
    <div class="map-scroll">
        <div class="booth-map is-grid" id="map-floor" role="application" aria-label="Floor grid"></div>
    </div>
    <p class="muted text-sm" id="map-status" role="status"></p>
</div>
<script type="application/json" id="map-booths"><?= $payload ?></script>
<script src="/assets/map-editor.js"></script>
