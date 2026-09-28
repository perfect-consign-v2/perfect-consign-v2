<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;

/** @var string $slug */
/** @var string $displayName */
/** @var string $greeting */
/** @var array{
 *   sales: array{amount: string, delta: ?array{direction: string, text: string}},
 *   payables: array{amount: string, caption: string},
 *   trend: list<array{day: string, title: string, height: int, today: bool}>,
 *   today_badge: string,
 *   sales_list: list<array{id: string, number: string, time: string, tenders: list<string>, total: string}>,
 *   occupancy: array{percent: int, caption: string, rows: list<array{label: string, count: int, badge: string}>}
 * } $picture */
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1"><?= View::text($greeting) ?></h1>
        <p class="muted">Here's how <?= View::text($displayName) ?> is doing today.</p>
    </div>
    <div class="row gap-2">
        <a class="btn" href="/<?= View::text($slug) ?>/journals"><svg aria-hidden="true"><use href="#i-report"></use></svg> Journals</a>
        <a class="btn btn-primary" href="/<?= View::text($slug) ?>/sale"><svg aria-hidden="true"><use href="#i-pos"></use></svg> Open sale</a>
    </div>
</div>

<div class="grid cols-4">
    <div class="stat">
        <div class="stat-label"><svg style="width:16px;height:16px" aria-hidden="true"><use href="#i-wallet"></use></svg> Today's sales</div>
        <div class="stat-value"><?= View::text($picture['sales']['amount']) ?></div>
        <?php if ($picture['sales']['delta'] !== null): ?>
            <div class="stat-delta <?= View::text($picture['sales']['delta']['direction']) ?>"><?= View::text($picture['sales']['delta']['text']) ?></div>
        <?php endif; ?>
    </div>
    <div class="stat">
        <div class="stat-label"><svg style="width:16px;height:16px" aria-hidden="true"><use href="#i-vendor"></use></svg> Vendor payables</div>
        <div class="stat-value"><?= View::text($picture['payables']['amount']) ?></div>
        <div class="stat-delta muted"><?= View::text($picture['payables']['caption']) ?></div>
    </div>
    <div class="stat">
        <div class="stat-label"><svg style="width:16px;height:16px" aria-hidden="true"><use href="#i-box"></use></svg> Inventory value</div>
        <div class="stat-value">—</div>
    </div>
    <div class="stat">
        <div class="stat-label"><svg style="width:16px;height:16px" aria-hidden="true"><use href="#i-alert"></use></svg> Low stock</div>
        <div class="stat-value">—</div>
    </div>
</div>

<div class="grid cols-2 mt-6" style="grid-template-columns: 2fr 1fr">
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
            <a class="text-sm" style="color:var(--accent)" href="/<?= View::text($slug) ?>/journals">View all</a>
        </div>
        <div class="table-wrap">
            <table class="table">
                <thead><tr><th>Receipt</th><th>Time</th><th>Tender</th><th class="num">Total</th></tr></thead>
                <tbody>
                <?php foreach ($picture['sales_list'] as $sale): ?>
                    <tr>
                        <td class="mono strong"><a href="/<?= View::text($slug) ?>/journals/<?= View::text($sale['id']) ?>"><?= View::text($sale['number']) ?></a></td>
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
            <div class="h3">Low stock</div>
        </div>
    </div>
</div>
