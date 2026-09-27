<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var list<array{id: string, code: string, name: string, x: int, z: int, width: int, depth: int, status: string, vendor: ?string, label: string}> $tiles */

$statusColor = [
    'occupied' => 'var(--success)',
    'available' => 'var(--info)',
    'ending' => 'var(--warning)',
    'incoming' => 'var(--accent)',
];
$cell = 56;
$gap = 8;
$feetPerCell = 5;
$minHeight = 420;
$minWidth = 0;
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Booth Map</h1>
        <p class="muted">Click a booth to view or edit. Keyboard: Tab to a booth, Enter to open.</p>
    </div>
    <div class="row gap-2">
        <a class="btn" href="/<?= View::text($slug) ?>/booths"><svg aria-hidden="true"><use href="#i-booth"></use></svg> List view</a>
        <a class="btn btn-primary" href="/<?= View::text($slug) ?>/booths/new"><svg aria-hidden="true"><use href="#i-plus"></use></svg> New booth</a>
    </div>
</div>

<div class="map-layout">
    <div class="card">
        <div class="card-head">
            <div class="h3">Main Floor</div>
            <div class="row gap-3 text-xs">
                <?php foreach ($statusColor as $name => $color): ?>
                    <span class="row gap-2"><span style="width:10px;height:10px;border-radius:3px;background:<?= View::text($color) ?>"></span><?= View::text(ucfirst($name)) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="card-body">
            <?php
            $placed = [];
            foreach ($tiles as $tile) {
                $gridX = 1 + intdiv($tile['x'] - 2, $feetPerCell);
                $gridY = 1 + intdiv($tile['z'] - 2, $feetPerCell);
                $gridW = max(1, intdiv($tile['width'], $feetPerCell));
                $gridH = max(1, intdiv($tile['depth'], $feetPerCell));
                $left = ($gridX - 1) * ($cell + $gap);
                $top = ($gridY - 1) * ($cell + $gap);
                $width = $gridW * $cell + ($gridW - 1) * $gap;
                $height = $gridH * $cell + ($gridH - 1) * $gap;
                $minHeight = max($minHeight, $top + $height + 16);
                $minWidth = max($minWidth, $left + $width + 16);
                $placed[] = $tile + [
                    'left' => $left,
                    'top' => $top,
                    'boxWidth' => $width,
                    'boxHeight' => $height,
                ];
            }
            ?>
            <div class="booth-map" id="booth-map" role="application" aria-label="Interactive booth map" style="min-height:<?= View::text($minHeight) ?>px;min-width:<?= View::text($minWidth) ?>px">
                <?php foreach ($placed as $tile): ?>
                    <?php
                    $vendor = $tile['vendor'] ?? 'Unassigned';
                    $size = $tile['width'] . ' × ' . $tile['depth'] . ' ft';
                    ?>
                    <a class="booth-cell"
                       href="/<?= View::text($slug) ?>/booths/<?= View::text($tile['id']) ?>/edit"
                       style="left:<?= View::text($tile['left']) ?>px;top:<?= View::text($tile['top']) ?>px;width:<?= View::text($tile['boxWidth']) ?>px;height:<?= View::text($tile['boxHeight']) ?>px;border-color:<?= View::text($statusColor[$tile['status']] ?? 'var(--border)') ?>"
                       data-booth="<?= View::text($tile['id']) ?>"
                       data-code="<?= View::text($tile['code']) ?>"
                       data-status="<?= View::text($tile['status']) ?>"
                       data-vendor="<?= View::text($vendor) ?>"
                       data-size="<?= View::text($size) ?>"
                       title="<?= View::text($tile['code'] . ' · ' . ucfirst($tile['status']) . ' · ' . $vendor) ?>">
                        <span class="booth-code"><?= View::text($tile['code']) ?></span>
                        <span class="booth-dot" style="background:<?= View::text($statusColor[$tile['status']] ?? 'var(--border)') ?>"></span>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card" id="booth-detail">
        <div class="card-head"><div class="h3">Booth details</div></div>
        <div class="card-body">
            <div class="empty" id="booth-detail-empty">
                <svg aria-hidden="true"><use href="#i-map"></use></svg>
                <p>Select a booth on the map to see its details.</p>
            </div>
            <div id="booth-detail-body" hidden>
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
</div>
<script src="/assets/map.js"></script>
