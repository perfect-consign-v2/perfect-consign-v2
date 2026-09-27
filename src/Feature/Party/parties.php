<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var array{vendors: int, active: int, ending: int, open_booths: int} $stats */
/** @var list<array{id: string, code: string, name: string, status: string, status_label: string, status_badge: string, since: string, booths: list<array{id: string, code: string, occupancy: string}>}> $vendors */
/** @var list<array{id: string, code: string, name: string}> $openBooths */
/** @var string $status */
/** @var string $query */

$cards = [
    ['key' => '', 'label' => 'Vendors', 'value' => $stats['vendors']],
    ['key' => 'active', 'label' => 'Active', 'value' => $stats['active']],
    ['key' => 'ending', 'label' => 'Ending', 'value' => $stats['ending']],
    ['key' => 'open', 'label' => 'Open booths', 'value' => $stats['open_booths']],
];
$filters = [
    '' => 'All',
    'incoming' => 'Incoming',
];
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Vendors</h1>
        <p class="muted">Who sells here, and which booth they hold.</p>
    </div>
    <a class="btn btn-primary" href="/<?= View::text($slug) ?>/vendors/new"><svg aria-hidden="true"><use href="#i-plus"></use></svg> New vendor</a>
</div>

<?php if ($stats['vendors'] === 0 && $stats['open_booths'] === 0): ?>
    <div class="card">
        <div class="empty">
            <svg aria-hidden="true"><use href="#i-vendor"></use></svg>
            <p>No vendors yet.</p>
        </div>
    </div>
<?php else: ?>
    <div class="grid cols-4">
        <?php foreach ($cards as $card): ?>
            <?php
            $selected = $status === $card['key'];
            $params = [];
            if ($card['key'] !== '' && !$selected) {
                $params['status'] = $card['key'];
            }
            if ($query !== '') {
                $params['q'] = $query;
            }
            $href = '/' . $slug . '/parties';
            if ($params !== []) {
                $href .= '?' . http_build_query($params);
            }
            ?>
            <?php if ($card['key'] === ''): ?>
                <div class="stat">
                    <div class="stat-label"><?= View::text($card['label']) ?></div>
                    <div class="stat-value"><?= View::text($card['value']) ?></div>
                </div>
            <?php else: ?>
                <a class="stat stat-link" href="<?= View::text($href) ?>"<?= $selected ? ' aria-current="page"' : '' ?>>
                    <div class="stat-label"><?= View::text($card['label']) ?></div>
                    <div class="stat-value"><?= View::text($card['value']) ?></div>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>

    <div class="segmented mt-6" role="navigation" aria-label="Vendor list">
        <?php foreach ($filters as $value => $label): ?>
            <?php
            $params = [];
            if ($value !== '') {
                $params['status'] = $value;
            }
            if ($query !== '') {
                $params['q'] = $query;
            }
            $href = '/' . $slug . '/parties';
            if ($params !== []) {
                $href .= '?' . http_build_query($params);
            }
            $selected = $status === $value;
            ?>
            <a class="seg-btn<?= $selected ? ' is-selected' : '' ?>" href="<?= View::text($href) ?>"<?= $selected ? ' aria-current="page"' : '' ?>><?= View::text($label) ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($status !== 'open'): ?>
        <ul class="occupancy-legend" aria-label="Booth colors">
            <li><span class="occupancy-pill occupied">Occupied</span></li>
            <li><span class="occupancy-pill ending">Ending</span></li>
            <li><span class="occupancy-pill incoming">Incoming</span></li>
        </ul>
    <?php endif; ?>

    <div class="card table-wrap mt-4">
        <?php if ($status === 'open'): ?>
            <?php if ($openBooths === []): ?>
                <div class="empty"><p>No open booths.</p></div>
            <?php else: ?>
                <table class="table">
                    <thead><tr><th>Booth</th><th>Name</th></tr></thead>
                    <tbody>
                    <?php foreach ($openBooths as $booth): ?>
                        <tr>
                            <td><a class="strong" href="/<?= View::text($slug) ?>/booths#booth-<?= View::text($booth['id']) ?>"><?= View::text($booth['code']) ?></a></td>
                            <td><?= View::text($booth['name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        <?php elseif ($vendors === []): ?>
            <div class="empty"><p>No vendors match.</p></div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Vendor</th>
                        <th>Booth</th>
                        <th>Since</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($vendors as $vendor): ?>
                    <tr id="vendor-<?= View::text($vendor['id']) ?>">
                        <td>
                            <div class="row gap-2">
                                <a class="strong" href="/<?= View::text($slug) ?>/vendors/<?= View::text($vendor['id']) ?>"><?= View::text($vendor['name']) ?></a>
                                <span class="badge badge-accent"><?= View::text($vendor['code']) ?></span>
                            </div>
                        </td>
                        <td>
                            <?php if ($vendor['booths'] === []): ?>
                                <span class="muted">Unassigned</span>
                            <?php else: ?>
                                <?php foreach ($vendor['booths'] as $booth): ?>
                                    <a class="occupancy-pill <?= View::text($booth['occupancy']) ?>" href="/<?= View::text($slug) ?>/booths#booth-<?= View::text($booth['id']) ?>"><?= View::text($booth['code']) ?></a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= View::text($vendor['since']) ?></td>
                        <td><span class="badge <?= View::text($vendor['status_badge']) ?>"><?= View::text($vendor['status_label']) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>
