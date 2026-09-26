<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $today */
/** @var list<array{id: string, code: string, name: string, x: int, z: int, width: int, depth: int, status: string, vendor: ?string, label: string}> $tiles */

$counts = ['available' => 0, 'occupied' => 0, 'ending' => 0, 'incoming' => 0];
foreach ($tiles as $tile) {
    $counts[$tile['status']]++;
}
$state = json_encode(
    ['floorWidth' => 120, 'floorDepth' => 90, 'tiles' => $tiles],
    JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
);
?>
<h1>Booth map</h1>
<p class="caption">Ground floor is 120 by 90 feet. 1 grid square = 1 ft. Today is <?= View::text($today) ?>.</p>
<p class="legend">
    <span class="pill pill-available">Available (<?= View::text($counts['available']) ?>)</span>
    <span class="pill pill-occupied">Occupied (<?= View::text($counts['occupied']) ?>)</span>
    <span class="pill pill-ending">Ending (<?= View::text($counts['ending']) ?>)</span>
    <span class="pill pill-incoming">Incoming (<?= View::text($counts['incoming']) ?>)</span>
</p>
<div class="map-layout">
    <div>
        <canvas id="mall-map" width="960" height="720" aria-label="Mall floor"></canvas>
        <ul class="map-keys">
            <?php foreach ($tiles as $tile): ?>
                <li>
                    <a href="/<?= View::text($slug) ?>/parties#booth-<?= View::text($tile['code']) ?>" data-booth="<?= View::text($tile['id']) ?>">
                        <?= View::text($tile['label']) ?>
                        <span class="pill pill-<?= View::text($tile['status']) ?>"><?= View::text($tile['status']) ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <aside class="inspector" id="map-inspector">
        <h2>Floor</h2>
        <p>Select a booth. Vendors and assignments stay on the vendor page.</p>
    </aside>
</div>
<script type="application/json" id="map-state"><?= $state ?></script>
<script src="/assets/map.js"></script>
