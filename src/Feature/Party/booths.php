<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $query */
/** @var string $standing */
/** @var array{available: int, occupied: int, ending: int, incoming: int} $counts */
/** @var string $confirm */
/** @var int $total */
/** @var list<array{id: string, code: string, name: string, width: int, depth: int, status: string, status_label: string, vendor_id: ?string, vendor_name: ?string, rent_minor: ?int, open_minor: ?int, placed: bool, deletable: bool}> $booths */

$blank = '—';
$list = '/' . $slug . '/booths';
$legend = [
    'available' => 'Available',
    'occupied' => 'Occupied',
    'ending' => 'Ending',
    'incoming' => 'Incoming',
];
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Booths</h1>
        <p class="muted">Who holds each booth, what it costs, and whether it sits on the map.</p>
    </div>
    <a class="btn btn-primary" href="/<?= View::text($slug) ?>/booths/new"><svg aria-hidden="true"><use href="#i-plus"></use></svg> New booth</a>
</div>

<?php if ($total === 0): ?>
    <div class="card">
        <div class="empty">
            <svg aria-hidden="true"><use href="#i-booth"></use></svg>
            <p>No booths yet.</p>
        </div>
    </div>
<?php else: ?>
    <form class="row gap-2 mt-6" method="get" action="<?= View::text($list) ?>" role="search">
        <input class="input" style="max-width:20rem" type="search" name="q" value="<?= View::text($query) ?>" placeholder="Search code or name" aria-label="Search booths">
        <?php if ($standing !== ''): ?>
            <input type="hidden" name="standing" value="<?= View::text($standing) ?>">
        <?php endif; ?>
        <button class="btn" type="submit">Search</button>
    </form>

    <p class="occupancy-summary" aria-label="Booth standing">
        <?php foreach ($legend as $status => $label): ?>
            <?php
            $count = $counts[$status];
            $text = $label . ' (' . $count . ')';
            $params = [];
            if ($query !== '') {
                $params['q'] = $query;
            }
            if ($standing !== $status && $count > 0) {
                $params['standing'] = $status;
            }
            $href = $list . ($params === [] ? '' : '?' . http_build_query($params));
            ?>
            <?php if ($count < 1): ?>
                <span class="occupancy-pill <?= View::text($status) ?>"><?= View::text($text) ?></span>
            <?php else: ?>
                <a class="occupancy-pill <?= View::text($status) ?>" href="<?= View::text($href) ?>"<?= $standing === $status ? ' aria-current="page"' : '' ?>><?= View::text($text) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </p>

    <div class="card table-wrap mt-4">
        <?php if ($booths === []): ?>
            <div class="empty"><p>No booths match.</p></div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Name</th>
                        <th>Size</th>
                        <th>Standing</th>
                        <th>Vendor</th>
                        <th class="num">Monthly rent</th>
                        <th class="num">Open rent</th>
                        <th>Map</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($booths as $booth): ?>
                    <?php
                    $keep = [];
                    if ($query !== '') {
                        $keep['q'] = $query;
                    }
                    if ($standing !== '') {
                        $keep['standing'] = $standing;
                    }
                    $cancel = $list;
                    if ($keep !== []) {
                        $cancel .= '?' . http_build_query($keep);
                    }
                    $ask = $keep;
                    $ask['confirm'] = $booth['id'];
                    $delete = $list . '?' . http_build_query($ask);
                    ?>
                    <tr id="booth-<?= View::text($booth['id']) ?>">
                        <td><a class="strong" href="/<?= View::text($slug) ?>/booths/<?= View::text($booth['id']) ?>/edit"><?= View::text($booth['code']) ?></a></td>
                        <td><?= View::text($booth['name']) ?></td>
                        <td class="tnum"><?= View::text($booth['width']) ?> × <?= View::text($booth['depth']) ?> ft</td>
                        <td><span class="occupancy-pill <?= View::text($booth['status']) ?>"><?= View::text($booth['status_label']) ?></span></td>
                        <td>
                            <?php if ($booth['vendor_id'] === null || $booth['vendor_name'] === null): ?>
                                <span class="muted"><?= $blank ?></span>
                            <?php else: ?>
                                <a class="strong" href="/<?= View::text($slug) ?>/vendors/<?= View::text($booth['vendor_id']) ?>"><?= View::text($booth['vendor_name']) ?></a>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= $booth['rent_minor'] === null ? $blank : View::text(View::money($booth['rent_minor'])) ?></td>
                        <td class="num"><?= $booth['open_minor'] === null ? $blank : View::text(View::money($booth['open_minor'])) ?></td>
                        <td>
                            <?php if ($booth['placed']): ?>
                                <a href="/<?= View::text($slug) ?>/map#booth-<?= View::text($booth['id']) ?>">On the map</a>
                            <?php else: ?>
                                <span class="muted">Not on the map</span>
                            <?php endif; ?>
                        </td>
                        <td class="row gap-2">
                            <a class="btn btn-sm btn-ghost" href="/<?= View::text($slug) ?>/booths/<?= View::text($booth['id']) ?>/edit"><svg aria-hidden="true"><use href="#i-edit"></use></svg> Edit</a>
                            <?php if ($confirm === $booth['id']): ?>
                                <span>Delete this booth?</span>
                                <a class="btn btn-sm btn-ghost" href="<?= View::text($cancel) ?>">Cancel</a>
                                <form method="post" action="/<?= View::text($slug) ?>/booths/<?= View::text($booth['id']) ?>/delete">
                                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                    <button class="btn btn-sm" type="submit"><svg aria-hidden="true"><use href="#i-trash"></use></svg> Delete</button>
                                </form>
                            <?php elseif ($booth['deletable']): ?>
                                <a class="btn btn-sm" href="<?= View::text($delete) ?>"><svg aria-hidden="true"><use href="#i-trash"></use></svg> Delete</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>
