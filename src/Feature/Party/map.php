<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var list<array{id: string, code: string, name: string, x: int, z: int, width: int, depth: int, status: string, vendor: ?string, label: string}> $tiles */
/** @var list<array{id: string, name: string, sort: int}> $floors */
/** @var ?string $floorId */
/** @var int $floorWidth */
/** @var int $floorDepth */

$foot = 12;
$floorHeight = $floorDepth * $foot;
$floorBox = $floorWidth * $foot;
$legend = ['available' => 'Available', 'occupied' => 'Occupied', 'ending' => 'Ending', 'incoming' => 'Incoming'];
$counts = ['available' => 0, 'occupied' => 0, 'ending' => 0, 'incoming' => 0];
foreach ($tiles as $tile) {
    if (isset($counts[$tile['status']])) {
        $counts[$tile['status']]++;
    }
}
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Booth Map</h1>
        <p class="muted">Each square is one foot. Click a booth to view or edit. Keyboard: Tab to a booth, Enter to open.</p>
    </div>
    <div class="row gap-2">
        <a class="btn" href="/<?= View::text($slug) ?>/booths"><svg aria-hidden="true"><use href="#i-booth"></use></svg> List view</a>
        <a class="btn btn-primary" href="/<?= View::text($slug) ?>/map/edit<?= $floorId === null ? '' : '?floor=' . View::text($floorId) ?>"><svg aria-hidden="true"><use href="#i-edit"></use></svg> Edit map</a>
    </div>
</div>

<div class="map-floor-tabs" role="tablist" aria-label="Building floors">
    <?php foreach ($floors as $floor): ?>
        <?php $current = $floor['id'] === $floorId; ?>
        <a class="map-floor-tab<?= $current ? ' current' : '' ?>" role="tab" aria-selected="<?= $current ? 'true' : 'false' ?>" href="/<?= View::text($slug) ?>/map?floor=<?= View::text($floor['id']) ?>"><?= View::text($floor['name']) ?></a>
    <?php endforeach; ?>
</div>

<div class="map-stage">
    <div class="card">
        <div class="card-head">
            <div class="h3"><?php
                $heading = 'Floor';
                foreach ($floors as $floor) {
                    if ($floor['id'] === $floorId) {
                        $heading = $floor['name'];
                    }
                }
                echo View::text($heading);
            ?></div>
            <ul class="map-legend-status">
                <?php foreach ($legend as $name => $label): ?>
                    <li><span class="map-swatch <?= View::text($name) ?>" aria-hidden="true"></span><?= View::text($label . ' (' . $counts[$name] . ')') ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="card-body">
            <?php
            $placed = [];
            foreach ($tiles as $tile) {
                $placed[] = $tile + [
                    'left' => $tile['x'] * $foot,
                    'top' => $tile['z'] * $foot,
                    'boxWidth' => $tile['width'] * $foot,
                    'boxHeight' => $tile['depth'] * $foot,
                ];
            }
            ?>
            <div class="map-scroll">
            <div class="booth-map is-grid" id="booth-map" role="application" aria-label="Interactive booth map" style="height:<?= View::text($floorHeight) ?>px;width:<?= View::text($floorBox) ?>px">
                <?php foreach ($placed as $tile): ?>
                    <?php
                    $vendor = $tile['vendor'] ?? 'Unassigned';
                    $size = $tile['width'] . ' × ' . $tile['depth'] . ' ft';
                    ?>
                    <a class="booth-cell <?= View::text($tile['status']) ?>"
                       id="booth-<?= View::text($tile['id']) ?>"
                       href="/<?= View::text($slug) ?>/booths/<?= View::text($tile['id']) ?>/edit"
                       style="left:<?= View::text($tile['left']) ?>px;top:<?= View::text($tile['top']) ?>px;width:<?= View::text($tile['boxWidth']) ?>px;height:<?= View::text($tile['boxHeight']) ?>px"
                       data-booth="<?= View::text($tile['id']) ?>"
                       data-code="<?= View::text($tile['code']) ?>"
                       data-status="<?= View::text($tile['status']) ?>"
                       data-vendor="<?= View::text($vendor) ?>"
                       data-size="<?= View::text($size) ?>"
                       title="<?= View::text($tile['code'] . ' · ' . ucfirst($tile['status']) . ' · ' . $vendor) ?>">
                        <span class="booth-code"><?= View::text($tile['code']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            </div>
        </div>
    </div>
</div>

<div class="modal" id="booth-detail" hidden>
    <div class="modal-panel card" role="dialog" aria-labelledby="booth-detail-title">
        <div class="card-head">
            <button class="modal-x" type="button" id="booth-detail-close" aria-label="Close">
                <svg aria-hidden="true"><use href="#i-x"></use></svg>
            </button>
            <div class="h3" id="booth-detail-title">Booth details</div>
        </div>
        <div class="card-body" id="booth-detail-body">
            <div class="h2 mono" id="bd-code"></div>
            <div class="mt-4 grid" style="gap:var(--space-3)">
                <div class="row between"><span class="muted">Status</span><span id="bd-status"></span></div>
                <div class="row between"><span class="muted">Vendor</span><span class="strong" id="bd-vendor"></span></div>
                <div class="row between"><span class="muted">Size</span><span class="strong tnum" id="bd-size"></span></div>
            </div>
            <a class="btn btn-primary btn-block mt-6" id="bd-edit" href="#"><svg aria-hidden="true"><use href="#i-edit"></use></svg> Edit booth</a>
        </div>
    </div>
</div>
<script src="/assets/map.js"></script>
