<?php

declare(strict_types=1);

use PerfectConsign\Shared\Http\View;
use PerfectConsign\Shared\Web\ListTable;

/** @var string $slug */
/** @var string $range */
/** @var string $label */
/** @var list<array{id: string, number: string, time: string, date: string, tenders: list<string>, total: string}> $rows */

$list = '/' . $slug . '/sales';
?>
<div class="page-head">
    <div class="titles">
        <h1 class="h1">Sales</h1>
        <p class="muted"><?= View::text($label) ?></p>
    </div>
    <a class="btn btn-primary" href="/<?= View::text($slug) ?>/pos">Sell</a>
</div>
<p class="mt-4">
    <a href="<?= View::text($list) ?>"<?= $range === 'today' ? ' aria-current="page"' : '' ?>>Today</a>
    · <a href="<?= View::text($list . '?range=yesterday') ?>"<?= $range === 'yesterday' ? ' aria-current="page"' : '' ?>>Yesterday</a>
    · <a href="<?= View::text($list . '?range=all') ?>"<?= $range === 'all' ? ' aria-current="page"' : '' ?>>All</a>
</p>
<?php if ($rows === []): ?>
    <div class="card mt-6">
        <div class="empty">
            <p>No sales in this range.</p>
            <p><a class="btn btn-primary" href="/<?= View::text($slug) ?>/pos">Sell</a></p>
        </div>
    </div>
<?php else: ?>
    <div class="card table-wrap list-dt-wrap mt-6">
        <?php
        ListTable::open(['id' => 'sales', 'export' => 'sales']);
        ListTable::head([
            ['label' => 'Receipt'],
            ['label' => 'Date'],
            ['label' => 'Tender'],
            ['label' => 'Total', 'sort' => 'num'],
        ]);
        ?>
            <tbody>
            <?php foreach ($rows as $sale): ?>
                <tr>
                    <td class="mono strong"><a href="/<?= View::text($slug) ?>/pos/receipt/<?= View::text($sale['id']) ?>"><?= View::text($sale['number']) ?></a></td>
                    <td class="muted"><?= View::text($sale['date']) ?></td>
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
<?php endif; ?>
<?php ListTable::assets(); ?>
