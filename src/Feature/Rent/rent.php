<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

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
/** @var list<array{id: string, code: string, name: string, vendor_id: ?string, vendor_name: ?string, rate_minor: ?int, charged: bool, chargeable: bool, charge_minor: ?int, journal_id: ?string, open_minor: int, charge_date: string, collect_amount: string}> $rows */
/** @var bool $fromSales */
/** @var list<array{vendor_id: string, code: string, name: string, booth_id: string, booth: string, owes: int, collect: string}> $debtors */
/** @var list<array{booth_id: string, code: string, vendor_name: ?string, month: string, label: string, amount_minor: int, charge_date: string}> $backlog */
/** @var string $activeSessionId */

$activeSessionId = $activeSessionId ?? '';
$fromSales = $fromSales ?? false;
$debtors = $debtors ?? [];
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
        <?php if ($fromSales): ?>
            <p class="muted">Who owes the store</p>
        <?php else: ?>
            <p class="muted"><time datetime="<?= View::text($today) ?>"><?= View::text($label) ?></time></p>
        <?php endif; ?>
    </div>
    <?php if (!$fromSales): ?>
    <div class="row gap-2">
        <a class="btn" href="<?= View::text($list . '?month=' . $previous) ?>">Previous</a>
        <a class="btn" href="<?= View::text($list . '?month=' . $next) ?>">Next</a>
    </div>
    <?php endif; ?>
</div>

<?php if ($backlog !== []): ?>
    <section class="card mt-6" aria-labelledby="rent-backlog">
        <div class="card-head"><div class="h3" id="rent-backlog">Not charged</div></div>
        <div class="card-body">
            <?php foreach ($backlog as $due): ?>
                <div class="row gap-2">
                    <span class="strong"><?= View::text($due['code']) ?></span>
                    <?php if ($due['vendor_name'] !== null): ?>
                        <span><?= View::text($due['vendor_name']) ?></span>
                    <?php endif; ?>
                    <span class="muted"><?= View::text($due['label']) ?></span>
                    <button class="btn btn-sm btn-primary" type="button" data-open-modal="charge-<?= View::text($due['booth_id'] . '-' . $due['month']) ?>">Charge <?= View::text(View::money($due['amount_minor'])) ?></button>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php foreach ($backlog as $due): ?>
        <div id="charge-<?= View::text($due['booth_id'] . '-' . $due['month']) ?>" class="modal" role="dialog" aria-modal="true" aria-labelledby="charge-title-<?= View::text($due['booth_id'] . '-' . $due['month']) ?>" hidden>
            <form class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/rent/charge">
                <div class="card-head"><div class="h3" id="charge-title-<?= View::text($due['booth_id'] . '-' . $due['month']) ?>">Charge this rent</div></div>
                <div class="card-body stack">
                    <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
                    <input type="hidden" name="booth_id" value="<?= View::text($due['booth_id']) ?>">
                    <input type="hidden" name="posting_date" value="<?= View::text($due['charge_date']) ?>">
                    <input type="hidden" name="month" value="<?= View::text($month) ?>">
                    <?php if ($query !== ''): ?>
                        <input type="hidden" name="q" value="<?= View::text($query) ?>">
                    <?php endif; ?>
                    <?php if ($standing !== ''): ?>
                        <input type="hidden" name="standing" value="<?= View::text($standing) ?>">
                    <?php endif; ?>
                    <p>Booth <?= View::text($due['code']) ?></p>
                    <?php if ($due['vendor_name'] !== null): ?>
                        <p><?= View::text($due['vendor_name']) ?></p>
                    <?php endif; ?>
                    <p><?= View::text($due['label']) ?></p>
                    <p class="h3">Charge <?= View::text(View::money($due['amount_minor'])) ?></p>
                    <div class="row gap-2">
                        <button class="btn btn-primary" type="submit">Charge <?= View::text(View::money($due['amount_minor'])) ?></button>
                        <button class="btn" type="button" data-close-modal="charge-<?= View::text($due['booth_id'] . '-' . $due['month']) ?>">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if ($fromSales): ?>
    <?php
    $needle = strtolower($query);
    $owing = [];
    $owedTotal = 0;
    foreach ($debtors as $row) {
        $haystack = strtolower($row['code'] . ' ' . $row['name'] . ' ' . $row['booth']);
        if ($needle !== '' && !str_contains($haystack, $needle)) {
            continue;
        }
        $owing[] = $row;
        $owedTotal += $row['owes'];
    }
    ?>
    <div class="stat mt-6">
        <div class="stat-label">Owes the store</div>
        <div class="stat-value"><?= View::text(View::money($owedTotal)) ?></div>
        <div class="stat-delta muted"><?= View::text($booths(count($owing))) ?></div>
    </div>
    <form class="row gap-2 mt-6" method="get" action="<?= View::text($list) ?>" role="search">
        <input class="input" style="max-width:20rem" type="search" name="q" value="<?= View::text($query) ?>" placeholder="Search booth or vendor" aria-label="Search booth or vendor">
        <button class="btn" type="submit">Search</button>
    </form>
    <div class="card table-wrap list-dt-wrap mt-4">
        <?php if ($owing === []): ?>
            <div class="empty"><p>Nobody owes rent.</p></div>
        <?php else: ?>
            <?php
            ListTable::open(['id' => 'rent', 'export' => 'rent']);
            ListTable::head([
                ['label' => 'Booth'],
                ['label' => 'Vendor'],
                ['label' => 'Owes the store', 'sort' => 'num'],
                ['label' => '', 'actions' => true],
            ]);
            ?>
                <tbody>
                <?php foreach ($owing as $row): ?>
                    <tr id="vendor-<?= View::text($row['vendor_id']) ?>">
                        <td>
                            <?php if ($row['booth_id'] === ''): ?>
                                <?= View::text($row['booth'] === '' ? $blank : $row['booth']) ?>
                            <?php else: ?>
                                <a class="strong" href="/<?= View::text($slug) ?>/booths/<?= View::text($row['booth_id']) ?>/edit"><?= View::text($row['booth']) ?></a>
                            <?php endif; ?>
                        </td>
                        <td><a class="strong" href="/<?= View::text($slug) ?>/vendors/<?= View::text($row['vendor_id']) ?>">(<?= View::text($row['code']) ?>) <?= View::text($row['name']) ?></a></td>
                        <td class="num"><?= View::text(View::money($row['owes'])) ?></td>
                        <td>
                            <?php if ($row['booth_id'] === ''): ?>
                                <span class="muted"><?= $blank ?></span>
                            <?php elseif ($activeSessionId === ''): ?>
                                <a href="/<?= View::text($slug) ?>/registers">Open a register to collect</a>
                            <?php else: ?>
                                <a class="btn btn-sm btn-primary" href="/<?= View::text($slug) ?>/rent/collect?vendor=<?= View::text($row['vendor_id']) ?>&amp;booth=<?= View::text($row['booth_id']) ?>">Collect</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
    <?php ListTable::assets(); ?>
<?php elseif ($total === 0): ?>
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

    <div class="card table-wrap list-dt-wrap mt-4">
        <?php if ($rows === []): ?>
            <div class="empty"><p>No booths match.</p></div>
        <?php else: ?>
            <?php
            ListTable::open(['id' => 'rent', 'export' => 'rent']);
            ListTable::head([
                ['label' => 'Booth'],
                ['label' => 'Vendor'],
                ['label' => 'Rate', 'sort' => 'num'],
                ['label' => 'This month'],
                ['label' => 'Still owed', 'sort' => 'num'],
                ['label' => '', 'actions' => true],
            ]);
            ?>
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
                            <?php elseif (!$row['chargeable']): ?>
                                <span class="muted"><?= $blank ?></span>
                            <?php else: ?>
                                <button class="btn btn-sm btn-primary" type="button" data-open-modal="charge-<?= View::text($row['id']) ?>">Charge <?= View::text(View::money($row['rate_minor'])) ?></button>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= View::text(View::money($row['open_minor'])) ?></td>
                        <td>
                            <?php if ($row['open_minor'] > 0 && $activeSessionId === ''): ?>
                                <a href="/<?= View::text($slug) ?>/registers">Open a register to collect</a>
                            <?php elseif ($row['open_minor'] > 0): ?>
                                <a class="btn btn-sm" href="/<?= View::text($slug) ?>/rent/collect?booth=<?= View::text($row['id']) ?>">Collect</a>
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
    <?php foreach ($rows as $row): ?>
        <?php if ($row['charged'] || $row['rate_minor'] === null || !$row['chargeable']) {
            continue;
        } ?>
        <div id="charge-<?= View::text($row['id']) ?>" class="modal" role="dialog" aria-modal="true" aria-labelledby="charge-title-<?= View::text($row['id']) ?>" hidden>
            <form class="modal-panel card" method="post" action="/<?= View::text($slug) ?>/rent/charge">
                <div class="card-head"><div class="h3" id="charge-title-<?= View::text($row['id']) ?>">Charge this rent</div></div>
                <div class="card-body stack">
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
                    <p>Booth <?= View::text($row['code']) ?></p>
                    <?php if ($row['vendor_name'] !== null): ?>
                        <p><?= View::text($row['vendor_name']) ?></p>
                    <?php endif; ?>
                    <p><?= View::text($label) ?></p>
                    <p class="h3">Charge <?= View::text(View::money($row['rate_minor'])) ?></p>
                    <div class="row gap-2">
                        <button class="btn btn-primary" type="submit">Charge <?= View::text(View::money($row['rate_minor'])) ?></button>
                        <button class="btn" type="button" data-close-modal="charge-<?= View::text($row['id']) ?>">Cancel</button>
                    </div>
                </div>
            </form>
        </div>
    <?php endforeach; ?>
    <?php ListTable::assets(); ?>
<?php endif; ?>
<?php if (($actorRole ?? '') === 'owner' && $rows !== []): ?>
    <form class="card mt-6" method="post" action="/<?= View::text($slug) ?>/rent/late-fee">
        <div class="card-head"><div class="h3">Late fee</div></div>
        <div class="card-body stack">
            <input type="hidden" name="csrf" value="<?= View::text($csrf) ?>">
            <p class="muted">This is its own charge. It does not reduce what the store owes the vendor. Waive it from Collect rent.</p>
            <div class="field">
                <label for="late_booth">Booth</label>
                <select class="select" id="late_booth" name="booth_id" required>
                    <option value="">Choose a booth</option>
                    <?php foreach ($rows as $row): ?>
                        <option value="<?= View::text($row['id']) ?>"><?= View::text($row['code']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="late_date">Date</label>
                <input class="input" id="late_date" name="posting_date" type="date" value="<?= View::text($today) ?>" required>
            </div>
            <div class="field">
                <label for="late_amount">Amount</label>
                <input class="input" id="late_amount" name="amount" inputmode="decimal" required>
            </div>
            <p><button class="btn" type="submit">Post late fee</button></p>
        </div>
    </form>
<?php endif; ?>
