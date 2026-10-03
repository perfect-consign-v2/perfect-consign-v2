<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $csrf */
/** @var string $month */
/** @var string $label */
/** @var string $previous */
/** @var string $next */
/** @var string $today */
/** @var string $query */
/** @var string $standing */
/** @var int $total */
/** @var int $matched */
/** @var array{due: int, charged: int, owed: int} $counts */
/** @var array{due_minor: int, due_count: int, charged_minor: int, charged_count: int, owed_minor: int, owed_count: int} $stats */
/** @var list<array{id: string, code: string, name: string, vendor_id: ?string, vendor_name: ?string, rate_minor: ?int, charged: bool, charge_minor: ?int, journal_id: ?string, open_minor: int, charge_date: string, collect_amount: string}> $rows */
/** @var string $activeSessionId */

$activeSessionId = $activeSessionId ?? '';
$blank = '—';
$list = '/' . $slug . '/rent';
$monthQuery = ['month' => $month];
$filters = [
    'due' => 'To charge',
    'charged' => 'Charged',
    'owed' => 'Still owed',
];
$booths = static function (int $count): string {
    if ($count === 1) {
        return '1 booth';
    }

    return $count . ' booths';
};
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Rent</h1>
        <p class="muted"><time datetime="<?= View::text($today) ?>"><?= View::text($label) ?></time></p>
    </div>
    <div class="row gap-2">
        <a class="btn" href="<?= View::text($list . '?month=' . $previous) ?>">Previous</a>
        <a class="btn" href="<?= View::text($list . '?month=' . $next) ?>">Next</a>
    </div>
</div>

<?php if ($total === 0): ?>
    <div class="card">
        <div class="empty">
            <svg aria-hidden="true"><use href="#i-booth"></use></svg>
            <p>No rent for this month.</p>
        </div>
    </div>
<?php else: ?>
    <div class="grid cols-3 mt-6">
        <div class="stat">
            <div class="stat-label">Still to charge</div>
            <div class="stat-value"><?= View::text(View::money($stats['due_minor'])) ?></div>
            <div class="stat-delta muted"><?= View::text($booths($stats['due_count'])) ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Charged</div>
            <div class="stat-value"><?= View::text(View::money($stats['charged_minor'])) ?></div>
            <div class="stat-delta muted"><?= View::text($booths($stats['charged_count'])) ?></div>
        </div>
        <div class="stat">
            <div class="stat-label">Still owed</div>
            <div class="stat-value"><?= View::text(View::money($stats['owed_minor'])) ?></div>
            <div class="stat-delta muted"><?= View::text($booths($stats['owed_count'])) ?></div>
        </div>
    </div>

    <form class="row gap-2 mt-6" method="get" action="<?= View::text($list) ?>" role="search">
        <input type="hidden" name="month" value="<?= View::text($month) ?>">
        <?php if ($standing !== ''): ?>
            <input type="hidden" name="standing" value="<?= View::text($standing) ?>">
        <?php endif; ?>
        <input class="input" style="max-width:20rem" type="search" name="q" value="<?= View::text($query) ?>" placeholder="Search booth or vendor" aria-label="Search booth or vendor">
        <button class="btn" type="submit">Search</button>
    </form>

    <p class="occupancy-summary" aria-label="Rent standing">
        <?php
        $all = $monthQuery;
        if ($query !== '') {
            $all['q'] = $query;
        }
        $allHref = $list . '?' . http_build_query($all);
        $allText = 'All (' . $matched . ')';
        ?>
        <?php if ($matched < 1): ?>
            <span class="occupancy-pill"><?= View::text($allText) ?></span>
        <?php else: ?>
            <a class="occupancy-pill" href="<?= View::text($allHref) ?>"<?= $standing === '' ? ' aria-current="page"' : '' ?>><?= View::text($allText) ?></a>
        <?php endif; ?>
        <?php foreach ($filters as $status => $name): ?>
            <?php
            $count = $counts[$status];
            $text = $name . ' (' . $count . ')';
            $params = $monthQuery;
            if ($query !== '') {
                $params['q'] = $query;
            }
            if ($standing !== $status && $count > 0) {
                $params['standing'] = $status;
            }
            $href = $list . '?' . http_build_query($params);
            ?>
            <?php if ($count < 1): ?>
                <span class="occupancy-pill"><?= View::text($text) ?></span>
            <?php else: ?>
                <a class="occupancy-pill" href="<?= View::text($href) ?>"<?= $standing === $status ? ' aria-current="page"' : '' ?>><?= View::text($text) ?></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </p>

    <div class="card table-wrap mt-4">
        <?php if ($rows === []): ?>
            <div class="empty"><p>No booths match.</p></div>
        <?php else: ?>
            <table class="table">
                <thead>
                    <tr>
                        <th>Booth</th>
                        <th>Vendor</th>
                        <th class="num">Rate</th>
                        <th>This month</th>
                        <th class="num">Still owed</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr id="booth-<?= View::text($row['id']) ?>">
                        <td><a class="strong" href="/<?= View::text($slug) ?>/booths/<?= View::text($row['id']) ?>/edit"><?= View::text($row['code']) ?></a></td>
                        <td>
                            <?php if ($row['vendor_id'] === null || $row['vendor_name'] === null): ?>
                                <span class="muted"><?= $blank ?></span>
                            <?php else: ?>
                                <a class="strong" href="/<?= View::text($slug) ?>/vendors/<?= View::text($row['vendor_id']) ?>"><?= View::text($row['vendor_name']) ?></a>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= $row['rate_minor'] === null ? $blank : View::text(View::money($row['rate_minor'])) ?></td>
                        <td>
                            <?php if ($row['charged'] && is_string($row['journal_id'])): ?>
                                <a class="badge badge-success" href="/<?= View::text($slug) ?>/journals/<?= View::text($row['journal_id']) ?>">Charged</a>
                            <?php elseif ($row['rate_minor'] === null): ?>
                                <a class="badge" href="/<?= View::text($slug) ?>/booths/<?= View::text($row['id']) ?>/edit">No rate</a>
                            <?php else: ?>
                                <form method="post" action="/<?= View::text($slug) ?>/rent/charge">
                                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                    <input type="hidden" name="booth_id" value="<?= View::text($row['id']) ?>">
                                    <input type="hidden" name="posting_date" value="<?= View::text($row['charge_date']) ?>">
                                    <input type="hidden" name="month" value="<?= View::text($month) ?>">
                                    <?php if ($query !== ''): ?>
                                        <input type="hidden" name="q" value="<?= View::text($query) ?>">
                                    <?php endif; ?>
                                    <?php if ($standing !== ''): ?>
                                        <input type="hidden" name="standing" value="<?= View::text($standing) ?>">
                                    <?php endif; ?>
                                    <button class="btn btn-sm btn-primary" type="submit">Charge <?= View::text(View::money($row['rate_minor'])) ?></button>
                                </form>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= View::text(View::money($row['open_minor'])) ?></td>
                        <td>
                            <?php if ($row['open_minor'] > 0 && $activeSessionId === ''): ?>
                                <a href="/<?= View::text($slug) ?>/registers">Open a register to collect</a>
                            <?php elseif ($row['open_minor'] > 0): ?>
                                <form method="post" action="/<?= View::text($slug) ?>/rent/payment">
                                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                                    <input type="hidden" name="booth_id" value="<?= View::text($row['id']) ?>">
                                    <input type="hidden" name="posting_date" value="<?= View::text($today) ?>">
                                    <input type="hidden" name="month" value="<?= View::text($month) ?>">
                                    <?php if ($query !== ''): ?>
                                        <input type="hidden" name="q" value="<?= View::text($query) ?>">
                                    <?php endif; ?>
                                    <?php if ($standing !== ''): ?>
                                        <input type="hidden" name="standing" value="<?= View::text($standing) ?>">
                                    <?php endif; ?>
                                    <label for="receipt-<?= View::text($row['id']) ?>">Receipt or check number</label>
                                    <input class="input" id="receipt-<?= View::text($row['id']) ?>" name="source_reference" required autocomplete="off">
                                    <label for="amount-<?= View::text($row['id']) ?>">Amount</label>
                                    <input class="input" id="amount-<?= View::text($row['id']) ?>" name="amount" inputmode="decimal" required value="<?= View::text($row['collect_amount']) ?>">
                                    <p><button class="btn btn-sm" type="submit">Collect</button></p>
                                </form>
                            <?php else: ?>
                                <span class="muted"><?= $blank ?></span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>
