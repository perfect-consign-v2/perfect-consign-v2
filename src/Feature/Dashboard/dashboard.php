<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var string $displayName */
/** @var string $greeting */
/** @var array{
 *   sales: array{amount: string, delta: ?array{direction: string, text: string}},
 *   payables: array{amount: string, caption: string},
 *   trend: list<array{day: string, title: string, height: int, today: bool, amount: string}>,
 *   today_badge: string,
 *   sales_list: list<array{id: string, number: string, time: string, date: string, tenders: list<string>, total: string}>,
 *   occupancy: array{percent: int, caption: string, rows: list<array{label: string, count: int, badge: string}>},
 *   shelf: array{value: string, unsold: int}
 * } $picture */
/** @var list<array{id: string, code: string, vendor_id: ?string, vendor_name: ?string, rate_minor: ?int, charged: bool, chargeable: bool, journal_id: ?string, open_minor: int}> $rentRows */
/** @var string $rentMonth */
/** @var string $activeSessionId */
$activeSessionId = is_string($activeSessionId ?? null) ? $activeSessionId : '';
$blank = '—';
?>
<?php if (($overdueJobs ?? 0) > 0): ?>
<p>A background job is waiting. Check the worker.</p>
<?php endif; ?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1"><?= View::text($greeting) ?></h1>
        <p class="muted">Who owes rent, what sold today, and who gets a check.</p>
    </div>
    <div class="row gap-2">
        <a class="btn" href="/<?= View::text($slug) ?>/sales"><svg aria-hidden="true"><use href="#i-report"></use></svg> Today's sales</a>
        <a class="btn btn-primary" href="/<?= View::text($slug) ?>/pos"><svg aria-hidden="true"><use href="#i-pos"></use></svg> Sell</a>
    </div>
</div>

<div class="grid cols-4">
    <a class="stat" href="/<?= View::text($slug) ?>/sales">
        <div class="stat-label"><svg style="width:16px;height:16px" aria-hidden="true"><use href="#i-wallet"></use></svg> Today's sales</div>
        <div class="stat-value"><?= View::text($picture['sales']['amount']) ?></div>
        <?php if ($picture['sales']['delta'] !== null): ?>
            <div class="stat-delta <?= View::text($picture['sales']['delta']['direction']) ?>"><?= View::text($picture['sales']['delta']['text']) ?></div>
        <?php endif; ?>
    </a>
    <a class="stat" href="/<?= View::text($slug) ?>/payouts">
        <div class="stat-label"><svg style="width:16px;height:16px" aria-hidden="true"><use href="#i-vendor"></use></svg> Vendor payables</div>
        <div class="stat-value"><?= View::text($picture['payables']['amount']) ?></div>
        <div class="stat-delta muted"><?= View::text($picture['payables']['caption']) ?></div>
    </a>
    <div class="stat">
        <div class="stat-label"><svg style="width:16px;height:16px" aria-hidden="true"><use href="#i-box"></use></svg> Inventory value</div>
        <div class="stat-value"><?= View::text($picture['shelf']['value']) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label"><svg style="width:16px;height:16px" aria-hidden="true"><use href="#i-alert"></use></svg> Not for sale</div>
        <div class="stat-value"><?= View::text((string) $picture['shelf']['unsold']) ?></div>
    </div>
</div>

<div class="grid split-aside mt-6">
    <div class="card">
        <div class="card-head">
            <div>
                <div class="h3">Sales trend</div>
                <div class="text-sm muted">Last 14 days</div>
            </div>
            <span class="badge badge-accent"><?= View::text($picture['today_badge']) ?></span>
        </div>
        <div class="card-body">
            <div class="row" style="align-items:flex-end; gap:6px; height:180px">
                <?php foreach ($picture['trend'] as $bar): ?>
                    <div style="flex:1; display:flex; flex-direction:column; align-items:center; gap:6px; height:100%; justify-content:flex-end" title="<?= View::text($bar['title']) ?>">
                        <div style="width:100%; height:<?= View::text((string) $bar['height']) ?>%; border-radius:6px 6px 0 0; background: <?= $bar['today'] ? 'var(--accent)' : 'var(--accent-soft)' ?>; border:1px solid <?= $bar['today'] ? 'var(--accent)' : 'var(--border)' ?>"></div>
                        <span class="text-xs subtle" style="white-space:nowrap"><?= View::text($bar['day']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <table class="table mt-4">
                <thead>
                    <tr><th>Day</th><th>Amount</th></tr>
                </thead>
                <tbody>
                <?php foreach ($picture['trend'] as $bar): ?>
                    <tr>
                        <td><?= View::text($bar['title']) ?></td>
                        <td class="num"><?= View::text($bar['amount']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-head"><div class="h3">Booth occupancy</div></div>
        <div class="card-body">
            <div class="row between">
                <span class="h1"><?= View::text((string) $picture['occupancy']['percent']) ?>%</span>
                <span class="muted text-sm"><?= View::text($picture['occupancy']['caption']) ?></span>
            </div>
            <div class="progress mt-4"><span style="width: <?= View::text((string) $picture['occupancy']['percent']) ?>%"></span></div>
            <div class="grid mt-6" style="gap:var(--space-3)">
                <?php foreach ($picture['occupancy']['rows'] as $row): ?>
                    <div class="row between">
                        <span class="badge <?= View::text($row['badge']) ?> badge-dot"><?= View::text($row['label']) ?></span>
                        <span class="strong tnum"><?= View::text((string) $row['count']) ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<div class="grid cols-2 mt-6">
    <div class="card">
        <div class="card-head">
            <div class="h3">Recent sales</div>
            <a class="text-sm" style="color:var(--accent)" href="/<?= View::text($slug) ?>/sales?range=all">View all</a>
        </div>
        <div class="table-wrap list-dt-wrap">
            <?php
            ListTable::open(['id' => 'recent-sales', 'export' => 'recent-sales']);
            ListTable::head([
                ['label' => 'Receipt'],
                ['label' => 'Time'],
                ['label' => 'Tender'],
                ['label' => 'Total', 'sort' => 'num'],
            ]);
            ?>
                <tbody>
                <?php foreach ($picture['sales_list'] as $sale): ?>
                    <tr>
                        <td class="mono strong"><a href="/<?= View::text($slug) ?>/pos/receipt/<?= View::text($sale['id']) ?>"><?= View::text($sale['number']) ?></a></td>
                        <td class="muted"><?= View::text($sale['time']) ?></td>
                        <td>
                            <?php foreach ($sale['tenders'] as $tender): ?>
                                <span class="badge"><?= View::text($tender) ?></span>
                            <?php endforeach; ?>
                        </td>
                        <td class="num strong"><?= View::text($sale['total']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <div class="card-head">
            <div class="h3">Rent due</div>
            <a class="text-sm" style="color:var(--accent)" href="/<?= View::text($slug) ?>/rent?month=<?= View::text($rentMonth) ?>">Rent</a>
        </div>
        <div class="card-body rent-due-scroll">
            <?php if ($rentRows === []): ?>
                <p class="muted">No rent is still owed this month.</p>
            <?php else: ?>
                <table class="table">
                    <thead>
                        <tr>
                            <th>Booth</th>
                            <th>Vendor</th>
                            <th>Rate</th>
                            <th>This month</th>
                            <th>Still owed</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rentRows as $row): ?>
                        <?php $rentRow = '/' . $slug . '/rent?month=' . rawurlencode($rentMonth) . '#booth-' . $row['id']; ?>
                        <tr>
                            <td><a class="strong" href="<?= View::text($rentRow) ?>"><?= View::text($row['code']) ?></a></td>
                            <td>
                                <?php if ($row['vendor_id'] === null || $row['vendor_name'] === null): ?>
                                    <span class="muted"><?= $blank ?></span>
                                <?php else: ?>
                                    <a class="strong" href="/<?= View::text($slug) ?>/vendors/<?= View::text($row['vendor_id']) ?>"><?= View::text($row['vendor_name']) ?></a>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= $row['rate_minor'] === null ? $blank : View::text(View::money($row['rate_minor'])) ?></td>
                            <td>
                                <?php if ($row['charged']): ?>
                                    <a href="<?= View::text($rentRow) ?>">Charged</a>
                                <?php elseif ($row['rate_minor'] === null): ?>
                                    <a href="<?= View::text($rentRow) ?>">No rate</a>
                                <?php elseif (!$row['chargeable']): ?>
                                    <span class="muted"><?= $blank ?></span>
                                <?php else: ?>
                                    <a href="<?= View::text($rentRow) ?>">Charge <?= View::text(View::money($row['rate_minor'])) ?></a>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= View::text(View::money($row['open_minor'])) ?></td>
                            <td>
                                <?php if (($actorRole ?? '') === 'books'): ?>
                                    <span class="muted"><?= $blank ?></span>
                                <?php elseif ($activeSessionId === ''): ?>
                                    <a href="/<?= View::text($slug) ?>/registers">Open a register to collect</a>
                                <?php elseif ($row['vendor_id'] !== null): ?>
                                    <a class="btn btn-sm" href="/<?= View::text($slug) ?>/rent/collect?vendor=<?= View::text($row['vendor_id']) ?>">Collect</a>
                                <?php else: ?>
                                    <a class="btn btn-sm" href="/<?= View::text($slug) ?>/rent/collect?booth=<?= View::text($row['id']) ?>">Collect</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php ListTable::assets(); ?>
