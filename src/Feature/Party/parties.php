<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var array{vendors: int, active: int, inactive: int, ending: int, incoming: int} $stats */
/** @var list<array{id: string, code: string, name: string, status: string, status_label: string, status_badge: string, since: string, booths: list<array{id: string, code: string, occupancy: string}>}> $vendors */
/** @var string $status */
/** @var string $query */
$consignmentOnly = ($consignmentOnly ?? false) === true;
$partyLabel = $consignmentOnly ? 'Consignors' : 'Vendors';
$partyOne = $consignmentOnly ? 'consignor' : 'vendor';

$shows = [
    '' => 'All',
    'active' => 'Active (' . $stats['active'] . ')',
    'inactive' => 'Inactive (' . $stats['inactive'] . ')',
    'ending' => 'Ending (' . $stats['ending'] . ')',
    'incoming' => 'Incoming (' . $stats['incoming'] . ')',
];
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1"><?= View::text($partyLabel) ?></h1>
        <p class="muted"><?= $consignmentOnly ? 'Who sells here.' : 'Who sells here, and which booth they hold.' ?></p>
    </div>
    <a class="btn btn-primary" href="/<?= View::text($slug) ?>/vendors/new"><svg aria-hidden="true"><use href="#i-plus"></use></svg> New <?= View::text($partyOne) ?></a>
</div>

<?php if ($stats['vendors'] === 0): ?>
    <div class="card">
        <div class="empty">
            <svg aria-hidden="true"><use href="#i-vendor"></use></svg>
            <p>No <?= $consignmentOnly ? 'consignors' : 'vendors' ?> yet.</p>
        </div>
    </div>
<?php else: ?>
    <div class="list-show">
        <p class="muted">Show</p>
        <div class="segmented" role="navigation" aria-label="Show">
            <?php foreach ($shows as $value => $label): ?>
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
    </div>

    <?php if (!$consignmentOnly): ?>
    <ul class="occupancy-legend" aria-label="Booth colors">
        <li><span class="occupancy-pill occupied">Occupied</span></li>
        <li><span class="occupancy-pill ending">Ending</span></li>
        <li><span class="occupancy-pill incoming">Incoming</span></li>
    </ul>
    <?php endif; ?>

    <div class="card table-wrap list-dt-wrap mt-4">
        <?php if ($vendors === []): ?>
            <div class="empty"><p>No <?= $consignmentOnly ? 'consignors' : 'vendors' ?> match.</p></div>
        <?php else: ?>
            <?php
            ListTable::open([
                'id' => 'vendors',
                'export' => 'vendors',
                'order' => [[0, 'asc']],
            ]);
            $columns = [
                ['label' => 'Custom ID'],
                ['label' => $consignmentOnly ? 'Consignor' : 'Vendor'],
            ];
            if (!$consignmentOnly) {
                $columns[] = ['label' => 'Booth'];
            }
            $columns[] = ['label' => 'Since'];
            $columns[] = ['label' => 'Status'];
            $columns[] = ['label' => '', 'actions' => true];
            ListTable::head($columns);
            ?>
                <tbody>
                <?php foreach ($vendors as $vendor): ?>
                    <tr id="vendor-<?= View::text($vendor['id']) ?>">
                        <td><a class="strong" href="/<?= View::text($slug) ?>/vendors/<?= View::text($vendor['id']) ?>"><?= View::text($vendor['code']) ?></a></td>
                        <td><a class="strong" href="/<?= View::text($slug) ?>/vendors/<?= View::text($vendor['id']) ?>"><?= View::text($vendor['name']) ?></a></td>
                        <?php if (!$consignmentOnly): ?>
                        <td>
                            <?php if ($vendor['booths'] === []): ?>
                                <span class="muted">Unassigned</span>
                            <?php else: ?>
                                <?php foreach ($vendor['booths'] as $booth): ?>
                                    <a class="occupancy-pill <?= View::text($booth['occupancy']) ?>" href="/<?= View::text($slug) ?>/booths#booth-<?= View::text($booth['id']) ?>"><?= View::text($booth['code']) ?></a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <?php endif; ?>
                        <td><?= View::text($vendor['since']) ?></td>
                        <td><span class="badge <?= View::text($vendor['status_badge']) ?>"><?= View::text($vendor['status_label']) ?></span></td>
                        <td><a class="btn btn-sm" href="/<?= View::text($slug) ?>/vendors/<?= View::text($vendor['id']) ?>/edit">Edit</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php ListTable::assets(); ?>
<?php endif; ?>
